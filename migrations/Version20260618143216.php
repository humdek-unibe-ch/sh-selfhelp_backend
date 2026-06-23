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
 * Re-prefix CMS style fields into the cross-platform naming taxonomy.
 *
 * Target design (see the mobile rendering plan, section 6):
 *   - content/behavior fields stay UNPREFIXED (`label`, `value`, `disabled`, …);
 *   - portable visual semantics become `shared_*` (mapped per platform);
 *   - genuinely web/Mantine-specific presentation becomes `web_*`;
 *   - native-only presentation becomes `mobile_*` (added by later migrations).
 *
 * This REPLACES the earlier blanket `REPLACE(name,'mantine_','web_')` codemod,
 * which was unsafe: it had no classification (it would have buried portable
 * semantics under `web_`), no collision detection, and no field-type fix. This
 * version is explicit and guarded:
 *   1. abort if any target name (shared_* / new field types) already exists;
 *   2. promote the 11 portable semantic fields `mantine_X -> shared_X`;
 *   3. rename the web toggle `use_mantine_style -> use_web_style`;
 *   4. rename every remaining `mantine_*` field to `web_*` (collision-checked);
 *   5. rename the spacing field *types* to renderer-neutral names
 *      (`mantine_spacing_margin_padding -> spacing`,
 *       `mantine_spacing_margin -> spacing-margin`) per plan section 6.4.
 *
 * Field links (`rel_fields_styles.id_fields`), user data (`data_cells.id_fields`),
 * and translations reference fields by id, not name, so renaming the `name`
 * column never touches a relationship.
 *
 * Scope note: the margin-only field (`mantine_spacing_margin`) keeps a `web_`
 * prefix rather than being consolidated into `shared_spacing`. Consolidating the
 * two legacy spacing systems requires a per-style value merge (39 + 37 style
 * links) and is intentionally deferred to a dedicated migration so this rename
 * stays lossless and reversible.
 *
 * `down()` is a best-effort inverse for local rollback.
 */
final class Version20260618143216 extends AbstractMigration
{
    /** Portable visual semantics: `mantine_X` is promoted to `shared_X`. */
    private const SHARED_RENAMES = [
        'mantine_align' => 'shared_align',
        'mantine_justify' => 'shared_justify',
        'mantine_gap' => 'shared_gap',
        'mantine_direction' => 'shared_direction',
        'mantine_wrap' => 'shared_wrap',
        'mantine_orientation' => 'shared_orientation',
        'mantine_fullwidth' => 'shared_full_width',
        'mantine_size' => 'shared_size',
        'mantine_radius' => 'shared_radius',
        'mantine_text_align' => 'shared_text_align',
        'mantine_spacing_margin_padding' => 'shared_spacing',
    ];

    /** Spacing editor field *types* become renderer-neutral (plan section 6.4). */
    private const FIELD_TYPE_RENAMES = [
        'mantine_spacing_margin_padding' => 'spacing',
        'mantine_spacing_margin' => 'spacing-margin',
    ];

    public function getDescription(): string
    {
        return 'Re-prefix CMS style fields into the shared_/web_ taxonomy (explicit, collision-checked) + neutral spacing field types.';
    }

    public function up(Schema $schema): void
    {
        // --- 1. Guard: target namespaces must be clear ------------------------
        $sharedTargets = array_values(self::SHARED_RENAMES);
        $this->abortIf(
            $this->countFieldsNamed($sharedTargets) > 0,
            'Refusing field rename: a shared_* target name already exists in `fields`.'
        );

        $typeTargets = array_values(self::FIELD_TYPE_RENAMES);
        $this->abortIf(
            $this->countFieldTypesNamed($typeTargets) > 0,
            'Refusing field rename: a target spacing field type already exists in `field_types`.'
        );

        // A `mantine_X` (that is NOT promoted to shared_) must not collide with
        // an existing `web_X`.
        $sharedSources = array_keys(self::SHARED_RENAMES);
        $placeholders = implode(',', array_fill(0, count($sharedSources), '?'));
        $collisions = $this->fetchCount(
            "SELECT COUNT(*) FROM `fields` a
             JOIN `fields` b ON b.`name` = CONCAT('web_', SUBSTRING(a.`name`, 9))
             WHERE a.`name` LIKE 'mantine\\_%' AND a.`name` NOT IN ($placeholders)",
            $sharedSources
        );
        $this->abortIf($collisions > 0, 'Refusing field rename: a mantine_ -> web_ rename would collide with an existing web_ field.');

        // --- 2. Promote portable semantics: mantine_X -> shared_X -------------
        foreach (self::SHARED_RENAMES as $old => $new) {
            $this->addSql('UPDATE `fields` SET `name` = ? WHERE `name` = ?', [$new, $old]);
        }

        // --- 3. Web toggle: use_mantine_style -> use_web_style ---------------
        $this->addSql("UPDATE `fields` SET `name` = 'use_web_style' WHERE `name` = 'use_mantine_style'");

        // --- 4. Remaining web/Mantine fields: mantine_* -> web_* -------------
        // The shared sources are already renamed away by step 2, so they no
        // longer match this prefix.
        $this->addSql("UPDATE `fields` SET `name` = CONCAT('web_', SUBSTRING(`name`, 9)) WHERE `name` LIKE 'mantine\\_%'");

        // --- 5. Neutral spacing field types ----------------------------------
        foreach (self::FIELD_TYPE_RENAMES as $old => $new) {
            $this->addSql('UPDATE `field_types` SET `name` = ? WHERE `name` = ?', [$new, $old]);
        }
    }

    public function down(Schema $schema): void
    {
        // Reverse field types first (independent of field names).
        foreach (self::FIELD_TYPE_RENAMES as $old => $new) {
            $this->addSql('UPDATE `field_types` SET `name` = ? WHERE `name` = ?', [$old, $new]);
        }

        // Reverse the bulk web_* -> mantine_* (also restores web_spacing_margin).
        $this->addSql("UPDATE `fields` SET `name` = CONCAT('mantine_', SUBSTRING(`name`, 5)) WHERE `name` LIKE 'web\\_%'");

        // Reverse the shared_* promotions.
        foreach (self::SHARED_RENAMES as $old => $new) {
            $this->addSql('UPDATE `fields` SET `name` = ? WHERE `name` = ?', [$old, $new]);
        }

        // Reverse the web toggle.
        $this->addSql("UPDATE `fields` SET `name` = 'use_mantine_style' WHERE `name` = 'use_web_style'");
    }

    /**
     * @param list<string> $names
     */
    private function countFieldsNamed(array $names): int
    {
        if ($names === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        return $this->fetchCount(
            "SELECT COUNT(*) FROM `fields` WHERE `name` IN ($placeholders)",
            $names
        );
    }

    /**
     * @param list<string> $names
     */
    private function countFieldTypesNamed(array $names): int
    {
        if ($names === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        return $this->fetchCount(
            "SELECT COUNT(*) FROM `field_types` WHERE `name` IN ($placeholders)",
            $names
        );
    }

    /**
     * @param list<string> $params
     */
    private function fetchCount(string $sql, array $params = []): int
    {
        $value = $this->connection->fetchOne($sql, $params);

        return is_numeric($value) ? (int) $value : 0;
    }
}
