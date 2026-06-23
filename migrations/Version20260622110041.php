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
 * Style catalog update for the typography / media / interactive style pass.
 *
 * Two things happen here:
 *
 * 1. Inline rich-text (Ctrl+B / I / U + link) is enabled for list items and
 *    blockquotes so a bold word authored on the web carries to the mobile app
 *    (parsed by `@selfhelp/shared` `parseInlineRich`, rendered by the web
 *    `html-react-parser` path and the mobile `<InlineText>`):
 *      - `list_item_content` is used only by `list-item`, so it is converted in
 *        place from `textarea` to `markdown-inline`.
 *      - `blockquote` shares the generic `content` field with `code`, and code
 *        legitimately contains angle brackets (`Vector<int>`) that must never be
 *        treated as HTML. So blockquote gets a DEDICATED `blockquote_content`
 *        markdown-inline field; existing authored blockquote content is migrated
 *        onto it and the generic `content` link is removed from blockquote.
 *
 * 2. New author-facing fields requested in the style pass (reusing existing
 *    field rows where one already fits — `shared_color`, `img_src`, `alt`,
 *    `web_left_icon`, `web_right_icon`, `has_controls`):
 *      - figure        : built-in `img_src` + `alt` (figure needs no child image)
 *      - link          : `shared_color`, `web_link_underline`, left/right icon
 *      - action-icon   : `aria_label` (accessible name for an icon-only button)
 *      - image         : `fallback_src` (Mantine Image fallbackSrc)
 *      - spoiler       : `shared_color` (show/hide control color)
 *      - video         : `poster_src`, `media_loop/autoplay/muted`, `has_controls`
 *      - audio         : `media_loop/autoplay`, `has_controls`
 *
 * Pre-1.0: deliberate, additive, FK-safe. `down()` is a best-effort inverse for
 * local rollback (it restores the blockquote content link and migrates the
 * authored text back, then drops the new fields/links).
 */
final class Version20260622110041 extends AbstractMigration
{
    /** Tables with an FK to fields(id); cleared before a field row is deleted. */
    private const FIELD_REF_TABLES = [
        'sections_fields_translation',
        'rel_fields_styles',
        'rel_fields_pages',
        'pages_fields_translation',
        'rel_fields_page_types',
    ];

    /**
     * New field rows created by this migration.
     *
     * @var array<string, array{type: string, display: int, config: ?string}>
     */
    private const NEW_FIELDS = [
        'web_link_underline' => [
            'type' => 'segment',
            'display' => 0,
            'config' => '{"options":[{"value":"always","text":"Always"},{"value":"hover","text":"On hover"},{"value":"never","text":"Never"}]}',
        ],
        'aria_label' => ['type' => 'text', 'display' => 1, 'config' => null],
        'poster_src' => ['type' => 'select-image', 'display' => 1, 'config' => null],
        'fallback_src' => ['type' => 'select-image', 'display' => 1, 'config' => null],
        'media_loop' => ['type' => 'checkbox', 'display' => 0, 'config' => null],
        'media_autoplay' => ['type' => 'checkbox', 'display' => 0, 'config' => null],
        'media_muted' => ['type' => 'checkbox', 'display' => 0, 'config' => null],
    ];

    public function getDescription(): string
    {
        return 'Style pass: enable inline rich-text in list-item + blockquote (dedicated blockquote_content), and add figure image, link color/underline/icons, action-icon aria_label, image fallback, spoiler color, and video/audio playback fields.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->fieldTypeExists('markdown-inline'),
            "Refusing: required field_type 'markdown-inline' is missing."
        );

        // --- 1a. list-item: convert the dedicated field in place. ----------------
        $this->addSql(
            "UPDATE `fields`
                SET id_field_types = (SELECT id FROM `field_types` WHERE `name` = 'markdown-inline')
              WHERE `name` = 'list_item_content'
                AND id_field_types = (SELECT id FROM `field_types` WHERE `name` = 'textarea')"
        );

