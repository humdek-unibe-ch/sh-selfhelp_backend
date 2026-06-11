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
 * Link use_mantine_style to showUserInput with default value 1.
 *
 * showUserInput always renders as a Mantine Table — there is no HTML fallback.
 * Setting the default to 1 means the existing SectionMantineProperties gate
 * (which shows mantine_* fields only when use_mantine_style === true) works
 * without any frontend changes.
 */
final class Version20260611115337 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link use_mantine_style (default 1) to showUserInput so mantine_* fields appear in the CMS editor.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "INSERT IGNORE INTO `rel_fields_styles`
                (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
             SELECT s.id, f.id, '1', 'showUserInput always renders as a Mantine Table.', 0, 0, 'Use Mantine Style'
             FROM `styles` s, `fields` f
             WHERE s.`name` = 'showUserInput' AND f.`name` = 'use_mantine_style'"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "DELETE rfs FROM `rel_fields_styles` rfs
             JOIN `styles` s ON s.id = rfs.id_styles
             JOIN `fields` f ON f.id = rfs.id_fields
             WHERE s.`name` = 'showUserInput' AND f.`name` = 'use_mantine_style'"
        );
    }
}
