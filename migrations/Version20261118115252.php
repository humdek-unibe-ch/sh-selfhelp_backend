<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F09 / F10: declare public API routes (`is_public`), superuser roles
 * (`is_superuser`), and system users (`is_system`). Seeds the former allowlist
 * from ApiRouteInventoryTest::INTENTIONALLY_PUBLIC and the admin / tpf names.
 */
final class Version20261118115252 extends AbstractMigration
{
    /**
     * Router-collection names (`<route_name>_<version>`) that were the reviewed
     * public allowlist before `is_public` existed. Kept here so the seed is
     * reviewable next to the schema change; the inventory test no longer owns
     * this list.
     *
     * @var list<string>
     */
    private const INTENTIONALLY_PUBLIC = [
        'auth_login_v1',
        'auth_logout_v1',
        'auth_refresh_token_v1',
        'auth_set_language_v1',
        'auth_2fa_verify_v1',
        'auth_events_stream_v1',
        'auth_register_v1',
        'auth_forgot_password_v1',
        'auth_reset_password_v1',
        'auth_user_data_get_v1',
        'auth_user_account_delete_v1',
        'auth_user_name_update_v1',
        'auth_user_password_update_v1',
        'auth_user_timezone_update_v1',
        'auth_user_communication_preferences_update_v1',
        'admin_users_stop_impersonate_v1',
        'form_submit_v1',
        'form_update_v1',
        'form_delete_v1',
        'form_files_serve_v1',
        'form_files_zip_v1',
        'form_list_state_v1',
        'form_list_quick_action_v1',
        'form_list_history_v1',
        'form_list_version_v1',
        'form_list_compare_v1',
        'form_list_restore_v1',
        'form_list_version_file_v1',
        'form_list_current_file_v1',
        'frontend_css_classes_get_all_v1',
        'languages_get_all_v1',
        'system_lookups_v1',
        'pages_get_all_v1',
        'pages_get_by_keyword_v1',
        'pages_get_all_with_language_v1',
        'pages_resolve_path_v1',
        'navigation_get_v1',
        'navigation_last_visited_put_v1',
        'search_get_v1',
        'search_pages_get_v1',
        'plugins_manifest_v1',
        'health_v1',
        'mobile_preview_session_exchange_v1',
        'user_validate_token_v1',
        'user_complete_validation_v1',
        'manager_system_update_pending_v1',
        'manager_system_update_status_v1',
        'assets_serve_by_uuid_v1',
    ];

    public function getDescription(): string
    {
        return 'Add api_routes.is_public, roles.is_superuser, users.is_system; seed flags (F09, F10)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_routes ADD is_public TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE roles ADD is_superuser TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE users ADD is_system TINYINT(1) DEFAULT 0 NOT NULL');

        $quoted = implode(', ', array_map(
            static fn (string $name): string => "'" . str_replace("'", "''", $name) . "'",
            self::INTENTIONALLY_PUBLIC
        ));

        // Only core routes that still have no permission rows become public.
        $this->addSql(<<<SQL
            UPDATE api_routes r
            SET r.is_public = 1
            WHERE r.id_plugins IS NULL
              AND CONCAT(r.route_name, '_', r.version) IN ({$quoted})
              AND NOT EXISTS (
                  SELECT 1 FROM rel_api_routes_permissions arp
                  WHERE arp.id_api_routes = r.id
              )
            SQL);

        $this->addSql("UPDATE roles SET is_superuser = 1 WHERE name = 'admin'");
        // Match AdminUserService::SYSTEM_USERS: display name only.
        $this->addSql("UPDATE users SET is_system = 1 WHERE name IN ('admin', 'tpf')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_routes DROP is_public');
        $this->addSql('ALTER TABLE roles DROP is_superuser');
        $this->addSql('ALTER TABLE users DROP is_system');
    }
}
