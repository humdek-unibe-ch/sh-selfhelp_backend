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
 * Move the asset folder access level onto the central lookup registry.
 *
 * `assets_folders_groups.access_level` was a free-text `varchar(20)` holding
 * `read` / `manage`. That is an enum-like value surfaced in the admin UI and
 * the API schemas, so per the lookup policy it belongs in `lookups`. As a
 * varchar the database accepted anything, and because the enforcement layer
 * compares against the exact string `manage`, a stray `'Manage'` would silently
 * degrade a grant to read-only with no error anywhere.
 *
 * The level becomes the FK `id_access_level` into the new
 * `assetFolderAccessLevels` lookup type, so an unknown level cannot be stored.
 *
 * The group is `closed` (core-owned): a plugin inventing a third level would
 * slip past `AssetFolderAclService::assertCanManage()`, which tests for exactly
 * `manage`.
 *
 * Seeding and conversion happen in one migration so existing grants are never
 * left pointing at a level that does not exist yet.
 */
final class Version20260728133415 extends AbstractMigration
{
    private const TYPE_CODE = 'assetFolderAccessLevels';

    public function getDescription(): string
    {
        return 'Move assets_folders_groups.access_level onto the assetFolderAccessLevels lookup.';
    }

    public function up(Schema $schema): void
    {
        // 1. Seed the catalog (idempotent: skip codes already present).
        $this->addSql(
            'INSERT INTO lookups (type_code, lookup_code, lookup_value, lookup_description) '
            . 'SELECT ?, ?, ?, ? FROM DUAL '
            . 'WHERE NOT EXISTS (SELECT 1 FROM lookups WHERE type_code = ? AND lookup_code = ?)',
            [
                self::TYPE_CODE,
                'read',
                'Read',
                'View and download the assets in this folder.',
                self::TYPE_CODE,
                'read',
            ]
        );
        $this->addSql(
            'INSERT INTO lookups (type_code, lookup_code, lookup_value, lookup_description) '
            . 'SELECT ?, ?, ?, ? FROM DUAL '
            . 'WHERE NOT EXISTS (SELECT 1 FROM lookups WHERE type_code = ? AND lookup_code = ?)',
            [
                self::TYPE_CODE,
                'manage',
                'Manage',
                'View, download, and also create, import and delete in this folder. Includes read.',
                self::TYPE_CODE,
                'manage',
            ]
        );

        // 2. Add the FK column, backfill from the old string, then drop it.
        $this->addSql('ALTER TABLE assets_folders_groups ADD id_access_level INT NULL');
        $this->addSql(
            'UPDATE assets_folders_groups afg '
            . 'INNER JOIN lookups l ON l.type_code = ? AND l.lookup_code = afg.access_level '
            . 'SET afg.id_access_level = l.id',
            [self::TYPE_CODE]
        );

        // Any row whose level did not match the catalog (case drift, typo) is
        // defaulted to the least-privileged level rather than dropped, so a
        // malformed grant degrades safely instead of vanishing.
        $this->addSql(
            'UPDATE assets_folders_groups afg '
            . 'INNER JOIN lookups l ON l.type_code = ? AND l.lookup_code = ? '
            . 'SET afg.id_access_level = l.id '
            . 'WHERE afg.id_access_level IS NULL',
            [self::TYPE_CODE, 'read']
        );

        $this->addSql('ALTER TABLE assets_folders_groups DROP access_level');
        $this->addSql('ALTER TABLE assets_folders_groups MODIFY id_access_level INT NOT NULL');
        $this->addSql('ALTER TABLE assets_folders_groups ADD CONSTRAINT FK_F189C34050BEC532 FOREIGN KEY (id_access_level) REFERENCES lookups (id)');
        $this->addSql('CREATE INDEX IDX_F189C34050BEC532 ON assets_folders_groups (id_access_level)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assets_folders_groups ADD access_level VARCHAR(20) NULL');
        $this->addSql(
            'UPDATE assets_folders_groups afg '
            . 'INNER JOIN lookups l ON l.id = afg.id_access_level '
            . 'SET afg.access_level = l.lookup_code'
        );

        $this->addSql('ALTER TABLE assets_folders_groups DROP FOREIGN KEY FK_F189C34050BEC532');
        $this->addSql('DROP INDEX IDX_F189C34050BEC532 ON assets_folders_groups');
        $this->addSql('ALTER TABLE assets_folders_groups DROP id_access_level');
        $this->addSql('ALTER TABLE assets_folders_groups MODIFY access_level VARCHAR(20) NOT NULL');

        $this->addSql('DELETE FROM lookups WHERE type_code = ?', [self::TYPE_CODE]);
    }
}
