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
 * Remove the `anchor` field from the `showUserInput` style.
 *
 * The `anchor` field has type `anchor-section`, which the frontend admin UI
 * does not yet support rendering. Keeping it linked caused an "Unknown field
 * type: anchor-section" error in the section editor.
 */
final class Version20260611071244 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove anchor field from showUserInput style: anchor-section type not yet supported by the frontend.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            DELETE rfs FROM `rel_fields_styles` rfs
            JOIN `styles` s ON s.id = rfs.id_styles
            JOIN `fields` f ON f.id = rfs.id_fields
            WHERE s.`name` = 'showUserInput' AND f.`name` = 'anchor'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_fields_styles`
                (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
            SELECT s.id, f.id, '', 'Optional HTML anchor ID for deep-linking to this section via URL hash.', 0, 0, 'Anchor'
            FROM `styles` s, `fields` f
            WHERE s.`name` = 'showUserInput' AND f.`name` = 'anchor'
        SQL);
    }
}
