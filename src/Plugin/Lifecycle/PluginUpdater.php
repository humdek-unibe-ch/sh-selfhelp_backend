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
use App\Plugin\Manifest\ResolvedSource;
use App\Plugin\Messenger\UpdatePluginMessage;
use App\Plugin\Registry\PluginRegistryService;
use App\Plugin\Security\PluginCapabilityValidator;
use App\Plugin\Security\PluginMigrationGuard;
use App\Plugin\Security\PluginMigrationGuardException;
use App\Plugin\Versioning\PluginCompatibilityValidator;
use App\Plugin\Versioning\SemverHelper;
use App\Repository\Plugin\PluginRepository;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Updates an installed plugin to a new manifest version.
 *
 * Mirrors `PluginInstaller`:
 *   - `request()` validates compatibility/version-diff/capabilities and
 *     dispatches `UpdatePluginMessage` so the Messenger worker can run
 *     `composer require <package>:<newVersion>` and promote any new
 *     `.shplugin` artifacts.
 *   - `finalize()` persists the updated plugin row, regenerates the
 *     bundles file, refreshes the lock file, and dispatches
 *     `PluginUpdatedEvent`.
 *
 * Signature verification of the incoming source happens upstream in
 * `ManifestResolver` / `PluginArchiveValidator`.
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
        private readonly PluginMigrationGuard $migrationGuard,
        private readonly TransactionService $transactions,
        private readonly EventDispatcherInterface $events,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function request(
        PluginManifest $newManifest,
        ResolvedSource $resolved,
        bool $forceMajor = false,
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

            $capabilities = $this->capabilityValidator->validate($newManifest, $resolved);
            $migrationScan = $this->scanPluginMigrations($newManifest);
            $installMode = $this->installModeResolver->resolve();

            $operation = $this->recorder->start(
                $pluginId,
                PluginOperation::TYPE_UPDATE,
                $installMode,
                $newManifest->getVersion(),
                $existing->getVersion(),
            );

            // Backup is requested either by `--backup-before` (CLI) or
            // whenever the diff implies destructive schema work.
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
                'resolvedSource' => [
                    'kind' => $resolved->kind,
                    'sourceName' => $resolved->sourceName,
                    'manifestUrl' => $resolved->manifestUrl,
                    'keyId' => $resolved->keyId,
                    'signature' => $resolved->signature,
                    'expectedChecksums' => $resolved->expectedChecksums,
                    'composer' => $resolved->composer,
                    'archiveStagingDir' => $resolved->archiveStagingDir,
                ],
                'migrationScan' => $migrationScan,
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
                sprintf('Plugin update requested: %s %s → %s (mode=%s, source=%s)', $pluginId, $existing->getVersion(), $newManifest->getVersion(), $installMode, $resolved->kind)
            );

            $opId = $operation->getId();
            if (!is_int($opId)) {
                throw new \LogicException('PluginOperation id was not generated.');
            }
            $this->messageBus->dispatch(new UpdatePluginMessage(
                operationId: $opId,
                manifestArray: $newManifest->toArray(),
                resolvedSource: $resolved,
            ));

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

        $bundleClass = $newManifest->getBackendBundleClass();
        if ($bundleClass !== null && $bundleClass !== '' && !class_exists($bundleClass)) {
            $packageHint = $newManifest->getBackendPackage() ?? $newManifest->getPluginId();
            $error = new ServiceException(sprintf(
                'Backend bundle class "%s" is not autoloadable after composer require. The Messenger worker reported success but the bundle did not register; check composer.json + autoload-dump for package "%s" at version "%s".',
                $bundleClass,
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
                $plugin->setFrontendRuntimeUrl($newManifest->getFrontendRuntimeEntrypoint());
                $plugin->setFrontendRuntimeStylesheetUrl($newManifest->getFrontendRuntimeStylesheet());
                $plugin->setFrontendRuntimeIntegrity($newManifest->getFrontendRuntimeIntegrity());
                $plugin->setFrontendRuntimeFormat($newManifest->getFrontendRuntimeFormat());
                $plugin->setMobilePackage($newManifest->getMobilePackage());
                $plugin->setMobilePackageVersion($newManifest->getMobilePackageVersion());
                $plugin->setManifestJson($newManifest->toArray());
                $plugin->setCapabilitiesJson($this->capabilityValidator->validate($newManifest));
                $plugin->setDescription($newManifest->getDescription());
                $plugin->setName($newManifest->getName());
                $this->applySigningMetadata($plugin, $operation);
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
     * Lift the signing keyId + signature out of the operation's
     * `resolvedSource` snapshot (written by `request()`) and pin them
     * on the `Plugin` entity so the lock file can render them.
     */
    private function applySigningMetadata(Plugin $plugin, PluginOperation $operation): void
    {
        $snapshots = $operation->getSnapshotsJson() ?? [];
        $resolved = $snapshots['resolvedSource'] ?? null;
        if (!is_array($resolved)) {
            return;
        }
        $keyId = $resolved['keyId'] ?? null;
        $signature = $resolved['signature'] ?? null;
        $plugin->setSigningKeyId(is_string($keyId) && $keyId !== '' ? $keyId : null);
        $plugin->setSignatureEd25519(is_string($signature) && $signature !== '' ? $signature : null);
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
     * @return array{scanned:int,files:list<array{file:string,violations:list<string>}>}
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
}
