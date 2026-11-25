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
 * Give unlabelled form tables their label. Until now a form table created by
 * the wizard or an import read a `displayName` field no form style has, so it
 * kept an empty `display_name`. Copy the owning form section's `name` field
 * (first language that has one) onto every form table that is still empty and
 * not locked by hand. Data only.
 */
final class Version20261117153539 extends AbstractMigration
{
    /** Same list as `StyleNames::FORM_STYLE_NAMES`. */
    private const FORM_STYLES = ['form-record', 'form-log', 'entry-record-form', 'entry-form-list'];

    public function getDescription(): string
    {
        return 'Backfill data_tables.display_name of form tables from the form section name field.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "UPDATE data_tables dt
             JOIN sections s ON dt.name = CAST(s.id AS CHAR)
             JOIN styles st ON st.id = s.id_styles AND st.name IN (?)
             JOIN (
                 SELECT sft.id_sections, sft.content
                 FROM sections_fields_translation sft
                 JOIN fields f ON f.id = sft.id_fields AND f.name = 'name'
                 WHERE sft.content <> ''
                   AND sft.id_languages = (
                       SELECT MIN(sft2.id_languages)
                       FROM sections_fields_translation sft2
                       WHERE sft2.id_sections = sft.id_sections
                         AND sft2.id_fields = sft.id_fields
                         AND sft2.content <> ''
                   )
             ) label ON label.id_sections = s.id
             LEFT JOIN lookups l ON l.id = dt.id_display_name_source
             SET dt.display_name = LEFT(label.content, 1000)
             WHERE (dt.display_name IS NULL OR dt.display_name = '')
               AND (l.lookup_code IS NULL OR l.lookup_code <> 'manual')",
            [self::FORM_STYLES],
            [\Doctrine\DBAL\ArrayParameterType::STRING]
        );
    }

    public function down(Schema $schema): void
    {
        // Nothing to undo: a filled label cannot be told apart from one set by a
        // save, and an empty label was never meaningful.
    }
}
