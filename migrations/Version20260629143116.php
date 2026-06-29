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
 * Field-type cleanup for the type-driven CMS editor mapping (issue #56).
 *
 * The legacy `textarea` type was overloaded: it backed both prose bodies AND
 * structured JSON config blobs, and it backed raw HTML markup. With the editor
 * now chosen purely from the field type (text = single line, textarea = rich
 * WYSIWYG, json/code = Monaco), those overloaded fields rendered the wrong
 * editor. This re-types them to their real shape:
 *
 *   - 7 structured-config fields  textarea -> json  (Monaco JSON editor)
 *   - html_tag_content            textarea -> code  (Monaco HTML editor)
 *   - 5 short message fields      text     -> textarea (rich WYSIWYG, accepts
 *                                                       newlines + lists/headers)
 *
 * Data-only metadata change (no schema change); down() restores the previous
 * types. Each statement is scoped by name AND current type so it is idempotent
 * and never touches unrelated rows.
 */
final class Version20260629143116 extends AbstractMigration
{
    /** @var list<string> Structured config blobs that must use the JSON editor. */
    private const JSON_FIELDS = [
        'web_combobox_data',
        'multi_select_data',
        'segmented_control_data',
        'slider_marks_values',
        'range_slider_marks_values',
        'web_color_picker_swatches',
        'web_datepicker_time_grid_config',
    ];

    /** @var list<string> Short copy fields promoted to rich multiline WYSIWYG. */
    private const TEXTAREA_FIELDS = [
        'error_text',
        'empty_text',
        'loading_text',
        'confirm_message',
        'delete_modal_body',
    ];

    public function getDescription(): string
    {
        return 'Retype overloaded CMS fields to json/code/textarea for the type-driven editor mapping (issue #56).';
    }

    public function up(Schema $schema): void
    {
        $this->retype(self::JSON_FIELDS, 'textarea', 'json');
        $this->retype(['html_tag_content'], 'textarea', 'code');
        $this->retype(self::TEXTAREA_FIELDS, 'text', 'textarea');
    }

    public function down(Schema $schema): void
    {
        $this->retype(self::JSON_FIELDS, 'json', 'textarea');
        $this->retype(['html_tag_content'], 'code', 'textarea');
        $this->retype(self::TEXTAREA_FIELDS, 'textarea', 'text');
    }

    /**
     * Point the given field names at $toType, but only where they currently use
     * $fromType, so the statement is safe to re-run and ignores already-migrated
     * or plugin-overridden rows.
     *
     * @param list<string> $fieldNames
     */
    private function retype(array $fieldNames, string $fromType, string $toType): void
    {
        $names = implode(', ', array_map(static fn (string $name): string => "'" . $name . "'", $fieldNames));

        $this->addSql(sprintf(
            "UPDATE fields SET id_field_types = (SELECT id FROM field_types WHERE name = '%s') "
            . "WHERE name IN (%s) AND id_field_types = (SELECT id FROM field_types WHERE name = '%s')",
            $toType,
            $names,
            $fromType
        ));
    }
}
