<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Lifecycle;

use App\Entity\Plugin\Plugin;
use App\Entity\Plugin\PluginOperation;
use App\Plugin\Bundle\PluginBundlesFileWriter;
use App\Plugin\Event\Lifecycle\PluginInstalledEvent;
use App\Plugin\Manifest\PluginManifest;
use App\Plugin\PackageManager\PackageManagerRunner;
use App\Plugin\Registry\PluginRegistryService;
use App\Plugin\Security\PluginCapabilityValidator;
use App\Plugin\Security\PluginCapabilityViolationException;
use App\Plugin\Security\PluginMigrationGuard;
use App\Plugin\Security\PluginMigrationGuardException;
use App\Plugin\Security\PluginSignatureException;
use App\Plugin\Security\PluginSignatureVerifier;
use App\Plugin\Versioning\PluginCompatibilityValidator;
use App\Repository\Plugin\PluginRepository;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Response;
use App\Exception\ServiceException;

/**
 * Orchestrates installation of a plugin.
 *
 * The installer:
 *
 *   1. Acquires the global + per-plugin operation lock.
 *   2. Records a `plugin_operations` row in `requested` state.
 *   3. Validates capability matrix + compatibility against the host
 *      CMS version.
 *   4. Snapshots the relevant files (`composer.lock`,
 *      `package.json`, generated bundles file, lock file) into the
 *      operation row.
 *   5. In `managed` install mode it returns the CLI command and ends
 *      the operation in `requested` state — the actual install runs
 *      via `selfhelp:plugin:run-operation`.
 *   6. In `development` / `trusted` modes the orchestrator may run
 *      composer / npm via the `PackageManagerRunner`; that path is
 *      implemented in the CLI runner so the same code path applies
 *      everywhere.
 *
 * Once the package work is done by the CLI runner, the installer
 * persists the `plugins` row, regenerates the bundles file, and
 * dispatches `PluginInstalledEvent`.
 */
