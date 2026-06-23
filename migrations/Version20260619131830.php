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
 * Style polish wave — alert / badge / avatar / button / login. Pairs with the
 * coupled `@selfhelp/shared` + web (and follow-up mobile) renderer reads. Pre-1.0:
 * nothing here is backward compatible. Decisions taken in the /style approval gate
 * (2026-06-19):
 *
 *   - alert   remove the dead `shared_size` link (Mantine `Alert` has no size prop
 *             and the mobile renderer ignores it); rename `web_with_close_button`
 *             -> `closable` so the dismiss toggle is cross-platform `common`
 *             (alert-only field; id-stable rename preserves authored values and
 *             flips the scope web -> common via `deriveFieldScope`).
 *   - badge   add the cross-platform `shared_variant` (primary variant control,
 *             read by both platforms via the shared mapper), move authored
 *             `web_variant` values onto it, and keep `web_variant` as an emptied
 *             web-only escape hatch (e.g. the badge-only `dot`); add the `circle`
 *             toggle (Mantine Badge `circle`).
 *   - avatar  add the `name` field (Mantine Avatar `name` -> auto initials + auto
 *             colour fallback). The `web_avatar_variant` -> `web_variant` fix is
 *             type/renderer-only (the DB already stores `web_variant`).
 *   - button  add the cross-platform `shared_variant` and move authored
 *             `web_variant` values onto it (clean promotion — `web_variant` is
 *             unlinked from button); link the existing `url` field so external
 *             links work consistently with the mobile renderer.
 *   - login   link the existing `subtitle` content field (optional sub-heading).
 *             The `shared_color` type drift + the dead `type` read are
 *             type/renderer-only fixes.
 *
 * Relationships and authored content reference fields by id, so renaming a `name`
 * never breaks a link. `down()` is a best-effort inverse for local rollback.
 */
