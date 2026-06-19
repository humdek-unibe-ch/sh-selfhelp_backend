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
 * Restrict the cross-platform `shared_size` / `shared_radius` scales to the
 * true common denominator (mobile rendering plan, section 6.2).
 *
 * `shared_*` semantics must render identically on web (Mantine) and mobile
 * (HeroUI Native / React Native). HeroUI Native only has `sm | md | lg`, so the
 * Mantine `xs..xl` size scale cannot be honoured cross-platform. Rather than let
 * the renderer SILENTLY clamp `xs`/`xl` at runtime (which hides the unsupported
 * value), this migration narrows the authored domain once:
 *
 *   - `shared_size`   options -> sm | md | lg
 *   - `shared_radius` options -> none | sm | md | lg | full
 *
 * and normalises any already-stored values (defaults + authored section content)
 * onto the new scale:
 *
 *   size:   xs -> sm, xl -> lg
 *   radius: xs -> sm, xl -> lg, '0' -> none, '50%' -> full
 *
 * Genuinely web-specific `web_size` / `web_radius` keep the full Mantine scale —
 * only the portable `shared_*` fields are narrowed.
 *
 * Fields are addressed by name (stable; renamed away from `mantine_*` by
 * Version20260618143216) and values are migrated by `id_fields`, never by
 * assuming a value is unique to one field. If the `shared_*` fields are absent
 * (migration applied before the rename) every statement simply affects 0 rows.
 *
 * `down()` restores the original `xs..xl` option lists. The value normalisation
 * is intentionally NOT reversed: `sm` could have originally been `xs` or `sm`,
 * so an automatic inverse would be lossy/ambiguous. This is a data-only
 * migration (no DDL), so the schema snapshot is unchanged either way.
 */
final class Version20260618195450 extends AbstractMigration
{
    private const SHARED_SIZE_OPTIONS = '{"options": [{"text": "Small", "value": "sm"}, {"text": "Medium", "value": "md"}, {"text": "Large", "value": "lg"}]}';

    private const SHARED_RADIUS_OPTIONS = '{"options": [{"text": "None", "value": "none"}, {"text": "Small", "value": "sm"}, {"text": "Medium", "value": "md"}, {"text": "Large", "value": "lg"}, {"text": "Full", "value": "full"}]}';

    private const LEGACY_SIZE_OPTIONS = '{"options": [{"text": "Extra Small", "value": "xs"}, {"text": "Small", "value": "sm"}, {"text": "Medium", "value": "md"}, {"text": "Large", "value": "lg"}, {"text": "Extra Large", "value": "xl"}]}';

    private const LEGACY_RADIUS_OPTIONS = '{"options": [{"text": "None", "value": "none"}, {"text": "Extra Small", "value": "xs"}, {"text": "Small", "value": "sm"}, {"text": "Medium", "value": "md"}, {"text": "Large", "value": "lg"}, {"text": "Extra Large", "value": "xl"}]}';

    public function getDescription(): string
    {
        return 'Narrow shared_size to sm|md|lg and shared_radius to none|sm|md|lg|full (common cross-platform scale) and normalise stored values.';
    }

    public function up(Schema $schema): void
    {
        // --- 1. Narrow the editor option lists --------------------------------
        $this->addSql('UPDATE `fields` SET `config` = ? WHERE `name` = ?', [self::SHARED_SIZE_OPTIONS, 'shared_size']);
        $this->addSql('UPDATE `fields` SET `config` = ? WHERE `name` = ?', [self::SHARED_RADIUS_OPTIONS, 'shared_radius']);

        // --- 2. Normalise stored values onto the new scale --------------------
        // Seed defaults (rel_fields_styles.default_value) and authored section
        // content (sections_fields_translation.content) both reference fields by
        // id, so migrate both per field.
        $this->normaliseValue('shared_size', 'xs', 'sm');
        $this->normaliseValue('shared_size', 'xl', 'lg');

        $this->normaliseValue('shared_radius', 'xs', 'sm');
        $this->normaliseValue('shared_radius', 'xl', 'lg');
        $this->normaliseValue('shared_radius', '0', 'none');
        $this->normaliseValue('shared_radius', '50%', 'full');
    }

    public function down(Schema $schema): void
    {
        // Best-effort inverse: restore the original Mantine option lists. Value
        // normalisation is not reversed (sm <- xs|sm is ambiguous).
        $this->addSql('UPDATE `fields` SET `config` = ? WHERE `name` = ?', [self::LEGACY_SIZE_OPTIONS, 'shared_size']);
        $this->addSql('UPDATE `fields` SET `config` = ? WHERE `name` = ?', [self::LEGACY_RADIUS_OPTIONS, 'shared_radius']);
    }

    /**
     * Re-map one stored value of a field (by field name) to a new value in both
     * the seed-default and authored-content tables.
     */
    private function normaliseValue(string $fieldName, string $oldValue, string $newValue): void
    {
        $this->addSql(
            'UPDATE `rel_fields_styles` SET `default_value` = ? '
            . 'WHERE `default_value` = ? AND `id_fields` = (SELECT `id` FROM `fields` WHERE `name` = ?)',
            [$newValue, $oldValue, $fieldName]
        );
        $this->addSql(
            'UPDATE `sections_fields_translation` SET `content` = ? '
            . 'WHERE `content` = ? AND `id_fields` = (SELECT `id` FROM `fields` WHERE `name` = ?)',
            [$newValue, $oldValue, $fieldName]
        );
    }
}
