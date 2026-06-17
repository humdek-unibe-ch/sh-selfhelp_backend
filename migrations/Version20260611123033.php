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
 * Add translatable delete-modal fields to showUserInput.
 *
 *   delete_modal_title   text display=1 — modal title (e.g. "Delete entry")
 *   delete_modal_body text display=1 — confirmation message (e.g. "Are you sure you want to delete this entry?")
 */
final class Version20260611123033 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add translatable delete_modal_title and delete_modal_body fields to showUserInput.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`, `config`)
             SELECT 'delete_modal_title', ft.id, 1, NULL FROM `field_types` ft WHERE ft.`name` = 'text'"
        );
        $this->addSql(
            "INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`, `config`)
             SELECT 'delete_modal_body', ft.id, 1, NULL FROM `field_types` ft WHERE ft.`name` = 'text'"
        );

        $this->linkField('showUserInput', 'delete_modal_title',   'Delete entry',                               'Delete Modal Title',   'Title shown on the delete confirmation modal.');
        $this->linkField('showUserInput', 'delete_modal_body', 'Are you sure you want to delete this entry?', 'Delete Modal Message', 'Confirmation message shown on the delete modal.');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "DELETE rfs FROM `rel_fields_styles` rfs
             JOIN `styles` s ON s.id = rfs.id_styles
             JOIN `fields` f ON f.id = rfs.id_fields
             WHERE s.`name` = 'showUserInput' AND f.`name` IN ('delete_modal_title', 'delete_modal_body')"
        );
        $this->addSql("DELETE FROM `fields` WHERE `name` IN ('delete_modal_title', 'delete_modal_body')");
    }

    private function linkField(string $style, string $field, string $defaultValue, string $title, string $help): void
    {
        $this->addSql(
            "INSERT IGNORE INTO `rel_fields_styles`
                (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
             SELECT s.id, f.id, :defaultValue, :help, 0, 0, :title
             FROM `styles` s, `fields` f
             WHERE s.`name` = :style AND f.`name` = :field",
            [
                'defaultValue' => $defaultValue,
                'help'         => $help,
                'title'        => $title,
                'style'        => $style,
                'field'        => $field,
            ]
        );
    }
}
