<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Repository;

use App\Entity\Style;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Style>
 */
class StyleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Style::class);
    }

    /**
     * Get all styles grouped by their style groups
     *
     * @return list<array<string, mixed>> Returns an array of styles grouped by style group
     */
    public function findAllStylesGroupedByGroup(): array
    {
        $qb = $this->createQueryBuilder('s')
            ->select(
                's.id AS style_id',
                's.name AS style_name',
                's.description AS style_description',
                's.canHaveChildren AS can_have_children',
                'lp.lookupCode AS render_target',
                'sg.id AS style_group_id',
                'sg.name AS style_group',
                'sg.description AS style_group_description',
                'sg.position AS style_group_position'
            )
            ->leftJoin('s.group', 'sg')
            ->leftJoin('s.renderTarget', 'lp')
            ->orderBy('sg.position', 'ASC')
            ->addOrderBy('s.name', 'ASC');

        /** @var list<array{style_id: int, style_name: mixed, style_description: mixed, can_have_children: mixed, render_target: string|null, style_group_id: int, style_group: mixed, style_group_description: mixed, style_group_position: mixed}> $styles */
        $styles = $qb->getQuery()->getArrayResult();

        // Get relationship information for all styles
        $relationships = $this->getStylesRelationshipInfo();

        // Group styles by their style group
        $groupedStyles = [];
        foreach ($styles as $style) {
            $groupId = $style['style_group_id'];
            $styleId = $style['style_id'];

            if (!isset($groupedStyles[$groupId])) {
                $groupedStyles[$groupId] = [
                    'id' => $style['style_group_id'],
                    'name' => $style['style_group'],
                    'description' => $style['style_group_description'],
                    'position' => $style['style_group_position'],
                    'styles' => []
                ];
            }

            $groupedStyles[$groupId]['styles'][] = [
                'id' => $style['style_id'],
                'name' => $style['style_name'],
                'description' => $style['style_description'],
                // NULL render target = legacy/core row that targets every platform.
                'renderTarget' => $style['render_target'] ?? 'both',
                'relationships' => [
                    // If can_have_children is 1 (true), return empty array (can have all children)
                    // If can_have_children is 0 (false), return custom allowed children from relationships
                    'allowedChildren' => $style['can_have_children'] ? [] : ($relationships['allowedChildren'][$styleId] ?? []),
                    'allowedParents' => $relationships['allowedParents'][$styleId] ?? []
                ]
            ];
        }

        // Convert to indexed array and preserve order
        return array_values($groupedStyles);
    }

    /**
     * Get relationship information for all styles
     *
     * @return array{allowedChildren: array<int|string, mixed>, allowedParents: array<int|string, mixed>} Returns array with allowedChildren and allowedParents for each style
     */
    private function getStylesRelationshipInfo(): array
    {
        $entityManager = $this->getEntityManager();
        $stylesAllowedRelationshipRepository = $entityManager->getRepository(\App\Entity\StylesAllowedRelationship::class);

        // Get all style IDs to query relationships for
        /** @var list<int> $styleIds */
        $styleIds = $this->createQueryBuilder('s')
            ->select('s.id')
            ->getQuery()
            ->getSingleColumnResult();

        if (empty($styleIds)) {
            return [
                'allowedChildren' => [],
                'allowedParents' => []
            ];
        }

        // Use the StylesAllowedRelationshipRepository to get relationships
        return $stylesAllowedRelationshipRepository->getRelationshipsForStyles($styleIds);
    }

    /**
     * Check if a parent-child relationship is allowed between two styles
     */
    public function isStyleRelationshipAllowed(Style $parentStyle, Style $childStyle): bool
    {
        $stylesAllowedRelationshipRepository = $this->getEntityManager()
            ->getRepository(\App\Entity\StylesAllowedRelationship::class);

        return $stylesAllowedRelationshipRepository->isRelationshipAllowed($parentStyle, $childStyle);
    }

    /**
     * Get all allowed children for a specific style
     *
     * @return list<array<string, mixed>>
     */
    public function getAllowedChildrenForStyle(Style $parentStyle): array
    {
        $stylesAllowedRelationshipRepository = $this->getEntityManager()
            ->getRepository(\App\Entity\StylesAllowedRelationship::class);

        return $stylesAllowedRelationshipRepository->findAllowedChildren($parentStyle);
    }

    /**
     * Get all allowed parents for a specific style
     *
     * @return list<array<string, mixed>>
     */
    public function getAllowedParentsForStyle(Style $childStyle): array
    {
        $stylesAllowedRelationshipRepository = $this->getEntityManager()
            ->getRepository(\App\Entity\StylesAllowedRelationship::class);

        return $stylesAllowedRelationshipRepository->findAllowedParents($childStyle);
    }

    /**
     * Fetch the full style/field schema for every style, keyed by style name.
     *
     * Single-query join over styles → rel_fields_styles → fields → field_types, then
     * a second query for allowed parent/child relationships (resolved by name).
     *
     * Shape:
     *   [
     *     'styleName' => [
     *       'id' => int,
     *       'group' => string,
     *       'can_have_children' => bool,
     *       'description' => string|null,
     *       'renderTarget' => string,    // 'web' | 'mobile' | 'both' ('both' when id_render_target is NULL)
     *       'fields' => [
     *         'fieldName' => [
     *           'type' => string,
     *           'scope' => string,         // 'content' | 'common' | 'shared' | 'web' | 'mobile' (display + prefix)
     *           'display' => int,          // 0 = internal/property (locale "all"), 1 = translatable/content (real locale)
     *           'default_value' => string|null,
     *           'help' => string|null,
     *           'title' => string|null,
     *           'disabled' => bool,
     *           'hidden' => int,
     *           'options' => array<int, array{value:string, text:string}>, // empty when field has no enum choices
     *           'placeholder' => string|null,
     *         ],
     *         ...
     *       ],
     *       'allowed_children' => ['styleName', ...],  // [] when `can_have_children` is true (allows everything)
     *       'allowed_parents'  => ['styleName', ...],
     *     ],
     *     ...
     *   ]
     *
     * @return array<string, array<string, mixed>>
     */
    public function findAllStylesWithFields(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $rows = $conn->executeQuery('
            SELECT
                s.id               AS style_id,
                s.name             AS style_name,
                s.description      AS style_description,
                s.can_have_children AS can_have_children,
                lp.lookup_code     AS render_target,
                sg.name            AS style_group,
                f.id               AS field_id,
                f.name             AS field_name,
                f.display          AS field_display,
                f.config           AS field_config,
                ft.name            AS field_type,
                sf.default_value   AS default_value,
                sf.help            AS help,
                sf.title           AS title,
                sf.disabled        AS disabled,
                sf.hidden          AS hidden
            FROM styles s
            INNER JOIN style_groups sg ON sg.id = s.id_style_groups
            LEFT JOIN lookups lp ON lp.id = s.id_render_target
            LEFT JOIN rel_fields_styles sf ON sf.id_styles = s.id
            LEFT JOIN fields f ON f.id = sf.id_fields
            LEFT JOIN field_types ft ON ft.id = f.id_field_types
            ORDER BY s.name ASC, f.name ASC
        ')->fetchAllAssociative();
        /** @var list<array{style_id: int|string, style_name: string, style_description: string|null, can_have_children: int|string, render_target: string|null, style_group: string, field_id: int|string|null, field_name: string|null, field_display: int|string|null, field_config: string|null, field_type: string|null, default_value: string|null, help: string|null, title: string|null, disabled: int|string|null, hidden: int|string|null}> $rows */

        // Group into styleName => { ...meta, fields: { fieldName => fieldMeta } }
        $schema = [];
        foreach ($rows as $row) {
            $styleName = $row['style_name'];
            if (!isset($schema[$styleName])) {
                $schema[$styleName] = [
                    'id' => (int) $row['style_id'],
                    'group' => $row['style_group'],
                    'can_have_children' => (bool) $row['can_have_children'],
                    'description' => $row['style_description'],
                    // NULL render target = legacy/core row that targets every platform.
                    'renderTarget' => $row['render_target'] ?? 'both',
                    'fields' => [],
                    'allowed_children' => [],
                    'allowed_parents' => [],
                ];
            }

            if ($row['field_name'] !== null) {
                [$options, $placeholder] = $this->parseFieldConfig($row['field_config']);
                $schema[$styleName]['fields'][$row['field_name']] = [
                    'type' => $row['field_type'],
                    'scope' => self::deriveFieldScope($row['field_name'], (int) $row['field_display']),
                    'display' => (int) $row['field_display'],
                    'default_value' => $row['default_value'],
                    'help' => $row['help'],
                    'title' => $row['title'],
                    'disabled' => (bool) $row['disabled'],
                    'hidden' => (int) ($row['hidden'] ?? 0),
                    'options' => $options,
                    'placeholder' => $placeholder,
                ];
            }
        }

        // Relationship lookup — resolve style ids to names so the schema is self-contained.
        $relRows = $conn->executeQuery('
            SELECT
                parent.name AS parent_name,
                child.name  AS child_name
            FROM rel_styles_allowed_relationships sar
            INNER JOIN styles parent ON parent.id = sar.id_parent_style
            INNER JOIN styles child  ON child.id  = sar.id_child_style
        ')->fetchAllAssociative();
        /** @var list<array{parent_name: string, child_name: string}> $relRows */

        foreach ($relRows as $rel) {
            $parentName = $rel['parent_name'];
            $childName = $rel['child_name'];

            if (isset($schema[$parentName])) {
                $schema[$parentName]['allowed_children'][] = $childName;
            }
            if (isset($schema[$childName])) {
                $schema[$childName]['allowed_parents'][] = $parentName;
            }
        }

        // Styles with can_have_children=true accept any child; keep `allowed_children` empty to signal "any".
        // For styles where can_have_children=false and no explicit relationship exists we leave [] (leaf).
        foreach ($schema as $styleName => &$styleMeta) {
            if ($styleMeta['can_have_children']) {
                $styleMeta['allowed_children'] = []; // empty = unrestricted
            } else {
                // De-duplicate in case of duplicate rows
                /** @var list<string> $allowedChildren */
                $allowedChildren = $styleMeta['allowed_children'];
                $styleMeta['allowed_children'] = array_values(array_unique($allowedChildren));
            }
            /** @var list<string> $allowedParents */
            $allowedParents = $styleMeta['allowed_parents'];
            $styleMeta['allowed_parents'] = array_values(array_unique($allowedParents));
        }
        unset($styleMeta);

        ksort($schema);
        return $schema;
    }

    /**
     * Derive a field's scope from its two independent dimensions — translatability
     * (`display`) and platform prefix. This is the single backend source of truth
     * for field scope (mobile rendering plan, section 6.4); the CMS frontend must
     * consume the emitted `scope` and must not re-derive it from the field name,
     * `display`, or a prefix.
     *
     * Translatability wins first: a translatable field (`display === 1`) is always
     * authored content, regardless of any prefix, so it is grouped in the
     * Content/Translations card. Property fields (`display === 0`) are then split
     * by platform prefix:
     *
     *   `display === 1`            -> content (translatable, locale-scoped copy)
     *   `display === 0`, `shared_*`-> shared  (portable visual semantics)
     *   `display === 0`, `web_*`   -> web     (Mantine / browser presentation)
     *   `display === 0`, `mobile_*`-> mobile  (HeroUI Native / native presentation)
     *   `display === 0`, otherwise -> common  (cross-platform behavior/data)
     *
     * @return 'content'|'common'|'shared'|'web'|'mobile'
     */
    public static function deriveFieldScope(string $fieldName, int $display): string
    {
        // Dimension 1 — translatable content always groups as content.
        if ($display === 1) {
            return 'content';
        }
        // Dimension 2 — property platform scope by canonical prefix.
        if (str_starts_with($fieldName, 'shared_')) {
            return 'shared';
        }
        if (str_starts_with($fieldName, 'web_')) {
            return 'web';
        }
        if (str_starts_with($fieldName, 'mobile_')) {
            return 'mobile';
        }
        return 'common';
    }

    /**
     * Decode the `fields.config` JSON column and extract the enum options plus
     * an optional placeholder hint. The column is stored as JSON in the DB
     * (or sometimes as a raw JSON string) so we tolerate both shapes.
     *
     * @return array{0: array<int, array{value:string,text:string}>, 1: ?string}
     *               Tuple of [options, placeholder].
     */
    private function parseFieldConfig(mixed $rawConfig): array
    {
        if ($rawConfig === null || $rawConfig === '' || $rawConfig === 'null') {
            return [[], null];
        }

        $config = is_string($rawConfig) ? json_decode($rawConfig, true) : $rawConfig;
        if (!is_array($config)) {
            return [[], null];
        }

        $options = [];
        if (isset($config['options']) && is_array($config['options'])) {
            foreach ($config['options'] as $opt) {
                if (!is_array($opt) || !array_key_exists('value', $opt)) {
                    continue;
                }
                $value = $opt['value'];
                $text = $opt['text'] ?? $value;
                $options[] = [
                    'value' => is_scalar($value) ? (string) $value : '',
                    'text' => is_scalar($text) ? (string) $text : '',
                ];
            }
        }

        $placeholder = isset($config['placeholder']) && is_scalar($config['placeholder'])
            ? (string) $config['placeholder']
            : null;

        return [$options, $placeholder];
    }
}