        // --- 1b. blockquote: dedicated markdown-inline field, migrate content. ----
        $this->createField('blockquote_content', 'markdown-inline', 1, null);
        // Link it to blockquote, copying the existing content link's default/help/title.
        $this->addSql(
            "INSERT INTO `rel_fields_styles` (id_styles, id_fields, default_value, help, disabled, hidden, title)
             SELECT rfs.id_styles, nf.id, rfs.default_value, rfs.help, rfs.disabled, rfs.hidden, rfs.title
             FROM `rel_fields_styles` rfs
             JOIN `styles` s ON s.id = rfs.id_styles AND s.`name` = 'blockquote'
             JOIN `fields` cf ON cf.id = rfs.id_fields AND cf.`name` = 'content'
             JOIN `fields` nf ON nf.`name` = 'blockquote_content'"
        );
        // Move authored blockquote content onto the new field (all languages).
        $this->addSql(
            "INSERT INTO `sections_fields_translation` (id_sections, id_fields, id_languages, content, meta)
             SELECT sft.id_sections, nf.id, sft.id_languages, sft.content, sft.meta
             FROM `sections_fields_translation` sft
             JOIN `fields` cf ON cf.id = sft.id_fields AND cf.`name` = 'content'
             JOIN `fields` nf ON nf.`name` = 'blockquote_content'
             WHERE sft.id_sections IN (
                 SELECT id FROM `sections` WHERE id_styles = (SELECT id FROM `styles` WHERE `name` = 'blockquote')
             )"
        );
        // Remove the old authored content rows + the generic content link from blockquote.
        $this->addSql(
            "DELETE FROM `sections_fields_translation`
             WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = 'content')
               AND id_sections IN (
                   SELECT id FROM `sections` WHERE id_styles = (SELECT id FROM `styles` WHERE `name` = 'blockquote')
               )"
        );
        $this->addSql(
            "DELETE FROM `rel_fields_styles`
             WHERE id_styles = (SELECT id FROM `styles` WHERE `name` = 'blockquote')
               AND id_fields = (SELECT id FROM `fields` WHERE `name` = 'content')"
        );

        // --- 2. Create the new field rows. --------------------------------------
        foreach (self::NEW_FIELDS as $name => $info) {
            $this->createField($name, $info['type'], $info['display'], $info['config']);
        }

        // --- 3. Link fields to styles (reusing existing rows where they fit). ----
        // figure: built-in image so the style needs no child image section.
        $this->link('figure', 'img_src', null, 'Optional built-in image. Leave empty to compose the figure from child sections instead.', 'Image');
        $this->link('figure', 'alt', null, 'Alternative text for the built-in image (accessibility).', 'Alt text');

        // link: color, underline behaviour, optional icons.
        $this->link('link', 'shared_color', '', 'Link color. Leave empty for the theme default link color.', 'Color');
        $this->link('link', 'web_link_underline', 'hover', 'When the underline is shown (Mantine Anchor underline).', 'Underline');
        $this->link('link', 'web_left_icon', null, 'Optional icon shown before the link label.', 'Left icon');
        $this->link('link', 'web_right_icon', null, 'Optional icon shown after the link label (e.g. an external-link arrow).', 'Right icon');

        // action-icon: accessible name for the icon-only button.
        $this->link('action-icon', 'aria_label', null, 'Accessible name announced by screen readers for this icon-only control.', 'Accessible label');

        // image: broken-image fallback.
        $this->link('image', 'fallback_src', null, 'Image shown if the main source fails to load (Mantine Image fallbackSrc).', 'Fallback image');

        // spoiler: control link color.
        $this->link('spoiler', 'shared_color', '', 'Color of the show/hide control. Leave empty for the theme default.', 'Control color');

        // video: poster + playback.
        $this->link('video', 'poster_src', null, 'Poster image shown before the video plays.', 'Poster image');
        $this->link('video', 'has_controls', '1', 'Show the native playback controls.', 'Show controls');
        $this->link('video', 'media_loop', '0', 'Restart the video automatically when it ends.', 'Loop');
        $this->link('video', 'media_autoplay', '0', 'Start playing automatically (browsers require muted for autoplay).', 'Autoplay');
        $this->link('video', 'media_muted', '0', 'Start muted.', 'Muted');

        // audio: playback.
        $this->link('audio', 'has_controls', '1', 'Show the native playback controls.', 'Show controls');
        $this->link('audio', 'media_loop', '0', 'Restart the audio automatically when it ends.', 'Loop');
        $this->link('audio', 'media_autoplay', '0', 'Start playing automatically.', 'Autoplay');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->fieldTypeExists('textarea'),
            "Refusing: required field_type 'textarea' is missing."
        );

        // --- 3/2 inverse: unlink + drop the new fields. -------------------------
        $this->unlink('audio', 'media_autoplay');
        $this->unlink('audio', 'media_loop');
        $this->unlink('audio', 'has_controls');
        $this->unlink('video', 'media_muted');
        $this->unlink('video', 'media_autoplay');
        $this->unlink('video', 'media_loop');
        $this->unlink('video', 'has_controls');
        $this->unlink('video', 'poster_src');
        $this->unlink('spoiler', 'shared_color');
        $this->unlink('image', 'fallback_src');
        $this->unlink('action-icon', 'aria_label');
        $this->unlink('link', 'web_right_icon');
        $this->unlink('link', 'web_left_icon');
        $this->unlink('link', 'web_link_underline');
        $this->unlink('link', 'shared_color');
        $this->unlink('figure', 'alt');
        $this->unlink('figure', 'img_src');

        foreach (array_keys(self::NEW_FIELDS) as $name) {
            $this->dropField($name);
        }

        // --- 1b inverse: restore blockquote on the generic content field. -------
        $this->addSql(
            "INSERT INTO `rel_fields_styles` (id_styles, id_fields, default_value, help, disabled, hidden, title)
             SELECT rfs.id_styles, cf.id, rfs.default_value, rfs.help, rfs.disabled, rfs.hidden, rfs.title
             FROM `rel_fields_styles` rfs
             JOIN `styles` s ON s.id = rfs.id_styles AND s.`name` = 'blockquote'
             JOIN `fields` nf ON nf.id = rfs.id_fields AND nf.`name` = 'blockquote_content'
             JOIN `fields` cf ON cf.`name` = 'content'"
        );
        $this->addSql(
            "INSERT INTO `sections_fields_translation` (id_sections, id_fields, id_languages, content, meta)
             SELECT sft.id_sections, cf.id, sft.id_languages, sft.content, sft.meta
             FROM `sections_fields_translation` sft
             JOIN `fields` nf ON nf.id = sft.id_fields AND nf.`name` = 'blockquote_content'
             JOIN `fields` cf ON cf.`name` = 'content'"
        );
        $this->dropField('blockquote_content');

        // --- 1a inverse: list-item back to textarea. ----------------------------
        $this->addSql(
            "UPDATE `fields`
                SET id_field_types = (SELECT id FROM `field_types` WHERE `name` = 'textarea')
              WHERE `name` = 'list_item_content'
                AND id_field_types = (SELECT id FROM `field_types` WHERE `name` = 'markdown-inline')"
        );
    }

    private function createField(string $name, string $type, int $display, ?string $config): void
    {
        $this->addSql(
            "INSERT INTO `fields` (`name`, id_field_types, `display`, `config`)
             SELECT ?, ft.id, ?, ? FROM `field_types` ft WHERE ft.`name` = ?",
            [$name, $display, $config, $type]
        );
    }

    private function dropField(string $name): void
    {
        foreach (self::FIELD_REF_TABLES as $table) {
            $this->addSql(
                "DELETE FROM `$table` WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = ?)",
                [$name]
            );
        }
        $this->addSql('DELETE FROM `fields` WHERE `name` = ?', [$name]);
    }

    private function link(string $style, string $field, ?string $default, ?string $help, ?string $title): void
    {
        $this->addSql(
            "INSERT INTO `rel_fields_styles` (id_styles, id_fields, default_value, help, disabled, hidden, title)
             SELECT s.id, f.id, ?, ?, 0, 0, ?
             FROM `styles` s, `fields` f
             WHERE s.`name` = ? AND f.`name` = ?",
            [$default, $help, $title, $style, $field]
        );
    }

    private function unlink(string $style, string $field): void
    {
        $this->addSql(
            "DELETE FROM `rel_fields_styles`
             WHERE id_styles = (SELECT id FROM `styles` WHERE `name` = ?)
               AND id_fields = (SELECT id FROM `fields` WHERE `name` = ?)",
            [$style, $field]
        );
    }

    private function fieldTypeExists(string $name): bool
    {
        $value = $this->connection->fetchOne('SELECT COUNT(*) FROM `field_types` WHERE `name` = ?', [$name]);

        return is_numeric($value) && (int) $value > 0;
    }
}
