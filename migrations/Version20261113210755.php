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
 * A `data-view` can draw every record with the author's own sections.
 *
 * The style so far drew a table or cards. It now also takes a third layout,
 * `custom`: the sections placed inside the data view are the layout of ONE
 * record, and the server renders that template once per record of the page
 * (the same way `entry-list` clones its children), so the visitor keeps search,
 * filters, sorting and paging while the author keeps every normal section
 * feature: styles, translations, conditions, classes.
 *
 *  - `styles.can_have_children` is set for `data-view`, so the editor lets the
 *    author add sections inside it;
 *  - `list_layout` offers the third option;
 *  - `list_row_css` is a new, optional property: the classes of the element that
 *    holds the rows (a responsive grid, for example), web only.
 *
 * Nothing is stored differently: a data view that keeps its table or cards
 * layout renders exactly as before.
 */
final class Version20261113210755 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let the data-view style take child sections as a row template (layout "custom") and add its list_row_css property.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'UPDATE styles SET can_have_children = 1, description = ? WHERE name = ?',
            [
                'A read-only list of the records of a data table for public or signed-in visitors: a table, cards, or your own layout built from sections placed inside it, with search, sort, filters and paging, each record optionally linking to a detail page. It never writes.',
                'data-view',
            ],
        );

        $this->addSql(
            'UPDATE fields SET config = ? WHERE name = ?',
            [
                json_encode(['options' => [
                    ['text' => 'Table', 'value' => 'table'],
                    ['text' => 'Cards', 'value' => 'cards'],
                    ['text' => 'Custom (sections inside)', 'value' => 'custom'],
                ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'list_layout',
            ],
        );
        $this->addSql(
            'UPDATE rel_fields_styles rfs INNER JOIN styles s ON s.id = rfs.id_styles INNER JOIN fields f ON f.id = rfs.id_fields '
            . 'SET rfs.help = ? WHERE s.name = ? AND f.name = ?',
            [
                'Show the records as a table, as cards, or as your own layout: with "Custom" the sections you place inside this view are drawn once per record (use {{field_name}} tokens for the values; list the fields you use under Columns).',
                'data-view',
                'list_layout',
            ],
        );

        $this->addSql(
            'INSERT IGNORE INTO fields (name, id_field_types, display, config) '
            . 'SELECT ?, ft.id, 0, NULL FROM field_types ft WHERE ft.name = ? LIMIT 1',
            ['list_row_css', 'text'],
        );
        $this->addSql(
            'INSERT IGNORE INTO rel_fields_styles (id_styles, id_fields, default_value, help, disabled, hidden, title) '
            . 'SELECT s.id, f.id, ?, ?, 0, 0, ? FROM styles s CROSS JOIN fields f WHERE s.name = ? AND f.name = ?',
            [
                '',
                'Classes of the element that holds the records of a custom layout, for example `grid grid-cols-1 md:grid-cols-2 gap-6` for two columns on wider screens. Empty = one record below the other. Web only.',
                'Record container classes',
                'data-view',
                'list_row_css',
            ],
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM rel_sections_hierarchy sh INNER JOIN sections p ON p.id = sh.id_parent_section INNER JOIN styles st ON st.id = p.id_styles WHERE st.name = 'data-view'"
            ) > 0,
            'Delete the sections placed inside a data-view first.',
        );

        $this->addSql("DELETE rfs FROM rel_fields_styles rfs INNER JOIN fields f ON f.id = rfs.id_fields WHERE f.name = 'list_row_css'");
        $this->addSql("DELETE FROM fields WHERE name = 'list_row_css'");
        $this->addSql(
            'UPDATE fields SET config = ? WHERE name = ?',
            [
                json_encode(['options' => [
                    ['text' => 'Table', 'value' => 'table'],
                    ['text' => 'Cards', 'value' => 'cards'],
                ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'list_layout',
            ],
        );
        $this->addSql(
            'UPDATE rel_fields_styles rfs INNER JOIN styles s ON s.id = rfs.id_styles INNER JOIN fields f ON f.id = rfs.id_fields '
            . 'SET rfs.help = ? WHERE s.name = ? AND f.name = ?',
            ['Show the records as a table or as cards.', 'data-view', 'list_layout'],
        );
        $this->addSql(
            'UPDATE styles SET can_have_children = 0, description = ? WHERE name = ?',
            [
                'A read-only list of the records of a data table for public or signed-in visitors: table or cards, search, sort, filters and paging, each record optionally linking to a detail page. It never writes.',
                'data-view',
            ],
        );
    }
}
