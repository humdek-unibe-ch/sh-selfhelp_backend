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
 * Modal-size page properties get a dedicated editor type (CMS-in-CMS modal UX).
 *
 * Follow-up to Version20260630172821, which seeded `modal_width` + `modal_height`
 * as plain `text` fields. A plain text box gives the author no hint of the useful
 * values, so this re-points both fields to a new `select-modal-size` editor type:
 * the admin renders them as a "dropdown + manual entry" (the same creatable-select
 * the section editor uses for CSS-like values) pre-filled with the size presets
 * (`auto`, `50%`..`100%`) while still accepting any custom CSS length.
 *
 * Editor-only change: the stored value (a free CSS length / `auto` / empty) and
 * the frontend contract (`IPageContent.modal_width|modal_height`, default 80%,
 * 90% cap) are unchanged. Mirrors the dedicated-field-type idiom of
 * Version20260630130327 (`select-nav-render-web` etc.).
 */
final class Version20260630181203 extends AbstractMigration
{
    /** The new dedicated editor field type for the modal-size selects. */
    private const FIELD_TYPE = 'select-modal-size';

    /** The fields re-pointed to the new type (were `text` in Version20260630172821). */
    private const FIELDS = ['modal_width', 'modal_height'];

    public function getDescription(): string
    {
        return 'Re-point modal_width + modal_height to a dedicated select-modal-size editor type (dropdown + manual entry).';
    }

    public function up(Schema $schema): void
    {
        // 1. New editor field type (idempotent).
        $this->addSql(
            'INSERT IGNORE INTO `field_types` (`name`, `position`) VALUES (?, 0)',
            [self::FIELD_TYPE]
        );

        // 2. Re-point the modal-size fields from `text` to the new type.
        foreach (self::FIELDS as $field) {
            $this->addSql(
                'UPDATE `fields` SET id_field_types = (SELECT id FROM `field_types` WHERE `name` = ?)
                 WHERE `name` = ?',
                [self::FIELD_TYPE, $field]
            );
        }
    }

    public function down(Schema $schema): void
    {
        // 1'. Re-point the modal-size fields back to the plain `text` type.
        foreach (self::FIELDS as $field) {
            $this->addSql(
                'UPDATE `fields` SET id_field_types = (SELECT id FROM `field_types` WHERE `name` = ?)
                 WHERE `name` = ?',
                ['text', $field]
            );
        }

        // 2'. Remove the now-unreferenced editor field type.
        $this->addSql('DELETE FROM `field_types` WHERE `name` = ?', [self::FIELD_TYPE]);
    }
}
