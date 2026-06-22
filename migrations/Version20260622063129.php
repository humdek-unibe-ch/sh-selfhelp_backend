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
 * Layout styles: cross-platform configurability pass.
 *
 * The layout styles (box, container, paper, center, group, stack, flex, grid,
 * grid-column, simple-grid, space, divider, scroll-area) carried most of their
 * sizing/behaviour under `web_*`, so on mobile they were barely configurable.
 * This migration promotes the portable properties to `shared_*` (read by both
 * renderers through the @selfhelp/shared mapper) and cleans up redundant fields.
 *
 * Operations (all FK-safe; field renames are id-stable so authored content and
 * relationships survive; re-links repoint `sections_fields_translation.id_fields`
 * so authored values are preserved across the scope change):
 *
 *  - RENAME (field used only by promoted styles):
 *      web_cols -> shared_cols                       (grid, simple-grid)
 *      web_divider_variant -> shared_divider_variant (divider)
 *      web_divider_label_position -> shared_divider_label_position (divider)
 *      web_grid_span/offset/order/grow -> shared_*    (grid-column)
 *      web_miw/mih/maw/mah -> shared_*                (center)
 *      web_vertical_spacing -> shared_vertical_spacing (simple-grid)
 *  - RE-LINK (field shared with non-promoted styles -> new/existing shared field
 *    on the layout styles only; web_* stays for the others):
 *      web_width  -> new shared_width   (center, flex, grid, grid-column, group, simple-grid, stack)
 *      web_height -> new shared_height  (+ scroll-area)
 *      paper.web_border -> shared_border (existing; matches card)
 *      space.web_space_direction -> shared_orientation (existing)
 *  - ADD:
 *      paper.title            (optional auto-styled heading, content)
 *      simple-grid.shared_gap (horizontal column spacing that was missing)
 *      simple-grid.web_cols_sm/md/lg (responsive overrides replacing web_breakpoints)
 *  - REMOVE (FK-safe, values dropped):
 *      web_px, web_py        (container + paper -> use shared_spacing padding)
 *      web_breakpoints       (simple-grid -> replaced by web_cols_sm/md/lg)
 *      web_space_direction   (space -> folded into shared_orientation)
 *
 * `grid.can_have_children` is intentionally left at 0: grid is restricted to
 * `grid-column` children via `rel_styles_allowed_relationships`, which is the
 * correct "restricted children" model (0 + whitelist), not a bug.
 *
 * down() is a best-effort structural inverse for local rollback (authored values
 * for the hard-removed web_px/web_py/web_breakpoints fields are not restored).
 */
final class Version20260622063129 extends AbstractMigration
{
    /** @var array<string, string> old field name => new field name (id-stable global rename) */
    private const RENAMES = [
        'web_cols' => 'shared_cols',
        'web_divider_variant' => 'shared_divider_variant',
        'web_divider_label_position' => 'shared_divider_label_position',
        'web_grid_span' => 'shared_grid_span',
        'web_grid_offset' => 'shared_grid_offset',
        'web_grid_order' => 'shared_grid_order',
        'web_grid_grow' => 'shared_grid_grow',
        'web_miw' => 'shared_miw',
        'web_mih' => 'shared_mih',
        'web_maw' => 'shared_maw',
        'web_mah' => 'shared_mah',
        'web_vertical_spacing' => 'shared_vertical_spacing',
    ];

    /** RN-safe width/height presets (drop the web-only fit/max/min-content keywords). */
    private const SIZE_CONFIG = '{"options":[{"text":"25%","value":"25%"},{"text":"50%","value":"50%"},{"text":"75%","value":"75%"},{"text":"100%","value":"100%"},{"text":"Auto","value":"auto"},{"text":"200px","value":"200px"},{"text":"300px","value":"300px"},{"text":"400px","value":"400px"}],"clearable":true,"creatable":true,"searchable":false}';

    /** Responsive column override presets (clearable = inherit base shared_cols). */
    private const COLS_CONFIG = '{"options":[{"text":"1","value":"1"},{"text":"2","value":"2"},{"text":"3","value":"3"},{"text":"4","value":"4"},{"text":"5","value":"5"},{"text":"6","value":"6"}],"clearable":true,"searchable":false}';

    /** Layout styles that previously carried web_width. */
    private const WIDTH_STYLES = ['center', 'flex', 'grid', 'grid-column', 'group', 'simple-grid', 'stack'];

