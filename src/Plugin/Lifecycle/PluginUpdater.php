<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Lifecycle;

use App\Entity\Plugin\Plugin;
use App\Entity\Plugin\PluginOperation;
use App\Exception\ServiceException;
use App\Plugin\Backup\PluginBackupHookInterface;
use App\Plugin\Bundle\PluginBundlesFileWriter;
use App\Plugin\Event\Lifecycle\PluginUpdatedEvent;
use App\Plugin\Manifest\PluginManifest;
use App\Plugin\PackageManager\PackageManagerRunner;
use App\Plugin\Registry\PluginRegistryService;
use App\Plugin\Security\PluginCapabilityValidator;
use App\Plugin\Security\PluginMigrationGuard;
use App\Plugin\Security\PluginMigrationGuardException;
use App\Plugin\Security\PluginSignatureException;
use App\Plugin\Security\PluginSignatureVerifier;
use App\Plugin\Versioning\PluginCompatibilityValidator;
use App\Plugin\Versioning\SemverHelper;
use App\Repository\Plugin\PluginRepository;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Updates an installed plugin to a new manifest version.
 *
 * Versioning semantics (codified in every host AGENTS.md):
 *
 *   - `patch` → code change without DB change. Migrations skipped.
 *   - `minor` → always carries a DB change.
 *   - `major` → breaking change. Requires `--force-major` from caller.
 *
 * Like `PluginInstaller`, the updater is split into `request()` (set
 * up the operation row + validations) and `finalize()` (commit the
 * new plugin record after the CLI runner has done the package + DB
 * work).
 */
