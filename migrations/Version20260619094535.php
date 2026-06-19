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
 * Style field cleanup, slice 4 — DB <-> shared-type reconciliation (seed the
 * fields the shared types + renderers already expect but the catalog was
 * missing). Decision register:
 * docs/reference/styles/style-refactoring-recommendations.md (RF-22, RF-23).
 *
 * Every field seeded here is already declared in `@selfhelp/shared` AND read by
 * a renderer with the exact default copy below, so this is purely additive: it
 * surfaces the fields in the CMS editor (with sensible defaults) without any
 * coupled shared / web / mobile code change.
 *
 *   - RF-22  `profile`: seed the 8 `profile_timezone_change_*` fields. The web
 *            `ProfileStyle` reads all eight with the hard-coded fallbacks
 *            replicated here as `default_value`. These names are profile-specific
 *            so the fields are created and linked.
 *   - RF-23  `two-factor-auth`: link the already-global `title`, `label_submit`,
 *            and `label_code` fields. The web `TwoFactorAuthStyle` reads `title`
 *            / `label_submit`; the mobile `TwoFactorAuth` reads `label_code` /
 *            `label_submit` — all with the defaults below. The fields already
 *            exist (shared by other styles) so only the `rel_fields_styles`
 *            links are added.
 *
 * Deliberately NOT touched (runtime evidence contradicts the original register
 * hypotheses — handled in a later, design-led slice):
 *   - `profile` `alert_fail` / `alert_del_fail` / `alert_del_success` /
 *     `alert_success`: declared in the shared type but read by NO renderer
 *     (web or mobile). They are stale type fields to be dropped from the type,
 *     not seeded into the catalog.
 *   - `validate` cancel URL: the web renderer reads `cancel_url` while the mobile
 *     renderer reads `btn_cancel_url` — a real cross-renderer naming drift that
 *     must be unified before seeding. `page_keyword` / `value_name` look
 *     backend-only (no renderer reads them) and need backend verification.
 *
 * `down()` removes the seeded `rel_fields_styles` links and deletes the
 * profile-specific fields it created (the shared `title` / `label_submit` /
 * `label_code` fields are left intact because other styles use them).
 */
final class Version20260619094535 extends AbstractMigration
{
    /**
     * Profile timezone-change fields to CREATE + link (profile-specific names).
     *
     * @var array<string, array{type: string, default: string, help: string}>
     */
    private const PROFILE_TZ_FIELDS = [
        'profile_timezone_change_title' => [
            'type' => 'text',
            'default' => 'Change Timezone',
            'help' => 'Heading for the timezone-change section of the profile page.',
        ],
        'profile_timezone_change_description' => [
            'type' => 'textarea',
            'default' => '<p>Select your preferred timezone. This will affect how dates and times are displayed.</p>',
            'help' => 'Intro text shown above the timezone selector (HTML allowed).',
        ],
        'profile_timezone_change_label' => [
            'type' => 'text',
            'default' => 'Timezone',
            'help' => 'Label for the timezone select input.',
        ],
        'profile_timezone_change_placeholder' => [
            'type' => 'text',
            'default' => 'Select a timezone',
            'help' => 'Placeholder for the timezone select input.',
        ],
        'profile_timezone_change_button' => [
            'type' => 'text',
            'default' => 'Update Timezone',
            'help' => 'Label for the update-timezone button.',
        ],
        'profile_timezone_change_success' => [
            'type' => 'text',
            'default' => 'Timezone updated successfully!',
            'help' => 'Success message shown after the timezone is updated.',
        ],
        'profile_timezone_change_error_required' => [
            'type' => 'text',
            'default' => 'Timezone is required',
            'help' => 'Validation message shown when no timezone is selected.',
        ],
        'profile_timezone_change_error_general' => [
            'type' => 'text',
            'default' => 'Failed to update timezone. Please try again.',
            'help' => 'Error message shown when the timezone update fails.',
        ],
    ];

    /**
     * Existing global fields to LINK to `two-factor-auth` (field already exists).
     *
     * @var array<string, array{default: string, help: string}>
     */
    private const TWO_FACTOR_LINKS = [
        'title' => [
            'default' => 'Two-Factor Authentication',
            'help' => 'Heading for the two-factor authentication form.',
        ],
        'label_submit' => [
            'default' => 'Verify',
            'help' => 'Label for the verify / submit button.',
        ],
        'label_code' => [
            'default' => 'Code',
            'help' => 'Label for the 2FA code input.',
        ],
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
        return 'Style field cleanup slice 4: seed profile timezone-change fields (RF-22) and link two-factor-auth title/label_submit/label_code (RF-23) so the catalog matches the shared types + renderers.';
    }

    public function up(Schema $schema): void
    {
        foreach (array_keys(self::PROFILE_TZ_FIELDS) as $name) {
            $this->abortIf(
                $this->fieldExists($name),
                sprintf("Refusing seed: field '%s' already exists.", $name)
            );
        }

        // RF-22 — create the profile-specific timezone fields and link them.
        foreach (self::PROFILE_TZ_FIELDS as $name => $info) {
            $this->addSql(
                "INSERT INTO `fields` (`name`, id_field_types, `display`)
                 SELECT ?, ft.id, 1 FROM `field_types` ft WHERE ft.`name` = ?",
                [$name, $info['type']]
            );
            $this->addSql(
                "INSERT INTO `rel_fields_styles` (id_styles, id_fields, default_value, help, disabled, hidden)
                 SELECT s.id, f.id, ?, ?, 0, 0
                 FROM `styles` s, `fields` f
                 WHERE s.`name` = 'profile' AND f.`name` = ?",
                [$info['default'], $info['help'], $name]
            );
        }

        // RF-23 — link the existing global fields to two-factor-auth.
        foreach (self::TWO_FACTOR_LINKS as $name => $info) {
            $this->addSql(
                "INSERT INTO `rel_fields_styles` (id_styles, id_fields, default_value, help, disabled, hidden)
                 SELECT s.id, f.id, ?, ?, 0, 0
                 FROM `styles` s, `fields` f
                 WHERE s.`name` = 'two-factor-auth' AND f.`name` = ?
                   AND NOT EXISTS (
                       SELECT 1 FROM `rel_fields_styles` r
                       WHERE r.id_styles = s.id AND r.id_fields = f.id
                   )",
                [$info['default'], $info['help'], $name]
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Remove the two-factor-auth links (keep the shared fields themselves).
        foreach (array_keys(self::TWO_FACTOR_LINKS) as $name) {
            $this->addSql(
                "DELETE FROM `rel_fields_styles`
                 WHERE id_styles = (SELECT id FROM `styles` WHERE `name` = 'two-factor-auth')
                   AND id_fields = (SELECT id FROM `fields` WHERE `name` = ?)",
                [$name]
            );
        }

        // Remove the profile timezone fields entirely (FK-safe), reversing up().
        foreach (array_keys(self::PROFILE_TZ_FIELDS) as $name) {
            foreach (self::FIELD_REF_TABLES as $table) {
                $this->addSql(
                    "DELETE FROM `$table` WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = ?)",
                    [$name]
                );
            }
            $this->addSql('DELETE FROM `fields` WHERE `name` = ?', [$name]);
        }
    }

    private function fieldExists(string $name): bool
    {
        $value = $this->connection->fetchOne('SELECT COUNT(*) FROM `fields` WHERE `name` = ?', [$name]);

        return is_numeric($value) && (int) $value > 0;
    }
}
