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
 * Seed migration: fields + styles catalog.
 *
 * Loads the ~600 fields, ~200 styles and their join-table links
 * required to render every component the frontend may produce.
 *
 * This is the largest seed in terms of row count. It is sourced from
 * `db/legacy/new_create_db.sql` and rewritten by the trait against
 * the canonical `fields`, `styles`, `rel_fields_styles`,
 * `rel_styles_allowed_relationships`, `rel_fields_page_types` tables.
 *
 * Depends on:
 *   - Version20260601000000_CanonicalBaseline (schema)
 *   - Version20260601000100_SeedReferenceData (field_types, style_groups, page_types)
 */
final class Version20260601000200 extends AbstractMigration
{
    use LegacySeedTrait;

    public function getDescription(): string
    {
        return 'Seed fields and styles catalog: fields, styles, rel_fields_styles, rel_styles_allowed_relationships, rel_fields_page_types.';
    }

    public function up(Schema $schema): void
    {
        // fields first (rel_fields_styles references it)
        $this->seedFromLegacy('fields');
        $this->seedFromLegacy('styles');
        $this->seedFromLegacy('styles_fields');
        $this->seedFromLegacy('styles_allowed_relationships');
        $this->seedFromLegacy('pageType_fields');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM `rel_fields_page_types`');
        $this->addSql('DELETE FROM `rel_styles_allowed_relationships`');
        $this->addSql('DELETE FROM `rel_fields_styles`');
        $this->addSql('DELETE FROM `styles`');
        $this->addSql('DELETE FROM `fields`');
    }
}
