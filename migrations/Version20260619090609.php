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
 * Style field cleanup, slice 1 — remove dead/leftover catalog fields and fix the
 * alert / datepicker field bugs. Decision register:
 * docs/reference/styles/style-refactoring-recommendations.md
 * (RF-01, RF-03, RF-04, RF-05, RF-06, RF-07, RF-08, RF-10, RF-11).
 *
 * Backend logic reads none of these fields (`src/` has zero references to
 * `use_web_style` / `is_log`); they are author-catalog metadata consumed only by
 * the frontend/mobile renderers, which drop them in the coupled renderer wave.
 * Pre-1.0: nothing here is backward compatible.
 *
 *   - RF-01  remove `use_web_style` (web always renders the Mantine component now;
 *            70 style links). Mobile never read it.
 *   - RF-03  remove `label_security_question_1` / `label_security_question_2`
 *            (belonged to the removed anonymous-registration flow).
 *   - RF-04/05 remove `is_log` (log vs record is decided by the style, not a flag).
 *   - RF-06  remove `subject_user`, `is_html` (reset-password e-mail is sent from
 *            mail templates now, not from this style).
 *   - RF-07  unlink `value` from `alert` (it duplicates the translatable
 *            `content` the renderers already use). The `value` field itself stays
 *            — 21 input styles use it legitimately.
 *   - RF-08  remove `web_alert_with_close_button` (duplicate of
 *            `web_with_close_button`).
 *   - RF-10  rename `web_alert_title` -> `alert_title` (translatable copy is shared,
 *            not web-only; both renderers read it — removes the mobile web leak).
 *   - RF-11  rename `web_datepicker_allow_deseselect` ->
 *            `web_datepicker_allow_deselect` (typo; the shared type already expects
 *            the correct spelling).
 *
 * Relationships and authored content reference fields by id, so renaming a `name`
 * never breaks a link. Deletes are FK-safe: every table referencing `fields.id`
 * is cleared for the removed field first.
 *
 * `down()` is a best-effort inverse for local rollback: it re-creates the removed
 * fields and their style links from the captured catalog snapshot (authored
 * section content for the removed fields is not restored).
 */
final class Version20260619090609 extends AbstractMigration
{
    /**
     * Fields removed entirely, with the editor type + display flag needed to
     * re-create them in down().
     *
     * @var array<string, array{type: string, display: int}>
     */
    private const REMOVED_FIELDS = [
        'use_web_style' => ['type' => 'checkbox', 'display' => 0],
        'is_log' => ['type' => 'checkbox', 'display' => 0],
        'label_security_question_1' => ['type' => 'text', 'display' => 1],
        'label_security_question_2' => ['type' => 'text', 'display' => 1],
        'subject_user' => ['type' => 'text', 'display' => 1],
        'is_html' => ['type' => 'checkbox', 'display' => 0],
        'web_alert_with_close_button' => ['type' => 'checkbox', 'display' => 0],
    ];

    /** @var array<string, string> old field name => new field name */
    private const RENAMES = [
        'web_alert_title' => 'alert_title',
        'web_datepicker_allow_deseselect' => 'web_datepicker_allow_deselect',
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
        return 'Style field cleanup slice 1: remove use_web_style/is_log/security-question/email-leftover fields, unlink alert.value, drop the alert close-button twin, rename alert title + datepicker typo (RF-01,03-08,10,11).';
    }

