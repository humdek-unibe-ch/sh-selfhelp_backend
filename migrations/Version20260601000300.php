<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

require_once __DIR__ . '/LegacySeedTrait.php';

/**
 * Seed migration: API routes + route-permission links.
 *
 * Materializes every row that `ApiRouteLoader` needs to register
 * the CMS API under `/cms-api/v1`. Without this seed the backend boots
 * with an empty route table and every API call returns 404.
 *
 * This migration folds in the three piecemeal route migrations from
 * the legacy history (`Version20260424120000`, `Version20260508140000`,
 * `Version20260508160000`) plus the contents of
 * `db/legacy/update_scripts/api_routes.sql`. Sourced from
 * `db/legacy/new_create_db.sql`.
 *
 * Depends on:
 *   - Version20260601000000_CanonicalBaseline (schema)
 *   - Version20260601000100_SeedReferenceData (permissions)
 */
final class Version20260601000300 extends AbstractMigration
{
    use LegacySeedTrait;

    public function getDescription(): string
    {
        return 'Seed api_routes and rel_api_routes_permissions.';
    }

    public function up(Schema $schema): void
    {
        $this->seedFromLegacy('api_routes');
        $this->seedFromLegacy('api_routes_permissions');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM `rel_api_routes_permissions`');
        $this->addSql('DELETE FROM `api_routes`');
    }
}
