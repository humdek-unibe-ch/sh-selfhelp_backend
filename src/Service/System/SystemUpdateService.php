<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Service\System;

use App\Entity\System\SystemUpdateOperation;
use App\Entity\User;
use App\Exception\ServiceException;
use App\Plugin\Registry\Unified\CompatibilityError;
use App\Plugin\Registry\Unified\CoreRelease;
use App\Plugin\Versioning\PluginCompatibility;
use App\Plugin\Versioning\SemverHelper;
use App\Repository\Plugin\PluginRepository;
use App\Repository\System\SystemUpdateOperationRepository;
use App\Service\Auth\UserContextService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Instance-scoped system update orchestration as seen from the CMS.
 *
 * The CMS NEVER controls Docker. Its role is to:
 *   1. compute a compatibility preflight (registry metadata + installed plugins
 *      + current version) — resource/Docker checks are explicitly delegated to
 *      the SelfHelp Manager;
 *   2. record an instance-scoped update REQUEST (the manager performs it);
 *   3. expose the status the manager writes back.
 *
 * Every method resolves the instance through {@see SystemInstanceService}; no
 * method accepts an instance id from the caller. {@see denyCrossInstance()}
 * turns any client-supplied instance id into a logged 403.
 */
class SystemUpdateService
{
    public const CHECK_RESOURCE = 'resource_checks';
    public const CHECK_REGISTRY_UNREACHABLE = 'registry_unreachable';
    public const CHECK_UPGRADE_PATH = 'upgrade_path';
    public const CHECK_DOWNGRADE = 'downgrade';
    public const CHECK_DESTRUCTIVE_MIGRATION = 'destructive_migration';
    public const CHECK_PLUGIN_COMPATIBILITY = 'plugin_compatibility';
    public const CHECK_VERSION_INVALID = 'version_invalid';

    public const STATUS_OK = 'ok';
    public const STATUS_WARNING = 'warning';
    public const STATUS_BLOCKED = 'blocked';

    /**
     * Synthetic status returned by {@see getStatus()} when this instance has
     * NEVER had an update operation. It is deliberately NOT a lifecycle status
     * the manager can write (see {@see SystemUpdateOperation::isManagerWritableStatus()}):
     * it means "no update has ever been requested — the instance is simply
     * running its installed version", which is honest where the old code faked a
     * "succeeded / 100%" terminal status for an update that never happened.
     */
    public const STATUS_IDLE = 'idle';

    /**
     * Cache key holding the ISO-8601 timestamp of the manager's last
     * authenticated call to the manager-loop endpoints (claim / status
     * write-back). Lets the health UI distinguish "manager polls this
     * instance" from "requests fall into a black hole".
     */
    private const MANAGER_LAST_SEEN_CACHE_KEY = 'selfhelp_manager_last_seen_at';

    /**
     * An operation still in `requested` after this many seconds means no
     * manager has picked it up (the manager poller claims within ~15s).
     */
    private const REQUESTED_STALE_AFTER_SECONDS = 120;

    public function __construct(
        private readonly SystemInstanceService $instance,
        private readonly SystemUpdateOperationRepository $operations,
        private readonly PluginRepository $pluginRepository,
        private readonly SystemRegistryReader $registry,
        private readonly UserContextService $userContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly CacheItemPoolInterface $cache,
        private readonly string $managerToken = '',
        private readonly ?\DateTimeImmutable $now = null,
    ) {
    }

    /**
     * Reject + log a cross-instance attempt. The backend must never trust a
     * client-supplied instance id; any non-null value is a violation. A null
     * value is in-scope (the server derives the instance), so this is safe to
     * call unconditionally.
     */
    public function denyCrossInstance(mixed $clientInstanceValue): void
    {
        if (!$this->instance->isUntrustedInstanceValue($clientInstanceValue)) {
            return;
        }

        $this->logger->warning('Denied cross-instance system update attempt.', [
            'trusted_instance_id' => $this->instance->getInstanceId(),
            'client_supplied_instance_id' => is_scalar($clientInstanceValue) ? (string) $clientInstanceValue : gettype($clientInstanceValue),
            'actual_user_id' => $this->userContext->getActualUserId(),
        ]);

        throw new ServiceException(
            'Cross-instance update requests are not permitted. Update management is scoped to the current instance.',
            Response::HTTP_FORBIDDEN
        );
    }

