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
 * Style field cleanup, slice 5 — DB <-> shared-type reconciliation tail.
 * Decision register: docs/reference/styles/style-refactoring-recommendations.md
 * (RF-12, RF-23).
 *
 * Runtime evidence (web `TwoFactorAuthStyle` + mobile `TwoFactorAuth`) shows the
 * 2FA heading is rendered from `title` (web) / `label_title` -> unified to `title`
 * (mobile). Slice 4 already seeded `title` on `two-factor-auth`. The legacy
 * `label` link (id_fields = the shared `label` field, default "Two-Factor
 * Authentication") is read by NO renderer and is now superseded by `title`, so it
 * is dropped here to declutter the editor.
 *
 * Only the `rel_fields_styles` LINK is removed — the global `label` field itself
 * is left intact because many other styles use it.
 *
 * The rest of slice 5 is type-only (`@selfhelp/shared` 1.14.1: drop stale
 * `IProfileStyle.alert_*` / `IValidateStyle` fields, rename `cancel_url` ->
 * `btn_cancel_url`) plus the coupled web/mobile renderer reads, so no other DB
 * change is required (the catalog already carries `btn_cancel_url` on `validate`).
 */
final class Version20260619095112 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Style field cleanup slice 5: drop the unused legacy two-factor-auth -> label link (heading is now rendered from title, seeded in slice 4).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "DELETE FROM `rel_fields_styles`
             WHERE id_styles = (SELECT id FROM `styles` WHERE `name` = 'two-factor-auth')
               AND id_fields = (SELECT id FROM `fields` WHERE `name` = 'label')"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "INSERT INTO `rel_fields_styles` (id_styles, id_fields, default_value, help, disabled, hidden)
             SELECT s.id, f.id, 'Two-Factor Authentication',
                    'The main heading displayed at the top of the two-factor authentication form.', 0, 0
             FROM `styles` s, `fields` f
             WHERE s.`name` = 'two-factor-auth' AND f.`name` = 'label'
               AND NOT EXISTS (
                   SELECT 1 FROM `rel_fields_styles` r
                   WHERE r.id_styles = s.id AND r.id_fields = f.id
               )"
        );
    }
}
