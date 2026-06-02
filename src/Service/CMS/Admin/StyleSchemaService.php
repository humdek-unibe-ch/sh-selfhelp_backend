<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


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
 * Backed by CacheService (CATEGORY_STYLES). After any DB change that touches the
 * `styles`, `fields`, `rel_fields_styles`, or `rel_styles_allowed_relationships` tables,
 * invalidate this category directly via:
 *
 *   $cacheService->withCategory(CacheService::CATEGORY_STYLES)->invalidateAllListsInCategory();
 *
 * No bespoke wrapper is provided so cache responsibility stays with the central
 * CacheService API and we don't grow a parallel invalidation surface.
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
                        $fields = $styleMeta['fields'] ?? null;
                        if (!is_array($fields)) {
                            continue;
                        }
                        foreach ($fields as $fieldName => $fieldMeta) {
                            $defaultValue = is_array($fieldMeta) ? ($fieldMeta['default_value'] ?? null) : null;
                            $defaults[$styleName][(string) $fieldName] = is_string($defaultValue) ? $defaultValue : null;
                        }
                    }
                    return $defaults;
                }
            );
    }
}
