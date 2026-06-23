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
 * Style field cleanup, slice 7 — form/validate button knobs (RF-21).
 * Decision register: docs/reference/styles/style-refactoring-recommendations.md.
 *
 * `form-log` / `form-record` / `validate` are custom composite styles (not 1:1
 * component maps), so the mobile renderer builds its own form. Promote the
 * meaningful button knobs from `web_*` to `shared_*` so the mobile custom form
 * reads the same authored config as the web Mantine form. Pure web cosmetics
 * (`web_card_padding`, `web_card_shadow`, `web_border` on `validate`) keep their
 * `web_` prefix (RF-16 — no clean RN peer).
 *
 *   web_buttons_size      -> shared_buttons_size
 *   web_buttons_radius    -> shared_buttons_radius
 *   web_buttons_variant   -> shared_buttons_variant
 *   web_buttons_position  -> shared_buttons_position
 *   web_buttons_order     -> shared_buttons_order
 *   web_btn_save_color    -> shared_btn_save_color
 *   web_btn_cancel_color  -> shared_btn_cancel_color
 *   web_btn_update_color  -> shared_btn_update_color   (form-record only)
 *
 * Each `fields.name` is global and used only by the three form styles
 * (verified), so renaming the `fields` row repoints every `rel_fields_styles`
 * link and keeps authored section values intact. This rename also fixes a latent
 * web bug: `FormStyle` read the un-prefixed `buttons_*`/`btn_*_color` names that
 * never matched the catalog, so its button styling silently fell back to
 * defaults. `down()` restores the original names. Backend `src/` reads none.
 */
final class Version20260619100044 extends AbstractMigration
{
    /** @var array<string, string> old field name => new field name */
    private const RENAMES = [
        'web_buttons_size' => 'shared_buttons_size',
        'web_buttons_radius' => 'shared_buttons_radius',
        'web_buttons_variant' => 'shared_buttons_variant',
        'web_buttons_position' => 'shared_buttons_position',
        'web_buttons_order' => 'shared_buttons_order',
        'web_btn_save_color' => 'shared_btn_save_color',
        'web_btn_cancel_color' => 'shared_btn_cancel_color',
        'web_btn_update_color' => 'shared_btn_update_color',
    ];

    public function getDescription(): string
    {
        return 'Style field cleanup slice 7: promote form/validate button knobs web_* -> shared_* (RF-21) so the mobile custom form reads the same config as web.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::RENAMES as $old => $new) {
            $this->abortIf(
                $this->fieldExists($new),
                sprintf("Refusing rename: target field '%s' already exists.", $new)
            );
        }

        foreach (self::RENAMES as $old => $new) {
            $this->addSql('UPDATE `fields` SET `name` = ? WHERE `name` = ?', [$new, $old]);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::RENAMES as $old => $new) {
            $this->addSql('UPDATE `fields` SET `name` = ? WHERE `name` = ?', [$old, $new]);
        }
    }

    private function fieldExists(string $name): bool
    {
        $value = $this->connection->fetchOne('SELECT COUNT(*) FROM `fields` WHERE `name` = ?', [$name]);

        return is_numeric($value) && (int) $value > 0;
    }
}
