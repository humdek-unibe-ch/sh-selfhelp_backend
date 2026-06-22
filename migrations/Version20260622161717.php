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
 * Style authoring upgrade for the form / notification / show-user-input styles
 * (approved /style run, 2026-06-22):
 *
 *   - form-record & form-log gain optional authoring fields:
 *       `title` + `description` (reused global content fields) render an
 *       auto-styled heading above the form when set; `alert_success_title` /
 *       `alert_error_title` make the success/error alert headings translatable
 *       (were hardcoded "Success"/"Error"); `confirm_submit` + `confirm_message`
 *       add an optional confirm-before-save dialog.
 *   - notification promotes its icon and close-button to portable scope so the
 *       mobile renderer can honour them too:
 *       `web_notification_with_close_button` -> `shared_with_close_button`
 *       (id-stable rename; notification is the only style that used it) and a new
 *       `shared_icon` field replaces the notification link to the shared
 *       `web_left_icon` field (which 15 styles use, so it cannot be renamed).
 *   - show-user-input gains `title` (optional heading) and `empty_text`
 *       (the empty-state message, was hardcoded "No entries found.").
 *
 * Reuse-first: `title` (markdown-inline) and `description` (textarea) are existing
 * global content fields linked to the new styles via rel_fields_styles. Only the
 * genuinely new fields are created. FK-safe idioms follow Version20260619090609.
 *
 * down() is a best-effort inverse for local rollback: it reverses the rename,
 * restores the notification web_left_icon link (copying values back from
 * shared_icon), drops the new fields FK-safely, and unlinks the reused
 * title/description fields from the new styles (the global fields themselves stay).
 */
final class Version20260622161717 extends AbstractMigration
{
    /**
     * Genuinely new catalog fields (name => editor type + translatability flag).
     *
     * @var array<string, array{type: string, display: int}>
     */
    private const NEW_FIELDS = [
        'alert_success_title' => ['type' => 'text', 'display' => 1],
        'alert_error_title' => ['type' => 'text', 'display' => 1],
        'confirm_message' => ['type' => 'text', 'display' => 1],
        'confirm_submit' => ['type' => 'checkbox', 'display' => 0],
        'empty_text' => ['type' => 'text', 'display' => 1],
        'shared_icon' => ['type' => 'select-icon', 'display' => 0],
    ];

    /**
     * Links to create: [style, field, default_value, help, inspector title].
     * Covers reused globals (title/description) and the new content/property fields
     * — but NOT shared_icon, which copies its config from the web_left_icon link.
     *
     * @var list<array{0:string,1:string,2:?string,3:string,4:string}>
     */
    private const LINKS = [
        ['form-record', 'title', '', 'Optional heading shown above the form. Leave empty to hide.', 'Title'],
        ['form-record', 'description', '', 'Optional sub-heading shown below the title.', 'Description'],
        ['form-record', 'alert_success_title', 'Success', 'Heading of the success alert shown after a successful submit.', 'Success alert title'],
        ['form-record', 'alert_error_title', 'Error', 'Heading of the error alert shown when a submit fails.', 'Error alert title'],
        ['form-record', 'confirm_submit', '0', 'When enabled, a confirmation dialog is shown before the form is submitted.', 'Confirm before submit'],
        ['form-record', 'confirm_message', 'Are you sure you want to submit?', 'Message shown in the confirmation dialog before submit.', 'Confirmation message'],
        ['form-log', 'title', '', 'Optional heading shown above the form. Leave empty to hide.', 'Title'],
        ['form-log', 'description', '', 'Optional sub-heading shown below the title.', 'Description'],
        ['form-log', 'alert_success_title', 'Success', 'Heading of the success alert shown after a successful submit.', 'Success alert title'],
        ['form-log', 'alert_error_title', 'Error', 'Heading of the error alert shown when a submit fails.', 'Error alert title'],
        ['form-log', 'confirm_submit', '0', 'When enabled, a confirmation dialog is shown before the form is submitted.', 'Confirm before submit'],
        ['form-log', 'confirm_message', 'Are you sure you want to submit?', 'Message shown in the confirmation dialog before submit.', 'Confirmation message'],
        ['show-user-input', 'title', '', 'Optional heading shown above the entries. Leave empty to hide.', 'Title'],
        ['show-user-input', 'empty_text', 'No entries found.', 'Message shown when there are no entries to display.', 'Empty state text'],
    ];

    /**
     * Reused global content fields to unlink in down() (style, field). The global
     * field rows themselves are shared and must stay.
     *
     * @var list<array{0:string,1:string}>
     */
    private const REUSED_LINKS = [
        ['form-record', 'title'],
        ['form-record', 'description'],
        ['form-log', 'title'],
        ['form-log', 'description'],
        ['show-user-input', 'title'],
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
        return 'Style authoring upgrade: form-record/form-log title+description+alert titles+confirm dialog; notification shared_icon + shared_with_close_button (promote from web); show-user-input title + empty_text.';
    }