    /**
     * Core versions published in the official registry, for the admin "Request
     * an update" version picker. Fail-soft: when the registry is unreachable
     * the result reports `available: false` with an empty list (the UI falls
     * back to manual version entry) — the instance never blocks on the
     * registry.
     *
     * @return array{available: bool, current_version: string, releases: list<array{version: string, channel: string, blocked: bool}>}
     */
    public function getAvailableReleases(): array
    {
        $releases = $this->registry->listCoreReleases();

        return [
            'available' => $releases !== null,
            'current_version' => $this->instance->getCmsVersion(),
            'releases' => $releases ?? [],
        ];
    }

    /**
     * Compute the compatibility preflight for an update to $targetVersion.
     *
     * @return array<string,mixed>
     */
    public function getPreflight(string $targetVersion): array
    {
        $currentVersion = $this->instance->getCmsVersion();
        $instanceId = $this->instance->getInstanceId();

        /** @var list<array<string,scalar|null>> $checks */
        $checks = [];
        /** @var list<array{type:string,version?:string,label:string}> $options */
        $options = [];

        // CMS does not run resource/Docker checks — always flag that they are
        // the manager's responsibility so operators are not misled.
        $checks[] = [
            'code' => self::CHECK_RESOURCE,
            'severity' => 'info',
            'message' => 'Disk, memory, port and Docker checks are performed by the SelfHelp Manager at execution time.',
        ];

        if (SemverHelper::parse($targetVersion) === null) {
            $checks[] = [
                'code' => self::CHECK_VERSION_INVALID,
                'severity' => 'error',
                'message' => sprintf('Target version "%s" is not a valid version.', $targetVersion),
            ];
        }

        $diff = SemverHelper::diffKind($currentVersion, $targetVersion);
        if ($diff === 'downgrade') {
            $checks[] = [
                'code' => self::CHECK_DOWNGRADE,
                'severity' => 'error',
                'message' => sprintf('Downgrades are not supported (current %s, target %s).', $currentVersion, $targetVersion),
            ];
        }

        $database = [
            'destructive' => false,
            'requires_backup' => true,
            'manual_confirmation_required' => false,
        ];

        $release = $this->registry->getCoreRelease($targetVersion);
        if ($release === null) {
            $checks[] = [
                'code' => self::CHECK_REGISTRY_UNREACHABLE,
                'severity' => 'warning',
                'message' => 'Registry metadata for the target version is unavailable; the SelfHelp Manager will re-validate compatibility and migrations before applying.',
            ];
        } else {
            $database = $this->readDatabaseFacts($release);
            if ($database['destructive']) {
                $checks[] = [
                    'code' => self::CHECK_DESTRUCTIVE_MIGRATION,
                    'severity' => 'warning',
                    'message' => 'This update includes destructive database migrations. A backup is required and the migration risk must be accepted before requesting it.',
                ];
            }

            $minDirect = $release->minimumDirectUpgradeFrom;
            if ($minDirect !== '' && SemverHelper::compare($currentVersion, $minDirect) < 0) {
                $checks[] = [
                    'code' => self::CHECK_UPGRADE_PATH,
                    'severity' => 'error',
                    'message' => sprintf('Direct upgrade to %s is not supported from %s; upgrade to at least %s first.', $targetVersion, $currentVersion, $minDirect),
                ];
            }

            $options[] = [
                'type' => 'core',
                'version' => $targetVersion,
                'label' => sprintf('SelfHelp core %s', $targetVersion),
            ];
        }

        // Per the distribution plan ("Core update preflight must check installed
        // plugins"), an installed plugin that does not declare compatibility with
        // the target core version BLOCKS the update. The reason is the SAME
        // standardized compatibility-error object the plugin install/update flow
        // emits ({@see CompatibilityError}), so the admin/operator sees one shape
        // regardless of which installer raised it. Pinned plugins are respected
        // (audit #52): they are never auto-updated, so a pinned incompatible
        // plugin is a hard block whose reason tells the operator to unpin first.
        foreach ($this->incompatiblePlugins($targetVersion) as $incompatible) {
            $error = CompatibilityError::coreUpdateBlockedByPlugin(
                pluginId: $incompatible['id'],
                currentCoreVersion: $currentVersion,
                coreTargetVersion: $targetVersion,
                requiredCoreRange: $incompatible['range'],
                pinned: $incompatible['pinned'],
            );
            $checks[] = array_merge(
                [
                    'code' => self::CHECK_PLUGIN_COMPATIBILITY,
                    'severity' => 'error',
                    'pinned' => $incompatible['pinned'],
                ],
                $error->toArray(),
            );
        }

        $status = $this->deriveStatus($checks);

        return [
            'preflight_id' => $this->makePreflightId($instanceId, $currentVersion, $targetVersion),
            'status' => $status,
            'instance_id' => $instanceId,
            'current_version' => $currentVersion,
            'target_version' => $targetVersion,
            'checks' => $checks,
            'options' => $options,
            'database' => $database,
            'rollback' => [
                // MVP policy (see distribution plan "Backup And Rollback"):
                // automatic rollback is ONLY safe before migrations. Once a
                // destructive migration has run, recovery requires restoring the
                // verified backup, so this must never claim automatic rollback.
                'automatic_before_migrations' => true,
                'automatic_after_destructive_migrations' => false,
            ],
        ];
    }