    /** Layout styles that previously carried web_height (web_width + scroll-area). */
    private const HEIGHT_STYLES = ['center', 'flex', 'grid', 'grid-column', 'group', 'scroll-area', 'simple-grid', 'stack'];

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
        return 'Layout styles cross-platform pass: promote width/height/cols/grid-col/divider/space props to shared_*, add paper.title + simple-grid responsive cols, remove web_px/web_py/web_breakpoints.';
    }

    public function up(Schema $schema): void
    {
        // Guard: rename targets and new field names must not already exist.
        foreach (self::RENAMES as $new) {
            $this->abortIf($this->fieldExists($new), sprintf("Refusing rename: target field '%s' already exists.", $new));
        }
        foreach (['shared_width', 'shared_height', 'web_cols_sm', 'web_cols_md', 'web_cols_lg'] as $new) {
            $this->abortIf($this->fieldExists($new), sprintf("Refusing create: field '%s' already exists.", $new));
        }

        // 1. id-stable global renames (web_* -> shared_*).
        foreach (self::RENAMES as $old => $new) {
            $this->addSql('UPDATE `fields` SET `name` = ? WHERE `name` = ?', [$new, $old]);
        }

        // 2. New fields.
        $this->createField('shared_width', 'select', 0, self::SIZE_CONFIG);
        $this->createField('shared_height', 'select', 0, self::SIZE_CONFIG);
        $this->createField('web_cols_sm', 'select', 0, self::COLS_CONFIG);
        $this->createField('web_cols_md', 'select', 0, self::COLS_CONFIG);
        $this->createField('web_cols_lg', 'select', 0, self::COLS_CONFIG);

        // 3. width/height re-link (preserve authored values via id_fields repoint).
        $widthHelp = 'Width of the element (e.g. 100%, 320px, auto). Applies on web and mobile.';
        $heightHelp = 'Height of the element (e.g. 100%, 320px, auto). Applies on web and mobile.';
        foreach (self::WIDTH_STYLES as $style) {
            $this->repointContent('web_width', 'shared_width', $style);
            $this->deleteRel('web_width', $style);
            $this->linkRel($style, 'shared_width', null, $widthHelp);
        }
        foreach (self::HEIGHT_STYLES as $style) {
            $this->repointContent('web_height', 'shared_height', $style);
            $this->deleteRel('web_height', $style);
            $this->linkRel($style, 'shared_height', null, $heightHelp);
        }

        // 4. paper: web_border -> shared_border (existing, matches card).
        $this->repointContent('web_border', 'shared_border', 'paper');
        $this->deleteRel('web_border', 'paper');
        $this->linkRel('paper', 'shared_border', '0', 'Show a border around the surface (web + mobile).');

        // 5. paper: optional auto-styled heading.
        $this->linkRel('paper', 'title', '', 'Optional heading rendered above the content. Leave empty for a plain surface.');

        // 6. space: web_space_direction -> shared_orientation (existing).
        $this->repointContent('web_space_direction', 'shared_orientation', 'space');
        $this->deleteRel('web_space_direction', 'space');
        $this->linkRel('space', 'shared_orientation', 'vertical', 'Direction the empty space is added (vertical or horizontal).');

        // 7. simple-grid: horizontal column spacing + responsive overrides.
        $this->linkRel('simple-grid', 'shared_gap', 'md', 'Horizontal spacing between columns.');
        $this->linkRel('simple-grid', 'web_cols_sm', null, 'Columns on small screens (web responsive). Leave empty to inherit.');
        $this->linkRel('simple-grid', 'web_cols_md', null, 'Columns on medium screens (web responsive). Leave empty to inherit.');
        $this->linkRel('simple-grid', 'web_cols_lg', null, 'Columns on large screens (web responsive). Leave empty to inherit.');

        // 8. Hard removals (FK-safe; values dropped).
        $this->dropFieldEverywhere('web_breakpoints');
        $this->dropFieldEverywhere('web_space_direction');
        $this->dropFieldEverywhere('web_px');
        $this->dropFieldEverywhere('web_py');
    }

    public function down(Schema $schema): void
    {
        // Inverse 8 — recreate hard-removed fields and their links (structure only).
        $this->createField('web_px', 'slider', 0, null);
        $this->createField('web_py', 'slider', 0, null);
        $this->createField('web_space_direction', 'segment', 0, '{"options":[{"text":"Horizontal","value":"horizontal"},{"text":"Vertical","value":"vertical"}]}');
        $this->createField('web_breakpoints', 'slider', 0, '{"options":[{"text":"Extra Small","value":"xs"},{"text":"Small","value":"sm"},{"text":"Medium","value":"md"},{"text":"Large","value":"lg"},{"text":"Extra Large","value":"xl"}]}');
        foreach (['container', 'paper'] as $style) {
            $this->linkRel($style, 'web_px', null, null);
            $this->linkRel($style, 'web_py', null, null);
        }

        // Inverse 7 — simple-grid.
        $this->deleteRel('shared_gap', 'simple-grid');
        $this->linkRel('simple-grid', 'web_breakpoints', null, null);
        foreach (['web_cols_sm', 'web_cols_md', 'web_cols_lg'] as $f) {
            $this->dropFieldEverywhere($f);
        }

        // Inverse 6 — space (recreate web_space_direction, repoint back, unlink shared_orientation).
        $this->repointContent('shared_orientation', 'web_space_direction', 'space');
        $this->deleteRel('shared_orientation', 'space');
        $this->linkRel('space', 'web_space_direction', 'vertical', null);

        // Inverse 5 — paper title (title field itself stays; used by card etc.).
        $this->deleteRel('title', 'paper');

        // Inverse 4 — paper border (shared_border field stays; used by card).
        $this->repointContent('shared_border', 'web_border', 'paper');
        $this->deleteRel('shared_border', 'paper');
        $this->linkRel('paper', 'web_border', '0', null);

        // Inverse 3 — width/height (repoint back, drop the new shared fields).
        foreach (self::WIDTH_STYLES as $style) {
            $this->repointContent('shared_width', 'web_width', $style);
            $this->deleteRel('shared_width', $style);
            $this->linkRel($style, 'web_width', null, null);
        }
        foreach (self::HEIGHT_STYLES as $style) {
            $this->repointContent('shared_height', 'web_height', $style);
            $this->deleteRel('shared_height', $style);
            $this->linkRel($style, 'web_height', null, null);
        }
        $this->dropFieldEverywhere('shared_width');
        $this->dropFieldEverywhere('shared_height');

        // Inverse 1 — reverse the global renames.
        foreach (self::RENAMES as $old => $new) {
            $this->addSql('UPDATE `fields` SET `name` = ? WHERE `name` = ?', [$old, $new]);
        }
    }

    /**
     * Repoint authored content for a field to another field, limited to sections
     * of one style (preserves the stored value across a scope change).
     */
    private function repointContent(string $oldField, string $newField, string $style): void
    {
        $this->addSql(
            "UPDATE `sections_fields_translation` sft
             JOIN `sections` sec ON sec.id = sft.id_sections
             JOIN `styles` st ON st.id = sec.id_styles
             SET sft.id_fields = (SELECT id FROM `fields` WHERE `name` = ?)
             WHERE sft.id_fields = (SELECT id FROM `fields` WHERE `name` = ?)
               AND st.`name` = ?",
            [$newField, $oldField, $style]
        );
    }

    /** Remove only the style<->field link (content already repointed). */
    private function deleteRel(string $field, string $style): void
    {
        $this->addSql(
            "DELETE FROM `rel_fields_styles`
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = ?)
               AND id_styles = (SELECT id FROM `styles` WHERE `name` = ?)",
            [$field, $style]
        );
    }

    /** @param string|null $default */
    private function linkRel(string $style, string $field, ?string $default, ?string $help): void
    {
        $this->addSql(
            "INSERT INTO `rel_fields_styles` (id_styles, id_fields, default_value, help, disabled, hidden)
             SELECT s.id, f.id, ?, ?, 0, 0
             FROM `styles` s, `fields` f
             WHERE s.`name` = ? AND f.`name` = ?",
            [$default, $help, $style, $field]
        );
    }

    /** @param string|null $config JSON string or null */
    private function createField(string $name, string $type, int $display, ?string $config): void
    {
        $this->addSql(
            "INSERT INTO `fields` (`name`, id_field_types, `display`, `config`)
             SELECT ?, ft.id, ?, ? FROM `field_types` ft WHERE ft.`name` = ?",
            [$name, $display, $config, $type]
        );
    }

    /** FK-safe full removal of a field across every referencing table. */
    private function dropFieldEverywhere(string $name): void
    {
        foreach (self::FIELD_REF_TABLES as $table) {
            $this->addSql(
                "DELETE FROM `$table` WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = ?)",
                [$name]
            );
        }
        $this->addSql('DELETE FROM `fields` WHERE `name` = ?', [$name]);
    }

    private function fieldExists(string $name): bool
    {
        $value = $this->connection->fetchOne('SELECT COUNT(*) FROM `fields` WHERE `name` = ?', [$name]);

        return is_numeric($value) && (int) $value > 0;
    }
}
