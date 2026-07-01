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
 * Modal size page properties (CMS-in-CMS modal UX, issue #30 follow-up).
 *
 * Seeds the `modal_width` + `modal_height` page PROPERTY fields (`display = 0`,
 * free-text) linked to the navigable content page types (`core`, `experiment`).
 * They only take effect together with `open_in_modal` (Version20260630151141):
 * when a page opens inside a modal on the web frontend, these control the modal
 * box size. Accepted values per field:
 *   - empty / unset  -> the platform default (80%); keeps every modal identical
 *     unless the author opts out, so existing modal pages need no data change.
 *   - a CSS length    -> `80%`, `640px`, `48rem`, `90vw`, ...
 *   - `auto`          -> the modal grows to fit its content, capped at 90% of the
 *     viewport so it never overflows the screen.
 *
 * The frontend (`DynamicPageClient`) owns the actual default + the `auto`/cap
 * behaviour, so `default_value` is intentionally left empty here (single source
 * of truth for the default). Reuse-first + FK-safe field-seed idiom of
 * Version20260630151141 / Version20260630130327.
 */
final class Version20260630172821 extends AbstractMigration
{
    /** Navigable content page types the modal-size properties attach to. */
    private const PAGE_TYPES = ['core', 'experiment'];

    /**
     * New catalog fields: name => [editor field type, display flag, inspector title, help].
     *
     * @var array<string, array{type: string, display: int, title: string, help: string}>
     */
    private const NEW_FIELDS = [
        'modal_width' => [
            'type' => 'text',
            'display' => 0,
            'title' => 'Modal width (web)',
            'help' => 'Width of the modal when this page opens in a modal (needs "Open in modal"). '
                . 'Use a CSS length (e.g. "80%", "640px", "48rem") or "auto" to fit the content (capped at 90% of the screen). '
                . 'Leave empty for the default (80%). Web only.',
        ],
        'modal_height' => [
            'type' => 'text',
            'display' => 0,
            'title' => 'Modal height (web)',
            'help' => 'Height of the modal when this page opens in a modal (needs "Open in modal"). '
                . 'Use a CSS length (e.g. "80%", "600px", "40rem") or "auto" to fit the content (capped at 90% of the screen). '
                . 'Leave empty for the default (80%). Web only.',
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
        return 'modal_width + modal_height page properties (core+experiment) for CMS-in-CMS open-in-modal sizing.';
    }

    public function up(Schema $schema): void
    {
        // Guard: refuse if any new field name already exists.
        foreach (array_keys(self::NEW_FIELDS) as $name) {
            $this->abortIf($this->fieldExists($name), sprintf("Refusing create: field '%s' already exists.", $name));
        }

        // 1. Create the new catalog fields (display = 0 behaviour/property fields).
        foreach (self::NEW_FIELDS as $name => $info) {
            $this->addSql(
                'INSERT INTO `fields` (`name`, id_field_types, `display`) SELECT ?, ft.id, ? FROM `field_types` ft WHERE ft.`name` = ?',
                [$name, $info['display'], $info['type']]
            );
        }

        // 2. Link the modal-size properties to the navigable content page types so
        //    they show in the page inspector. default_value stays NULL (the
        //    frontend owns the 80% default + the auto/cap behaviour).
        foreach (self::PAGE_TYPES as $pageType) {
            foreach (self::NEW_FIELDS as $name => $info) {
                $this->addSql(
                    'INSERT IGNORE INTO `rel_fields_page_types` (id_page_types, id_fields, title, help)
                     SELECT pt.id, f.id, ?, ?
                     FROM `page_types` pt, `fields` f
                     WHERE pt.`name` = ? AND f.`name` = ?',
                    [$info['title'], $info['help'], $pageType, $name]
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        // Drop the new fields FK-safely (removes their page-type links and any
        // authored page values), then the fields themselves.
        foreach (array_keys(self::NEW_FIELDS) as $name) {
            foreach (self::FIELD_REF_TABLES as $table) {
                $this->addSql(
                    "DELETE FROM `$table` WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = ?)",
                    [$name]
                );
            }
            $this->addSql('DELETE FROM `fields` WHERE `name` = ?', [$name]);
        }
    }

    private function fieldExists(string $name): bool
    {
        $value = $this->connection->fetchOne('SELECT COUNT(*) FROM `fields` WHERE `name` = ?', [$name]);

        return is_numeric($value) && (int) $value > 0;
    }
}