    /**
     * Persist an instance-scoped update request. The instance is server-derived;
     * the preflight is recomputed here so a blocked update cannot be requested by
     * crafting a stale/forged preflight client-side.
     *
     * @param array<array-key,mixed> $data validated request body
     * @return array{operation_id:string, instance_id:string, status:string}
     */
    public function requestUpdate(array $data): array
    {
        $targetVersion = is_scalar($data['target_version'] ?? null) ? (string) $data['target_version'] : '';
        $preflightId = is_scalar($data['preflight_id'] ?? null) ? (string) $data['preflight_id'] : null;
        $acceptedRisk = (bool) ($data['accepted_migration_risk'] ?? false);

        $preflight = $this->getPreflight($targetVersion);
        if ($preflight['status'] === self::STATUS_BLOCKED) {
            throw new ServiceException(
                'Update is blocked by preflight checks: ' . $this->firstErrorMessage($preflight),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $database = $preflight['database'];
        $destructive = is_array($database) && ($database['destructive'] ?? false) === true;
        if ($destructive && !$acceptedRisk) {
            throw new ServiceException(
                'This update includes destructive database migrations; you must accept the migration risk to proceed.',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $operation = new SystemUpdateOperation(
            $this->instance->getInstanceId(),
            $this->makeOperationId(),
            $targetVersion
        );
        // Attach a managed reference (the security-context User may belong to a
        // different unit of work, which would make flush() treat it as new).
        $requesterId = $this->userContext->getCurrentUser()?->getId();
        $requestedBy = $requesterId !== null
            ? $this->entityManager->getReference(User::class, $requesterId)
            : null;
        $operation
            ->setPreflightId($preflightId)
            ->setAcceptedMigrationRisk($acceptedRisk)
            ->setRequestedBy($requestedBy)
            ->setStatus(SystemUpdateOperation::STATUS_REQUESTED)
            ->setProgressPercent(0)
            ->setStepsJson([
                ['name' => 'requested', 'status' => 'succeeded', 'detail' => 'Update requested from the CMS; awaiting the SelfHelp Manager.'],
            ]);

        $this->entityManager->persist($operation);
        $this->entityManager->flush();

        $this->logger->info('System update requested (instance-scoped).', [
            'instance_id' => $operation->getInstanceId(),
            'operation_id' => $operation->getOperationId(),
            'target_version' => $targetVersion,
            'accepted_migration_risk' => $acceptedRisk,
            'actual_user_id' => $this->userContext->getActualUserId(),
        ]);

        return [
            'operation_id' => $operation->getOperationId(),
            'instance_id' => $operation->getInstanceId(),
            'status' => $operation->getStatus(),
        ];
    }

    /**
     * Status of the latest update operation for THIS instance. When no operation
     * has ever existed, returns the synthetic {@see STATUS_IDLE} state (progress
     * 0, current version) so the polling UI has a stable shape WITHOUT pretending
     * a phantom update "succeeded".
     *
     * @return array<string,mixed>
     */
    public function getStatus(): array
    {
        $instanceId = $this->instance->getInstanceId();
        $operation = $this->operations->findLatestForInstance($instanceId);

        if ($operation === null) {
            $now = $this->utcNow()->format(\DateTimeInterface::ATOM);

            return [
                'instance_id' => $instanceId,
                'operation_id' => '',
                'status' => self::STATUS_IDLE,
                'target_version' => $this->instance->getCmsVersion(),
                'progress_percent' => 0,
                'steps' => [],
                'requested_at' => $now,
                'updated_at' => $now,
                'message' => sprintf('No update has been requested for this instance; it is running version %s.', $this->instance->getCmsVersion()),
                'manager' => $this->managerStatus(null),
            ];
        }

        return [
            'instance_id' => $operation->getInstanceId(),
            'operation_id' => $operation->getOperationId(),
            'status' => $operation->getStatus(),
            'target_version' => $operation->getTargetVersion(),
            'progress_percent' => $operation->getProgressPercent(),
            'steps' => $operation->getStepsJson() ?? [],
            'requested_at' => $operation->getRequestedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $operation->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'message' => $operation->getMessage(),
            'manager' => $this->managerStatus($operation),
        ];
    }

    /**
     * Record that an authenticated manager touched the manager-loop endpoints
     * just now. Called from the manager-facing service methods only (the
     * controller verifies the bearer token before they run).
     */
    private function recordManagerSeen(): void
    {
        try {
            $item = $this->cache->getItem(self::MANAGER_LAST_SEEN_CACHE_KEY);
            $item->set($this->utcNow()->format(\DateTimeInterface::ATOM));
            $this->cache->save($item);
        } catch (\Throwable) {
            // A cache hiccup must never fail the manager loop itself.
        }
    }

    /**
     * Manager-loop visibility facts for the health/status surfaces:
     * whether a manager token is configured at all, and when an authenticated
     * manager last polled this instance.
     *
     * @return array{configured: bool, last_seen_at: string|null}
     */
    public function getManagerLoopInfo(): array
    {
        $lastSeen = null;
        try {
            $stored = $this->cache->getItem(self::MANAGER_LAST_SEEN_CACHE_KEY)->get();
            if (is_string($stored) && $stored !== '') {
                $lastSeen = $stored;
            }
        } catch (\Throwable) {
            // Report "never seen" on cache failure rather than erroring.
        }

        return [
            'configured' => $this->managerToken !== '',
            'last_seen_at' => $lastSeen,
        ];
    }

    /**
     * Manager block for {@see getStatus()}: loop facts plus whether the
     * current operation sits unclaimed in `requested` for too long — the
     * "I requested an update and nothing happens" signal.
     *
     * @return array{configured: bool, last_seen_at: string|null, requested_stale: bool}
     */
    private function managerStatus(?SystemUpdateOperation $operation): array
    {
        $info = $this->getManagerLoopInfo();

        $stale = false;
        if ($operation !== null && $operation->getStatus() === SystemUpdateOperation::STATUS_REQUESTED) {
            $age = $this->utcNow()->getTimestamp() - $operation->getRequestedAt()->getTimestamp();
            $stale = $age > self::REQUESTED_STALE_AFTER_SECONDS;
        }

        return [
            'configured' => $info['configured'],
            'last_seen_at' => $info['last_seen_at'],
            'requested_stale' => $stale,
        ];
    }

    private function utcNow(): \DateTimeImmutable
    {
        return $this->now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Claim the next pending operation for THIS instance on behalf of the
     * SelfHelp Manager. Returns null when nothing is claimable. Instance-scoped:
     * the manager can only ever see this instance's operations.
     *
     * @return array<string,mixed>|null
     */
    public function claimPendingOperation(): ?array
    {
        $this->recordManagerSeen();

        $instanceId = $this->instance->getInstanceId();
        $operation = $this->operations->findLatestClaimableForInstance($instanceId);
        if ($operation === null) {
            return null;
        }

        // Recompute whether the target carries destructive migrations so the
        // manager-side approval guard has accurate input (defense-in-depth on
        // top of the check already enforced at request time).
        $database = $this->getPreflight($operation->getTargetVersion())['database'] ?? null;
        $destructive = is_array($database) && ($database['destructive'] ?? false) === true;

        return [
            'operation_id' => $operation->getOperationId(),
            'instance_id' => $operation->getInstanceId(),
            'target_version' => $operation->getTargetVersion(),
            'preflight_id' => $operation->getPreflightId() ?? '',
            // The operation id doubles as the approval token: only the trusted
            // manager (authenticated by the per-instance manager token) can
            // claim it, and the backend only emits operations it already
            // approved at request time.
            'approval_token' => $operation->getOperationId(),
            'approved_by_user_id' => $operation->getRequestedBy()?->getId() ?? 0,
            'accepted_migration_risk' => $operation->isAcceptedMigrationRisk(),
            'destructive_migration' => $destructive,
        ];
    }

    /**
     * Persist a manager-written status update for an operation owned by THIS
     * instance. Cross-instance and unknown operations are rejected with 404 so
     * one instance can never read or affect another's operations. Only
     * manager-writable lifecycle statuses are accepted.
     *
     * @param array<int,array<string,mixed>>|null $steps
     * @return array<string,mixed>
     */
    public function recordManagerStatus(
        string $operationId,
        string $status,
        int $progressPercent,
        ?array $steps = null,
        ?string $message = null,
    ): array {
        $this->recordManagerSeen();

        if (!SystemUpdateOperation::isManagerWritableStatus($status)) {
            throw new ServiceException(
                sprintf('Status "%s" is not a valid manager-writable operation status.', $status),
                Response::HTTP_BAD_REQUEST
            );
        }

        $instanceId = $this->instance->getInstanceId();
        $operation = $this->operations->findByOperationId($operationId);
        if ($operation === null || $operation->getInstanceId() !== $instanceId) {
            $this->logger->warning('Rejected manager status write-back for unknown/cross-instance operation.', [
                'operation_id' => $operationId,
                'trusted_instance_id' => $instanceId,
            ]);
            throw new ServiceException('Update operation not found for this instance.', Response::HTTP_NOT_FOUND);
        }

        if (SystemUpdateOperation::isTerminalStatus($operation->getStatus())) {
            throw new ServiceException(
                'Update operation is already in a terminal state and cannot be updated further.',
                Response::HTTP_CONFLICT
            );
        }

        $operation->setStatus($status)->setProgressPercent($progressPercent);
        if ($steps !== null) {
            $operation->setStepsJson($steps);
        }
        if ($message !== null) {
            $operation->setMessage($message);
        }
        $this->entityManager->flush();

        $this->logger->info('Manager wrote update operation status.', [
            'instance_id' => $operation->getInstanceId(),
            'operation_id' => $operation->getOperationId(),
            'status' => $operation->getStatus(),
            'progress_percent' => $operation->getProgressPercent(),
        ]);

        return [
            'operation_id' => $operation->getOperationId(),
            'instance_id' => $operation->getInstanceId(),
            'status' => $operation->getStatus(),
            'progress_percent' => $operation->getProgressPercent(),
            'updated_at' => $operation->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Read the database-migration facts from the SIGNED, signature-verified core
     * release (see {@see SystemRegistryReader::getCoreRelease()}). The release is
     * a typed {@see CoreRelease}, so these facts are taken from the verified
     * document rather than re-parsed from a raw array.
     *
     * @return array{destructive:bool,requires_backup:bool,manual_confirmation_required:bool}
     */
    private function readDatabaseFacts(CoreRelease $release): array
    {
        return [
            'destructive' => $release->destructive,
            'requires_backup' => $release->requiresBackup,
            'manual_confirmation_required' => $release->manualConfirmationRequired,
        ];
    }

    /**
     * @return list<array{id:string,range:string,pinned:bool}> installed plugins incompatible with $targetVersion
     */
    private function incompatiblePlugins(string $targetVersion): array
    {
        $incompatible = [];
        foreach ($this->pluginRepository->findAllOrderedByName() as $plugin) {
            // The CORE-axis compatibility range an installed plugin declares
            // (manifest `compatibility.selfhelp`, registry `compatibility.core`),
            // resolved through the single backend compatibility helper so the
            // rule matches the version summary, resolver, and validator exactly.
            $range = PluginCompatibility::manifestCoreRange($plugin->getManifestJson());
            if ($range !== null && !PluginCompatibility::coreSatisfied($targetVersion, $range)) {
                $incompatible[] = [
                    'id' => $plugin->getPluginId(),
                    'range' => $range,
                    'pinned' => $plugin->isPinned(),
                ];
            }
        }

        return $incompatible;
    }

    /**
     * @param list<array<string,scalar|null>> $checks
     */
    private function deriveStatus(array $checks): string
    {
        $status = self::STATUS_OK;
        foreach ($checks as $check) {
            if ($check['severity'] === 'error') {
                return self::STATUS_BLOCKED;
            }
            if ($check['severity'] === 'warning') {
                $status = self::STATUS_WARNING;
            }
        }

        return $status;
    }

    /**
     * @param array<string,mixed> $preflight
     */
    private function firstErrorMessage(array $preflight): string
    {
        $checks = $preflight['checks'] ?? [];
        if (is_array($checks)) {
            foreach ($checks as $check) {
                if (is_array($check) && ($check['severity'] ?? null) === 'error' && is_string($check['message'] ?? null)) {
                    return $check['message'];
                }
            }
        }

        return 'see preflight checks.';
    }

    private function makePreflightId(string $instanceId, string $current, string $target): string
    {
        return 'pf_' . substr(hash('sha256', $instanceId . '|' . $current . '->' . $target), 0, 16);
    }

    private function makeOperationId(): string
    {
        return 'op_' . bin2hex(random_bytes(12));
    }
}
