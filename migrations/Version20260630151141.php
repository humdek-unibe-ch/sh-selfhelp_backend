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
 * "Open in modal" page behaviour + form save behaviours + list action links
 * (CMS-in-CMS UX, issue #30 Phase 6 follow-up).
 *
 * Seeds:
 *   - `open_in_modal` page PROPERTY field (`display = 0`, checkbox) linked to the
 *     navigable content page types (`core`, `experiment`). When enabled the web
 *     frontend renders the page's content inside a modal (page title as header,
 *     a close button) instead of inline — used to open CMS-in-CMS create/edit/
 *     detail pages as overlays. Web-only behaviour; mobile ignores it.
 *   - `close_modal_on_save` (checkbox) + `redirect_on_save` (text) SECTION fields
 *     linked to the `form-log` + `form-record` styles. After a successful submit
 *     the web form closes the surrounding modal (`close_modal_on_save`) and/or
 *     navigates to `redirect_on_save`, refreshing the parent list.
 *   - `add_url` (text) + `edit_url` (text) SECTION fields linked to the
 *     `show-user-input` data-table style. `add_url` renders an "Add new" button
 *     above the table; `edit_url` adds a per-row open/edit action with the row's
 *     `{record_id}` substituted into the URL template.
 *
 * Reuse-first + FK-safe field-seed idiom of Version20260630130327 /
 * Version20260622161717. All new fields are `display = 0` (non-translatable
 * behaviour/property fields). Page-property and style links default empty, so
 * existing pages/sections keep their current behaviour.
 */
final class Version20260630151141 extends AbstractMigration
{
    /** Navigable content page types the open_in_modal property attaches to. */
    private const PAGE_TYPES = ['core', 'experiment'];

    /**
     * New catalog fields: name => [editor field type, display flag].
     *
     * @var array<string, array{type: string, display: int}>
     */
    private const NEW_FIELDS = [
        'open_in_modal' => ['type' => 'checkbox', 'display' => 0],
        'close_modal_on_save' => ['type' => 'checkbox', 'display' => 0],
        'redirect_on_save' => ['type' => 'text', 'display' => 0],
        'add_url' => ['type' => 'text', 'display' => 0],
        'edit_url' => ['type' => 'text', 'display' => 0],
    ];

    /**
     * Page-type link for the open_in_modal property: [title, help].
     *
     * @var array{0:string,1:string}
     */
    private const OPEN_IN_MODAL_META = [
        'Open in modal (web)',
        'When enabled, the website opens this page inside a modal overlay (the page title becomes the modal header, with a close button) instead of a full page. '
            . 'Ideal for CMS-in-CMS create/edit/detail pages opened from a list. Web only — the mobile app opens the page as a normal screen.',
    ];

    /**
     * Style field links: [style, field, default_value, help, inspector title].
     *
     * @var list<array{0:string,1:string,2:?string,3:string,4:string}>
     */
    private const LINKS = [
        ['form-log', 'close_modal_on_save', '0', 'When enabled, a successful submit closes the surrounding modal (if this form is shown inside one).', 'Close modal on save'],
        ['form-log', 'redirect_on_save', '', 'Optional URL to navigate to after a successful submit (the parent list is refreshed). Leave empty to stay/close.', 'Redirect on save'],
        ['form-record', 'close_modal_on_save', '0', 'When enabled, a successful submit closes the surrounding modal (if this form is shown inside one).', 'Close modal on save'],
        ['form-record', 'redirect_on_save', '', 'Optional URL to navigate to after a successful submit (the parent list is refreshed). Leave empty to stay/close.', 'Redirect on save'],
        ['show-user-input', 'add_url', '', 'Optional URL of a create form. When set, an "Add new" button is shown above the table.', 'Add new URL'],
        ['show-user-input', 'edit_url', '', 'Optional URL template for opening a row (e.g. "/cms/team/{record_id}"). When set, each row gets an open/edit action with {record_id} substituted.', 'Open/edit URL'],
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
        return 'Open-in-modal page property (core+experiment) + close_modal_on_save/redirect_on_save (form-log/form-record) '
            . '+ add_url/edit_url (show-user-input) for CMS-in-CMS modal UX.';
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

        // 2. Link the open_in_modal property to the navigable content page types
        //    so it shows in the page inspector. default_value stays NULL.
        foreach (self::PAGE_TYPES as $pageType) {
            $this->addSql(
                'INSERT IGNORE INTO `rel_fields_page_types` (id_page_types, id_fields, title, help)
                 SELECT pt.id, f.id, ?, ?
                 FROM `page_types` pt, `fields` f
                 WHERE pt.`name` = ? AND f.`name` = ?',
                [self::OPEN_IN_MODAL_META[0], self::OPEN_IN_MODAL_META[1], $pageType, 'open_in_modal']
            );
        }

        // 3. Link the form-save + table-action fields to their styles.
        foreach (self::LINKS as [$style, $field, $default, $help, $title]) {
            $this->addSql(
                'INSERT INTO `rel_fields_styles` (id_styles, id_fields, default_value, help, disabled, hidden, title)
                 SELECT s.id, f.id, ?, ?, 0, 0, ?
                 FROM `styles` s, `fields` f
                 WHERE s.`name` = ? AND f.`name` = ?',
                [$default, $help, $title, $style, $field]
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Drop the new fields FK-safely (removes their page-type/style links and
        // any authored page/section values), then the fields themselves.
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
