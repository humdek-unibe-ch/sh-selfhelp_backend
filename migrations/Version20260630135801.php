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
 * Fix the web page menu icon field so it renders the searchable Tabler icon
 * picker in the page inspector.
 *
 * The page property field `icon` predates the navigation work: it was seeded
 * (legacy id 174) as a plain `text` field with the title "Page icon" and the
 * now-misleading help "...For mobile icons use prefix `mobile-`". Because the
 * field already existed, the `INSERT IGNORE` in Version20260630130327 could not
 * change its type, so the inspector kept rendering it as a free-text box instead
 * of the `select-icon` picker (the curated mobile picker worked because
 * `mobile_icon` was a brand-new field).
 *
 * This migration switches the existing `icon` field to the `select-icon` field
 * type (Tabler picker, same UX as the mobile picker) and rewrites its
 * inspector copy to the web-menu wording (the mobile icon now has its own
 * dedicated `mobile_icon` field, so the "mobile- prefix" hint is obsolete).
 *
 * Presentation only: the stored value is still a single icon name; nothing in
 * page structure, URLs, or routing changes.
 */
final class Version20260630135801 extends AbstractMigration
{
    private const WEB_ICON_TITLE = 'Menu icon (web)';
    private const WEB_ICON_HELP = 'Icon shown next to this page in the website menu. Pick a Tabler icon. Optional.';

    /** Legacy inspector copy (restored on down). */
    private const LEGACY_TITLE = 'Page icon';
    private const LEGACY_HELP = 'The icon which will be used for menus. For mobile icons use prefix `mobile-`';

    public function getDescription(): string
    {
        return 'Switch the existing page `icon` property field to the select-icon (Tabler) picker and update its inspector copy to web-menu wording.';
    }

    public function up(Schema $schema): void
    {
        // 1. Point the `icon` field at the `select-icon` editor type so the page
        //    inspector renders the searchable Tabler picker (same UX as mobile).
        $this->addSql(
            'UPDATE `fields`
             SET id_field_types = (SELECT id FROM `field_types` WHERE `name` = ?)
             WHERE `name` = ?',
            ['select-icon', 'icon']
        );

        // 2. Refresh the inspector title + help for every page type the field is
        //    linked to (the legacy "mobile- prefix" hint no longer applies).
        $this->addSql(
            'UPDATE `rel_fields_page_types`
             SET title = ?, help = ?
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = ?)',
            [self::WEB_ICON_TITLE, self::WEB_ICON_HELP, 'icon']
        );
    }

    public function down(Schema $schema): void
    {
        // 1'. Restore the legacy inspector copy.
        $this->addSql(
            'UPDATE `rel_fields_page_types`
             SET title = ?, help = ?
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = ?)',
            [self::LEGACY_TITLE, self::LEGACY_HELP, 'icon']
        );

        // 2'. Revert the field type back to plain text.
        $this->addSql(
            'UPDATE `fields`
             SET id_field_types = (SELECT id FROM `field_types` WHERE `name` = ?)
             WHERE `name` = ?',
            ['text', 'icon']
        );
    }
}
