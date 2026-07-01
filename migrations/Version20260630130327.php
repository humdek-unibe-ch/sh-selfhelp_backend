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
 * Navigation pages + page icons (navigation-rendering model).
 *
 * Seeds two page PROPERTY fields (`display = 0`, stored in
 * `pages_fields_translation`) and links them to the navigable content page types
 * (`core`, `experiment`) so they appear in the page inspector:
 *
 *   - `icon`              -> `select-icon`            (web menu icon, Tabler name)
 *   - `mobile_icon`       -> `select-icon-mobile`     (mobile menu icon, curated lucide name)
 *
 * The `select-icon-mobile` field type is a dedicated editor type so the admin UI
 * can render the curated mobile-icon picker (decision A1). The option list lives
 * in `@selfhelp/shared` (single source of truth), so no per-field DB option config
 * is needed.
 *
 * Reuse-first: `icon` reuses the existing `select-icon` field type. Follows the
 * FK-safe field-seed idiom of Version20260622161717 / Version20260501000900.
 */
final class Version20260630130327 extends AbstractMigration
{
    /** Navigable content page types the nav/icon fields attach to. */
    private const PAGE_TYPES = ['core', 'experiment'];

    /**
     * New editor field types (name => position).
     *
     * @var array<string, int>
     */
    private const NEW_FIELD_TYPES = [
        'select-icon-mobile' => 0,
    ];

    /**
     * New page property fields: name => editor field-type name. All are
     * `display = 0` (non-translatable page properties).
     *
     * @var array<string, string>
     */
    private const NEW_FIELDS = [
        'icon' => 'select-icon',
        'mobile_icon' => 'select-icon-mobile',
    ];

    /**
     * Inspector copy per field: name => [title, help].
     *
     * @var array<string, array{0:string,1:string}>
     */
    private const FIELD_META = [
        'icon' => [
            'Menu icon (web)',
            'Icon shown next to this page in the website menu. Pick a Tabler icon. Optional.',
        ],
        'mobile_icon' => [
            'Menu icon (mobile)',
            'Icon shown next to this page in the mobile app menu. Pick from the curated icon set. Optional.',
        ],
    ];

    /** Every table with an FK to fields(id), for FK-safe field removal. */
    private const FIELD_REF_TABLES = [
        'sections_fields_translation',
        'rel_fields_styles',
        'rel_fields_pages',
        'pages_fields_translation',
        'rel_fields_page_types',
    ];

    public function getDescription(): string
    {
        return 'Navigation page icons: seed icon/mobile_icon page property fields '
            . '(+ select-icon-mobile field type) linked to core+experiment page types.';
    }

    public function up(Schema $schema): void
    {
        // 1. New editor field types (idempotent).
        foreach (self::NEW_FIELD_TYPES as $name => $position) {
            $this->addSql(
                'INSERT IGNORE INTO `field_types` (`name`, `position`) VALUES (?, ?)',
                [$name, $position]
            );
        }

        // 2. New page property fields (display = 0), bound to their field type.
        foreach (self::NEW_FIELDS as $field => $type) {
            $this->addSql(
                'INSERT IGNORE INTO `fields` (`name`, id_field_types, `display`)
                 SELECT ?, ft.id, 0 FROM `field_types` ft WHERE ft.`name` = ?',
                [$field, $type]
            );
        }

        // 3. Link each field to the navigable content page types so it shows in
        //    the page inspector. default_value stays NULL (renderer defaults).
        foreach (self::PAGE_TYPES as $pageType) {
            foreach (self::FIELD_META as $field => [$title, $help]) {
                $this->addSql(
                    'INSERT IGNORE INTO `rel_fields_page_types` (id_page_types, id_fields, title, help)
                     SELECT pt.id, f.id, ?, ?
                     FROM `page_types` pt, `fields` f
                     WHERE pt.`name` = ? AND f.`name` = ?',
                    [$title, $help, $pageType, $field]
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        // 1'. Remove the fields FK-safely (drops their page-type links + any
        //     authored page values), then the fields themselves.
        foreach (array_keys(self::NEW_FIELDS) as $field) {
            foreach (self::FIELD_REF_TABLES as $table) {
                $this->addSql(
                    "DELETE FROM `$table` WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = ?)",
                    [$field]
                );
            }
            $this->addSql('DELETE FROM `fields` WHERE `name` = ?', [$field]);
        }

        // 2'. Remove the new editor field types (now unreferenced).
        foreach (array_keys(self::NEW_FIELD_TYPES) as $type) {
            $this->addSql('DELETE FROM `field_types` WHERE `name` = ?', [$type]);
        }
    }
}
