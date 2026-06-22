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
 * Field-naming unification (Option B): the `shared_*` field-name prefix is
 * dropped so that "no prefix = applies to both platforms".
 *
 * Rationale: `shared_*` (cross-platform presentation, display=0) and the
 * legacy unprefixed `common` fields (cross-platform behaviour, display=0) are
 * the SAME scope. The prefix was redundant. After this migration the rule is:
 * no prefix = both platforms (translatable when display=1), `web_`/`mobile_` =
 * platform-specific. `fields.name` is globally unique and a pre-flight check
 * confirmed every stripped name is collision-free.
 *
 * 47 of the 50 `shared_*` fields unprefix cleanly. `shared_height`,
 * `shared_width` and `shared_icon` are EXCLUDED: those bare names already exist
 * as page-type fields in the globally-unique `fields` table, so they keep their
 * prefix as documented reserved-name exceptions.
 *
 * This is a cross-repo contract change: `@selfhelp/shared` style types and the
 * frontend/mobile renderers read these fields by name and are renamed in the
 * same wave.
 */
final class Version20260622165615 extends AbstractMigration
{
    /**
     * The `shared_*` suffixes to unprefix. Each `shared_<suffix>` becomes
     * `<suffix>`.
     *
     * NOTE: `height`, `width` and `icon` are deliberately EXCLUDED. Those bare
     * names already exist in the globally-unique `fields` table as page-type
     * fields (page `icon` is live on real pages), so `shared_height`,
     * `shared_width` and `shared_icon` keep their prefix as documented
     * reserved-name exceptions. 47 of the original 50 unprefix cleanly.
     *
     * @var list<string>
     */
    private const SUFFIXES = [
        'accordion_variant', 'align', 'autosize', 'border', 'btn_cancel_color',
        'btn_save_color', 'btn_update_color', 'buttons_order', 'buttons_position',
        'buttons_radius', 'buttons_size', 'buttons_variant', 'chip_variant',
        'clearable', 'color', 'cols', 'direction', 'divider_label_position',
        'divider_variant', 'full_width', 'gap', 'grid_grow', 'grid_offset',
        'grid_order', 'grid_span', 'justify', 'label_position',
        'line_clamp', 'mah', 'maw', 'max_length', 'max_rows', 'mih', 'min_rows',
        'miw', 'multiple', 'orientation', 'radius', 'rows', 'searchable', 'size',
        'spacing', 'text_align', 'variant', 'vertical_spacing',
        'with_close_button', 'wrap',
    ];

    public function getDescription(): string
    {
        return 'Drop the shared_ field-name prefix from 47 fields (Option B: no prefix = both platforms; height/width/icon kept as reserved-name exceptions).';
    }

    public function up(Schema $schema): void
    {
        foreach (self::SUFFIXES as $suffix) {
            $this->addSql(
                'UPDATE fields SET name = ? WHERE name = ?',
                [$suffix, 'shared_' . $suffix]
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::SUFFIXES as $suffix) {
            $this->addSql(
                'UPDATE fields SET name = ? WHERE name = ?',
                ['shared_' . $suffix, $suffix]
            );
        }
    }
}
