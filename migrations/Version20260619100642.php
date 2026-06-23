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
 * Style field cleanup, slice 9 — merge the legacy margin-only `web_spacing_margin`
 * (field type `spacing-margin`) into the portable box-model `shared_spacing`
 * (field type `spacing`). Decision register:
 * docs/reference/styles/style-refactoring-recommendations.md (RF-15).
 *
 * Both fields store the SAME box-model JSON (`{"mt":"md","mb":"lg",…}`) and are
 * mutually exclusive at the style level (zero styles link both), so the merge is
 * a value-preserving repoint, not a format conversion: every `web_spacing_margin`
 * link + authored section value moves onto `shared_spacing`, giving the 39
 * margin-only styles full portable margin+padding that the mobile renderer reads
 * (`shared_spacing`). Backend `src/` references neither field name (it reads the
 * catalog dynamically), so this is backend-safe. Pre-1.0: not backward
 * compatible — the coupled `@selfhelp/shared` + web/mobile renderer wave drops
 * `web_spacing_margin`.
 *
 * `down()` is a faithful inverse for local rollback: it re-creates the
 * `spacing-margin` type + `web_spacing_margin` field and moves exactly the
 * captured styles' links and their authored section values back (the captured
 * list is the live set of styles that linked `web_spacing_margin`).
 */
final class Version20260619100642 extends AbstractMigration
{
    /**
     * Styles that linked `web_spacing_margin` at merge time (captured from the
     * live catalog). Used by down() to repoint exactly these back.
     *
     * @var list<int>
     */
    private const MARGIN_ONLY_STYLE_IDS = [
        1, 8, 13, 15, 18, 27, 43, 93, 94, 95, 96, 97, 99, 101, 102, 103, 104, 105,
        106, 107, 108, 109, 110, 115, 117, 118, 119, 121, 122, 127, 131, 136, 137,
        138, 141, 142, 143, 144, 147,
    ];

    public function getDescription(): string
    {
        return 'Style field cleanup slice 9: merge legacy margin-only web_spacing_margin into the portable box-model shared_spacing (move links + section values, drop the field and its spacing-margin type) (RF-15).';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->fieldExists('shared_spacing'),
            "Refusing merge: target field 'shared_spacing' does not exist."
        );

        // 1) Move authored section values onto shared_spacing. Section sets are
        //    disjoint (a section can only hold a web_spacing_margin value if its
        //    style linked that field, and those styles never linked shared_spacing),
        //    so no (id_sections, id_fields, id_languages) collision is possible.
        $this->addSql(
            "UPDATE `sections_fields_translation`
             SET id_fields = (SELECT id FROM `fields` WHERE `name` = 'shared_spacing')
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = 'web_spacing_margin')"
        );

        // 2) Repoint the style links onto shared_spacing, upgrading the editor
        //    label/help from margin-only to the box-model wording the existing
        //    shared_spacing links already use. Zero overlap => no PK collision.
        $this->addSql(
            "UPDATE `rel_fields_styles`
             SET id_fields = (SELECT id FROM `fields` WHERE `name` = 'shared_spacing'),
                 `title` = 'Spacing',
                 `help` = REPLACE(`help`, 'Sets the margin of the', 'Sets the margin and padding of the')
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = 'web_spacing_margin')"
        );

        // 3) Drop the now-orphaned field and its dedicated (now-unused) type.
        $this->addSql("DELETE FROM `fields` WHERE `name` = 'web_spacing_margin'");
        $this->addSql("DELETE FROM `field_types` WHERE `name` = 'spacing-margin'");
    }

    public function down(Schema $schema): void
    {
        // 1) Re-create the spacing-margin type + web_spacing_margin field.
        $this->addSql("INSERT INTO `field_types` (`name`, `position`) VALUES ('spacing-margin', 0)");
        $this->addSql(
            "INSERT INTO `fields` (`name`, id_field_types, `display`)
             SELECT 'web_spacing_margin', ft.id, 0 FROM `field_types` ft WHERE ft.`name` = 'spacing-margin'"
        );

        $ids = implode(', ', self::MARGIN_ONLY_STYLE_IDS);

        // 2) Move the captured styles' authored section values back.
        $this->addSql(
            "UPDATE `sections_fields_translation` sft
             JOIN `sections` s ON s.id = sft.id_sections
             SET sft.id_fields = (SELECT id FROM `fields` WHERE `name` = 'web_spacing_margin')
             WHERE sft.id_fields = (SELECT id FROM `fields` WHERE `name` = 'shared_spacing')
               AND s.id_styles IN ($ids)"
        );

        // 3) Repoint exactly the captured style links back, restoring the
        //    margin-only label/help.
        $this->addSql(
            "UPDATE `rel_fields_styles`
             SET id_fields = (SELECT id FROM `fields` WHERE `name` = 'web_spacing_margin'),
                 `title` = 'Margin',
                 `help` = REPLACE(`help`, 'Sets the margin and padding of the', 'Sets the margin of the')
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = 'shared_spacing')
               AND id_styles IN ($ids)"
        );
    }

    private function fieldExists(string $name): bool
    {
        $value = $this->connection->fetchOne('SELECT COUNT(*) FROM `fields` WHERE `name` = ?', [$name]);

        return is_numeric($value) && (int) $value > 0;
    }
}
