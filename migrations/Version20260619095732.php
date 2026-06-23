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
 * Style field cleanup, slice 6 — mobile configurability (RF-17, RF-18, RF-19).
 * Decision register: docs/reference/styles/style-refactoring-recommendations.md.
 *
 * Promote behaviour/sizing knobs that are portable to BOTH renderers from the
 * web-only `web_*` prefix to the semantic `shared_*` prefix, so the mobile
 * renderer can read the same authored value (per the contract-first model: DB
 * owns semantics, each renderer adapts). Pure presentation twins with no clean
 * React Native peer keep their `web_` prefix (RF-16): `web_textarea_resize`,
 * `web_textarea_variant`, `web_accordion_variant`, `web_accordion_chevron_*`,
 * `web_accordion_loop`, `web_accordion_transition_duration`,
 * `web_accordion_default_value`, `web_accordion_disable_chevron_rotation`.
 *
 *   - RF-17 `select`:  `web_select_searchable`  -> `shared_searchable`
 *                      `web_select_clearable`   -> `shared_clearable`
 *   - RF-18 `textarea`: `web_textarea_autosize` -> `shared_autosize`
 *                       `web_textarea_min_rows` -> `shared_min_rows`
 *                       `web_textarea_max_rows` -> `shared_max_rows`
 *                       `web_textarea_rows`     -> `shared_rows`
 *   - RF-19 `accordion`: `web_accordion_multiple` -> `shared_multiple`
 *
 * RF-20 (`button`/`link` `open_in_new_tab`) needs no DB change — the field is
 * already unprefixed/common and both renderers already read it.
 *
 * Each `fields.name` is global and used by exactly one style (verified), so a
 * straight rename of the `fields` row repoints every `rel_fields_styles` link.
 * Renaming by `id_fields` keeps existing authored section values intact.
 * `down()` restores the original names. Backend `src/` reads none of these.
 */
final class Version20260619095732 extends AbstractMigration
{
    /** @var array<string, string> old field name => new field name */
    private const RENAMES = [
        // RF-17 select
        'web_select_searchable' => 'shared_searchable',
        'web_select_clearable' => 'shared_clearable',
        // RF-18 textarea
        'web_textarea_autosize' => 'shared_autosize',
        'web_textarea_min_rows' => 'shared_min_rows',
        'web_textarea_max_rows' => 'shared_max_rows',
        'web_textarea_rows' => 'shared_rows',
        // RF-19 accordion
        'web_accordion_multiple' => 'shared_multiple',
    ];

    public function getDescription(): string
    {
        return 'Style field cleanup slice 6: promote portable select/textarea/accordion knobs web_* -> shared_* (RF-17/18/19) so the mobile renderer can read them.';
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
