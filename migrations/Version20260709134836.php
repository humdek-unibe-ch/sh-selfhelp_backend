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
 * `entry-table` gains an opt-in language preview toggle for CMS app list grids.
 * Default off so public/admin tables keep the site language unless authors enable it.
 */
final class Version20260709134836 extends AbstractMigration
{
    /** @var array<string, array{type: string, display: int}> */
    private const NEW_FIELDS = [
        'show_language_preview' => ['type' => 'checkbox', 'display' => 0],
    ];

    /** @var list<array{0:string,1:string,2:?string,3:string,4:string}> */
    private const LINKS = [
        [
            'entry-table',
            'show_language_preview',
            '0',
            'When enabled, the web table shows a language selector above the grid and reloads translatable column values for the chosen locale. Mainly for CMS app content lists.',
            'Show language preview',
        ],
    ];

    /** @var list<string> */
    private const FIELD_REF_TABLES = [
        'sections_fields_translation',
        'rel_fields_styles',
        'rel_fields_pages',
        'pages_fields_translation',
        'rel_fields_page_types',
    ];

    public function getDescription(): string
    {
        return 'entry-table: add show_language_preview checkbox (default off) for CMS list locale preview.';
    }

    public function up(Schema $schema): void
    {
        foreach (array_keys(self::NEW_FIELDS) as $name) {
            $this->abortIf($this->fieldExists($name), sprintf("Refusing create: field '%s' already exists.", $name));
        }

        foreach (self::NEW_FIELDS as $name => $info) {
            $this->addSql(
                'INSERT INTO `fields` (`name`, id_field_types, `display`) SELECT ?, ft.id, ? FROM `field_types` ft WHERE ft.`name` = ?',
                [$name, $info['display'], $info['type']]
            );
        }

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
        $result = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM `fields` WHERE `name` = ?',
            [$name]
        );

        return is_numeric($result) && (int) $result > 0;
    }
}
