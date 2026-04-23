<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Frontend BFF / SSR refactor support:
 *   1. Adds the `acl_version` column to `users` so the frontend BFF can
 *      detect ACL changes and surgically invalidate its navigation cache
 *      without refetching on every navigation.
 *   2. Registers the new public `GET /pages/by-keyword/{keyword}` route in
 *      `api_routes`, which the frontend uses to resolve a slug directly to
 *      full page content (skipping the old nav → id → content waterfall).
 */
final class Version20260421000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add acl_version column to users and register /pages/by-keyword/{keyword} route';
    }

    public function up(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        if ($schemaManager->tablesExist(['users'])) {
            $usersTable = $schemaManager->introspectTable('users');

            if (!$usersTable->hasColumn('acl_version')) {
                $this->addSql('ALTER TABLE users ADD acl_version VARCHAR(36) DEFAULT NULL');
            }
        }

        if ($schemaManager->tablesExist(['api_routes'])) {
            $this->addSql(
                "INSERT IGNORE INTO `api_routes` (`route_name`, `version`, `path`, `controller`, `methods`, `requirements`, `params`) VALUES ("
                . "'pages_get_by_keyword', 'v1', '/pages/by-keyword/{keyword}', "
                . "'App\\\\Controller\\\\Api\\\\V1\\\\Frontend\\\\PageController::getPageByKeyword', 'GET', "
                . "JSON_OBJECT('keyword', '[a-zA-Z0-9_\\\\-]+'), "
                . "JSON_OBJECT("
                . "'keyword', JSON_OBJECT('in', 'path', 'required', true), "
                . "'language_id', JSON_OBJECT('in', 'query', 'required', false), "
                . "'preview', JSON_OBJECT('in', 'query', 'required', false)"
                . "))"
            );
        }
    }

    public function down(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        if ($schemaManager->tablesExist(['api_routes'])) {
            $this->addSql("DELETE FROM `api_routes` WHERE `route_name` = 'pages_get_by_keyword'");
        }

        if ($schemaManager->tablesExist(['users'])) {
            $usersTable = $schemaManager->introspectTable('users');

            if ($usersTable->hasColumn('acl_version')) {
                $this->addSql('ALTER TABLE users DROP COLUMN acl_version');
            }
        }
    }
}
