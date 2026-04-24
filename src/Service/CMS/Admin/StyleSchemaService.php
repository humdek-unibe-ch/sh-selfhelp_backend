<?php

namespace App\Service\CMS\Admin;

use App\Repository\StyleRepository;
use App\Service\Cache\Core\CacheService;

/**
 * Central read-only accessor for the full CMS style schema.
 *
 * Exposes:
 * - getSchema(): the full { styleName => { fields, allowed_children, ... } } map
 * - getDefaultValuesByStyleName(): a per-style [fieldName => default_value] map used by
 *   SectionExportImportService to minimize exports and reason about defaults during imports.
 *
 * Backed by CacheService (CATEGORY_STYLES). Re-query by clearing the `styles` cache category
 * after any change to the `styles`, `fields`, `styles_fields`, or `styles_allowed_relationships`
 * tables (e.g. after running a DB migration).
 */
class StyleSchemaService
{
    private const CACHE_KEY_FULL = 'styles_schema_full';
    private const CACHE_KEY_DEFAULTS = 'styles_schema_defaults_by_name';

    public function __construct(
        private readonly StyleRepository $styleRepository,
        private readonly CacheService $cache
    ) {
    }

    /**
     * Get the full style schema keyed by style name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getSchema(): array
    {
        return $this->cache
            ->withCategory(CacheService::CATEGORY_STYLES)
            ->getList(
                self::CACHE_KEY_FULL,
                fn () => $this->styleRepository->findAllStylesWithFields()
            );
    }

    /**
     * Get a lookup map: styleName => [fieldName => default_value].
     * Used by export minimization to strip translations equal to the DB default.
     *
     * @return array<string, array<string, ?string>>
     */
    public function getDefaultValuesByStyleName(): array
    {
        return $this->cache
            ->withCategory(CacheService::CATEGORY_STYLES)
            ->getList(
                self::CACHE_KEY_DEFAULTS,
                function () {
                    $defaults = [];
                    foreach ($this->getSchema() as $styleName => $styleMeta) {
                        $defaults[$styleName] = [];
                        foreach ($styleMeta['fields'] as $fieldName => $fieldMeta) {
                            $defaults[$styleName][$fieldName] = $fieldMeta['default_value'];
                        }
                    }
                    return $defaults;
                }
            );
    }

    /**
     * Return only the valid field names for a given style. Empty list means unknown style.
     *
     * @return string[]
     */
    public function getFieldNamesForStyle(string $styleName): array
    {
        $schema = $this->getSchema();
        if (!isset($schema[$styleName])) {
            return [];
        }
        return array_keys($schema[$styleName]['fields']);
    }

    /**
     * Invalidate cached style schemas. Call this after any DB change that affects styles or fields.
     */
    public function invalidateCache(): void
    {
        $this->cache
            ->withCategory(CacheService::CATEGORY_STYLES)
            ->invalidateAllListsInCategory();
    }
}
