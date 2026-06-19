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
 * Style polish wave — accordion + accordion-item. Decisions taken in the /style
 * approval gate (2026-06-19). Pairs with the coupled `@selfhelp/shared` + web +
 * mobile renderer reads. Pre-1.0: nothing here is backward compatible.
 *
 *   - accordion       promote the variant control to a cross-platform shared
 *                     token by renaming `web_accordion_variant` ->
 *                     `shared_accordion_variant`. Because field scope is derived
 *                     from the name prefix (`StyleRepository::deriveFieldScope`),
 *                     this `display = 0` field flips from the Web card to the
 *                     Shared card and becomes readable by the mobile renderer
 *                     (web -> Mantine variant; mobile -> HeroUI Native
 *                     default/surface). The id-stable rename preserves the
 *                     default/contained/filled/separated options and authored
 *                     values; `clearable` is enabled and the help is made
 *                     platform-accurate. The accordion's value enum is distinct
 *                     from the generic button-style `shared_variant`, so it keeps
 *                     its own field rather than sharing that catalog row.
 *   - accordion-item  link the existing translatable `description` content field
 *                     (optional subtitle rendered under the item label on both
 *                     platforms; empty = hidden).
 *
 * Relationships and authored content reference fields by id, so renaming a `name`
 * never breaks a link. `down()` is a best-effort inverse for local rollback.
 */
final class Version20260619183601 extends AbstractMigration
{
    private const VARIANT_CONFIG_SHARED = '{"options": [{"text": "Default", "value": "default"}, {"text": "Contained", "value": "contained"}, {"text": "Filled", "value": "filled"}, {"text": "Separated", "value": "separated"}], "clearable": true, "searchable": false}';

    private const VARIANT_CONFIG_WEB = '{"options": [{"text": "Default", "value": "default"}, {"text": "Contained", "value": "contained"}, {"text": "Filled", "value": "filled"}, {"text": "Separated", "value": "separated"}], "clearable": false, "searchable": false}';

    private const VARIANT_HELP_SHARED = 'Visual variant of the accordion. On web maps to the Mantine variant (default/contained/filled/separated); on mobile, "default" renders a plain list and the others render as a grouped surface.';

    private const VARIANT_HELP_WEB = 'Sets the variant of the accordion. For more information check https://mantine.dev/core/accordion';

    public function getDescription(): string
    {
        return 'Style polish wave: accordion (web_accordion_variant -> shared_accordion_variant cross-platform promotion, clearable), accordion-item (+ description subtitle).';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->fieldExists('shared_accordion_variant'),
            "Refusing rename: target field 'shared_accordion_variant' already exists."
        );
        $this->abortIf(
            !$this->fieldExists('web_accordion_variant'),
            "Refusing rename: source field 'web_accordion_variant' does not exist."
        );

        // ===== accordion =====
        // Promote the variant token web -> shared (id-stable rename).
        $this->addSql("UPDATE `fields` SET `name` = 'shared_accordion_variant' WHERE `name` = 'web_accordion_variant'");
        // Enable clearable + platform-accurate options on the field.
        $this->addSql(
            "UPDATE `fields` SET `config` = ? WHERE `name` = 'shared_accordion_variant'",
            [self::VARIANT_CONFIG_SHARED]
        );
        // Platform-accurate help on the accordion link.
        $this->addSql(
            "UPDATE `rel_fields_styles`
             SET `help` = ?, `title` = 'Variant'
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = 'shared_accordion_variant')
               AND id_styles = (SELECT id FROM `styles` WHERE `name` = 'accordion')",
            [self::VARIANT_HELP_SHARED]
        );

        // ===== accordion-item =====
        // Optional subtitle under the item label (existing translatable field).
        $this->addSql(
            "INSERT INTO `rel_fields_styles` (id_styles, id_fields, default_value, help, disabled, hidden, title)
             SELECT s.id, f.id, '', 'Optional subtitle shown under the item label. Leave empty to hide.', 0, 0, 'Description'
             FROM `styles` s, `fields` f
             WHERE s.`name` = 'accordion-item' AND f.`name` = 'description'"
        );
    }

    public function down(Schema $schema): void
    {
        // ===== accordion-item =====
        $this->addSql(
            "DELETE FROM `sections_fields_translation`
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = 'description')
               AND id_sections IN (
                   SELECT id FROM `sections` WHERE id_styles = (SELECT id FROM `styles` WHERE `name` = 'accordion-item')
               )"
        );
        $this->addSql(
            "DELETE FROM `rel_fields_styles`
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = 'description')
               AND id_styles = (SELECT id FROM `styles` WHERE `name` = 'accordion-item')"
        );

        // ===== accordion =====
        $this->addSql(
            "UPDATE `rel_fields_styles`
             SET `help` = ?, `title` = 'Variant'
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = 'shared_accordion_variant')
               AND id_styles = (SELECT id FROM `styles` WHERE `name` = 'accordion')",
            [self::VARIANT_HELP_WEB]
        );
        $this->addSql(
            "UPDATE `fields` SET `config` = ? WHERE `name` = 'shared_accordion_variant'",
            [self::VARIANT_CONFIG_WEB]
        );
        $this->addSql("UPDATE `fields` SET `name` = 'web_accordion_variant' WHERE `name` = 'shared_accordion_variant'");
    }

    private function fieldExists(string $name): bool
    {
        $value = $this->connection->fetchOne('SELECT COUNT(*) FROM `fields` WHERE `name` = ?', [$name]);

        return is_numeric($value) && (int) $value > 0;
    }
}
