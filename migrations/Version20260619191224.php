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
 * Style polish wave — card, card-segment, checkbox, chip, code, title.
 * Decisions taken in the /style approval gate (2026-06-19). Pairs with the
 * coupled `@selfhelp/shared` contract + web + mobile renderer reads. Pre-1.0:
 * nothing here is backward compatible.
 *
 * Field scope is derived from the name prefix
 * (`StyleRepository::deriveFieldScope`), so renaming a `web_*` field to
 * `shared_*` / unprefixed flips it from the Web card to the Shared / Properties
 * card and makes it readable by the mobile renderer. Relationships and authored
 * content reference fields by id, so an id-stable rename never breaks a link.
 *
 *   - card          authoring-UX upgrade: link the existing translatable `title`
 *                   (auto-styled heading) and `img_src` (select-image asset
 *                   picker, auto-styled top image) content fields — both render
 *                   only when filled, never auto-creating a child section. Border
 *                   becomes cross-platform: `card` drops the web-only `web_border`
 *                   and gains the new shared `shared_border` (the global
 *                   `web_border` field stays for indicator/notification/paper/
 *                   validate, which remain web-only for now).
 *   - card-segment  gains the new shared `shared_border` (Mantine Card.Section
 *                   withBorder; themed divider on mobile) and the new web-only
 *                   `web_segment_inherit_padding` (Mantine inheritPadding).
 *   - checkbox      promote `web_checkbox_label_position` -> `shared_label_position`
 *                   (mobile honours label side too).
 *   - chip          promote `web_chip_variant` -> `shared_chip_variant`
 *                   (id-stable; keeps the filled/outline/light enum, distinct
 *                   from the wider generic `shared_variant`; clearable enabled).
 *   - code          promote `web_code_block` -> `code_block` (cross-platform
 *                   block-vs-inline behaviour, unprefixed) and link `shared_radius`.
 *   - title         link `shared_color`; promote `web_title_order` -> `title_order`
 *                   (semantic heading level, unprefixed) and
 *                   `web_title_line_clamp` -> `shared_line_clamp` (mobile
 *                   numberOfLines). `web_title_text_wrap` stays web-only.
 *
 * `down()` is a best-effort inverse for local rollback (authored section content
 * for newly linked fields is not restored).
 */
final class Version20260619191224 extends AbstractMigration
{
    private const CHIP_VARIANT_CONFIG_SHARED = '{"options": [{"text": "Filled", "value": "filled"}, {"text": "Outline", "value": "outline"}, {"text": "Light", "value": "light"}], "clearable": true, "searchable": false}';

    private const CHIP_VARIANT_CONFIG_WEB = '{"options": [{"text": "Filled", "value": "filled"}, {"text": "Outline", "value": "outline"}, {"text": "Light", "value": "light"}], "clearable": false, "searchable": false}';

    /** @var array<string, string> old field name => new field name (id-stable promotions) */
    private const RENAMES = [
        'web_chip_variant' => 'shared_chip_variant',
        'web_checkbox_label_position' => 'shared_label_position',
        'web_title_order' => 'title_order',
        'web_title_line_clamp' => 'shared_line_clamp',
        'web_code_block' => 'code_block',
    ];

    /** @var list<string> every table with an FK to fields(id) */
    private const FIELD_REF_TABLES = [
        'sections_fields_translation',
        'rel_fields_styles',
        'rel_fields_pages',
        'pages_fields_translation',
        'rel_fields_page_types',
    ];

    public function getDescription(): string
    {
        return 'Style polish wave: card (+title/+image content, web_border->shared_border), card-segment (+shared_border/+inherit_padding), checkbox/chip/code/title field promotions to shared/common scope.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::RENAMES as $old => $new) {
            $this->abortIf(
                $this->fieldExists($new),
                sprintf("Refusing rename: target field '%s' already exists.", $new)
            );
        }
        $this->abortIf(
            $this->fieldExists('shared_border') || $this->fieldExists('web_segment_inherit_padding'),
            'Refusing create: a new field name already exists.'
        );

        // ===== new fields =====
        $this->addSql(
            "INSERT INTO `fields` (`name`, `id_field_types`, `display`)
             SELECT 'shared_border', ft.id, 0 FROM `field_types` ft WHERE ft.`name` = 'checkbox'"
        );
        $this->addSql(
            "INSERT INTO `fields` (`name`, `id_field_types`, `display`)
             SELECT 'web_segment_inherit_padding', ft.id, 0 FROM `field_types` ft WHERE ft.`name` = 'checkbox'"
        );

        // ===== field promotions (id-stable renames) =====
        foreach (self::RENAMES as $old => $new) {
            $this->addSql('UPDATE `fields` SET `name` = ? WHERE `name` = ?', [$new, $old]);
        }
        // chip variant: enable clearable now that it is a shared override.
        $this->addSql(
            "UPDATE `fields` SET `config` = ? WHERE `name` = 'shared_chip_variant'",
            [self::CHIP_VARIANT_CONFIG_SHARED]
        );

        // ===== card =====
        // Optional auto-styled heading + top image (render only when filled).
        $this->linkField('card', 'title', '', 'Optional heading rendered above the card content. Leave empty to hide.', 'Title');
        $this->linkField('card', 'img_src', '', 'Optional image shown at the top of the card. Pick from the asset library; leave empty to hide.', 'Image');
        // Border becomes cross-platform: drop the web-only link, add the shared one.
        $this->unlinkField('card', 'web_border');
        $this->linkField('card', 'shared_border', '0', 'Draw a border around the card. Maps to Mantine `withBorder` on web and a themed border on mobile.', 'Border');

