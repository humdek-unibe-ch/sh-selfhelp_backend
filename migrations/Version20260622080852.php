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
 * Layout styles: set the per-style field labels (`rel_fields_styles.title`) that
 * the previous layout pass (`Version20260622063129`) left empty.
 *
 * The section inspector shows `rel_fields_styles.title` as a field's label and
 * falls back to the raw `fields.name` when it is empty. The new layout links
 * (shared_width/shared_height/paper.title/paper.shared_border/space.shared_orientation
 * /simple-grid.shared_gap/web_cols_sm|md|lg) were inserted without a title, so
 * they rendered as the raw snake_case name ("shared_width") instead of "Width".
 * This sets the missing labels to match the convention used by the established
 * links (e.g. card.shared_border = "Border", flex.shared_gap = "Gap").
 *
 * Each UPDATE only fills rows whose title is still empty, so any label set by
 * hand is preserved. down() clears just these links back to NULL.
 */
final class Version20260622080852 extends AbstractMigration
{
    /** Layout styles linked to shared_width by Version20260622063129. */
    private const WIDTH_STYLES = ['center', 'flex', 'grid', 'grid-column', 'group', 'simple-grid', 'stack'];

    /** Layout styles linked to shared_height by Version20260622063129. */
    private const HEIGHT_STYLES = ['center', 'flex', 'grid', 'grid-column', 'group', 'scroll-area', 'simple-grid', 'stack'];

    public function getDescription(): string
    {
        return 'Set missing rel_fields_styles.title labels for the layout fields linked by Version20260622063129 (shared_width/height, paper.title/border, space.orientation, simple-grid gap + responsive cols).';
    }

    public function up(Schema $schema): void
    {
        foreach (self::WIDTH_STYLES as $style) {
            $this->setTitle($style, 'shared_width', 'Width');
        }
        foreach (self::HEIGHT_STYLES as $style) {
            $this->setTitle($style, 'shared_height', 'Height');
        }
        $this->setTitle('paper', 'shared_border', 'Border');
        $this->setTitle('paper', 'title', 'Title');
        $this->setTitle('space', 'shared_orientation', 'Orientation');
        $this->setTitle('simple-grid', 'shared_gap', 'Gap');
        $this->setTitle('simple-grid', 'web_cols_sm', 'Columns (SM)');
        $this->setTitle('simple-grid', 'web_cols_md', 'Columns (MD)');
        $this->setTitle('simple-grid', 'web_cols_lg', 'Columns (LG)');
    }

    public function down(Schema $schema): void
    {
        foreach (self::WIDTH_STYLES as $style) {
            $this->clearTitle($style, 'shared_width');
        }
        foreach (self::HEIGHT_STYLES as $style) {
            $this->clearTitle($style, 'shared_height');
        }
        $this->clearTitle('paper', 'shared_border');
        $this->clearTitle('paper', 'title');
        $this->clearTitle('space', 'shared_orientation');
        $this->clearTitle('simple-grid', 'shared_gap');
        $this->clearTitle('simple-grid', 'web_cols_sm');
        $this->clearTitle('simple-grid', 'web_cols_md');
        $this->clearTitle('simple-grid', 'web_cols_lg');
    }

    /** Fill the per-style field label only when it is still empty. */
    private function setTitle(string $style, string $field, string $title): void
    {
        $this->addSql(
            "UPDATE `rel_fields_styles`
             SET `title` = ?
             WHERE id_styles = (SELECT id FROM `styles` WHERE `name` = ?)
               AND id_fields = (SELECT id FROM `fields` WHERE `name` = ?)
               AND (`title` IS NULL OR `title` = '')",
            [$title, $style, $field]
        );
    }

    private function clearTitle(string $style, string $field): void
    {
        $this->addSql(
            "UPDATE `rel_fields_styles`
             SET `title` = NULL
             WHERE id_styles = (SELECT id FROM `styles` WHERE `name` = ?)
               AND id_fields = (SELECT id FROM `fields` WHERE `name` = ?)",
            [$style, $field]
        );
    }
}
