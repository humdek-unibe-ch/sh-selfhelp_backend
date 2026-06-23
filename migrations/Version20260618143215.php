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
 * Render-target targeting for CMS styles (web / mobile / both).
 *
 * A style declares which client it is intentionally renderable on. This is a
 * cross-repo contract shared with `@selfhelp/shared` (`StyleDefinition.renderTarget`),
 * the frontend `BasicStyle` dispatcher + Add-Section picker, and the mobile
 * renderer. The web renderer silently skips styles that do not target `web`;
 * the mobile renderer skips non-mobile styles. The Add-Section picker hides
 * styles the current page cannot render.
 *
 * Three distinct concepts are kept separate (see docs/reference + the plan):
 *   - request *client*    : web | mobile           (VariableResolverService::getPlatform)
 *   - page *access* target: web | mobile | mobile_and_web (pages.id_page_access_types)
 *   - style *render* target: web | mobile | both   (styles.id_render_target -> this migration)
 *
 * Deliberately NOT touched: `pages` gets no render-target column. The existing
 * `pages.id_page_access_types` lookup is the single source of truth for where a
 * page may load; adding a second page-platform field would create two sources
 * of truth.
 *
 * Mechanism (lookup-backed, mirroring the existing `id_page_access_types` FK):
 *   - `styleRenderTargets` lookup type with `web` / `mobile` / `both` values.
 *   - nullable `styles.id_render_target` FK to `lookups`.
 *   - NULL is treated as `both` by the catalog serializer
 *     ({@see \App\Repository\StyleRepository}) so legacy rows keep rendering
 *     everywhere even before the backfill runs.
 *
 * No backward-compatibility window is needed (nothing shipped): every existing
 * style is explicitly backfilled to `both`. Style-specific render targets are
 * assigned by later, intentional migrations when a real authoring use case
 * exists.
 */
final class Version20260618143215 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Render-target targeting for styles: styleRenderTargets lookup + nullable styles.id_render_target FK + backfill to both.';
    }

    public function up(Schema $schema): void
    {
        // --- Lookups: web / mobile / both -------------------------------------
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `lookups` (`type_code`, `lookup_code`, `lookup_value`, `lookup_description`) VALUES
            ('styleRenderTargets', 'web', 'Web', 'Style renders on the web frontend only.'),
            ('styleRenderTargets', 'mobile', 'Mobile', 'Style renders on the mobile app only.'),
            ('styleRenderTargets', 'both', 'Web + Mobile', 'Style renders on both web and mobile.')
        SQL);

        // --- Schema: nullable id_render_target FK (mirror id_plugins) ---------
        $this->addSql('ALTER TABLE `styles` ADD COLUMN `id_render_target` INT DEFAULT NULL AFTER `id_plugins`');
        $this->addSql('ALTER TABLE `styles` ADD KEY `idx_styles_id_render_target` (`id_render_target`)');
        $this->addSql('ALTER TABLE `styles` ADD CONSTRAINT `fk_styles_id_render_target` FOREIGN KEY (`id_render_target`) REFERENCES `lookups` (`id`) ON DELETE SET NULL');

        // --- Backfill every existing style to `both` -------------------------
        $this->addSql(<<<SQL
            UPDATE `styles` SET `id_render_target` = (
                SELECT `id` FROM `lookups` WHERE `type_code` = 'styleRenderTargets' AND `lookup_code` = 'both'
            ) WHERE `id_render_target` IS NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `styles` DROP FOREIGN KEY `fk_styles_id_render_target`');
        $this->addSql('ALTER TABLE `styles` DROP KEY `idx_styles_id_render_target`');
        $this->addSql('ALTER TABLE `styles` DROP COLUMN `id_render_target`');

        $this->addSql("DELETE FROM `lookups` WHERE `type_code` = 'styleRenderTargets'");
    }
}
