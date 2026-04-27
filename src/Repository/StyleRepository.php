<?php

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
     * @return array Returns an array of styles grouped by style group
     */
    public function findAllStylesGroupedByGroup(): array
    {
        $qb = $this->createQueryBuilder('s')
            ->select(
                's.id AS style_id',
                's.name AS style_name',
                's.description AS style_description',
                's.canHaveChildren AS can_have_children',
                'sg.id AS style_group_id',
                'sg.name AS style_group',
                'sg.description AS style_group_description',
                'sg.position AS style_group_position'
            )
            ->leftJoin('s.group', 'sg')
            ->orderBy('sg.position', 'ASC')
            ->addOrderBy('s.name', 'ASC');

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
     * @return array Returns array with allowedChildren and allowedParents for each style
     */
    private function getStylesRelationshipInfo(): array
    {
        $entityManager = $this->getEntityManager();
        $stylesAllowedRelationshipRepository = $entityManager->getRepository(\App\Entity\StylesAllowedRelationship::class);

        // Get all style IDs to query relationships for
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
     */
    public function getAllowedChildrenForStyle(Style $parentStyle): array
    {
        $stylesAllowedRelationshipRepository = $this->getEntityManager()
            ->getRepository(\App\Entity\StylesAllowedRelationship::class);

        return $stylesAllowedRelationshipRepository->findAllowedChildren($parentStyle);
    }

    /**
     * Get all allowed parents for a specific style
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
     * Single-query join over styles → styles_fields → fields → fieldType, then
     * a second query for allowed parent/child relationships (resolved by name).
     *
     * Shape:
     *   [
     *     'styleName' => [
     *       'id' => int,
     *       'group' => string,
     *       'can_have_children' => bool,
     *       'description' => string|null,
     *       'fields' => [
     *         'fieldName' => [
     *           'type' => string,
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
            INNER JOIN styleGroup sg ON sg.id = s.id_group
            LEFT JOIN styles_fields sf ON sf.id_styles = s.id
            LEFT JOIN fields f ON f.id = sf.id_fields
            LEFT JOIN fieldType ft ON ft.id = f.id_type
            ORDER BY s.name ASC, f.name ASC
        ')->fetchAllAssociative();

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
                    'fields' => [],
                    'allowed_children' => [],
                    'allowed_parents' => [],
                ];
            }

            if ($row['field_name'] !== null) {
                [$options, $placeholder] = $this->parseFieldConfig($row['field_config']);
                $schema[$styleName]['fields'][$row['field_name']] = [
                    'type' => $row['field_type'],
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
            FROM styles_allowed_relationships sar
            INNER JOIN styles parent ON parent.id = sar.id_parent_style
            INNER JOIN styles child  ON child.id  = sar.id_child_style
        ')->fetchAllAssociative();

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
                $styleMeta['allowed_children'] = array_values(array_unique($styleMeta['allowed_children']));
            }
            $styleMeta['allowed_parents'] = array_values(array_unique($styleMeta['allowed_parents']));
        }
        unset($styleMeta);

        ksort($schema);
        return $schema;
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
                $options[] = [
                    'value' => (string) $opt['value'],
                    'text' => isset($opt['text']) ? (string) $opt['text'] : (string) $opt['value'],
                ];
            }
        }

        $placeholder = isset($config['placeholder']) ? (string) $config['placeholder'] : null;

        return [$options, $placeholder];
    }
}
