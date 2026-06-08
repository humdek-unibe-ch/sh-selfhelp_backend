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
 * Scheduled-jobs Docker runner + communication preferences (issue #29).
 *
 * Schema:
 *   - users.receives_notifications / users.receives_emails (default 1) for
 *     delivery preferences.
 *   - scheduled_jobs.date_started for runner stale-job detection.
 *   - scheduled_job_recipients: per-delivery recipient snapshots with policy.
 *   - scheduled_job_runner_settings / scheduled_job_runner_runs: runner ops.
 *
 * Seed data:
 *   - scheduledJobsStatus skipped statuses + scheduledJobDeliveryPolicies +
 *     skipped transaction types.
 *   - admin.scheduled_job.manage permission (granted to admin role).
 *   - auth communication-preferences route + admin runner routes/permissions.
 */
final class Version20260605081254 extends AbstractMigration
{
    private const VERSION = 'v1';

    /**
     * Admin runner + job-type routes: [route_name, path, controller_method, methods, permission].
     *
     * The job-type catalog route lives on AdminScheduledJobController; its static
     * `/types` path is matched before the dynamic `/{jobId}` detail route because
     * ApiRouteLoader orders static paths ahead of dynamic ones.
     *
     * @return list<array{0:string,1:string,2:string,3:string,4:string}>
     */
    private function runnerRoutes(): array
    {
        $ctrl = 'App\\Controller\\Api\\V1\\Admin\\AdminScheduledJobRunnerController::';
        $jobsCtrl = 'App\\Controller\\Api\\V1\\Admin\\AdminScheduledJobController::';

        return [
            ['admin_scheduled_jobs_runner_status_v1', '/admin/scheduled-jobs/runner/status', $ctrl . 'getStatus', 'GET', 'admin.scheduled_job.read'],
            ['admin_scheduled_jobs_runner_settings_v1', '/admin/scheduled-jobs/runner/settings', $ctrl . 'updateSettings', 'PUT', 'admin.scheduled_job.manage'],
            ['admin_scheduled_jobs_runner_enable_v1', '/admin/scheduled-jobs/runner/enable', $ctrl . 'enable', 'POST', 'admin.scheduled_job.manage'],
            ['admin_scheduled_jobs_runner_disable_v1', '/admin/scheduled-jobs/runner/disable', $ctrl . 'disable', 'POST', 'admin.scheduled_job.manage'],
            ['admin_scheduled_jobs_runner_run_now_v1', '/admin/scheduled-jobs/runner/run-now', $ctrl . 'runNow', 'POST', 'admin.scheduled_job.execute'],
            ['admin_scheduled_jobs_types_v1', '/admin/scheduled-jobs/types', $jobsCtrl . 'getJobTypes', 'GET', 'admin.scheduled_job.read'],
        ];
    }

    public function getDescription(): string
    {
        return 'Scheduled-jobs Docker runner + issue #29 communication preferences (schema, lookups, permission, routes).';
    }

    public function up(Schema $schema): void
    {
        // --- Schema (auto-generated) ---
        $this->addSql('CREATE TABLE scheduled_job_recipients (id INT AUTO_INCREMENT NOT NULL, channel VARCHAR(32) DEFAULT \'email\' NOT NULL, recipient_type VARCHAR(16) DEFAULT \'to\' NOT NULL, recipient_email VARCHAR(255) DEFAULT NULL, delivery_policy VARCHAR(64) DEFAULT \'respect_user_preferences\' NOT NULL, resolved_from VARCHAR(32) DEFAULT NULL, created_at DATETIME NOT NULL, id_scheduled_jobs INT NOT NULL, id_users INT DEFAULT NULL, INDEX idx_scheduled_job_recipients_id_scheduled_jobs (id_scheduled_jobs), INDEX idx_scheduled_job_recipients_id_users (id_users), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE=InnoDB');
        $this->addSql('CREATE TABLE scheduled_job_runner_runs (id INT AUTO_INCREMENT NOT NULL, trigger_type VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, duration_ms INT DEFAULT NULL, due_count INT DEFAULT 0 NOT NULL, attempted_count INT DEFAULT 0 NOT NULL, done_count INT DEFAULT 0 NOT NULL, failed_count INT DEFAULT 0 NOT NULL, skipped_count INT DEFAULT 0 NOT NULL, lock_acquired TINYINT DEFAULT 0 NOT NULL, error_message LONGTEXT DEFAULT NULL, settings_snapshot JSON DEFAULT NULL, INDEX idx_scheduled_job_runner_runs_started_at (started_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE=InnoDB');
        $this->addSql('CREATE TABLE scheduled_job_runner_settings (id INT AUTO_INCREMENT NOT NULL, enabled TINYINT DEFAULT 1 NOT NULL, interval_seconds INT DEFAULT 60 NOT NULL, max_jobs_per_run INT DEFAULT 100, lock_ttl_seconds INT DEFAULT 120 NOT NULL, stale_running_after_seconds INT DEFAULT 900 NOT NULL, updated_at DATETIME DEFAULT NULL, id_updated_by_users INT DEFAULT NULL, INDEX IDX_F1E53295D29D97A (id_updated_by_users), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE=InnoDB');
        $this->addSql('ALTER TABLE scheduled_job_recipients ADD CONSTRAINT FK_FBF34CC65A3497EA FOREIGN KEY (id_scheduled_jobs) REFERENCES scheduled_jobs (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE scheduled_job_recipients ADD CONSTRAINT FK_FBF34CC6FA06E4D9 FOREIGN KEY (id_users) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE scheduled_job_runner_settings ADD CONSTRAINT FK_F1E53295D29D97A FOREIGN KEY (id_updated_by_users) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE scheduled_jobs ADD date_started DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD receives_notifications TINYINT DEFAULT 1 NOT NULL, ADD receives_emails TINYINT DEFAULT 1 NOT NULL');

        // --- Lookups: skipped statuses, delivery policies, skipped tx types ---
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `lookups` (`type_code`, `lookup_code`, `lookup_value`, `lookup_description`) VALUES
            ('scheduledJobsStatus', 'skipped_user_disabled_notifications', 'Skipped: notifications disabled by user', 'The notification was intentionally not sent because the target user disabled notifications.'),
            ('scheduledJobsStatus', 'skipped_user_disabled_emails', 'Skipped: emails disabled by user', 'The email was intentionally not sent because the target user disabled emails.'),
            ('scheduledJobDeliveryPolicies', 'respect_user_preferences', 'Respect user preferences', 'Delivery is skipped when the target user disabled this communication channel.'),
            ('scheduledJobDeliveryPolicies', 'required_system', 'Required system mail', 'Account/security mail that must be delivered even when the user disabled emails.'),
            ('transactionTypes', 'send_mail_skipped', 'Send Mail Skipped', 'An email delivery was skipped because the recipient disabled emails.'),
            ('transactionTypes', 'send_notification_skipped', 'Send Notification Skipped', 'A notification delivery was skipped because the recipient disabled notifications.')
        SQL);

        // --- Permission: admin.scheduled_job.manage (runner settings) ---
        $this->addSql("INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES ('admin.scheduled_job.manage', 'Can manage the scheduled-job runner settings')");
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_permissions_roles` (`id_permissions`, `id_roles`)
            SELECT p.id, r.id FROM `permissions` p JOIN `roles` r ON r.name = 'admin'
            WHERE p.name = 'admin.scheduled_job.manage'
        SQL);

        // --- Self-service communication-preferences route (JWT auth, no perm) ---
        $this->addSql('DELETE FROM `api_routes` WHERE route_name = ? AND version = ?', ['auth_user_communication_preferences_update_v1', self::VERSION]);
        $this->addSql(
            'INSERT INTO `api_routes` (route_name, version, path, controller, methods, requirements, params, id_plugins) VALUES (?, ?, ?, ?, ?, NULL, NULL, NULL)',
            [
                'auth_user_communication_preferences_update_v1',
                self::VERSION,
                '/auth/user/communication-preferences',
                'App\\Controller\\Api\\V1\\Auth\\ProfileController::updateCommunicationPreferences',
                'PUT',
            ]
        );

        // --- Admin runner routes + permission links ---
        foreach ($this->runnerRoutes() as [$routeName, $path, $controller, $methods, $permission]) {
            $this->addSql('DELETE FROM `api_routes` WHERE route_name = ? AND version = ?', [$routeName, self::VERSION]);
            $this->addSql(
                'INSERT INTO `api_routes` (route_name, version, path, controller, methods, requirements, params, id_plugins) VALUES (?, ?, ?, ?, ?, NULL, NULL, NULL)',
                [$routeName, self::VERSION, $path, $controller, $methods]
            );
            $this->addSql(
                'INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`) '
                . "SELECT ar.id, p.id FROM `api_routes` ar JOIN `permissions` p ON p.name = ? WHERE ar.route_name = ? AND ar.version = ?",
                [$permission, $routeName, self::VERSION]
            );
        }
    }

    public function down(Schema $schema): void
    {
        // --- Routes ---
        $routeNames = array_map(static fn(array $r): string => $r[0], $this->runnerRoutes());
        $routeNames[] = 'auth_user_communication_preferences_update_v1';
        foreach ($routeNames as $routeName) {
            $this->addSql(
                'DELETE rarp FROM `rel_api_routes_permissions` rarp JOIN `api_routes` ar ON ar.id = rarp.id_api_routes WHERE ar.route_name = ? AND ar.version = ?',
                [$routeName, self::VERSION]
            );
            $this->addSql('DELETE FROM `api_routes` WHERE route_name = ? AND version = ?', [$routeName, self::VERSION]);
        }

        // --- Permission ---
        $this->addSql("DELETE rpr FROM `rel_permissions_roles` rpr JOIN `permissions` p ON p.id = rpr.id_permissions WHERE p.name = 'admin.scheduled_job.manage'");
        $this->addSql("DELETE FROM `permissions` WHERE name = 'admin.scheduled_job.manage'");

        // --- Lookups ---
        $this->addSql("DELETE FROM `lookups` WHERE type_code = 'scheduledJobDeliveryPolicies'");
        $this->addSql("DELETE FROM `lookups` WHERE type_code = 'scheduledJobsStatus' AND lookup_code IN ('skipped_user_disabled_notifications', 'skipped_user_disabled_emails')");
        $this->addSql("DELETE FROM `lookups` WHERE type_code = 'transactionTypes' AND lookup_code IN ('send_mail_skipped', 'send_notification_skipped')");

        // --- Schema ---
        $this->addSql('ALTER TABLE scheduled_job_recipients DROP FOREIGN KEY FK_FBF34CC65A3497EA');
        $this->addSql('ALTER TABLE scheduled_job_recipients DROP FOREIGN KEY FK_FBF34CC6FA06E4D9');
        $this->addSql('ALTER TABLE scheduled_job_runner_settings DROP FOREIGN KEY FK_F1E53295D29D97A');
        $this->addSql('DROP TABLE scheduled_job_recipients');
        $this->addSql('DROP TABLE scheduled_job_runner_runs');
        $this->addSql('DROP TABLE scheduled_job_runner_settings');
        $this->addSql('ALTER TABLE scheduled_jobs DROP date_started');
        $this->addSql('ALTER TABLE users DROP receives_notifications, DROP receives_emails');
    }
}