    public function up(Schema $schema): void
    {
        // Guard: refuse if any new field name or the rename target already exists.
        foreach (array_keys(self::NEW_FIELDS) as $name) {
            $this->abortIf($this->fieldExists($name), sprintf("Refusing create: field '%s' already exists.", $name));
        }
        $this->abortIf($this->fieldExists('shared_with_close_button'), "Refusing rename: target field 'shared_with_close_button' already exists.");

        // 1. Create the genuinely new fields.
        foreach (self::NEW_FIELDS as $name => $info) {
            $this->addSql(
                'INSERT INTO `fields` (`name`, id_field_types, `display`) SELECT ?, ft.id, ? FROM `field_types` ft WHERE ft.`name` = ?',
                [$name, $info['display'], $info['type']]
            );
        }

        // 2. Link reused + new fields to their styles.
        foreach (self::LINKS as [$style, $field, $default, $help, $title]) {
            $this->addSql(
                'INSERT INTO `rel_fields_styles` (id_styles, id_fields, default_value, help, disabled, hidden, title)
                 SELECT s.id, f.id, ?, ?, 0, 0, ?
                 FROM `styles` s, `fields` f
                 WHERE s.`name` = ? AND f.`name` = ?',
                [$default, $help, $title, $style, $field]
            );
        }

        // 3. Promote the notification icon: shared_icon replaces the notification
        //    link to the shared web_left_icon field (kept for the other 15 styles).
        //    3a. Copy notification's web_left_icon link config to shared_icon.
        $this->addSql(
            "INSERT INTO `rel_fields_styles` (id_styles, id_fields, default_value, help, disabled, hidden, title)
             SELECT r.id_styles, (SELECT id FROM `fields` WHERE `name` = 'shared_icon'), r.default_value, r.help, r.disabled, r.hidden, r.title
             FROM `rel_fields_styles` r
             WHERE r.id_fields = (SELECT id FROM `fields` WHERE `name` = 'web_left_icon')
               AND r.id_styles = (SELECT id FROM `styles` WHERE `name` = 'notification')"
        );
        //    3b. Copy any authored per-section icon values to shared_icon.
        $this->addSql(
            "INSERT INTO `sections_fields_translation` (id_sections, id_fields, id_languages, content, meta)
             SELECT t.id_sections, (SELECT id FROM `fields` WHERE `name` = 'shared_icon'), t.id_languages, t.content, t.meta
             FROM `sections_fields_translation` t
             WHERE t.id_fields = (SELECT id FROM `fields` WHERE `name` = 'web_left_icon')
               AND t.id_sections IN (SELECT id FROM `sections` WHERE id_styles = (SELECT id FROM `styles` WHERE `name` = 'notification'))"
        );
        //    3c. Remove the old web_left_icon notification values + link.
        $this->addSql(
            "DELETE FROM `sections_fields_translation`
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = 'web_left_icon')
               AND id_sections IN (SELECT id FROM `sections` WHERE id_styles = (SELECT id FROM `styles` WHERE `name` = 'notification'))"
        );
        $this->addSql(
            "DELETE FROM `rel_fields_styles`
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = 'web_left_icon')
               AND id_styles = (SELECT id FROM `styles` WHERE `name` = 'notification')"
        );

        // 4. Promote the close-button toggle (notification-only field) to shared scope.
        $this->addSql('UPDATE `fields` SET `name` = ? WHERE `name` = ?', ['shared_with_close_button', 'web_notification_with_close_button']);
    }

    public function down(Schema $schema): void
    {
        // 4'. Reverse the close-button rename.
        $this->addSql('UPDATE `fields` SET `name` = ? WHERE `name` = ?', ['web_notification_with_close_button', 'shared_with_close_button']);

        // 3'. Restore the notification web_left_icon link + values from shared_icon
        //     (shared_icon itself is dropped in step 1' below).
        $this->addSql(
            "INSERT INTO `rel_fields_styles` (id_styles, id_fields, default_value, help, disabled, hidden, title)
             SELECT r.id_styles, (SELECT id FROM `fields` WHERE `name` = 'web_left_icon'), r.default_value, r.help, r.disabled, r.hidden, r.title
             FROM `rel_fields_styles` r
             WHERE r.id_fields = (SELECT id FROM `fields` WHERE `name` = 'shared_icon')
               AND r.id_styles = (SELECT id FROM `styles` WHERE `name` = 'notification')"
        );
        $this->addSql(
            "INSERT INTO `sections_fields_translation` (id_sections, id_fields, id_languages, content, meta)
             SELECT t.id_sections, (SELECT id FROM `fields` WHERE `name` = 'web_left_icon'), t.id_languages, t.content, t.meta
             FROM `sections_fields_translation` t
             WHERE t.id_fields = (SELECT id FROM `fields` WHERE `name` = 'shared_icon')
               AND t.id_sections IN (SELECT id FROM `sections` WHERE id_styles = (SELECT id FROM `styles` WHERE `name` = 'notification'))"
        );

        // 1'. Drop the new fields FK-safely (removes their links + section values,
        //     including shared_icon's).
        foreach (array_keys(self::NEW_FIELDS) as $name) {
            foreach (self::FIELD_REF_TABLES as $table) {
                $this->addSql(
                    "DELETE FROM `$table` WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = ?)",
                    [$name]
                );
            }
            $this->addSql('DELETE FROM `fields` WHERE `name` = ?', [$name]);
        }

        // 2'. Unlink the reused global content fields from the new styles
        //     (title/description stay as global fields for the other styles).
        foreach (self::REUSED_LINKS as [$style, $field]) {
            $this->addSql(
                "DELETE FROM `sections_fields_translation`
                 WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = ?)
                   AND id_sections IN (SELECT id FROM `sections` WHERE id_styles = (SELECT id FROM `styles` WHERE `name` = ?))",
                [$field, $style]
            );
            $this->addSql(
                "DELETE FROM `rel_fields_styles`
                 WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = ?)
                   AND id_styles = (SELECT id FROM `styles` WHERE `name` = ?)",
                [$field, $style]
            );
        }
    }

    private function fieldExists(string $name): bool
    {
        $value = $this->connection->fetchOne('SELECT COUNT(*) FROM `fields` WHERE `name` = ?', [$name]);

        return is_numeric($value) && (int) $value > 0;
    }
}
