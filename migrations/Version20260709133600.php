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
 * Refresh option-catalog field help with copy-able JSON examples for the CMS help popover.
 */
final class Version20260709133600 extends AbstractMigration
{
    /** @var list<string> */
    private const CATALOG_FIELDS = [
        'options',
        'radio_options',
        'combobox_options',
        'segmented_control_data',
    ];

    public function getDescription(): string
    {
        return 'Update option catalog and option_labels field help with JSON examples';
    }

    public function up(Schema $schema): void
    {
        $catalogHelp = <<<'HELP'
Define the stable option codes stored in submitted data. Enter translated display labels in the grid for each CMS language.

Example catalog:
```json
[{"value":"release","sort":1},{"value":"feature","sort":2,"disabled":false}]
```

Codes are language-neutral. Labels are saved separately per language. Generated template variables `{{_field_label}}` and `{{_field_labels}}` are read-only.
HELP;

        $labelsHelp = <<<'HELP'
Map each stable code to the translated label for this CMS language. Codes must match the option catalog.

Example labels for one language:
```json
{"release":"Release","feature":"Feature","notice":"Notice"}
```

In list/detail templates use the generated read-only fields `{{_field_label}}` (single choice) or `{{_field_labels}}` (multi choice).
HELP;

        foreach (self::CATALOG_FIELDS as $fieldName) {
            $this->addSql(
                'UPDATE `rel_fields_styles` rfs
                 JOIN `fields` f ON f.id = rfs.id_fields
                 SET rfs.help = ?
                 WHERE f.`name` = ?',
                [$catalogHelp, $fieldName]
            );
        }

        $this->addSql(
            'UPDATE `rel_fields_styles` rfs
             JOIN `fields` f ON f.id = rfs.id_fields
             SET rfs.help = ?
             WHERE f.`name` = ?',
            [$labelsHelp, 'option_labels']
        );
    }

    public function down(Schema $schema): void
    {
        // Help text only; no schema rollback required.
    }
}
