<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Security;

use App\Entity\Plugin\Plugin;

/**
 * Canonical list of capability keys plus the trust-level matrix.
 *
 * The catalog is the single source of truth for:
 *
 *   - which capability strings a plugin manifest may declare,
 *   - which capabilities each trust level is allowed to grant.
 *
 * `PluginCapabilityValidator` consults this catalog at install time
 * and on every state transition. Runtime guards (e.g. the realtime
 * publisher's "realtimePublish" check) read from the cached
 * `Plugin::getCapabilitiesJson()` granted at install time so the
 * matrix is enforced even if the manifest changes after install.
 *
 * Capability keys mirror the manifest contract documented in
 * `docs/plugins/architecture.md` (deny-by-default capability matrix).
 */
final class CapabilityCatalog
{
    public const CAP_BACKEND_BUNDLE = 'backendBundle';
    public const CAP_DATABASE_MIGRATIONS = 'databaseMigrations';
    public const CAP_READ_USERS = 'readUsers';
    public const CAP_WRITE_USERS = 'writeUsers';
    public const CAP_DELETE_USERS = 'deleteUsers';
    public const CAP_READ_DATA_TABLES = 'readDataTables';
    public const CAP_WRITE_DATA_TABLES = 'writeDataTables';
    public const CAP_DELETE_DATA_TABLES = 'deleteDataTables';
    public const CAP_EXTERNAL_NETWORK = 'externalNetworkAccess';
    public const CAP_SCHEDULED_JOBS = 'scheduledJobs';
    public const CAP_PUBLIC_CALLBACKS = 'publicCallbacks';
    public const CAP_ADMIN_PAGES = 'adminPages';
    public const CAP_FRONTEND_STYLES = 'frontendStyles';
    public const CAP_MOBILE_STYLES = 'mobileStyles';
    public const CAP_REALTIME_PUBLISH = 'realtimePublish';
    public const CAP_FILE_UPLOADS = 'fileUploads';
    public const CAP_SECRET_ACCESS = 'secretAccess';
    public const CAP_LOOKUP_EXTEND = 'lookupExtend';
    public const CAP_LOOKUP_OWN_GROUP = 'lookupOwnGroup';

    /**
     * All capability keys plugins may declare. Anything outside this
     * list is refused by `PluginCapabilityValidator`.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::CAP_BACKEND_BUNDLE,
            self::CAP_DATABASE_MIGRATIONS,
            self::CAP_READ_USERS,
            self::CAP_WRITE_USERS,
            self::CAP_DELETE_USERS,
            self::CAP_READ_DATA_TABLES,
            self::CAP_WRITE_DATA_TABLES,
            self::CAP_DELETE_DATA_TABLES,
            self::CAP_EXTERNAL_NETWORK,
            self::CAP_SCHEDULED_JOBS,
            self::CAP_PUBLIC_CALLBACKS,
            self::CAP_ADMIN_PAGES,
            self::CAP_FRONTEND_STYLES,
            self::CAP_MOBILE_STYLES,
            self::CAP_REALTIME_PUBLISH,
            self::CAP_FILE_UPLOADS,
            self::CAP_SECRET_ACCESS,
            self::CAP_LOOKUP_EXTEND,
            self::CAP_LOOKUP_OWN_GROUP,
        ];
    }

    /**
     * Capabilities that each trust level may grant. Anything outside
     * the trust level's set is denied even when the manifest declares
     * it. `untrusted` plugins are confined to frontend/mobile UI plus
     * realtime publish.
     *
     * @return array<string, list<string>>
     */
    public static function matrix(): array
    {
        return [
            Plugin::TRUST_OFFICIAL => self::all(),
            Plugin::TRUST_REVIEWED => [
                self::CAP_BACKEND_BUNDLE,
                self::CAP_DATABASE_MIGRATIONS,
                self::CAP_READ_DATA_TABLES,
                self::CAP_WRITE_DATA_TABLES,
                self::CAP_DELETE_DATA_TABLES,
                self::CAP_EXTERNAL_NETWORK,
                self::CAP_SCHEDULED_JOBS,
                self::CAP_PUBLIC_CALLBACKS,
                self::CAP_ADMIN_PAGES,
                self::CAP_FRONTEND_STYLES,
                self::CAP_MOBILE_STYLES,
                self::CAP_REALTIME_PUBLISH,
                self::CAP_FILE_UPLOADS,
                self::CAP_SECRET_ACCESS,
                self::CAP_LOOKUP_EXTEND,
                self::CAP_LOOKUP_OWN_GROUP,
            ],
            Plugin::TRUST_UNTRUSTED => [
                self::CAP_FRONTEND_STYLES,
                self::CAP_MOBILE_STYLES,
                self::CAP_REALTIME_PUBLISH,
                self::CAP_LOOKUP_EXTEND,
            ],
        ];
    }

    /**
     * Returns true if `$trustLevel` is allowed to grant `$capability`.
     */
    public static function allows(string $trustLevel, string $capability): bool
    {
        $matrix = self::matrix();
        $allowed = $matrix[$trustLevel] ?? null;
        return $allowed !== null && in_array($capability, $allowed, true);
    }

    /**
     * @return list<string>
     */
    public static function allowedFor(string $trustLevel): array
    {
        return self::matrix()[$trustLevel] ?? [];
    }
}
