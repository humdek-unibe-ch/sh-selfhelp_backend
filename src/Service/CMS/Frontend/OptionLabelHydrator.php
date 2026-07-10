<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\Frontend;

use App\Service\CMS\CmsPreferenceService;
use App\Service\CMS\FormFieldKeyResolver;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Projects translated labels for stable user-owned enum codes into entry rows.
 *
 * Persisted data remains code-only. The generated `_..._label(s)` keys exist
 * solely during page hydration and are available to interpolation templates.
 */
final class OptionLabelHydrator
{
    /**
     * style name => language-neutral catalog field + optional multi flag field.
     *
     * @var array<string, array{catalog: string, multiple?: string}>
     */
    private const OPTION_STYLES = [
        'select' => [
            'catalog' => 'options',
            'multiple' => 'is_multiple',
        ],
        'radio' => [
            'catalog' => 'radio_options',
        ],
        'combobox' => [
            'catalog' => 'combobox_options',
            'multiple' => 'web_combobox_multi_select',
        ],
        'segmented-control' => [
            'catalog' => 'segmented_control_data',
        ],
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly CmsPreferenceService $cmsPreferenceService,
        private readonly FormFieldKeyResolver $formFieldKeyResolver,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function hydrate(array $rows, string $tableName, int $languageId): array
    {
        if ($rows === [] || !ctype_digit($tableName)) {
            return $rows;
        }

        $ownerSectionId = (int) ltrim($tableName, '0');
        if ($ownerSectionId <= 0) {
            return $rows;
        }

        $fieldDefinitions = $this->resolveFieldDefinitions($ownerSectionId, $languageId);
        if ($fieldDefinitions === []) {
            return $rows;
        }

        $nameToFieldKey = $this->formFieldKeyResolver->getNameToFieldKey($tableName);

        foreach ($rows as &$row) {
            foreach ($fieldDefinitions as $fieldName => $definition) {
                $storageKey = $nameToFieldKey[$fieldName] ?? $fieldName;
                $valueKey = array_key_exists($storageKey, $row)
                    ? $storageKey
                    : (array_key_exists($fieldName, $row) ? $fieldName : null);
                if ($valueKey === null || !is_scalar($row[$valueKey])) {
                    continue;
                }

                $codes = $this->parseCodes((string) $row[$valueKey]);
                if ($codes === []) {
                    continue;
                }

                $labels = array_map(
                    static fn (string $code): string => $definition['labels'][$code] ?? $code,
                    $codes
                );
                $runtimeKey = '_' . $valueKey . ($definition['multiple'] ? '_labels' : '_label');
                if (array_key_exists($runtimeKey, $row)) {
                    throw new \LogicException(sprintf(
                        'Data row field "%s" collides with reserved runtime option label "%s".',
                        $fieldName,
                        $runtimeKey
                    ));
                }

                $row[$runtimeKey] = $definition['multiple']
                    ? implode(', ', $labels)
                    : $labels[0];
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * @return array<string, array<string, string>> field key => code => label
     */
    public function resolveFieldLabelMaps(string $tableName, int $languageId): array
    {
        if (!ctype_digit($tableName)) {
            return [];
        }

        $ownerSectionId = (int) ltrim($tableName, '0');
        if ($ownerSectionId <= 0) {
            return [];
        }

        $fieldDefinitions = $this->resolveFieldDefinitions($ownerSectionId, $languageId);
        $maps = [];
        $nameToFieldKey = $this->formFieldKeyResolver->getNameToFieldKey($tableName);
        foreach ($fieldDefinitions as $fieldName => $definition) {
            $maps[$fieldName] = $definition['labels'];
            if (isset($nameToFieldKey[$fieldName])) {
                $maps[$nameToFieldKey[$fieldName]] = $definition['labels'];
            }
        }

        return $maps;
    }

    /**
     * @return array<string, array{labels: array<string, string>, multiple: bool}>
     */
    private function resolveFieldDefinitions(int $ownerSectionId, int $languageId): array
    {
        $fallbackLanguageId = $this->resolveFallbackLanguageId();
        $languageIds = array_values(array_unique([1, $languageId, $fallbackLanguageId]));
        $fieldNames = ['name', 'option_labels'];
        foreach (self::OPTION_STYLES as $style) {
            $fieldNames[] = $style['catalog'];
            if (isset($style['multiple'])) {
                $fieldNames[] = $style['multiple'];
            }
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
WITH RECURSIVE section_tree AS (
    SELECT id FROM sections WHERE id = :owner_section_id
    UNION ALL
    SELECT rel.id_child_section
    FROM rel_sections_hierarchy rel
    JOIN section_tree tree ON tree.id = rel.id_parent_section
)
SELECT s.id AS section_id,
       styles.name AS style_name,
       fields.name AS field_name,
       translations.id_languages AS language_id,
       translations.content
FROM section_tree tree
JOIN sections s ON s.id = tree.id
JOIN styles ON styles.id = s.id_styles
JOIN sections_fields_translation translations ON translations.id_sections = s.id
JOIN fields ON fields.id = translations.id_fields
WHERE styles.name IN (:style_names)
  AND fields.name IN (:field_names)
  AND translations.id_languages IN (:language_ids)
SQL,
            [
                'owner_section_id' => $ownerSectionId,
                'style_names' => array_keys(self::OPTION_STYLES),
                'field_names' => array_values(array_unique($fieldNames)),
                'language_ids' => $languageIds,
            ],
            [
                'style_names' => ArrayParameterType::STRING,
                'field_names' => ArrayParameterType::STRING,
                'language_ids' => ArrayParameterType::INTEGER,
            ]
        );

        /** @var array<int, array{style: string, fields: array<string, array<int, string>>}> $bySection */
        $bySection = [];
        foreach ($rows as $row) {
            $sectionId = is_numeric($row['section_id'] ?? null) ? (int) $row['section_id'] : 0;
            $styleName = is_string($row['style_name'] ?? null) ? $row['style_name'] : '';
            $fieldName = is_string($row['field_name'] ?? null) ? $row['field_name'] : '';
            $content = is_string($row['content'] ?? null) ? $row['content'] : null;
            $fieldLanguageId = is_numeric($row['language_id'] ?? null) ? (int) $row['language_id'] : 0;
            if ($sectionId <= 0 || !isset(self::OPTION_STYLES[$styleName]) || $fieldName === '' || $content === null) {
                continue;
            }

            $bySection[$sectionId]['style'] = $styleName;
            $bySection[$sectionId]['fields'][$fieldName][$fieldLanguageId] = $content;
        }

        $definitions = [];
        foreach ($bySection as $section) {
            $style = self::OPTION_STYLES[$section['style']];
            $fields = $section['fields'];
            $name = $this->resolveBaseValue($fields['name'] ?? [], $languageId, $fallbackLanguageId);
            $catalogRaw = $this->resolveBaseValue($fields[$style['catalog']] ?? [], $languageId, $fallbackLanguageId);
            if ($name === null || trim($name) === '' || $catalogRaw === null) {
                continue;
            }

            $catalog = json_decode($catalogRaw, true);
            if (!is_array($catalog)) {
                continue;
            }

            $activeLabels = $this->decodeLabelMap($fields['option_labels'][$languageId] ?? null);
            $fallbackLabels = $this->decodeLabelMap($fields['option_labels'][$fallbackLanguageId] ?? null);
            $allLanguageLabels = $this->decodeLabelMap($fields['option_labels'][1] ?? null);
            $labels = [];
            foreach ($catalog as $option) {
                if (!is_array($option) || !isset($option['value']) || !is_scalar($option['value'])) {
                    continue;
                }

                $code = trim((string) $option['value']);
                if ($code === '') {
                    continue;
                }

                $legacyLabel = $option['label'] ?? $option['text'] ?? null;
                $label = $activeLabels[$code]
                    ?? $fallbackLabels[$code]
                    ?? $allLanguageLabels[$code]
                    ?? (is_string($legacyLabel) && trim($legacyLabel) !== '' ? trim($legacyLabel) : $code);
                $labels[$code] = $label;
            }
            if ($labels === []) {
                continue;
            }

            $multiple = false;
            if (isset($style['multiple'])) {
                $multipleRaw = $this->resolveBaseValue(
                    $fields[$style['multiple']] ?? [],
                    $languageId,
                    $fallbackLanguageId
                );
                $multiple = in_array(strtolower((string) $multipleRaw), ['1', 'true', 'on'], true);
            }

            $definitions[trim($name)] = [
                'labels' => $labels,
                'multiple' => $multiple,
            ];
        }

        return $definitions;
    }

    private function resolveFallbackLanguageId(): int
    {
        try {
            return $this->cmsPreferenceService->getDefaultLanguageId() ?? 1;
        } catch (\Throwable) {
            return 1;
        }
    }

    /**
     * Property fields are stored on language 1. The later fallbacks retain
     * compatibility with pre-migration translatable option catalogs.
     *
     * @param array<int, string> $values
     */
    private function resolveBaseValue(array $values, int $languageId, int $fallbackLanguageId): ?string
    {
        return $values[1] ?? $values[$fallbackLanguageId] ?? $values[$languageId] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function decodeLabelMap(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $labels = [];
        foreach ($decoded as $code => $label) {
            if (!is_string($code) || !is_string($label) || trim($label) === '') {
                continue;
            }
            $labels[$code] = trim($label);
        }

        return $labels;
    }

    /**
     * @return list<string>
     */
    private function parseCodes(string $value): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $code): bool => $code !== ''
        ));
    }
}