    public function up(Schema $schema): void
    {
        foreach (self::RENAMES as $old => $new) {
            $this->abortIf(
                $this->fieldExists($new),
                sprintf("Refusing rename: target field '%s' already exists.", $new)
            );
        }

        // RF-01,03,04,05,06,08 — remove the dead fields entirely (FK-safe).
        foreach (array_keys(self::REMOVED_FIELDS) as $name) {
            foreach (self::FIELD_REF_TABLES as $table) {
                $this->addSql(
                    "DELETE FROM `$table` WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = ?)",
                    [$name]
                );
            }
            $this->addSql('DELETE FROM `fields` WHERE `name` = ?', [$name]);
        }

        // RF-07 — unlink `value` from `alert` only (the shared field stays).
        $this->addSql(
            "DELETE FROM `sections_fields_translation`
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = 'value')
               AND id_sections IN (
                   SELECT id FROM `sections` WHERE id_styles = (SELECT id FROM `styles` WHERE `name` = 'alert')
               )"
        );
        $this->addSql(
            "DELETE FROM `rel_fields_styles`
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = 'value')
               AND id_styles = (SELECT id FROM `styles` WHERE `name` = 'alert')"
        );

        // RF-10,11 — renames (id-stable; relationships untouched).
        foreach (self::RENAMES as $old => $new) {
            $this->addSql('UPDATE `fields` SET `name` = ? WHERE `name` = ?', [$new, $old]);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::RENAMES as $old => $new) {
            $this->addSql('UPDATE `fields` SET `name` = ? WHERE `name` = ?', [$old, $new]);
        }

        // RF-07 — re-link `value` to `alert`.
        $this->addSql(
            "INSERT INTO `rel_fields_styles` (id_styles, id_fields, default_value, help, disabled, hidden)
             SELECT s.id, f.id, NULL, NULL, 0, 0
             FROM `styles` s, `fields` f
             WHERE s.`name` = 'alert' AND f.`name` = 'value'"
        );

        // Re-create the removed fields + their style links (structure only).
        $links = $this->removedFieldLinks();
        foreach (self::REMOVED_FIELDS as $name => $info) {
            $this->addSql(
                "INSERT INTO `fields` (`name`, id_field_types, `display`)
                 SELECT ?, ft.id, ? FROM `field_types` ft WHERE ft.`name` = ?",
                [$name, $info['display'], $info['type']]
            );
            foreach (($links[$name] ?? []) as $style => $default) {
                $this->addSql(
                    "INSERT INTO `rel_fields_styles` (id_styles, id_fields, default_value, help, disabled, hidden)
                     SELECT s.id, f.id, ?, NULL, 0, 0
                     FROM `styles` s, `fields` f
                     WHERE s.`name` = ? AND f.`name` = ?",
                    [$default, $style, $name]
                );
            }
        }
    }

    /**
     * Style links to restore per removed field (styleName => default_value|null),
     * captured from the live catalog. `use_web_style` was linked to 70 styles, all
     * defaulting to `1`.
     *
     * @return array<string, array<string, string|null>>
     */
    private function removedFieldLinks(): array
    {
        $useWebStyleStyles = [
            'accordion', 'accordion-item', 'action-icon', 'alert', 'aspect-ratio',
            'avatar', 'background-image', 'badge', 'blockquote', 'box', 'button',
            'card', 'carousel', 'checkbox', 'chip', 'code', 'color-input',
            'color-picker', 'combobox', 'container', 'divider', 'fieldset',
            'file-input', 'flex', 'form-log', 'form-record', 'grid', 'grid-column',
            'group', 'highlight', 'image', 'indicator', 'input', 'kbd', 'list',
            'list-item', 'login', 'notification', 'number-input', 'paper', 'profile',
            'progress', 'progress-root', 'progress-section', 'radio', 'range-slider',
            'rating', 'register', 'reset-password', 'rich-text-editor', 'scroll-area',
            'segmented-control', 'select', 'simple-grid', 'slider', 'space', 'spoiler',
            'stack', 'switch', 'tab', 'tabs', 'text', 'text-input', 'textarea',
            'theme-icon', 'timeline', 'timeline-item', 'title', 'typography', 'validate',
        ];

        return [
            'use_web_style' => array_fill_keys($useWebStyleStyles, '1'),
            'is_log' => ['form-log' => '1', 'form-record' => '0'],
            'label_security_question_1' => ['register' => 'Select security question 1'],
            'label_security_question_2' => ['register' => 'Select security question 2'],
            'subject_user' => ['reset-password' => null],
            'is_html' => ['reset-password' => '0'],
            'web_alert_with_close_button' => ['alert' => '0'],
        ];
    }

    private function fieldExists(string $name): bool
    {
        $value = $this->connection->fetchOne('SELECT COUNT(*) FROM `fields` WHERE `name` = ?', [$name]);

        return is_numeric($value) && (int) $value > 0;
    }
}
