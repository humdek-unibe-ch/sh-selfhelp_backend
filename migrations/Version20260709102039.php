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
 * Introduce the two-field user-owned option contract for existing option styles.
 *
 * Catalog fields become language-neutral properties (`display = 0`) and the
 * new `option_labels` JSON field carries translated code-to-label maps
 * (`display = 1`). No new style or lookup rows are introduced.
 */
final class Version20260709102039 extends AbstractMigration
{
    /** @var list<string> */
    private const CATALOG_FIELDS = [
        'options',
        'radio_options',
        'combobox_options',
        'segmented_control_data',
    ];

    /** @var list<string> */
    private const OPTION_STYLES = [
        'select',
        'radio',
        'combobox',
        'segmented-control',
    ];

    public function getDescription(): string
    {
        return 'Add translatable option_labels and make existing option catalogs language-neutral';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($this->fieldExists('option_labels'), "Refusing create: field 'option_labels' already exists.");

        $this->addSql(
            "INSERT INTO `fields` (`name`, id_field_types, `display`, `config`)
             SELECT 'option_labels', ft.id, 1, NULL
             FROM `field_types` ft
             WHERE ft.`name` = 'json'"
        );

        foreach (self::CATALOG_FIELDS as $fieldName) {
            $this->addSql(
                "UPDATE `fields`
                 SET `display` = 0,
                     id_field_types = (SELECT id FROM `field_types` WHERE `name` = 'json')
                 WHERE `name` = ?",
                [$fieldName]
            );
        }

        foreach (self::OPTION_STYLES as $styleName) {
            $this->addSql(
                "INSERT INTO `rel_fields_styles`
                    (id_styles, id_fields, default_value, help, disabled, hidden, title)
                 SELECT s.id, f.id, '{}',
                        'Map each stable code to the translated label for this CMS language. Codes must match the option catalog.

Example labels for one language:
```json
{\"release\":\"Release\",\"feature\":\"Feature\",\"notice\":\"Notice\"}
```

In list/detail templates use the generated read-only fields `{{_field_label}}` (single choice) or `{{_field_labels}}` (multi choice).',
                        0, 0, 'Option labels'
                 FROM `styles` s, `fields` f
                 WHERE s.`name` = ? AND f.`name` = 'option_labels'",
                [$styleName]
            );
        }

        $this->addSql(
            "UPDATE `rel_fields_styles` rfs
             JOIN `fields` f ON f.id = rfs.id_fields
             SET rfs.help = 'Define the stable option codes stored in submitted data. Enter translated display labels in the grid for each CMS language.

Example catalog:
```json
[{\"value\":\"release\",\"sort\":1},{\"value\":\"feature\",\"sort\":2,\"disabled\":false}]
```

Codes are language-neutral. Labels are saved separately per language. Generated template variables `{{_field_label}}` and `{{_field_labels}}` are read-only.',
                 rfs.title = 'Options'
             WHERE f.`name` IN (?)",
            [self::CATALOG_FIELDS],
            [\Doctrine\DBAL\ArrayParameterType::STRING]
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "DELETE FROM `fields` WHERE `name` = 'option_labels'"
        );

        foreach (self::CATALOG_FIELDS as $fieldName) {
            $this->addSql(
                "UPDATE `fields` SET `display` = 1 WHERE `name` = ?",
                [$fieldName]
            );
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