final class PluginUpdater
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PluginRepository $plugins,
        private readonly PluginOperationLock $lock,
        private readonly PluginOperationRecorder $recorder,
        private readonly PluginCapabilityValidator $capabilityValidator,
        private readonly PluginCompatibilityValidator $compatibilityValidator,
        private readonly PluginBackupHookInterface $backupHook,
        private readonly InstallModeResolver $installModeResolver,
        private readonly PluginLockFileReader $lockFileReader,
        private readonly PluginLockFileWriter $lockFileWriter,
        private readonly PluginBundlesFileWriter $bundlesWriter,
        private readonly PluginRegistryService $registry,
        private readonly PluginSignatureVerifier $signatureVerifier,
        private readonly PluginMigrationGuard $migrationGuard,
        private readonly PackageManagerRunner $packageManagerRunner,
        private readonly TransactionService $transactions,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    public function request(
        PluginManifest $newManifest,
        bool $forceMajor = false,
        ?array $registryEntry = null,
        bool $backupBefore = false,
    ): PluginOperation {
        $pluginId = $newManifest->getPluginId();
        $this->lock->assertCanStart($pluginId);

        try {
            $existing = $this->plugins->findOneByPluginId($pluginId);
            if (!$existing instanceof Plugin) {
                throw new ServiceException(sprintf(
                    'Cannot update plugin "%s": not installed. Use install instead.',
                    $pluginId
                ), Response::HTTP_NOT_FOUND);
            }

            $diff = SemverHelper::diffKind($existing->getVersion(), $newManifest->getVersion());
            if ($diff === 'same') {
                throw new ServiceException(sprintf(
                    'Plugin "%s" is already at version %s.',
                    $pluginId,
                    $newManifest->getVersion()
                ), Response::HTTP_CONFLICT);
            }
            if ($diff === 'downgrade') {
                throw new ServiceException(sprintf(
                    'Cannot update plugin "%s" to an older version (%s → %s). Use rollback instead.',
                    $pluginId,
                    $existing->getVersion(),
                    $newManifest->getVersion()
                ), Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            if ($diff === 'major' && !$forceMajor) {
                throw new ServiceException(sprintf(
                    'Plugin "%s" major update requested (%s → %s). Pass --force-major to acknowledge breaking changes.',
                    $pluginId,
                    $existing->getVersion(),
                    $newManifest->getVersion()
                ), Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $compat = $this->compatibilityValidator->check($newManifest);
            if ($compat['severity'] === 'blocking') {
                throw new ServiceException(
                    'Plugin compatibility check failed: ' . implode('; ', $compat['reasons']),
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    ['compatibility' => $compat]
                );
            }

            $capabilities = $this->capabilityValidator->validate($newManifest);

            try {
                $this->signatureVerifier->verify(
                    $this->extractExpectedChecksums($registryEntry),
                    $this->extractActualChecksums($registryEntry),
                    $this->extractSignature($registryEntry),
                    $this->extractSignedPayload($registryEntry),
                );
            } catch (PluginSignatureException $e) {
                throw new ServiceException($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, null);
            }

            $migrationScan = $this->scanPluginMigrations($newManifest);
            $dryRun = $this->collectPackageDryRunResults($newManifest);

            $installMode = $this->installModeResolver->resolve();
            $operation = $this->recorder->start(
                $pluginId,
                PluginOperation::TYPE_UPDATE,
                $installMode,
                $newManifest->getVersion(),
                $existing->getVersion(),
            );

            // Backup is requested either by `--backup-before` (CLI) or
            // any time the diff kind implies destructive schema work
            // (major versions). The hook itself decides what to do —
            // the default NoopPluginBackupHook returns a suggested
            // `mysqldump` command for the operator to run manually.
            $shouldBackup = $backupBefore || $diff === 'major' || $diff === 'minor';
            $backup = $shouldBackup
                ? $this->backupHook->beforeDestructive(
                    $pluginId,
                    PluginOperation::TYPE_UPDATE,
                    $this->affectedTables($existing),
                )
                : null;

            $this->recorder->snapshot($operation, [
                'currentManifest' => $existing->getManifestJson(),
                'newManifest' => $newManifest->toArray(),
                'capabilities' => $capabilities,
                'compatibility' => $compat,
                'lockFileBefore' => $this->lockFileReader->readRaw(),
                'diffKind' => $diff,
                'backup' => $backup,
                'registryEntry' => $registryEntry,
                'migrationScan' => $migrationScan,
                'packageDryRun' => $dryRun,
            ]);

            $this->recorder->setRollbackPlan($operation, [
                'restoreLockFile' => true,
                'regenerateBundles' => true,
                'restorePackages' => true,
            ]);

            $this->transactions->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'plugins',
                $existing->getId(),
                false,
                sprintf('Plugin update requested: %s %s → %s (mode=%s)', $pluginId, $existing->getVersion(), $newManifest->getVersion(), $installMode)
            );

            return $operation;
        } catch (\Throwable $e) {
            $this->lock->release($pluginId);
            throw $e;
        }
    }

    public function finalize(PluginOperation $operation, PluginManifest $newManifest): Plugin
    {
        $plugin = $this->plugins->findOneByPluginId($newManifest->getPluginId());
        if (!$plugin instanceof Plugin) {
            throw new \LogicException('Plugin disappeared between request() and finalize().');
        }

        $this->recorder->markRunning($operation, 'Finalizing plugin update');

        // Mirrors PluginInstaller::finalize(): refuse to regenerate the
        // bundles file with a class the autoloader cannot resolve. The
        // operator must run composer for the new version first.
        $bundleClass = $newManifest->getBackendBundleClass();
        if ($bundleClass !== null && $bundleClass !== '' && !class_exists($bundleClass)) {
            $packageHint = $newManifest->getBackendPackage() ?? $newManifest->getPluginId();
            $error = new ServiceException(sprintf(
                'Backend bundle class "%s" is not autoloadable. The composer package "%s" must be installed before finalizing. ' .
                'Run `composer require %s:%s` (or use the plugin\'s `scripts/install-local.{ps1,sh}` helper) and click Finalize again.',
                $bundleClass,
                $packageHint,
                $packageHint,
                $newManifest->getVersion(),
            ), Response::HTTP_PRECONDITION_FAILED);
            $this->recorder->fail($operation, $error, 'finalize:bundle-missing');
            $this->lock->release($newManifest->getPluginId());
            throw $error;
        }

        try {
            $this->em->beginTransaction();
            try {
                $plugin->setVersion($newManifest->getVersion());
                $plugin->setPluginApiVersion($newManifest->getPluginApiVersion());
                $plugin->setTrustLevel($newManifest->getTrustLevel());
                $plugin->setBackendPackage($newManifest->getBackendPackage());
                $plugin->setBackendBundleClass($newManifest->getBackendBundleClass());
                $plugin->setFrontendPackage($newManifest->getFrontendPackage());
                $plugin->setFrontendPackageVersion($newManifest->getFrontendPackageVersion());
                $plugin->setMobilePackage($newManifest->getMobilePackage());
                $plugin->setMobilePackageVersion($newManifest->getMobilePackageVersion());
                $plugin->setManifestJson($newManifest->toArray());
                $plugin->setCapabilitiesJson($this->capabilityValidator->validate($newManifest));
                $plugin->setDescription($newManifest->getDescription());
                $plugin->setName($newManifest->getName());
                $plugin->touchUpdatedAt();
                $this->em->flush();
                $this->em->commit();
            } catch (\Throwable $e) {
                $this->em->rollback();
                $this->recorder->fail($operation, $e, 'finalize');
                throw $e;
            }

            $this->registry->invalidate();
            $this->bundlesWriter->regenerate();
            $this->lockFileWriter->upsertPlugin($plugin, $newManifest);

            $this->recorder->succeed($operation, 'Plugin updated', $plugin, $plugin->getVersion());
            $this->events->dispatch(new PluginUpdatedEvent(
                $plugin,
                $operation,
                $operation->getFromVersion(),
                $plugin->getVersion(),
            ));

            return $plugin;
        } finally {
            $this->lock->release($newManifest->getPluginId());
        }
    }

    /**
     * @return list<string>
     */
    private function affectedTables(Plugin $plugin): array
    {
        $data = $plugin->getManifestJson();
        $owned = $data['dataAccess']['ownedTables'] ?? [];
        return is_array($owned) ? array_values(array_filter($owned, 'is_string')) : [];
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
     * @param array<string,mixed>|null $registryEntry
     * @return array{files: array<string,string>}
     */
    private function extractActualChecksums(?array $registryEntry): array
    {
        return ['files' => $this->extractExpectedChecksums($registryEntry)];
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
