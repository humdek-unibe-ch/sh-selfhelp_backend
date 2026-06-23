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
 * Style field cleanup, slice 2 — semantic colour promotion + two field-name
 * fixes. Decision register:
 * docs/reference/styles/style-refactoring-recommendations.md
 * (RF-13, RF-36, RF-37). Pairs with `@selfhelp/shared` v1.13.0 and the coupled
 * web + mobile renderer reads. Pre-1.0: nothing here is backward compatible.
 *
 *   - RF-13  rename `web_color` -> `shared_color`. Colour is a semantic token both
 *            platforms use (an author setting a login/alert/button colour must
 *            apply on mobile too), so it drops the `web_` prefix. Because field
 *            scope is derived from the name prefix
 *            (`StyleRepository::deriveFieldScope`), the rename alone flips the
 *            field from the Web card to the Shared card and makes the mobile
 *            renderer read it. The web-only colour-UI config fields
 *            (`web_color_format`, `web_color_input_*`, `web_color_picker_*`) are
 *            deliberately untouched — they configure the colour-picker widget,
 *            not a semantic colour.
 *   - RF-37  rename `web_checkbox_labelPosition` -> `web_checkbox_label_position`
 *            (camelCase violated the snake_case field-name rule).
 *   - RF-36  remove the `image` duplicates `web_image_src` / `web_image_alt`
 *            (they duplicate the `img_src` / `alt` content fields the renderers
 *            already read).
 *
 * Relationships and authored content reference fields by id, so renaming a `name`
 * never breaks a link. The RF-36 delete is FK-safe: every table referencing
 * `fields.id` is cleared for the removed field first.
 *
 * `down()` is a best-effort inverse for local rollback: it reverses the renames
 * and re-creates the removed `web_image_*` fields and their `image` link from the
 * captured catalog snapshot (authored section content is not restored).
 */
final class Version20260619092612 extends AbstractMigration
{
    /** @var array<string, string> old field name => new field name */
    private const RENAMES = [
        'web_color' => 'shared_color',
        'web_checkbox_labelPosition' => 'web_checkbox_label_position',
    ];

    /**
     * Fields removed entirely (RF-36), with the editor type + display flag +
     * owning style + help needed to re-create them in down().
     *
     * @var array<string, array{type: string, display: int, style: string, help: string}>
     */
    private const REMOVED_IMAGE_FIELDS = [
        'web_image_src' => [
            'type' => 'text',
            'display' => 1,
            'style' => 'image',
            'help' => 'The source URL of the image. For more information check https://mantine.dev/core/image',
        ],
        'web_image_alt' => [
            'type' => 'text',
            'display' => 1,
            'style' => 'image',
            'help' => 'Alt text for the image for accessibility. For more information check https://mantine.dev/core/image',
        ],
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
        return 'Style field cleanup slice 2: rename web_color -> shared_color (semantic colour, RF-13), fix web_checkbox_labelPosition -> web_checkbox_label_position (RF-37), remove the image web_image_src/web_image_alt duplicates (RF-36).';
    }

    public function up(Schema $schema): void
    {
        foreach (self::RENAMES as $old => $new) {
            $this->abortIf(
                $this->fieldExists($new),
                sprintf("Refusing rename: target field '%s' already exists.", $new)
            );
        }

        // RF-13, RF-37 — renames (id-stable; relationships untouched). Scope is
        // re-derived from the new name prefix at read time.
        foreach (self::RENAMES as $old => $new) {
            $this->addSql('UPDATE `fields` SET `name` = ? WHERE `name` = ?', [$new, $old]);
        }

        // RF-36 — remove the image src/alt duplicates entirely (FK-safe).
        foreach (array_keys(self::REMOVED_IMAGE_FIELDS) as $name) {
            foreach (self::FIELD_REF_TABLES as $table) {
                $this->addSql(
                    "DELETE FROM `$table` WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = ?)",
                    [$name]
                );
            }
            $this->addSql('DELETE FROM `fields` WHERE `name` = ?', [$name]);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::RENAMES as $old => $new) {
            $this->addSql('UPDATE `fields` SET `name` = ? WHERE `name` = ?', [$old, $new]);
        }

        // Re-create the removed image fields + their style link (structure only).
        foreach (self::REMOVED_IMAGE_FIELDS as $name => $info) {
            $this->addSql(
                "INSERT INTO `fields` (`name`, id_field_types, `display`)
                 SELECT ?, ft.id, ? FROM `field_types` ft WHERE ft.`name` = ?",
                [$name, $info['display'], $info['type']]
            );
            $this->addSql(
                "INSERT INTO `rel_fields_styles` (id_styles, id_fields, default_value, help, disabled, hidden)
                 SELECT s.id, f.id, NULL, ?, 0, 0
                 FROM `styles` s, `fields` f
                 WHERE s.`name` = ? AND f.`name` = ?",
                [$info['help'], $info['style'], $name]
            );
        }
    }

    private function fieldExists(string $name): bool
    {
        $value = $this->connection->fetchOne('SELECT COUNT(*) FROM `fields` WHERE `name` = ?', [$name]);

        return is_numeric($value) && (int) $value > 0;
    }
}