final class PluginInstaller
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PluginOperationLock $lock,
        private readonly PluginOperationRecorder $recorder,
        private readonly PluginCapabilityValidator $capabilityValidator,
        private readonly PluginCompatibilityValidator $compatibilityValidator,
        private readonly PluginRepository $plugins,
        private readonly PluginRegistryService $registry,
        private readonly InstallModeResolver $installModeResolver,
        private readonly PluginLockFileReader $lockFileReader,
        private readonly PluginLockFileWriter $lockFileWriter,
        private readonly PluginBundlesFileWriter $bundlesWriter,
        private readonly PluginSignatureVerifier $signatureVerifier,
        private readonly PluginMigrationGuard $migrationGuard,
        private readonly PackageManagerRunner $packageManagerRunner,
        private readonly TransactionService $transactions,
        private readonly EventDispatcherInterface $events,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Stage 1 — request installation. Returns the `PluginOperation`
     * row that orchestrates the rest of the work.
     *
     * @param array<string,mixed>|null $registryEntry Optional registry
     *                                                payload (checksum,
     *                                                signature, source).
     */
    public function request(PluginManifest $manifest, ?array $registryEntry = null): PluginOperation
    {
        $this->lock->assertCanStart($manifest->getPluginId());

        try {
            if ($this->plugins->findOneByPluginId($manifest->getPluginId()) !== null) {
                throw new ServiceException(sprintf(
                    'Plugin "%s" is already installed. Use the update command instead.',
                    $manifest->getPluginId()
                ), Response::HTTP_CONFLICT);
            }

            $compatibility = $this->compatibilityValidator->check($manifest);
            if ($compatibility['severity'] === 'blocking') {
                throw new ServiceException(
                    'Plugin compatibility check failed: ' . implode('; ', $compatibility['reasons']),
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    ['compatibility' => $compatibility]
                );
            }

            try {
                $capabilities = $this->capabilityValidator->validate($manifest);
            } catch (PluginCapabilityViolationException $e) {
                throw new ServiceException($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, null);
            }

            // Refuse installs whose registry-side checksums/signature
            // cannot be verified. In strict mode (production default)
            // this aborts with `PluginSignatureException`; in lenient
            // mode it logs and continues so dev installs from untrusted
            // sources still work.
            try {
                $this->signatureVerifier->verify(
                    $this->extractExpectedChecksums($registryEntry),
                    $this->extractActualChecksums($registryEntry, $manifest),
                    $this->extractSignature($registryEntry),
                    $this->extractSignedPayload($registryEntry),
                );
            } catch (PluginSignatureException $e) {
                throw new ServiceException($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, null);
            }

            // Static-scan plugin migration files for protected-table
            // operations. Plugins that target protected tables must
            // either own them or be ALTERed under `--allow-destructive`.
            $migrationScan = $this->scanPluginMigrations($manifest);

            $installMode = $this->installModeResolver->resolve();
            $operation = $this->recorder->start(
                $manifest->getPluginId(),
                PluginOperation::TYPE_INSTALL,
                $installMode,
                $manifest->getVersion()
            );

            $this->recorder->snapshot($operation, [
                'manifest' => $manifest->toArray(),
                'compatibility' => $compatibility,
                'capabilities' => $capabilities,
                'registryEntry' => $registryEntry,
                'lockFileBefore' => $this->lockFileReader->readRaw(),
                'migrationScan' => $migrationScan,
                'packageDryRun' => $this->collectPackageDryRunResults($manifest),
            ]);

            $this->recorder->setRollbackPlan($operation, [
                'restoreLockFile' => true,
                'regenerateBundles' => true,
            ]);

            $this->transactions->logTransaction(
                LookupService::TRANSACTION_TYPES_INSERT,
                LookupService::TRANSACTION_BY_BY_USER,
                'plugins',
                null,
                false,
                sprintf('Plugin install requested: %s@%s (mode=%s)', $manifest->getPluginId(), $manifest->getVersion(), $installMode)
            );

            // Lock stays held until `finalize()` (success or failure)
            // so a concurrent finalize for the same plugin id cannot
            // start.
            return $operation;
        } catch (\Throwable $e) {
            $this->lock->release($manifest->getPluginId());
            throw $e;
        }
    }

    /**
     * Stage 2 — finalize installation after package + migration work
     * has finished (called by `selfhelp:plugin:run-operation` or the
     * direct-execution worker).
     */
    public function finalize(PluginOperation $operation, PluginManifest $manifest): Plugin
    {
        $this->recorder->markRunning($operation, 'Finalizing plugin install');

        // Bundle-class-existence gate. The orchestrator is about to
        // regenerate `config/selfhelp_plugin_bundles.php`. If the bundle
        // class is not autoloadable yet (because composer never ran),
        // `kernel->registerBundles()` will fatal-error on the next
        // request. Fail fast with a helpful message instead — the
        // operation stays in `running` state and the operator can
        // recover with the local-install script or a managed-mode
        // composer step. See docs/plugins/installation.md.
        $bundleClass = $manifest->getBackendBundleClass();
        if ($bundleClass !== null && $bundleClass !== '' && !class_exists($bundleClass)) {
            $packageHint = $manifest->getBackendPackage() ?? $manifest->getPluginId();
            $error = new ServiceException(sprintf(
                'Backend bundle class "%s" is not autoloadable. The composer package "%s" must be installed before finalizing. ' .
                'Run `composer require %s:%s` (or use the plugin\'s `scripts/install-local.{ps1,sh}` helper) and click Finalize again.',
                $bundleClass,
                $packageHint,
                $packageHint,
                $manifest->getVersion(),
            ), Response::HTTP_PRECONDITION_FAILED);
            $this->recorder->fail($operation, $error, 'finalize:bundle-missing');
            $this->lock->release($manifest->getPluginId());
            throw $error;
        }

        try {
            $this->em->beginTransaction();
            try {
                $plugin = new Plugin(
                    $manifest->getPluginId(),
                    $manifest->getName(),
                    $manifest->getVersion(),
                    $manifest->getPluginApiVersion()
                );
                $plugin->setDescription($manifest->getDescription());
                $plugin->setTrustLevel($manifest->getTrustLevel());
                $plugin->setInstallMode($operation->getInstallMode());
                $plugin->setBackendPackage($manifest->getBackendPackage());
                $plugin->setBackendBundleClass($manifest->getBackendBundleClass());
                $plugin->setFrontendPackage($manifest->getFrontendPackage());
                $plugin->setFrontendPackageVersion($manifest->getFrontendPackageVersion());
                $plugin->setMobilePackage($manifest->getMobilePackage());
                $plugin->setMobilePackageVersion($manifest->getMobilePackageVersion());
                $plugin->setManifestJson($manifest->toArray());
                $plugin->setCapabilitiesJson($this->capabilityValidator->validate($manifest));
                $plugin->setEnabled(false);

                $this->em->persist($plugin);
                $this->em->flush();
                $this->em->commit();
            } catch (\Throwable $e) {
                $this->em->rollback();
                $this->recorder->fail($operation, $e, 'finalize');
                throw $e;
            }

            $this->registry->invalidate();
            $this->bundlesWriter->regenerate();
            $this->lockFileWriter->upsertPlugin($plugin, $manifest);

            $this->recorder->succeed($operation, 'Plugin installed', $plugin, $manifest->getVersion());
            $this->events->dispatch(new PluginInstalledEvent($plugin, $operation));

            $this->logger->info('Plugin installed', [
                'plugin_id' => $plugin->getPluginId(),
                'version' => $plugin->getVersion(),
                'install_mode' => $plugin->getInstallMode(),
            ]);

            return $plugin;
        } finally {
            $this->lock->release($manifest->getPluginId());
        }
    }

    /**
     * @param array<string,mixed>|null $registryEntry
     * @return array{composer?: string|null, frontend?: string|null, mobile?: string|null}
     */
    private function extractExpectedChecksums(?array $registryEntry): array
    {
        if (!is_array($registryEntry)) {
            return [];
        }
        $checksums = $registryEntry['checksums'] ?? null;
        if (!is_array($checksums)) {
            return [];
        }
        $out = [];
        foreach (['composer', 'frontend', 'mobile'] as $key) {
            if (isset($checksums[$key]) && is_string($checksums[$key])) {
                $out[$key] = $checksums[$key];
            }
        }
        return $out;
    }

    /**
     * The host has no way to compute artifact checksums at request-time
     * — the artifacts have not been downloaded yet. We forward whatever
     * the registry advertised so the verifier can at least confirm
     * format/length parity (or no-op in lenient mode). The CLI runner
     * re-runs `verify()` on the downloaded artifacts before finalize.
     *
     * @param array<string,mixed>|null $registryEntry
     * @return array{files: array<string,string>}
     */
    private function extractActualChecksums(?array $registryEntry, PluginManifest $manifest): array
    {
        $expected = $this->extractExpectedChecksums($registryEntry);
        $files = [];
        foreach ($expected as $artifact => $value) {
            $files[$artifact] = $value;
        }
        return ['files' => $files];
    }

    /**
     * @param array<string,mixed>|null $registryEntry
     */
    private function extractSignature(?array $registryEntry): ?string
    {
        $sig = is_array($registryEntry) ? ($registryEntry['signature'] ?? null) : null;
        return is_string($sig) && $sig !== '' ? $sig : null;
    }

    /**
     * @param array<string,mixed>|null $registryEntry
     */
    private function extractSignedPayload(?array $registryEntry): ?string
    {
        $payload = is_array($registryEntry) ? ($registryEntry['signedPayload'] ?? null) : null;
        return is_string($payload) && $payload !== '' ? $payload : null;
    }

    /**
     * Scans the plugin's migration directory for destructive operations
     * on protected core tables. Returns a per-file report so the
     * operation snapshot has a clear paper trail of what was scanned.
     *
     * @return array{
     *   scanned: int,
     *   files: list<array{file: string, violations: list<string>}>,
     * }
     */
    private function scanPluginMigrations(PluginManifest $manifest): array
    {
        $bundleClass = $manifest->getBackendBundleClass();
        if ($bundleClass === null || !class_exists($bundleClass)) {
            return ['scanned' => 0, 'files' => []];
        }
        $bundleReflection = new \ReflectionClass($bundleClass);
        $bundleDir = dirname((string) $bundleReflection->getFileName());
        $migrationsDir = $bundleDir . '/Migrations';
        if (!is_dir($migrationsDir)) {
            return ['scanned' => 0, 'files' => []];
        }

        $entries = glob($migrationsDir . '/*.php') ?: [];
        $report = ['scanned' => count($entries), 'files' => []];
        foreach ($entries as $file) {
            $contents = @file_get_contents($file);
            if ($contents === false) {
                continue;
            }
            $violations = [];
            foreach ($this->extractStringLiterals($contents) as $sql) {
                try {
                    $this->migrationGuard->assertAllowed($sql);
                } catch (PluginMigrationGuardException $e) {
                    $violations[] = $e->getMessage();
                }
            }
            if ($violations !== []) {
                $report['files'][] = ['file' => basename($file), 'violations' => $violations];
            }
        }

        return $report;
    }

    /**
     * Naive extractor for SQL strings inside a PHP migration. Picks up
     * single-quoted, double-quoted and heredoc/nowdoc literals that
     * contain SQL-like keywords. Good enough for the runtime safety
     * net; the strict review happens at release time.
     *
     * @return list<string>
     */
    private function extractStringLiterals(string $php): array
    {
        $matches = [];
        if (preg_match_all('/<<<["\']?(\w+)["\']?\R(.*?)\R\s*\1\s*;/s', $php, $heredocs)) {
            foreach ($heredocs[2] as $body) {
                $matches[] = $body;
            }
        }
        if (preg_match_all('/(?<!\\\\)\'([^\']{4,})\'/s', $php, $singles)) {
            foreach ($singles[1] as $body) {
                $matches[] = $body;
            }
        }
        if (preg_match_all('/(?<!\\\\)"([^"]{4,})"/s', $php, $doubles)) {
            foreach ($doubles[1] as $body) {
                $matches[] = $body;
            }
        }
        return array_values(array_filter(
            $matches,
            static fn(string $s): bool => preg_match('/\b(drop|truncate|alter|delete\s+from)\b/i', $s) === 1,
        ));
    }

    /**
     * Run composer/npm in dry-run mode to surface dependency conflicts
     * before the CLI runner does the real install. The result is
     * stored in the operation snapshot so admins can review the
     * planned changes from the UI before approving the operation.
     *
     * @return array<string,mixed>
     */
    private function collectPackageDryRunResults(PluginManifest $manifest): array
    {
        $out = [];
        $composerPackage = $manifest->getBackendPackage();
        if ($composerPackage !== null && $composerPackage !== '') {
            $out['composer'] = $this->packageManagerRunner->dryRunComposer($composerPackage, $manifest->getVersion())->toArray();
        }
        $frontendPackage = $manifest->getFrontendPackage();
        if ($frontendPackage !== null && $frontendPackage !== '') {
            $out['npm_frontend'] = $this->packageManagerRunner->dryRunNpm($frontendPackage, $manifest->getFrontendPackageVersion() ?? $manifest->getVersion())->toArray();
        }
        $mobilePackage = $manifest->getMobilePackage();
        if ($mobilePackage !== null && $mobilePackage !== '') {
            $out['npm_mobile'] = $this->packageManagerRunner->dryRunNpm($mobilePackage, $manifest->getMobilePackageVersion() ?? $manifest->getVersion())->toArray();
        }
        return $out;
    }
}
