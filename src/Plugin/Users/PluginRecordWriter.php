<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Users;

use App\Exception\ServiceException;
use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Registry\PluginRegistryService;
use App\Service\Auth\UserContextService;
use App\Service\CMS\DataRevision\RecordWriteContext;
use App\Service\CMS\DataService;
use App\Service\Core\LookupService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes a record for another user through {@see DataService::saveData()}
 * (contract C20). The data store keeps doing what it always does: version,
 * history, change notices, domain events; this class only decides whether the
 * write is allowed and builds the context that names the door.
 *
 * Order, so that a refusal never leaves a half-done write behind and its audit
 * row is not rolled back with one (rule 4.6d): arguments, table prefix and the
 * identity of the actor (programming errors), then who may write (audited), then
 * the owner and the target record, then the write itself.
 *
 * The history names the user who is signed in, because the data store reads it
 * from the session and the write context carries no author. A non-zero actor
 * is therefore accepted only when it is that user, so the history and the actor
 * the permission was checked for can never differ. Decided 2026-11-26 (task
 * `2026-11-25-plugin-resource-hooks`, part 12).
 */
final class PluginRecordWriter implements PluginRecordWriterInterface
{
    public function __construct(
        private readonly PluginRegistryService $registry,
        private readonly PluginUserScopeInterface $scope,
        private readonly DataService $dataService,
        private readonly Connection $connection,
        private readonly UserContextService $userContext,
    ) {
    }

    public function saveFor(
        string $pluginId,
        int $ownerUserId,
        int $actorUserId,
        string $tableName,
        array $data,
        ?string $contentRef = null,
        ?int $recordId = null,
        ?int $expectedVersion = null,
    ): int {
        if ($ownerUserId < 1) {
            throw new \InvalidArgumentException('The owner of a record is a user (id 1 or more).');
        }
        if ($actorUserId < self::SYSTEM_ACTOR) {
            throw new \InvalidArgumentException('The actor is a user id, or SYSTEM_ACTOR (0) for the system.');
        }
        if ($recordId !== null && $recordId < 1) {
            throw new \InvalidArgumentException('A record id is 1 or more.');
        }
        if ($expectedVersion !== null && ($recordId === null || $expectedVersion < 1)) {
            throw new \InvalidArgumentException('An expected version is 1 or more and belongs to a change of a record that exists.');
        }
        if (array_key_exists('id_users', $data) && $data['id_users'] !== $ownerUserId) {
            throw new \InvalidArgumentException('The owner of the record is the ownerUserId argument; leave id_users out of the data.');
        }
        $this->assertTableOwnedBy($pluginId, $tableName);
        $this->assertActorIsSignedIn($actorUserId);

        // The door is the plugin's. A malformed content reference is a programming error: it is refused here, before the audited check below.
        $context = new RecordWriteContext(source: LookupService::DATA_REVISION_SOURCES_PLUGIN, expectedVersion: $expectedVersion);
        if ($contentRef !== null) {
            $context = $context->withContentRef($contentRef);
        }

        if ($actorUserId !== self::SYSTEM_ACTOR && $actorUserId !== $ownerUserId && !$this->scope->mayManage($actorUserId, $ownerUserId)) {
            throw new UserNotManagedException($actorUserId, $ownerUserId);
        }

        if ($this->connection->fetchOne('SELECT 1 FROM users WHERE id = :id', ['id' => $ownerUserId]) === false) {
            throw new ServiceException('user_not_found', Response::HTTP_NOT_FOUND);
        }

        if ($recordId !== null) {
            $table = $this->dataService->getDataTableByName($tableName);
            $row = $table !== null ? $this->dataService->findRecordInTable((int) $table->getId(), $recordId) : null;
            if ($row === null || $row->getIdUsers() !== $ownerUserId) {
                throw new ServiceException('record_not_found', Response::HTTP_NOT_FOUND);
            }
        }

        $data['id_users'] = $ownerUserId;
        $saved = $this->dataService->saveData(
            $tableName,
            $data,
            $actorUserId !== self::SYSTEM_ACTOR ? LookupService::TRANSACTION_BY_BY_USER : LookupService::TRANSACTION_BY_BY_SYSTEM,
            $recordId !== null ? ['record_id' => $recordId] : null,
            false,
            null,
            $context,
        );
        if ($saved === false) {
            throw new ServiceException('record_not_found', Response::HTTP_NOT_FOUND);
        }

        return $saved;
    }

    /**
     * A person named as actor must be the one who is signed in: the history shows that person (the effective user,
     * the impersonated one when an administrator impersonates), and this class does not let it differ from the actor
     * the permission was checked for. {@see self::SYSTEM_ACTOR} names nobody and is always accepted.
     */
    private function assertActorIsSignedIn(int $actorUserId): void
    {
        if ($actorUserId === self::SYSTEM_ACTOR) {
            return;
        }

        $signedIn = $this->userContext->getEffectiveUserId();
        if ($signedIn !== $actorUserId) {
            throw new \InvalidArgumentException(sprintf(
                'Actor %d is not the signed-in user (%s): the history of a record names the signed-in user, so a plugin names that user, or SYSTEM_ACTOR (0) when no person writes.',
                $actorUserId,
                $signedIn === null ? 'nobody' : (string) $signedIn,
            ));
        }
    }

    /**
     * The table must belong to the plugin: it starts with the prefix the enabled plugin's manifest owns, and continues after it.
     */
    private function assertTableOwnedBy(string $pluginId, string $tableName): void
    {
        $plugin = $this->registry->findByPluginId($pluginId);
        if ($plugin === null || !$plugin->isEnabled()) {
            throw new \InvalidArgumentException(sprintf('Plugin "%s" is not installed and enabled, so it cannot write records.', $pluginId));
        }

        $prefix = (new PluginManifest($plugin->getManifestJson()))->getOwnedDataTablePrefix();
        if ($prefix === null) {
            throw new \InvalidArgumentException(sprintf('Plugin "%s" owns no data table prefix (dataAccess.ownedDataTablePrefix), so it cannot write records.', $pluginId));
        }
        if (!str_starts_with($tableName, $prefix) || $tableName === $prefix) {
            throw new \InvalidArgumentException(sprintf('Table "%s" is not a table of plugin "%s": it must start with "%s" and continue after it.', $tableName, $pluginId, $prefix));
        }
    }
}