        // ===== card-segment =====
        $this->linkField('card-segment', 'shared_border', '0', 'Draw a separating border for this segment. Maps to Mantine Card.Section `withBorder` on web and a themed divider on mobile.', 'Border');
        $this->linkField('card-segment', 'web_segment_inherit_padding', '0', 'Web only: make the segment inherit the card\'s horizontal padding (Mantine `inheritPadding`).', 'Inherit padding');

        // ===== checkbox =====
        $this->retitleLink('checkbox', 'shared_label_position', 'Which side the label sits on (left/right). Applied on web and mobile.', 'Label position');

        // ===== chip =====
        $this->retitleLink('chip', 'shared_chip_variant', 'Visual variant of the chip. On web maps to the Mantine variant (filled/outline/light); on mobile maps to the HeroUI Native chip variant.', 'Variant');

        // ===== code =====
        $this->retitleLink('code', 'code_block', 'Render as a block (multi-line) instead of inline code. Applied on web and mobile.', 'Block');
        $this->linkField('code', 'shared_radius', 'sm', 'Corner radius of the code block. Mapped per platform.', 'Radius');

        // ===== title =====
        $this->linkField('title', 'shared_color', '', 'Text colour of the title from the theme palette. Mapped to web and mobile.', 'Color');
        $this->retitleLink('title', 'title_order', 'Heading level (H1-H6). Sets the semantic heading + default size on web and the heading scale on mobile.', 'Heading level');
        $this->retitleLink('title', 'shared_line_clamp', 'Maximum number of lines before truncating with an ellipsis. Web uses lineClamp; mobile uses numberOfLines.', 'Line clamp');
    }

    public function down(Schema $schema): void
    {
        // ===== title =====
        $this->unlinkField('title', 'shared_color');
        $this->retitleLink('title', 'title_order', null, 'Heading Level');
        $this->retitleLink('title', 'shared_line_clamp', null, 'Line Clamp');

        // ===== code =====
        $this->unlinkField('code', 'shared_radius');
        $this->retitleLink('code', 'code_block', null, 'Block');

        // ===== chip =====
        $this->retitleLink('chip', 'shared_chip_variant', null, 'Variant');
        $this->addSql(
            "UPDATE `fields` SET `config` = ? WHERE `name` = 'shared_chip_variant'",
            [self::CHIP_VARIANT_CONFIG_WEB]
        );

        // ===== checkbox =====
        $this->retitleLink('checkbox', 'shared_label_position', null, 'Label Position');

        // ===== card-segment =====
        $this->unlinkField('card-segment', 'web_segment_inherit_padding');
        $this->unlinkField('card-segment', 'shared_border');

        // ===== card =====
        $this->unlinkField('card', 'shared_border');
        $this->linkField('card', 'web_border', '0', null, 'With Border');
        $this->unlinkField('card', 'img_src');
        $this->unlinkField('card', 'title');

        // ===== reverse renames =====
        foreach (self::RENAMES as $old => $new) {
            $this->addSql('UPDATE `fields` SET `name` = ? WHERE `name` = ?', [$old, $new]);
        }

        // ===== drop the new fields (FK-safe) =====
        foreach (['shared_border', 'web_segment_inherit_padding'] as $name) {
            foreach (self::FIELD_REF_TABLES as $table) {
                $this->addSql(
                    "DELETE FROM `$table` WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = ?)",
                    [$name]
                );
            }
            $this->addSql('DELETE FROM `fields` WHERE `name` = ?', [$name]);
        }
    }

    private function linkField(string $style, string $field, ?string $default, ?string $help, ?string $title): void
    {
        $this->addSql(
            "INSERT INTO `rel_fields_styles` (id_styles, id_fields, default_value, help, disabled, hidden, title)
             SELECT s.id, f.id, ?, ?, 0, 0, ?
             FROM `styles` s, `fields` f
             WHERE s.`name` = ? AND f.`name` = ?",
            [$default, $help, $title, $style, $field]
        );
    }

    private function unlinkField(string $style, string $field): void
    {
        $this->addSql(
            "DELETE FROM `sections_fields_translation`
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = ?)
               AND id_sections IN (
                   SELECT id FROM `sections` WHERE id_styles = (SELECT id FROM `styles` WHERE `name` = ?)
               )",
            [$field, $style]
        );
        $this->addSql(
            "DELETE FROM `rel_fields_styles`
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = ?)
               AND id_styles = (SELECT id FROM `styles` WHERE `name` = ?)",
            [$field, $style]
        );
    }

    private function retitleLink(string $style, string $field, ?string $help, ?string $title): void
    {
        $this->addSql(
            "UPDATE `rel_fields_styles`
             SET `help` = ?, `title` = ?
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = ?)
               AND id_styles = (SELECT id FROM `styles` WHERE `name` = ?)",
            [$help, $title, $field, $style]
        );
    }

    private function fieldExists(string $name): bool
    {
        $value = $this->connection->fetchOne('SELECT COUNT(*) FROM `fields` WHERE `name` = ?', [$name]);

        return is_numeric($value) && (int) $value > 0;
    }
}
