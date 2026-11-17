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
 * The `version` style gets the headings of its "about the platform" block as
 * translatable style fields, so no client hard-codes text: `title` (an
 * existing field, reused as it is) and the seven new text fields
 * `label_frameworks`, `label_plugins`, `label_libraries`, `label_web`,
 * `label_app`, `label_filter` and `label_no_match`. Every link carries the
 * English heading as `default_value`; the en-GB / de-CH texts of the Impressum
 * section come with the next migration.
 *
 * Idempotent (INSERT IGNORE). down() removes the seven new fields, the style's
 * links and every translation of them; the shared `title` field stays.
 */
final class Version20261112164930 extends AbstractMigration
{
    private const STYLE = 'version';
    private const FIELD_TYPE = 'text';

    /**
     * Field => [label in the editor, help, English default heading].
     * `title` exists already (markdown-inline, translatable); the rest is new.
     */
    private const FIELDS = [
        'title' => ['Title', 'Heading of the "about the platform" block.', 'About this platform'],
        'label_frameworks' => ['Frameworks heading', 'Heading of the list of server side frameworks (PHP, Symfony, MySQL, Redis).', 'Frameworks'],
        'label_plugins' => ['Plugins heading', 'Heading of the list of installed plugins.', 'Plugins'],
        'label_libraries' => ['Libraries heading', 'Heading of the list of libraries the backend is built with.', 'Libraries'],
        'label_web' => ['Website heading', 'Heading of the list of libraries the website is built with.', 'Website'],
        'label_app' => ['Mobile app heading', 'Heading of the list of libraries the mobile app is built with.', 'Mobile app'],
        'label_filter' => ['Filter label', 'Placeholder and accessible label of the filter box in the about block.', 'Filter'],
        'label_no_match' => ['No-match message', 'Shown in a tab when the filter matches no row.', 'Nothing matches the filter.'],
    ];

    /** The fields this migration creates (`title` is shared and stays). */
    private const NEW_FIELDS = [
        'label_frameworks',
        'label_plugins',
        'label_libraries',
        'label_web',
        'label_app',
        'label_filter',
        'label_no_match',
    ];

    public function getDescription(): string
    {
        return 'Give the version style translatable headings: title plus label_frameworks, label_plugins, label_libraries, label_web, label_app, label_filter and label_no_match.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::NEW_FIELDS as $field) {
            $this->addSql(
                'INSERT IGNORE INTO `fields` (`name`, `id_field_types`, `display`) '
                . 'SELECT ?, ft.id, 1 FROM `field_types` ft WHERE ft.`name` = ?',
                [$field, self::FIELD_TYPE]
            );
        }

        foreach (self::FIELDS as $field => [$label, $help, $default]) {
            $this->addSql(
                'INSERT IGNORE INTO `rel_fields_styles` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) '
                . 'SELECT s.id, f.id, ?, ?, 0, 0, ? FROM `styles` s JOIN `fields` f ON f.`name` = ? WHERE s.`name` = ?',
                [$default, $help, $label, $field, self::STYLE]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $allNames = array_keys(self::FIELDS);
        $allMarks = implode(', ', array_fill(0, count($allNames), '?'));
        $newMarks = implode(', ', array_fill(0, count(self::NEW_FIELDS), '?'));

        // Texts the style's sections hold for any of its fields (`title`
        // is shared, so only the sections of this style are touched).
        $this->addSql(
            'DELETE sft FROM `sections_fields_translation` sft '
            . 'JOIN `sections` sec ON sec.id = sft.id_sections '
            . 'JOIN `styles` s ON s.id = sec.id_styles AND s.`name` = ? '
            . 'JOIN `fields` f ON f.id = sft.id_fields AND f.`name` IN (' . $allMarks . ')',
            [self::STYLE, ...$allNames]
        );
        // The new fields are removed for good, so no section may keep a text for them.
        $this->addSql(
            'DELETE sft FROM `sections_fields_translation` sft '
            . 'JOIN `fields` f ON f.id = sft.id_fields AND f.`name` IN (' . $newMarks . ')',
            self::NEW_FIELDS
        );
        $this->addSql(
            'DELETE rfs FROM `rel_fields_styles` rfs '
            . 'JOIN `styles` s ON s.id = rfs.id_styles AND s.`name` = ? '
            . 'JOIN `fields` f ON f.id = rfs.id_fields AND f.`name` IN (' . $allMarks . ')',
            [self::STYLE, ...$allNames]
        );
        $this->addSql('DELETE FROM `fields` WHERE `name` IN (' . $newMarks . ')', self::NEW_FIELDS);
    }
}