final class Version20260619131830 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Style polish wave: alert (drop dead shared_size, web_with_close_button -> closable), badge (+shared_variant primary, web_variant escape hatch, +circle), avatar (+name), button (web_variant -> shared_variant, +url), login (+subtitle).';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($this->fieldExists('closable'), "Refusing rename: target field 'closable' already exists.");
        $this->abortIf($this->fieldExists('circle'), "Refusing create: field 'circle' already exists.");
        $this->abortIf(!$this->fieldExists('shared_variant'), "Refusing link: source field 'shared_variant' does not exist.");

        // ===== alert =====
        // Drop the dead shared_size link (+ authored alert values for it).
        $this->addSql(
            "DELETE FROM `sections_fields_translation`
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = 'shared_size')
               AND id_sections IN (
                   SELECT id FROM `sections` WHERE id_styles = (SELECT id FROM `styles` WHERE `name` = 'alert')
               )"
        );
        $this->addSql(
            "DELETE FROM `rel_fields_styles`
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = 'shared_size')
               AND id_styles = (SELECT id FROM `styles` WHERE `name` = 'alert')"
        );
        // Rename the close-button toggle to the unprefixed cross-platform name.
        $this->addSql("UPDATE `fields` SET `name` = 'closable' WHERE `name` = 'web_with_close_button'");

        // ===== badge =====
        // Primary cross-platform variant.
        $this->addSql(
            "INSERT INTO `rel_fields_styles` (id_styles, id_fields, default_value, help, disabled, hidden, title)
             SELECT s.id, f.id, 'filled', 'Visual variant of the badge. Mapped to the Mantine variant on web and HeroUI on mobile.', 0, 0, 'Variant'
             FROM `styles` s, `fields` f WHERE s.`name` = 'badge' AND f.`name` = 'shared_variant'"
        );
        // Move authored web_variant values onto shared_variant (badge sections only;
        // no shared_variant rows exist yet, so no collision).
        $this->addSql(
            "UPDATE `sections_fields_translation` sft
             JOIN `sections` s ON s.id = sft.id_sections
             SET sft.id_fields = (SELECT id FROM `fields` WHERE `name` = 'shared_variant')
             WHERE sft.id_fields = (SELECT id FROM `fields` WHERE `name` = 'web_variant')
               AND s.id_styles = (SELECT id FROM `styles` WHERE `name` = 'badge')"
        );
        // Keep web_variant as an emptied web-only escape hatch (e.g. `dot`).
        $this->addSql(
            "UPDATE `rel_fields_styles`
             SET default_value = '', `title` = 'Variant (web override)',
                 help = 'Web-only variant override (e.g. \"dot\"). Leave empty to use the cross-platform Variant.'
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = 'web_variant')
               AND id_styles = (SELECT id FROM `styles` WHERE `name` = 'badge')"
        );
        // New `circle` toggle.
        $this->addSql(
            "INSERT INTO `fields` (`name`, id_field_types, `display`)
             SELECT 'circle', ft.id, 0 FROM `field_types` ft WHERE ft.`name` = 'checkbox'"
        );
        $this->addSql(
            "INSERT INTO `rel_fields_styles` (id_styles, id_fields, default_value, help, disabled, hidden, title)
             SELECT s.id, f.id, '0', 'Render the badge as a circle (equal width and height, no horizontal padding) - ideal for short counts.', 0, 0, 'Circle'
             FROM `styles` s, `fields` f WHERE s.`name` = 'badge' AND f.`name` = 'circle'"
        );

        // ===== avatar =====
        // Mantine Avatar `name` -> auto initials + auto colour fallback.
        $this->addSql(
            "INSERT INTO `rel_fields_styles` (id_styles, id_fields, default_value, help, disabled, hidden, title)
             SELECT s.id, f.id, NULL, 'Name of the person. When no image is set it is shown as initials and seeds an auto-generated colour.', 0, 0, 'Name'
             FROM `styles` s, `fields` f WHERE s.`name` = 'avatar' AND f.`name` = 'name'"
        );

        // ===== button =====
        // Primary cross-platform variant.
        $this->addSql(
            "INSERT INTO `rel_fields_styles` (id_styles, id_fields, default_value, help, disabled, hidden, title)
             SELECT s.id, f.id, 'filled', 'Visual variant of the button. Mapped to the Mantine variant on web and HeroUI on mobile.', 0, 0, 'Variant'
             FROM `styles` s, `fields` f WHERE s.`name` = 'button' AND f.`name` = 'shared_variant'"
        );
        // Move authored web_variant values onto shared_variant (button sections only).
        $this->addSql(
            "UPDATE `sections_fields_translation` sft
             JOIN `sections` s ON s.id = sft.id_sections
             SET sft.id_fields = (SELECT id FROM `fields` WHERE `name` = 'shared_variant')
             WHERE sft.id_fields = (SELECT id FROM `fields` WHERE `name` = 'web_variant')
               AND s.id_styles = (SELECT id FROM `styles` WHERE `name` = 'button')"
        );
        // Clean promotion: unlink web_variant from button.
        $this->addSql(
            "DELETE FROM `rel_fields_styles`
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = 'web_variant')
               AND id_styles = (SELECT id FROM `styles` WHERE `name` = 'button')"
        );
        // External-URL link target (consistent with the mobile renderer).
        $this->addSql(
            "INSERT INTO `rel_fields_styles` (id_styles, id_fields, default_value, help, disabled, hidden, title)
             SELECT s.id, f.id, '', 'External URL to open when the button is a link and no internal page is selected.', 0, 0, 'URL'
             FROM `styles` s, `fields` f WHERE s.`name` = 'button' AND f.`name` = 'url'"
        );

        // ===== login =====
        // Optional sub-heading under the title.
        $this->addSql(
            "INSERT INTO `rel_fields_styles` (id_styles, id_fields, default_value, help, disabled, hidden, title)
             SELECT s.id, f.id, '', 'Optional subtitle shown under the title. Leave empty to hide.', 0, 0, 'Subtitle'
             FROM `styles` s, `fields` f WHERE s.`name` = 'login' AND f.`name` = 'subtitle'"
        );
    }

    public function down(Schema $schema): void
    {
        // ===== login =====
        $this->addSql(
            "DELETE FROM `sections_fields_translation`
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = 'subtitle')
               AND id_sections IN (SELECT id FROM `sections` WHERE id_styles = (SELECT id FROM `styles` WHERE `name` = 'login'))"
        );
        $this->addSql(
            "DELETE FROM `rel_fields_styles`
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = 'subtitle')
               AND id_styles = (SELECT id FROM `styles` WHERE `name` = 'login')"
        );

        // ===== button =====
        $this->addSql(
            "DELETE FROM `sections_fields_translation`
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = 'url')
               AND id_sections IN (SELECT id FROM `sections` WHERE id_styles = (SELECT id FROM `styles` WHERE `name` = 'button'))"
        );
        $this->addSql(
            "DELETE FROM `rel_fields_styles`
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = 'url')
               AND id_styles = (SELECT id FROM `styles` WHERE `name` = 'button')"
        );
        // Re-link web_variant to button, then move shared_variant values back.
        $this->addSql(
            "INSERT INTO `rel_fields_styles` (id_styles, id_fields, default_value, help, disabled, hidden, title)
             SELECT s.id, f.id, 'filled', 'The variant of the button. For more information check https://mantine.dev/core/button', 0, 0, 'Variant'
             FROM `styles` s, `fields` f WHERE s.`name` = 'button' AND f.`name` = 'web_variant'"
        );
        $this->addSql(
            "UPDATE `sections_fields_translation` sft
             JOIN `sections` s ON s.id = sft.id_sections
             SET sft.id_fields = (SELECT id FROM `fields` WHERE `name` = 'web_variant')
             WHERE sft.id_fields = (SELECT id FROM `fields` WHERE `name` = 'shared_variant')
               AND s.id_styles = (SELECT id FROM `styles` WHERE `name` = 'button')"
        );
        $this->addSql(
            "DELETE FROM `rel_fields_styles`
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = 'shared_variant')
               AND id_styles = (SELECT id FROM `styles` WHERE `name` = 'button')"
        );

        // ===== avatar =====
        $this->addSql(
            "DELETE FROM `sections_fields_translation`
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = 'name')
               AND id_sections IN (SELECT id FROM `sections` WHERE id_styles = (SELECT id FROM `styles` WHERE `name` = 'avatar'))"
        );
        $this->addSql(
            "DELETE FROM `rel_fields_styles`
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = 'name')
               AND id_styles = (SELECT id FROM `styles` WHERE `name` = 'avatar')"
        );

        // ===== badge =====
        // Drop `circle` entirely.
        $this->addSql(
            "DELETE FROM `sections_fields_translation` WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = 'circle')"
        );
        $this->addSql(
            "DELETE FROM `rel_fields_styles` WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = 'circle')"
        );
        $this->addSql("DELETE FROM `fields` WHERE `name` = 'circle'");
        // Restore the badge web_variant link (default + help), move values back.
        $this->addSql(
            "UPDATE `rel_fields_styles`
             SET default_value = 'filled', `title` = 'Variant',
                 help = 'The variant of the badge. For more information check https://mantine.dev/core/badge'
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = 'web_variant')
               AND id_styles = (SELECT id FROM `styles` WHERE `name` = 'badge')"
        );
        $this->addSql(
            "UPDATE `sections_fields_translation` sft
             JOIN `sections` s ON s.id = sft.id_sections
             SET sft.id_fields = (SELECT id FROM `fields` WHERE `name` = 'web_variant')
             WHERE sft.id_fields = (SELECT id FROM `fields` WHERE `name` = 'shared_variant')
               AND s.id_styles = (SELECT id FROM `styles` WHERE `name` = 'badge')"
        );
        $this->addSql(
            "DELETE FROM `rel_fields_styles`
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = 'shared_variant')
               AND id_styles = (SELECT id FROM `styles` WHERE `name` = 'badge')"
        );

        // ===== alert =====
        $this->addSql("UPDATE `fields` SET `name` = 'web_with_close_button' WHERE `name` = 'closable'");
        $this->addSql(
            "INSERT INTO `rel_fields_styles` (id_styles, id_fields, default_value, help, disabled, hidden, title)
             SELECT s.id, f.id, 'md', 'The size of the alert. For more information check https://mantine.dev/core/alert', 0, 0, 'Size'
             FROM `styles` s, `fields` f WHERE s.`name` = 'alert' AND f.`name` = 'shared_size'"
        );
    }

    private function fieldExists(string $name): bool
    {
        $value = $this->connection->fetchOne('SELECT COUNT(*) FROM `fields` WHERE `name` = ?', [$name]);

        return is_numeric($value) && (int) $value > 0;
    }
}
