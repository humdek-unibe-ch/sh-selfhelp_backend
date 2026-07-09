<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS\Admin;

use App\Entity\Language;
use App\Entity\Page;
use App\Entity\Section;
use App\Entity\SectionsFieldsTranslation;
use App\Exception\ServiceException;
use App\Entity\CmsApp;
use App\Repository\CmsAppRepository;
use App\Repository\PageRepository;
use App\Repository\SectionRepository;
use App\Repository\StyleRepository;
use App\Routing\RouteConflictValidator;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\Common\StyleNames;
use App\Service\CMS\DataService;
use App\Service\CMS\DataTableService;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;
use App\Service\Core\UserContextAwareService;
use App\Service\System\SystemInstanceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Full page export/import with multi-page bundles (issue #30, Phase 5).
 *
 * Wraps {@see SectionExportImportService} (which already serializes/recreates the
 * nested section + style + config + translation tree) and adds the page-level
 * envelope the section exporter does not cover: page metadata, `page_surface`,
 * the `page_routes[]` contract, and page content fields. The result is a single
 * portable JSON "page bundle" that recreates a CMS-in-CMS pattern (e.g. a public
 * `/team` + `/team/{record_id}` pair plus their `/cms/...` admin pair) on
 * another install.
 *
 * Import is a dry-run-validated, transactional, id-remapping recreate: pages are
 * created first (so cross-page parent links resolve), then each page's routes
 * and section tree are materialized. Route param NAMES (`record_id`, `user_id`,
 * `token`) are part of the contract and are NEVER remapped — only database ids
 * are.
 */
class PageExportImportService extends BaseService
{
    public const BUNDLE_FORMAT = 'selfhelp/page-bundle';
    public const BUNDLE_VERSION = '2.0';

    /**
     * Symbolic owner token written into a portable bundle in place of a numeric
     * `data_config.table` that points at an in-bundle form section. The numeric
     * section id is install-specific and changes on import, so the export rewrites
     * `"table":"123"` to `"table":"@section:<owner section name>"` and the import
     * resolves it back to the freshly-created form section's id (issue #30).
     */
    private const OWNER_TOKEN_PREFIX = '@section:';

    /**
     * Content field names that carry an in-app URL and therefore must follow
     * the route prefix on import: the `link`/`button` target, the entry-table
     * CRUD URLs, and the form redirect / cancel targets.
     */
    private const URL_FIELD_NAMES = [
        'url', 'add_url', 'edit_url', 'btn_cancel_url', 'cancel_url',
        'url_cancel', 'redirect_at_end', 'redirect_on_save',
    ];

    private const ISSUE_ERROR = 'error';
    private const ISSUE_WARNING = 'warning';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PageRepository $pageRepository,
        private readonly SectionRepository $sectionRepository,
        private readonly StyleRepository $styleRepository,
        private readonly RouteConflictValidator $conflictValidator,
        private readonly PageRouteService $pageRouteService,
        private readonly PageFieldService $pageFieldService,
        private readonly AdminPageService $adminPageService,
        private readonly SectionExportImportService $sectionExportImportService,
        private readonly ExampleBundlePathResolver $exampleBundlePathResolver,
        private readonly DataService $dataService,
        private readonly DataTableService $dataTableService,
        private readonly UserContextAwareService $userContextAwareService,
        private readonly SystemInstanceService $instance,
        private readonly CacheService $cache,
        private readonly CmsAppService $cmsAppService,
        private readonly CmsAppHubSyncService $cmsAppHubSyncService,
        private readonly CmsAppRepository $cmsAppRepository,
    ) {
    }

    /**
     * Build a portable bundle for the given page ids.
     *
     * The page structure is always made portable: any `entry-list` /
     * `entry-record` `data_config.table` that points at a *form section inside
     * this bundle* is rewritten from its install-specific numeric id to the
     * `@section:<owner name>` token so the import can relink it (issue #30).
     *
     * Data is opt-in: with `includeDataTables` the bundle also carries each owned
     * table's column list (so the importer can pre-create an empty table), and
     * with `includeDataRows` it additionally carries the rows keyed by human
     * field name (re-inserted through the normal form-save path on import).
     *
     * @param list<int> $pageIds
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function exportBundle(array $pageIds, array $options = []): array
    {
        $includeRows = (bool) ($options['includeDataRows'] ?? false);
        $includeTables = $includeRows || (bool) ($options['includeDataTables'] ?? false);

        // Bundle-wide section index (id -> name + name collisions) used to
        // rewrite owner table references and enforce owner-name uniqueness.
        $idToName = [];
        $nameCounts = [];
        foreach ($pageIds as $pageId) {
            $hierarchical = $this->sectionRepository->fetchSectionsHierarchicalByPageId($pageId);
            $this->collectSectionIndex($hierarchical, $idToName, $nameCounts);
        }

        $pages = [];
        $cmsAppMeta = null;
        foreach ($pageIds as $pageId) {
            $pageEntity = $this->pageRepository->find($pageId);
            if ($pageEntity?->getCmsApp() !== null && $cmsAppMeta === null) {
                $app = $pageEntity->getCmsApp();
                $cmsAppMeta = [
                    'name' => $app->getName(),
                    'slug' => $app->getSlug(),
                    'description' => $app->getDescription(),
                ];
            }
            $pages[] = $this->exportPage($pageId);
        }

        // Rewrite numeric `table` -> `@section:<owner>` and collect the set of
        // in-bundle form sections that own a referenced table.
        /** @var array<int, true> $ownerTableIds */
        $ownerTableIds = [];
        foreach ($pages as &$page) {
            $sections = $this->asSectionList($page['sections'] ?? null);
            $this->rewriteExportTableRefs($sections, $idToName, $nameCounts, $ownerTableIds);
            $page['sections'] = $sections;
        }
        unset($page);

        $bundle = [
            'format' => self::BUNDLE_FORMAT,
            'version' => self::BUNDLE_VERSION,
            'exported_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'core_version' => $this->instance->getCmsVersion(),
            'pages' => $pages,
        ];
        if ($cmsAppMeta !== null) {
            $bundle['cms_app'] = $cmsAppMeta;
        }
        if ($includeTables && $ownerTableIds !== []) {
            $bundle['data_tables'] = $this->exportOwnedDataTables(array_keys($ownerTableIds), $idToName, $includeRows);
        }

        return $bundle;
    }

    /**
     * Walk the raw hierarchical section tree (which still carries DB ids) and
     * record `section id -> name` plus a per-name count so the export can detect
     * a non-unique owner name (which would make the `@section:` token ambiguous
     * on import).
     *
     * @param array<int, array<string, mixed>> $sections
     * @param array<int, string> $idToName
     * @param array<string, int> $nameCounts
     * @param-out array<int, string> $idToName
     * @param-out array<string, int> $nameCounts
     */
    private function collectSectionIndex(array $sections, array &$idToName, array &$nameCounts): void
    {
        foreach ($sections as $section) {
            $id = $section['id'] ?? null;
            $name = $this->asString($section['section_name'] ?? ($section['name'] ?? ''));
            if (is_numeric($id) && $name !== '') {
                // A section may legitimately appear on several bundle pages
                // (e.g. the wizard reuses the form section on the admin detail
                // page). Count each unique section id once so reuse is not
                // mistaken for an owner-name collision.
                if (!isset($idToName[(int) $id])) {
                    $idToName[(int) $id] = $name;
                    $nameCounts[$name] = ($nameCounts[$name] ?? 0) + 1;
                }
            }
            if (is_array($section['children'] ?? null)) {
                $this->collectSectionIndex($this->asSectionList($section['children']), $idToName, $nameCounts);
            }
        }
    }

    /**
     * Recursively rewrite every section's `global_fields.data_config` so a
     * numeric `table` that points at an in-bundle section becomes the portable
     * `@section:<owner name>` token. Records the owner section id so the caller
     * can export its table/rows. Throws if the owner name is not unique in the
     * bundle (the token could not be resolved deterministically on import).
     *
     * @param array<int, array<string, mixed>> $sections
     * @param array<int, string> $idToName
     * @param array<string, int> $nameCounts
     * @param array<int, true> $ownerTableIds
     * @param-out array<int, array<string, mixed>> $sections
     * @param-out array<int, true> $ownerTableIds
     */
    private function rewriteExportTableRefs(array &$sections, array $idToName, array $nameCounts, array &$ownerTableIds): void
    {
        foreach ($sections as &$section) {
            $globalFields = $section['global_fields'] ?? null;
            if (is_array($globalFields)) {
                $rawDataConfig = $globalFields['data_config'] ?? null;
                if (is_string($rawDataConfig) && $rawDataConfig !== '') {
                    $rewritten = $this->rewriteDataConfigForExport($rawDataConfig, $idToName, $nameCounts, $ownerTableIds);
                    if ($rewritten !== null) {
                        $globalFields['data_config'] = $rewritten;
                        $section['global_fields'] = $globalFields;
                    }
                }
            }
            if ($this->asString($section['style_name'] ?? '') === StyleNames::STYLE_ENTRY_TABLE) {
                $this->rewriteEntryTableExportRef($section, $idToName, $nameCounts, $ownerTableIds);
            }
            if (is_array($section['children'] ?? null)) {
                $children = $this->asSectionList($section['children']);
                $this->rewriteExportTableRefs($children, $idToName, $nameCounts, $ownerTableIds);
                $section['children'] = $children;
            }
        }
        unset($section);
    }

    /**
     * Make an `entry-table` section's `data_table` field portable: the stored
     * value is an install-specific numeric `data_tables.id`, so when the table
     * is owned by an in-bundle form section (its name is that section's id) the
     * exported field content becomes the `@section:<owner name>` token and the
     * owner is recorded for the bundle's `data_tables[]` block. References to
     * tables not owned by a bundled section are left numeric (they point at
     * install-local data the bundle cannot carry).
     *
     * @param array<string, mixed> $section
     * @param array<int, string> $idToName
     * @param array<string, int> $nameCounts
     * @param array<int, true> $ownerTableIds
     * @param-out array<string, mixed> $section
     * @param-out array<int, true> $ownerTableIds
     */
    private function rewriteEntryTableExportRef(array &$section, array $idToName, array $nameCounts, array &$ownerTableIds): void
    {
        $fields = $section['fields'] ?? null;
        if (!is_array($fields) || !is_array($fields['data_table'] ?? null)) {
            return;
        }

        $changed = false;
        foreach ($fields['data_table'] as $locale => $entry) {
            if (!is_array($entry) || !is_numeric($entry['content'] ?? null)) {
                continue;
            }
            $dataTable = $this->dataService->getDataTableById((int) $entry['content']);
            if ($dataTable === null || !is_numeric($dataTable->getName())) {
                continue;
            }
            $ownerId = (int) $dataTable->getName();
            $ownerName = $idToName[$ownerId] ?? null;
            if ($ownerName === null) {
                continue;
            }
            if (($nameCounts[$ownerName] ?? 0) > 1) {
                $this->throwBadRequest(sprintf(
                    'Cannot export: the data-owning form section "%s" (id %d) shares its name with another section in the bundle, so its data link cannot be made portable. Rename it to a unique name and export again.',
                    $ownerName,
                    $ownerId
                ));
            }
            $entry['content'] = self::OWNER_TOKEN_PREFIX . $ownerName;
            $fields['data_table'][$locale] = $entry;
            $ownerTableIds[$ownerId] = true;
            $changed = true;
        }

        if ($changed) {
            $section['fields'] = $fields;
        }
    }

    /**
     * Rewrite a single `data_config` JSON string. Returns the new JSON when a
     * table reference was rewritten, or null when nothing changed.
     *
     * @param array<int, string> $idToName
     * @param array<string, int> $nameCounts
     * @param array<int, true> $ownerTableIds
     * @param-out array<int, true> $ownerTableIds
     */
    private function rewriteDataConfigForExport(string $dataConfig, array $idToName, array $nameCounts, array &$ownerTableIds): ?string
    {
        $decoded = json_decode($dataConfig, true);
        if (!is_array($decoded)) {
            return null;
        }

        $changed = false;
        $walker = function (array &$node) use (&$walker, $idToName, $nameCounts, &$ownerTableIds, &$changed): void {
            foreach ($node as $key => &$value) {
                if ($key === 'table' && is_numeric($value) && isset($idToName[(int) $value])) {
                    $ownerId = (int) $value;
                    $ownerName = $idToName[$ownerId];
                    if (($nameCounts[$ownerName] ?? 0) > 1) {
                        $this->throwBadRequest(sprintf(
                            'Cannot export: the data-owning form section "%s" (id %d) shares its name with another section in the bundle, so its data link cannot be made portable. Rename it to a unique name and export again.',
                            $ownerName,
                            $ownerId
                        ));
                    }
                    $value = self::OWNER_TOKEN_PREFIX . $ownerName;
                    $ownerTableIds[$ownerId] = true;
                    $changed = true;
                } elseif (is_array($value)) {
                    $walker($value);
                }
            }
            unset($value);
        };
        $walker($decoded);

        if (!$changed) {
            return null;
        }

        $encoded = json_encode($decoded);

        return $encoded === false ? null : $encoded;
    }

    /**
     * Build the `data_tables[]` bundle block for the given owner form-section
     * ids: column human-names always, and rows (keyed by human name) only when
     * `includeRows` is set. Tables that do not exist yet (form never submitted)
     * are emitted with empty columns so the importer still creates them.
     *
     * @param list<int> $ownerTableIds
     * @param array<int, string> $idToName
     * @return list<array<string, mixed>>
     */
    private function exportOwnedDataTables(array $ownerTableIds, array $idToName, bool $includeRows): array
    {
        $result = [];
        foreach ($ownerTableIds as $ownerId) {
            $ownerName = $idToName[$ownerId] ?? null;
            if ($ownerName === null) {
                continue;
            }

            $dataTable = $this->dataService->getDataTableByName((string) $ownerId);
            $columns = [];
            $rows = [];
            if ($dataTable !== null) {
                $tableId = (int) $dataTable->getId();
                $columns = array_values($this->dataService->getColumnDisplayLabels($tableId));
                if ($includeRows) {
                    $rows = $this->exportTableRows($tableId, $columns);
                }
            }

            $result[] = [
                'owner_section_name' => $ownerName,
                'columns' => $columns,
                'rows' => $rows,
            ];
        }

        return $result;
    }

    /**
     * Read every row of a form data table and project it down to the human
     * column names (dropping the metadata/projection columns) so the rows are
     * portable and can be re-inserted through the normal form-save path.
     *
     * @param list<string> $columns
     * @return list<array<string, mixed>>
     */
    private function exportTableRows(int $tableId, array $columns): array
    {
        if ($columns === []) {
            return [];
        }
        $allowed = array_fill_keys($columns, true);

        // All rows, all users (userId -1), excluding soft-deleted; remap the
        // immutable `section_<id>` keys back to their human input names.
        $rows = $this->dataService->getData($tableId, '', false, -1, false, true);
        $rows = $this->dataService->remapEntriesToInputNames($tableId, $rows);

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $clean = [];
            foreach ($row as $key => $value) {
                $keyString = (string) $key;
                if (isset($allowed[$keyString]) && (is_scalar($value) || $value === null)) {
                    $clean[$keyString] = $value;
                }
            }
            if ($clean !== []) {
                $result[] = $clean;
            }
        }

        return $result;
    }

    /**
     * Serialize one page (metadata + surface + routes + content fields + nested
     * sections) into the bundle's per-page shape.
     *
     * @return array<string, mixed>
     */
    private function exportPage(int $pageId): array
    {
        $this->userContextAwareService->checkAdminAccessById($pageId, 'select');

        $page = $this->pageRepository->find($pageId);
        if (!$page) {
            $this->throwNotFound('Page not found');
        }

        $pageWithFields = $this->pageFieldService->getPageWithFields($pageId);
        /** @var array<string, mixed> $pageMeta */
        $pageMeta = is_array($pageWithFields['page'] ?? null) ? $pageWithFields['page'] : [];
        /** @var list<array<string, mixed>> $fields */
        $fields = is_array($pageWithFields['fields'] ?? null) ? array_values($pageWithFields['fields']) : [];

        $accessType = is_array($pageMeta['pageAccessType'] ?? null)
            ? $this->asString($pageMeta['pageAccessType']['lookupCode'] ?? 'mobile_and_web')
            : 'mobile_and_web';

        return [
            'keyword' => $page->getKeyword(),
            'surface' => $page->getPageSurfaceCode(),
            'cms_app_role' => $page->getCmsAppRole(),
            'page_access_type' => $accessType,
            'headless' => $page->isHeadless(),
            'open_access' => $page->isOpenAccess(),
            'url' => $page->getUrl(),
            'parent_keyword' => $page->getParentPage()?->getKeyword(),
            'is_system' => $page->isSystem(),
            'fields' => $this->exportFields($fields),
            'routes' => $this->exportRoutes($pageId),
            'sections' => $this->sectionExportImportService->exportPageSections($pageId),
        ];
    }

    /**
     * Strip ids from the route set so import always creates fresh rows; the
     * pattern + requirements + flags are the portable contract.
     *
     * @return list<array<string, mixed>>
     */
    private function exportRoutes(int $pageId): array
    {
        $routes = [];
        foreach ($this->pageRouteService->getRoutesForPage($pageId) as $route) {
            $routes[] = [
                'path_pattern' => $route['path_pattern'],
                'requirements' => $route['requirements'],
                'is_canonical' => $route['is_canonical'],
                'is_active' => $route['is_active'],
                'priority' => $route['priority'],
            ];
        }

        return $routes;
    }

    /**
     * Reduce the admin field payload to the portable {name, display,
     * translations:[{language_code, content}]} shape.
     *
     * @param list<array<string, mixed>> $fields
     * @return list<array<string, mixed>>
     */
    private function exportFields(array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            $translations = [];
            $rawTranslations = is_array($field['translations'] ?? null) ? $field['translations'] : [];
            foreach ($rawTranslations as $translation) {
                if (!is_array($translation)) {
                    continue;
                }
                $translations[] = [
                    'language_code' => $this->asString($translation['language_code'] ?? ''),
                    'content' => $translation['content'] ?? null,
                ];
            }

            $result[] = [
                'name' => $this->asString($field['name'] ?? ''),
                'display' => (bool) ($field['display'] ?? false),
                'translations' => $translations,
            ];
        }

        return $result;
    }

    /**
     * Suggest related page ids that belong in the same bundle as the seed page:
     * its list/detail counterpart (same base path with/without a trailing
     * `{param}`) and its `/cms/...` admin counterpart. Returns the seed id plus
     * any related ids (deduplicated, seed first).
     *
     * @return list<int>
     */
    public function suggestRelatedPageIds(int $pageId): array
    {
        $this->userContextAwareService->checkAdminAccessById($pageId, 'select');

        $seedPage = $this->pageRepository->find($pageId);
        if (!$seedPage) {
            $this->throwNotFound('Page not found');
        }

        $seedBases = $this->routeBaseShapes($pageId);
        $result = [$pageId];

        if ($seedBases === []) {
            return $result;
        }

        /** @var list<Page> $allPages */
        $allPages = $this->pageRepository->findAll();
        foreach ($allPages as $candidate) {
            $candidateId = (int) $candidate->getId();
            if ($candidateId === $pageId) {
                continue;
            }
            foreach ($this->routeBaseShapes($candidateId) as $candidateBase) {
                if ($this->basesAreRelated($seedBases, $candidateBase)) {
                    if (!in_array($candidateId, $result, true)) {
                        $result[] = $candidateId;
                    }
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Normalized "base" of every active route of a page: the static prefix
     * before the first placeholder, plus a `/cms`-stripped variant so a public
     * page and its admin counterpart relate.
     *
     * @return list<string>
     */
    private function routeBaseShapes(int $pageId): array
    {
        $bases = [];
        foreach ($this->pageRouteService->getRoutesForPage($pageId) as $route) {
            if (!$route['is_active']) {
                continue;
            }
            $pattern = $route['path_pattern'];
            $placeholderPos = strpos($pattern, '{');
            $base = $placeholderPos !== false ? rtrim(substr($pattern, 0, $placeholderPos), '/') : rtrim($pattern, '/');
            if ($base === '') {
                continue;
            }
            $bases[] = $base;
            // Relate `/cms/team` <-> `/team`.
            if (str_starts_with($base, '/cms/')) {
                $bases[] = substr($base, 4);
            } else {
                $bases[] = '/cms' . $base;
            }
        }

        return array_values(array_unique($bases));
    }

    /**
     * @param list<string> $seedBases
     */
    private function basesAreRelated(array $seedBases, string $candidateBase): bool
    {
        return in_array($candidateBase, $seedBases, true);
    }

    /**
     * List the shipped, importable example page bundles (issue #30, decision E):
     * the read-only catalogue the admin "Example bundles" import picker shows.
     * Each entry carries the full decoded bundle so the picker can hand it
     * straight to the existing validate/import flow without a second fetch.
     *
 * Bundles are `*.bundle.json` files resolved from the frontend `examples/`
 * catalogue (pages/, cms-in-cms/, navigation/) with backend fixture fallback.
     *
     * @return list<array{id: string, title: string, description: string, tags: list<string>, page_count: int, bundle: array<string, mixed>}>
     */
    public function listExampleBundles(): array
    {
        $dirs = $this->exampleBundlePathResolver->listBundleDirectories();

        $files = [];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $matches = glob($dir . '/*.bundle.json');
            if ($matches === false) {
                continue;
            }
            foreach ($matches as $file) {
                $files[$file] = true;
            }
        }

        if ($files === []) {
            return [];
        }

        $paths = array_keys($files);
        sort($paths);

        $result = [];
        foreach ($paths as $file) {
            $raw = file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }

            /** @var array<string, mixed> $bundle */
            $bundle = [];
            foreach ($decoded as $key => $value) {
                $bundle[(string) $key] = $value;
            }

            $id = basename($file, '.bundle.json');
            $pages = is_array($bundle['pages'] ?? null) ? $bundle['pages'] : [];
            $title = $this->asString($bundle['title'] ?? '');
            // Optional gallery tags carried by the bundle (e.g. "cms-in-cms",
            // "list + detail") so the template picker can badge each card.
            $tags = [];
            foreach (is_array($bundle['tags'] ?? null) ? $bundle['tags'] : [] as $tag) {
                if (is_string($tag) && $tag !== '') {
                    $tags[] = $tag;
                }
            }

            $result[] = [
                'id' => $id,
                'title' => $title !== '' ? $title : ucwords(str_replace('-', ' ', $id)),
                'description' => $this->asString($bundle['description'] ?? ''),
                'tags' => $tags,
                'page_count' => count($pages),
                'bundle' => $bundle,
            ];
        }

        return $result;
    }

    /**
     * Validate a bundle without writing anything. Returns a structured report
     * with one entry per issue. The importer refuses to run while any
     * `error`-level issue remains (warnings are advisory).
     *
     * @param array<string, mixed> $bundle
     * @param array<string, mixed> $options
     * @return array{valid:bool, issues:list<array{level:string, code:string, message:string, page_keyword:?string}>}
     */
    public function validateImport(array $bundle, array $options = []): array
    {
        $issues = [];

        $pages = $this->bundlePages($bundle);
        if ($pages === []) {
            $issues[] = $this->issue(self::ISSUE_ERROR, 'empty_bundle', 'The bundle contains no pages.', null);
            return ['valid' => false, 'issues' => $issues];
        }

        $keywordPrefix = $this->asString($options['keywordPrefix'] ?? '');
        $routePrefix = $this->asString($options['routePrefix'] ?? '');
        $skipConflictingRoutes = (bool) ($options['skipConflictingRoutes'] ?? false);

        $installedLocales = $this->buildLocaleMap();
        // Every section name carried by the bundle, so `@section:` owner tokens
        // and `data_tables[].owner_section_name` can be checked up front.
        $bundleSectionNames = [];
        foreach ($pages as $page) {
            $this->collectBundleSectionNames($this->asSectionList($page['sections'] ?? null), $bundleSectionNames);
        }

        // Keywords present in the bundle (post-prefix), to resolve parent refs.
        $bundleKeywords = [];
        foreach ($pages as $page) {
            $bundleKeywords[$this->prefixKeyword($keywordPrefix, $this->asString($page['keyword'] ?? ''))] = true;
        }

        foreach ($pages as $page) {
            $keyword = $this->prefixKeyword($keywordPrefix, $this->asString($page['keyword'] ?? ''));
            if ($keyword === '') {
                $issues[] = $this->issue(self::ISSUE_ERROR, 'missing_keyword', 'A page in the bundle has no keyword.', null);
                continue;
            }

            // Duplicate keyword vs existing pages.
            if ($this->pageRepository->findOneBy(['keyword' => $keyword])) {
                $issues[] = $this->issue(
                    self::ISSUE_ERROR,
                    'duplicate_keyword',
                    sprintf('A page with keyword "%s" already exists. Use a keyword prefix to import a copy.', $keyword),
                    $keyword
                );
                continue;
            }

            // Parent hierarchy: parent must be in the bundle or already exist.
            $parentKeyword = $page['parent_keyword'] ?? null;
            if (is_string($parentKeyword) && $parentKeyword !== '') {
                $prefixedParent = $this->prefixKeyword($keywordPrefix, $parentKeyword);
                if (!isset($bundleKeywords[$prefixedParent]) && !$this->pageRepository->findOneBy(['keyword' => $prefixedParent])) {
                    $issues[] = $this->issue(
                        self::ISSUE_ERROR,
                        'missing_parent',
                        sprintf('Page "%s" references parent "%s" which is neither in the bundle nor installed.', $keyword, $prefixedParent),
                        $keyword
                    );
                }
            }

            $this->validatePageRoutes($page, $keyword, $routePrefix, $skipConflictingRoutes, $issues);
            $this->validatePageSectionsStyles($page, $keyword, $issues);
            $this->validateRouteParamUsage($page, $keyword, $issues);
            $this->validatePageLocales($page, $keyword, $installedLocales, $issues);
            $this->validateOwnerTokens($page, $keyword, $bundleSectionNames, $issues);
        }

        $this->validateBundleDataTables($bundle, $bundleSectionNames, $issues);
        $this->validateLegacyBundleNavigationIgnored($bundle, $issues);

        $hasError = false;
        foreach ($issues as $issue) {
            if ($issue['level'] === self::ISSUE_ERROR) {
                $hasError = true;
                break;
            }
        }

        return ['valid' => !$hasError, 'issues' => $issues];
    }

    /**
     * @param array<string, mixed> $page
     * @param list<array{level:string, code:string, message:string, page_keyword:?string}> $issues
     * @param-out list<array{level:string, code:string, message:string, page_keyword:?string}> $issues
     */
    private function validatePageRoutes(array $page, string $keyword, string $routePrefix, bool $skipConflictingRoutes, array &$issues): void
    {
        $routes = is_array($page['routes'] ?? null) ? $page['routes'] : [];
        $activePatterns = [];
        $hasCanonical = false;

        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }
            $pattern = $this->prefixRoute($routePrefix, $this->asString($route['path_pattern'] ?? ''));
            if ($pattern === '' || $pattern[0] !== '/') {
                $issues[] = $this->issue(
                    self::ISSUE_ERROR,
                    'invalid_pattern',
                    sprintf('Page "%s" has an invalid route pattern "%s" (must start with /).', $keyword, $pattern),
                    $keyword
                );
                continue;
            }
            if (!$this->placeholdersAreValid($pattern)) {
                $issues[] = $this->issue(
                    self::ISSUE_ERROR,
                    'invalid_placeholder',
                    sprintf('Page "%s" route "%s" has a malformed {placeholder}.', $keyword, $pattern),
                    $keyword
                );
            }
            $isActive = !array_key_exists('is_active', $route) || (bool) $route['is_active'];
            if ($isActive) {
                $activePatterns[] = ['path_pattern' => $pattern];
                if (!empty($route['is_canonical'])) {
                    $hasCanonical = true;
                }
            }
        }

        if ($activePatterns !== [] && !$hasCanonical) {
            $issues[] = $this->issue(
                self::ISSUE_WARNING,
                'missing_canonical',
                sprintf('Page "%s" has active routes but no canonical route; the first active route will be promoted.', $keyword),
                $keyword
            );
        }

        // Global conflicts against installed routes (skipped if the importer is
        // going to drop conflicting routes anyway).
        if (!$skipConflictingRoutes && $activePatterns !== []) {
            foreach ($this->conflictValidator->findConflictsForSet($activePatterns, null) as $conflict) {
                $issues[] = $this->issue(
                    self::ISSUE_ERROR,
                    'route_conflict',
                    sprintf('Page "%s": %s', $keyword, $conflict['message']),
                    $keyword
                );
            }
        }
    }

    /**
     * Every section style referenced by the bundle must exist on this install.
     *
     * @param array<string, mixed> $page
     * @param list<array{level:string, code:string, message:string, page_keyword:?string}> $issues
     * @param-out list<array{level:string, code:string, message:string, page_keyword:?string}> $issues
     */
    private function validatePageSectionsStyles(array $page, string $keyword, array &$issues): void
    {
        $sections = $this->asSectionList($page['sections'] ?? null);
        $styleNames = [];
        $this->collectStyleNames($sections, $styleNames);

        foreach (array_keys($styleNames) as $styleName) {
            if ($styleName === '' || $this->styleRepository->findOneBy(['name' => $styleName]) === null) {
                $issues[] = $this->issue(
                    self::ISSUE_ERROR,
                    'missing_style',
                    sprintf('Page "%s" uses style "%s" which is not installed.', $keyword, $styleName),
                    $keyword
                );
            }
        }
    }

    /**
     * Warn when a section `data_config` filters on `{{route.<param>}}` for a
     * param that none of the page's active route patterns define.
     *
     * @param array<string, mixed> $page
     * @param list<array{level:string, code:string, message:string, page_keyword:?string}> $issues
     * @param-out list<array{level:string, code:string, message:string, page_keyword:?string}> $issues
     */
    private function validateRouteParamUsage(array $page, string $keyword, array &$issues): void
    {
        $definedParams = [];
        $routes = is_array($page['routes'] ?? null) ? $page['routes'] : [];
        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }
            foreach ($this->extractPlaceholders($this->asString($route['path_pattern'] ?? '')) as $placeholder) {
                $definedParams[$placeholder] = true;
            }
        }

        $sections = $this->asSectionList($page['sections'] ?? null);
        $usedParams = [];
        $this->collectRouteParamUsage($sections, $usedParams);

        foreach (array_keys($usedParams) as $param) {
            if (!isset($definedParams[$param])) {
                $issues[] = $this->issue(
                    self::ISSUE_WARNING,
                    'undefined_route_param',
                    sprintf('Page "%s" references {{route.%s}} but no route defines that parameter.', $keyword, $param),
                    $keyword
                );
            }
        }
    }

    /**
     * Fail import when a translatable (display=1) field carries content in a
     * locale that is not installed on this CMS, naming the missing locale so the
     * operator can either add the language or re-export. Property (display=0)
     * fields are stored under the internal language and are not checked.
     *
     * @param array<string, mixed> $page
     * @param array<string, int> $installedLocales
     * @param list<array{level:string, code:string, message:string, page_keyword:?string}> $issues
     * @param-out list<array{level:string, code:string, message:string, page_keyword:?string}> $issues
     */
    private function validatePageLocales(array $page, string $keyword, array $installedLocales, array &$issues): void
    {
        $missing = [];
        $fields = is_array($page['fields'] ?? null) ? $page['fields'] : [];
        foreach ($fields as $field) {
            if (!is_array($field) || !(bool) ($field['display'] ?? false)) {
                continue;
            }
            $translations = is_array($field['translations'] ?? null) ? $field['translations'] : [];
            foreach ($translations as $translation) {
                if (!is_array($translation)) {
                    continue;
                }
                $locale = $this->asString($translation['language_code'] ?? '');
                if ($locale !== '' && !isset($installedLocales[$locale])) {
                    $missing[$locale] = true;
                }
            }
        }

        foreach (array_keys($missing) as $locale) {
            $issues[] = $this->issue(
                self::ISSUE_ERROR,
                'missing_locale',
                sprintf('Page "%s" has translatable content in locale "%s" which is not installed. Add the language or re-export without it.', $keyword, $locale),
                $keyword
            );
        }
    }

    /**
     * Fail import when a section `data_config` carries an `@section:<owner>`
     * owner token whose owner section is not present in the bundle (the link
     * could not be relinked to a real table on import).
     *
     * @param array<string, mixed> $page
     * @param array<string, true> $bundleSectionNames
     * @param list<array{level:string, code:string, message:string, page_keyword:?string}> $issues
     * @param-out list<array{level:string, code:string, message:string, page_keyword:?string}> $issues
     */
    private function validateOwnerTokens(array $page, string $keyword, array $bundleSectionNames, array &$issues): void
    {
        $owners = [];
        $this->collectOwnerTokens($this->asSectionList($page['sections'] ?? null), $owners);
        foreach (array_keys($owners) as $owner) {
            if (!isset($bundleSectionNames[$owner])) {
                $issues[] = $this->issue(
                    self::ISSUE_ERROR,
                    'missing_owner_section',
                    sprintf('Page "%s" links to data owner section "%s" which is not part of this bundle.', $keyword, $owner),
                    $keyword
                );
            }
        }

        // Numeric entry-table bindings survive import verbatim but point at
        // install-specific table ids — almost certainly the wrong table on the
        // target install. Exports rewrite in-bundle bindings to `@section:`
        // tokens; a remaining numeric value means the table's owner form is
        // not part of this bundle.
        $numericRefs = [];
        $this->collectEntryTableNumericRefs($this->asSectionList($page['sections'] ?? null), $numericRefs);
        foreach ($numericRefs as $sectionName => $tableId) {
            $issues[] = $this->issue(
                self::ISSUE_WARNING,
                'unportable_data_table',
                sprintf(
                    'Page "%s": entry-table section "%s" is bound to data table id %d, which is install-specific. The imported grid may show the wrong table; rebind it after import.',
                    $keyword,
                    $sectionName,
                    $tableId
                ),
                $keyword
            );
        }
    }

    /**
     * Collect `section name -> numeric data_table id` for every entry-table
     * section in the tree whose binding was NOT rewritten to an owner token.
     *
     * @param array<int, array<string, mixed>> $sections
     * @param array<string, int> $numericRefs
     * @param-out array<string, int> $numericRefs
     */
    private function collectEntryTableNumericRefs(array $sections, array &$numericRefs): void
    {
        foreach ($sections as $section) {
            if ($this->asString($section['style_name'] ?? '') === StyleNames::STYLE_ENTRY_TABLE) {
                $fields = $section['fields'] ?? null;
                $dataTable = is_array($fields) ? ($fields['data_table'] ?? null) : null;
                if (is_array($dataTable)) {
                    foreach ($dataTable as $entry) {
                        if (is_array($entry) && is_numeric($entry['content'] ?? null)) {
                            $name = $this->asString($section['section_name'] ?? '');
                            $numericRefs[$name !== '' ? $name : 'entry-table'] = (int) $entry['content'];
                            break;
                        }
                    }
                }
            }
            if (is_array($section['children'] ?? null)) {
                $this->collectEntryTableNumericRefs($this->asSectionList($section['children']), $numericRefs);
            }
        }
    }

    /**
     * Fail import when a bundle `data_tables[]` entry names an owner section that
     * is not present in the bundle.
     *
     * @param array<string, mixed> $bundle
     * @param array<string, true> $bundleSectionNames
     * @param list<array{level:string, code:string, message:string, page_keyword:?string}> $issues
     * @param-out list<array{level:string, code:string, message:string, page_keyword:?string}> $issues
     */
    private function validateBundleDataTables(array $bundle, array $bundleSectionNames, array &$issues): void
    {
        $dataTables = is_array($bundle['data_tables'] ?? null) ? $bundle['data_tables'] : [];
        foreach ($dataTables as $dataTable) {
            if (!is_array($dataTable)) {
                continue;
            }
            $owner = $this->asString($dataTable['owner_section_name'] ?? '');
            if ($owner !== '' && !isset($bundleSectionNames[$owner])) {
                $issues[] = $this->issue(
                    self::ISSUE_ERROR,
                    'missing_owner_section',
                    sprintf('Bundle data table references owner section "%s" which is not part of this bundle.', $owner),
                    null
                );
            }
        }
    }

    /**
     * Recreate every page in the bundle (transactional). Pages are created
     * first so cross-page parent links resolve, then routes + section trees are
     * materialized. Aborts (and rolls back) if validation finds any error.
     *
     * @param array<string, mixed> $bundle
     * @param array<string, mixed> $options
     * @return array{created:list<array{keyword:string, page_id:int}>}
     */
    public function importBundle(array $bundle, array $options = []): array
    {
        $validation = $this->validateImport($bundle, $options);
        if (!$validation['valid']) {
            throw new ServiceException(
                'Import validation failed.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['issues' => $validation['issues']]
            );
        }

        $pages = $this->bundlePages($bundle);
        $keywordPrefix = $this->asString($options['keywordPrefix'] ?? '');
        $routePrefix = $this->asString($options['routePrefix'] ?? '');
        $activateRoutes = !array_key_exists('activateRoutes', $options) || (bool) $options['activateRoutes'];
        $skipConflictingRoutes = (bool) ($options['skipConflictingRoutes'] ?? false);
        $importData = (bool) ($options['importData'] ?? false);
        // Optional viewer/editor groups granted access to every imported page.
        // Admin always gets full access inside createPage(); these are the extra
        // groups the importer picked so the pages are visible to real users
        // (read-only on public pages, full CRUD on cms-app pages — createPage
        // applies the surface-appropriate ACL).
        $accessGroups = $this->intListFromOption($options['accessGroups'] ?? null);

        $localeMap = $this->buildLocaleMap();

        // A route prefix moves every bundle page to a new URL base, so the
        // bundle's own cross-page links (card "Open" links, entry-table
        // add/edit URLs, form redirect/cancel URLs) must move with them or
        // they would 404 / hit the unprefixed original pages after import.
        if ($routePrefix !== '') {
            $pages = $this->rewriteInContentLinksForPrefix($pages, $routePrefix);
        }

        $this->entityManager->beginTransaction();
        try {
            // CMS-in-cms bundles must carry first-class cms_app metadata + roles
            // (no dual-format / no silent downgrade to plain pages).
            $isCmsInCmsBundle = $this->bundleLooksLikeCmsInCms($bundle, $pages);
            $cmsAppPayload = is_array($bundle['cms_app'] ?? null) ? $bundle['cms_app'] : null;
            if ($isCmsInCmsBundle) {
                $this->assertCmsInCmsBundleContract($bundle, $pages, $cmsAppPayload);
            } elseif ($cmsAppPayload !== null) {
                // Explicit cms_app block on a non-tagged bundle still requires
                // every page to declare a valid cms_app_role.
                $this->assertCmsInCmsBundleContract($bundle, $pages, $cmsAppPayload);
            }

            $cmsAppId = null;
            if ($cmsAppPayload !== null) {
                $cmsAppId = $this->resolveCmsAppIdForImport($cmsAppPayload, $keywordPrefix);
            }

            // Pass 1: create every page (no parent yet) and remember its id.
            /** @var array<string, int> $keywordToId */
            $keywordToId = [];
            foreach ($pages as $page) {
                $keyword = $this->prefixKeyword($keywordPrefix, $this->asString($page['keyword'] ?? ''));
                // Apply the route prefix to the page url up front: pass 2
                // realigns it to the canonical route anyway, but the raw
                // bundle url could collide with a still-existing source page
                // when importing next to the original (`pages.url` is unique).
                $pageUrl = $this->stringOrNull($page['url'] ?? null);
                if ($pageUrl !== null && $routePrefix !== '' && str_starts_with($pageUrl, '/')) {
                    $pageUrl = $this->prefixRoute($routePrefix, $pageUrl);
                }
                $created = $this->adminPageService->createPage(
                    $keyword,
                    $this->asString($page['page_access_type'] ?? 'mobile_and_web'),
                    (bool) ($page['headless'] ?? false),
                    (bool) ($page['open_access'] ?? false),
                    $pageUrl,
                    null,
                    $this->normalizeSurface($page['surface'] ?? 'public'),
                    // Admin is always granted full access inside createPage; these
                    // importer-selected groups get read (public) / full CRUD (cms)
                    // so the imported pages are actually visible to real users.
                    $accessGroups,
                    null,
                    // Skip auto-route: the bundle's own routes are applied
                    // faithfully in pass 2 (applyFieldsAndRoutes), honoring the
                    // route prefix / activate / skip-conflicting options.
                    [],
                );
                $keywordToId[$keyword] = (int) $created->getId();
            }

            // Pass 2: parents, fields, routes, sections. Collect the bundle-wide
            // `source section name -> new section id` map as we go so owner
            // tokens can be relinked once every section exists.
            $createdResult = [];
            /** @var array<string, int> $sourceNameToNewId */
            $sourceNameToNewId = [];
            /** @var list<int> $allNewSectionIds */
            $allNewSectionIds = [];
            foreach ($pages as $page) {
                $keyword = $this->prefixKeyword($keywordPrefix, $this->asString($page['keyword'] ?? ''));
                $pageId = $keywordToId[$keyword];

                $this->applyParent($page, $keyword, $keywordPrefix, $keywordToId, $pageId);
                $this->applyFieldsAndRoutes($page, $pageId, $routePrefix, $activateRoutes, $skipConflictingRoutes, $localeMap);

                $sections = $this->asSectionList($page['sections'] ?? null);
                if ($sections !== []) {
                    $imported = $this->sectionExportImportService->importSectionsToPage($pageId, $sections);
                    foreach ($imported as $importedSection) {
                        $newId = $importedSection['id'] ?? null;
                        if (!is_numeric($newId)) {
                            continue;
                        }
                        $allNewSectionIds[] = (int) $newId;
                        $sourceName = $this->asString($importedSection['source_name'] ?? '');
                        if ($sourceName !== '') {
                            $sourceNameToNewId[$sourceName] = (int) $newId;
                        }
                    }
                }

                if ($cmsAppId !== null) {
                    $role = $this->asString($page['cms_app_role'] ?? '');
                    if (!CmsAppRole::isValid($role)) {
                        throw new ServiceException(
                            sprintf(
                                'Page "%s" has invalid cms_app_role "%s". Allowed: %s.',
                                $this->asString($page['keyword'] ?? $keyword),
                                $role,
                                implode(', ', CmsAppRole::ALL)
                            ),
                            Response::HTTP_BAD_REQUEST
                        );
                    }
                    $this->cmsAppService->assignPage($cmsAppId, $pageId, $role);
                }

                $createdResult[] = ['keyword' => $keyword, 'page_id' => $pageId];
            }

            // Pass 3: relink `@section:` owner tokens to the new section ids and
            // (optionally) restore the owned data tables + sample rows. The
            // entry-table relink runs last: it needs the owner's data table row
            // to exist so the field can point at its fresh numeric id.
            $this->relinkOwnerTokens($allNewSectionIds, $sourceNameToNewId);
            $this->restoreDataTables($bundle, $sourceNameToNewId, $importData, $localeMap);
            $this->relinkEntryTableDataTables($allNewSectionIds, $sourceNameToNewId);

            if ($cmsAppId !== null) {
                $app = $this->cmsAppService->requireApp($cmsAppId);
                $this->cmsAppHubSyncService->sync($app);
            }

            $this->entityManager->commit();

            // Re-invalidate AFTER the commit. createPage/addGroupAcl may have
            // invalidated while the transaction was still open, so a concurrent
            // request could re-cache the pre-import state. Bump the full
            // category generations (not only list tags) so nested
            // `hasAccess` / per-user ACL item keys also retire and imported
            // pages are visible to admin immediately.
            $this->cache
                ->withCategory(CacheService::CATEGORY_PAGES)
                ->invalidateCategory();
            $this->cache
                ->withCategory(CacheService::CATEGORY_PERMISSIONS)
                ->invalidateCategory();

            return ['created' => $createdResult];
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw $e instanceof ServiceException ? $e : new ServiceException(
                'Page bundle import failed: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous_exception' => $e->getMessage()]
            );
        }
    }

    /**
     * Replace every `@section:<owner name>` owner token in the freshly-imported
     * sections' `data_config` with the owner's new section id. Runs after all
     * sections exist so cross-page references resolve. The owning section caches
     * were already dropped by the per-page section import and are not
     * re-populated before this rewrite, so no extra invalidation is needed.
     *
     * @param list<int> $newSectionIds
     * @param array<string, int> $sourceNameToNewId
     */
    private function relinkOwnerTokens(array $newSectionIds, array $sourceNameToNewId): void
    {
        $touched = false;
        foreach ($newSectionIds as $sectionId) {
            $section = $this->sectionRepository->find($sectionId);
            if (!$section instanceof Section) {
                continue;
            }
            $dataConfig = $section->getDataConfig();
            if (!is_string($dataConfig) || !str_contains($dataConfig, self::OWNER_TOKEN_PREFIX)) {
                continue;
            }
            $resolved = $this->resolveOwnerTokens($dataConfig, $sourceNameToNewId);
            $section->setDataConfig($resolved);
            $this->entityManager->persist($section);
            $touched = true;
        }

        if ($touched) {
            $this->entityManager->flush();
        }
    }

    /**
     * Replace every `@section:<owner name>` token stored in a freshly-imported
     * `entry-table` section's `data_table` field with the numeric id of the
     * owner's (freshly-created) data table. Runs after {@see restoreDataTables}
     * so bundles that carry a `data_tables[]` block already have the table row;
     * for bundles without one the empty table is materialized here — the field
     * must hold a real `data_tables.id` either way.
     *
     * @param list<int> $newSectionIds
     * @param array<string, int> $sourceNameToNewId
     */
    private function relinkEntryTableDataTables(array $newSectionIds, array $sourceNameToNewId): void
    {
        $touched = false;
        foreach ($newSectionIds as $sectionId) {
            $section = $this->sectionRepository->find($sectionId);
            if (!$section instanceof Section || $section->getStyle()?->getName() !== StyleNames::STYLE_ENTRY_TABLE) {
                continue;
            }

            /** @var list<SectionsFieldsTranslation> $translations */
            $translations = $this->entityManager->getRepository(SectionsFieldsTranslation::class)
                ->createQueryBuilder('t')
                ->join('t.field', 'f')
                ->where('t.section = :sectionId')
                ->andWhere('f.name = :fieldName')
                ->setParameter('sectionId', $sectionId)
                ->setParameter('fieldName', 'data_table')
                ->getQuery()
                ->getResult();

            foreach ($translations as $translation) {
                $content = (string) $translation->getContent();
                if (!str_starts_with($content, self::OWNER_TOKEN_PREFIX)) {
                    continue;
                }
                $ownerName = substr($content, strlen(self::OWNER_TOKEN_PREFIX));
                $newOwnerId = $sourceNameToNewId[$ownerName] ?? null;
                if ($newOwnerId === null) {
                    throw new ServiceException(
                        sprintf('Import failed: data table link references owner section "%s" which is not part of this bundle.', $ownerName),
                        Response::HTTP_UNPROCESSABLE_ENTITY,
                        ['owner_section_name' => $ownerName]
                    );
                }

                $ownerSection = $this->sectionRepository->find($newOwnerId);
                $dataTable = $ownerSection instanceof Section
                    ? $this->dataTableService->createDataTableForFormSection($ownerSection)
                    : null;
                if ($dataTable === null) {
                    throw new ServiceException(
                        sprintf('Import failed: could not materialize the data table owned by section "%s".', $ownerName),
                        Response::HTTP_INTERNAL_SERVER_ERROR,
                        ['owner_section_name' => $ownerName]
                    );
                }

                $translation->setContent((string) $dataTable->getId());
                $this->entityManager->persist($translation);
                $touched = true;
            }
        }

        if ($touched) {
            $this->entityManager->flush();
        }
    }

    /**
     * Resolve `@section:<owner>` tokens in a single `data_config` JSON string to
     * the owner's new numeric section id (the new table name). Aborts the import
     * if a token references an owner that is not part of this bundle.
     *
     * @param array<string, int> $sourceNameToNewId
     */
    private function resolveOwnerTokens(string $dataConfig, array $sourceNameToNewId): string
    {
        $decoded = json_decode($dataConfig, true);
        if (!is_array($decoded)) {
            return $dataConfig;
        }

        $walker = function (array &$node) use (&$walker, $sourceNameToNewId): void {
            foreach ($node as $key => &$value) {
                if ($key === 'table' && is_string($value) && str_starts_with($value, self::OWNER_TOKEN_PREFIX)) {
                    $ownerName = substr($value, strlen(self::OWNER_TOKEN_PREFIX));
                    $newId = $sourceNameToNewId[$ownerName] ?? null;
                    if ($newId === null) {
                        throw new ServiceException(
                            sprintf('Import failed: data link references owner section "%s" which is not part of this bundle.', $ownerName),
                            Response::HTTP_UNPROCESSABLE_ENTITY,
                            ['owner_section_name' => $ownerName]
                        );
                    }
                    $value = (string) $newId;
                } elseif (is_array($value)) {
                    $walker($value);
                }
            }
            unset($value);
        };
        $walker($decoded);

        $encoded = json_encode($decoded);

        return $encoded === false ? $dataConfig : $encoded;
    }

    /**
     * Recreate the bundle's owned data tables against the freshly-imported form
     * sections. The empty table is always created (so an `entry-list` binding
     * resolves even with no data); sample rows are re-inserted through the normal
     * form-save path only when `importData` is set and the bundle carries rows.
     *
     * @param array<string, mixed> $bundle
     * @param array<string, int> $sourceNameToNewId
     */
    private function restoreDataTables(array $bundle, array $sourceNameToNewId, bool $importData, array $localeMap): void
    {
        $dataTables = is_array($bundle['data_tables'] ?? null) ? $bundle['data_tables'] : [];
        foreach ($dataTables as $dataTable) {
            if (!is_array($dataTable)) {
                continue;
            }
            $ownerName = $this->asString($dataTable['owner_section_name'] ?? '');
            if ($ownerName === '') {
                continue;
            }
            $newOwnerId = $sourceNameToNewId[$ownerName] ?? null;
            if ($newOwnerId === null) {
                throw new ServiceException(
                    sprintf('Import failed: bundle data table references owner section "%s" which is not part of this bundle.', $ownerName),
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    ['owner_section_name' => $ownerName]
                );
            }

            $ownerSection = $this->sectionRepository->find($newOwnerId);
            if (!$ownerSection instanceof Section) {
                continue;
            }

            // Always materialize the (empty) table so the binding resolves.
            $this->dataTableService->createDataTableForFormSection($ownerSection);

            if (!$importData) {
                continue;
            }

            $rows = is_array($dataTable['rows'] ?? null) ? $dataTable['rows'] : [];
            foreach ($rows as $row) {
                $rowData = $this->normalizeRowForSave($row, $localeMap);
                if ($rowData === []) {
                    continue;
                }
                $saved = $this->dataService->saveData((string) $newOwnerId, $rowData, LookupService::TRANSACTION_BY_BY_USER, null, false);
                if ($saved === false) {
                    throw new ServiceException(
                        sprintf('Import failed: could not insert a sample row into data table for owner section "%s".', $ownerName),
                        Response::HTTP_INTERNAL_SERVER_ERROR,
                        ['owner_section_name' => $ownerName]
                    );
                }
            }
        }
    }

    /**
     * Reduce a bundle row to values the form-save path accepts (it remaps the
     * human names to the new `section_<id>` keys). Scalar fields are stored as
     * plain strings; translatable fields may use a locale map
     * (`{"de-CH":"…","en-GB":"…"}`) or a list of `{language_code, content}`.
     *
     * @param mixed $row
     * @param array<string, int> $localeMap
     * @return array<string, mixed>
     */
    private function normalizeRowForSave(mixed $row, array $localeMap): array
    {
        if (!is_array($row)) {
            return [];
        }
        $clean = [];
        foreach ($row as $key => $value) {
            $name = (string) $key;
            if ($name === '') {
                continue;
            }
            if (is_scalar($value)) {
                $clean[$name] = $value;
                continue;
            }
            if (!is_array($value)) {
                continue;
            }

            $multiLang = $this->normalizeTranslatableBundleValue($value, $localeMap);
            if ($multiLang !== null) {
                $clean[$name] = $multiLang;
            }
        }

        return $clean;
    }

    /**
     * @param array<mixed> $value
     * @param array<string, int> $localeMap
     * @return list<array{language_id: int, value: string}>|null
     */
    private function normalizeTranslatableBundleValue(array $value, array $localeMap): ?array
    {
        if ($value === []) {
            return null;
        }

        // `{ "de-CH": "…", "en-GB": "…" }`
        $isLocaleMap = true;
        $fromLocaleMap = [];
        foreach ($value as $locale => $content) {
            if (!is_string($locale) || !is_scalar($content)) {
                $isLocaleMap = false;
                break;
            }
            $languageId = $localeMap[$locale] ?? null;
            if ($languageId === null) {
                continue;
            }
            $fromLocaleMap[] = [
                'language_id' => $languageId,
                'value' => (string) $content,
            ];
        }
        if ($isLocaleMap && $fromLocaleMap !== []) {
            return $fromLocaleMap;
        }

        // `[ { "language_code": "de-CH", "content": "…" }, … ]`
        if (!isset($value[0]) || !is_array($value[0])) {
            return null;
        }
        $fromList = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }
            $locale = $this->asString($item['language_code'] ?? '');
            $languageId = $localeMap[$locale] ?? null;
            if ($languageId === null) {
                continue;
            }
            $fromList[] = [
                'language_id' => $languageId,
                'value' => $this->asString($item['content'] ?? ''),
            ];
        }

        return $fromList !== [] ? $fromList : null;
    }

    /**
     * Resolve the parent keyword to a created/installed page id and persist it
     * via the page update path.
     *
     * @param array<string, mixed> $page
     * @param array<string, int> $keywordToId
     */
    private function applyParent(array $page, string $keyword, string $keywordPrefix, array $keywordToId, int $pageId): void
    {
        $parentKeyword = $page['parent_keyword'] ?? null;
        if (!is_string($parentKeyword) || $parentKeyword === '') {
            return;
        }
        $prefixedParent = $this->prefixKeyword($keywordPrefix, $parentKeyword);
        $parentId = $keywordToId[$prefixedParent] ?? null;
        if ($parentId === null) {
            $installedParent = $this->pageRepository->findOneBy(['keyword' => $prefixedParent]);
            $parentId = $installedParent ? (int) $installedParent->getId() : null;
        }
        if ($parentId !== null) {
            $this->adminPageService->updatePage($pageId, ['parent' => $parentId], []);
        }
    }

    /**
     * Apply content fields + routes to a freshly-created page via updatePage so
     * the normal validation/sync/cache-invalidation paths run.
     *
     * @param array<string, mixed> $page
     * @param array<string, int> $localeMap
     */
    private function applyFieldsAndRoutes(
        array $page,
        int $pageId,
        string $routePrefix,
        bool $activateRoutes,
        bool $skipConflictingRoutes,
        array $localeMap
    ): void {
        $fieldNameToId = $this->buildFieldNameMap($pageId);
        $fieldEntries = $this->buildFieldEntries($page, $fieldNameToId, $localeMap);
        $routes = $this->buildRouteEntries($page, $routePrefix, $activateRoutes, $skipConflictingRoutes);

        $pageData = [];
        if ($routes !== []) {
            $pageData['routes'] = $routes;
            // Keep `pages.url` consistent with the (possibly prefixed) canonical
            // route so links built from the page url — the navigation menu, the
            // admin pages list — resolve through the DB router. createPage stored
            // the bundle's raw (unprefixed) url, which would 404 once a route
            // prefix is applied; realign it to the canonical active route here.
            $canonicalUrl = $this->canonicalRouteUrl($routes);
            if ($canonicalUrl !== null) {
                $pageData['url'] = $canonicalUrl;
            }
        }

        if ($pageData !== [] || $fieldEntries !== []) {
            $this->adminPageService->updatePage($pageId, $pageData, $fieldEntries);
        }
    }

    /**
     * Pick the url to mirror onto `pages.url` from a freshly-built route set:
     * the canonical active route if any, else the first active route, else the
     * first route. Returns null when no usable pattern exists.
     *
     * @param list<array<string, mixed>> $routes
     */
    private function canonicalRouteUrl(array $routes): ?string
    {
        $active = array_values(array_filter($routes, static fn (array $r): bool => (bool) ($r['is_active'] ?? false)));
        $pool = $active !== [] ? $active : $routes;

        $canonical = null;
        foreach ($pool as $route) {
            if ((bool) ($route['is_canonical'] ?? false)) {
                $canonical = $route;
                break;
            }
        }
        $chosen = $canonical ?? $pool[0];

        $pattern = $chosen['path_pattern'] ?? null;

        return is_string($pattern) && $pattern !== '' ? $pattern : null;
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, int> $fieldNameToId
     * @param array<string, int> $localeMap
     * @return list<array{fieldId:int, languageId:int, content:string|null}>
     */
    private function buildFieldEntries(array $page, array $fieldNameToId, array $localeMap): array
    {
        $entries = [];
        $fields = is_array($page['fields'] ?? null) ? $page['fields'] : [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = $this->asString($field['name'] ?? '');
            $fieldId = $fieldNameToId[$name] ?? null;
            if ($fieldId === null) {
                continue;
            }
            $display = (bool) ($field['display'] ?? false);
            $translations = is_array($field['translations'] ?? null) ? $field['translations'] : [];
            foreach ($translations as $translation) {
                if (!is_array($translation)) {
                    continue;
                }
                $content = $translation['content'] ?? null;
                $contentString = $content === null ? null : $this->asString($content);
                $languageId = $display
                    ? ($localeMap[$this->asString($translation['language_code'] ?? '')] ?? null)
                    : 1;
                if ($languageId === null) {
                    continue;
                }
                $entries[] = [
                    'fieldId' => $fieldId,
                    'languageId' => $languageId,
                    'content' => $contentString,
                ];
            }
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $page
     * @return list<array<string, mixed>>
     */
    private function buildRouteEntries(array $page, string $routePrefix, bool $activateRoutes, bool $skipConflictingRoutes): array
    {
        $routes = is_array($page['routes'] ?? null) ? $page['routes'] : [];
        $entries = [];
        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }
            $pattern = $this->prefixRoute($routePrefix, $this->asString($route['path_pattern'] ?? ''));
            if ($pattern === '') {
                continue;
            }
            $isActive = $activateRoutes && (!array_key_exists('is_active', $route) || (bool) $route['is_active']);

            if ($skipConflictingRoutes && $isActive) {
                $conflicts = $this->conflictValidator->findConflictsForSet([['path_pattern' => $pattern]], null);
                if ($conflicts !== []) {
                    continue;
                }
            }

            $requirements = null;
            if (isset($route['requirements']) && is_array($route['requirements'])) {
                $requirements = [];
                foreach ($route['requirements'] as $reqKey => $reqValue) {
                    if (is_scalar($reqValue)) {
                        $requirements[(string) $reqKey] = (string) $reqValue;
                    }
                }
            }

            $entries[] = [
                'path_pattern' => $pattern,
                'requirements' => $requirements,
                'is_canonical' => (bool) ($route['is_canonical'] ?? false),
                'is_active' => $isActive,
                'priority' => is_numeric($route['priority'] ?? null) ? (int) $route['priority'] : 0,
            ];
        }

        return $entries;
    }

    /**
     * @return array<string, int> field name -> field id for the page's page type
     */
    private function buildFieldNameMap(int $pageId): array
    {
        $pageWithFields = $this->pageFieldService->getPageWithFields($pageId);
        $fields = is_array($pageWithFields['fields'] ?? null) ? $pageWithFields['fields'] : [];
        $map = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = $this->asString($field['name'] ?? '');
            $id = $field['id'] ?? null;
            if ($name !== '' && is_numeric($id)) {
                $map[$name] = (int) $id;
            }
        }

        return $map;
    }

    /**
     * @return array<string, int> locale -> language id
     */
    private function buildLocaleMap(): array
    {
        /** @var list<Language> $languages */
        $languages = $this->entityManager->getRepository(Language::class)->findAll();
        $map = [];
        foreach ($languages as $language) {
            $locale = $language->getLocale();
            $id = $language->getId();
            if ($locale !== null && $id !== null) {
                $map[$locale] = (int) $id;
            }
        }

        return $map;
    }

    /**
     * Normalize an exported sections payload into the list-of-arrays shape
     * {@see SectionExportImportService::importSectionsToPage()} expects.
     *
     * @return array<int, array<string, mixed>>
     */
    private function asSectionList(mixed $sections): array
    {
        if (!is_array($sections)) {
            return [];
        }

        $result = [];
        foreach ($sections as $section) {
            if (is_array($section)) {
                /** @var array<string, mixed> $assoc */
                $assoc = [];
                foreach ($section as $key => $value) {
                    $assoc[(string) $key] = $value;
                }
                $result[] = $assoc;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $bundle
     * @return list<array<string, mixed>>
     */
    private function bundlePages(array $bundle): array
    {
        if (!is_array($bundle['pages'] ?? null)) {
            return [];
        }

        $pages = [];
        foreach ($bundle['pages'] as $page) {
            if (is_array($page)) {
                /** @var array<string, mixed> $assoc */
                $assoc = [];
                foreach ($page as $key => $value) {
                    $assoc[(string) $key] = $value;
                }
                $pages[] = $assoc;
            }
        }

        return $pages;
    }

    /**
     * @param array<int, array<string, mixed>> $sections
     * @param array<string, true> $names
     * @param-out array<string, true> $names
     */
    private function collectStyleNames(array $sections, array &$names): void
    {
        foreach ($sections as $section) {
            $styleName = $section['style_name'] ?? null;
            if (is_string($styleName) && $styleName !== '') {
                $names[$styleName] = true;
            }
            if (is_array($section['children'] ?? null)) {
                $this->collectStyleNames($this->asSectionList($section['children']), $names);
            }
        }
    }

    /**
     * Collect every section name carried by the bundle (recursively), used to
     * validate owner references resolve before the importer runs.
     *
     * @param array<int, array<string, mixed>> $sections
     * @param array<string, true> $names
     * @param-out array<string, true> $names
     */
    private function collectBundleSectionNames(array $sections, array &$names): void
    {
        foreach ($sections as $section) {
            $name = $this->asString($section['section_name'] ?? '');
            if ($name !== '') {
                $names[$name] = true;
            }
            if (is_array($section['children'] ?? null)) {
                $this->collectBundleSectionNames($this->asSectionList($section['children']), $names);
            }
        }
    }

    /**
     * Collect every `@section:<owner>` owner name referenced by a section's
     * `data_config` or by an `entry-table` `data_table` field (recursively).
     *
     * @param array<int, array<string, mixed>> $sections
     * @param array<string, true> $owners
     * @param-out array<string, true> $owners
     */
    private function collectOwnerTokens(array $sections, array &$owners): void
    {
        foreach ($sections as $section) {
            $globalFields = $section['global_fields'] ?? null;
            if (is_array($globalFields)) {
                $dataConfig = $globalFields['data_config'] ?? null;
                if (is_string($dataConfig) && str_contains($dataConfig, self::OWNER_TOKEN_PREFIX)) {
                    if (preg_match_all('/"table"\s*:\s*"' . preg_quote(self::OWNER_TOKEN_PREFIX, '/') . '([^"]+)"/', $dataConfig, $matches)) {
                        foreach ($matches[1] as $owner) {
                            $owners[$owner] = true;
                        }
                    }
                }
            }
            foreach ($this->entryTableOwnerTokens($section) as $owner) {
                $owners[$owner] = true;
            }
            if (is_array($section['children'] ?? null)) {
                $this->collectOwnerTokens($this->asSectionList($section['children']), $owners);
            }
        }
    }

    /**
     * Owner names referenced by a bundle section's `data_table` field token(s).
     * Empty for anything that is not an `entry-table` with `@section:` content.
     *
     * @param array<string, mixed> $section
     * @return list<string>
     */
    private function entryTableOwnerTokens(array $section): array
    {
        if ($this->asString($section['style_name'] ?? '') !== StyleNames::STYLE_ENTRY_TABLE) {
            return [];
        }
        $fields = $section['fields'] ?? null;
        if (!is_array($fields) || !is_array($fields['data_table'] ?? null)) {
            return [];
        }

        $owners = [];
        foreach ($fields['data_table'] as $entry) {
            $content = is_array($entry) ? $this->asString($entry['content'] ?? '') : '';
            if (str_starts_with($content, self::OWNER_TOKEN_PREFIX)) {
                $owners[] = substr($content, strlen(self::OWNER_TOKEN_PREFIX));
            }
        }

        return $owners;
    }

    /**
     * @param array<int, array<string, mixed>> $sections
     * @param array<string, true> $params
     * @param-out array<string, true> $params
     */
    private function collectRouteParamUsage(array $sections, array &$params): void
    {
        foreach ($sections as $section) {
            $encoded = json_encode($section);
            if (is_string($encoded)) {
                if (preg_match_all('/\{\{\s*route\.([A-Za-z0-9_]+)\s*\}\}/', $encoded, $matches)) {
                    foreach ($matches[1] as $param) {
                        $params[$param] = true;
                    }
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function extractPlaceholders(string $pattern): array
    {
        if (preg_match_all('/\{([^}]+)\}/', $pattern, $matches)) {
            return array_values(array_unique($matches[1]));
        }

        return [];
    }

    private function placeholdersAreValid(string $pattern): bool
    {
        // Reject unbalanced braces; every {name} must be a simple token.
        if (substr_count($pattern, '{') !== substr_count($pattern, '}')) {
            return false;
        }
        if (preg_match_all('/\{([^}]*)\}/', $pattern, $matches)) {
            foreach ($matches[1] as $name) {
                if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function prefixKeyword(string $prefix, string $keyword): string
    {
        $keyword = trim($keyword);
        if ($prefix === '' || $keyword === '') {
            return $keyword;
        }

        return $prefix . $keyword;
    }

    private function prefixRoute(string $prefix, string $pattern): string
    {
        $pattern = trim($pattern);
        if ($prefix === '' || $pattern === '') {
            return $pattern;
        }
        $normalizedPrefix = '/' . trim($prefix, '/');

        return $normalizedPrefix . $pattern;
    }

    /**
     * Rewrite the bundle's own in-content links for a prefixed import: every
     * URL field value that targets an in-bundle route base (the static part of
     * a bundled page's route pattern, e.g. `/team-members` or
     * `/cms/team-members`) gets the route prefix prepended — matching what
     * {@see prefixRoute} does to the routes themselves. External URLs,
     * anchors, and links to routes not in the bundle are left untouched.
     * Dynamic tails (`{{route.record_id}}` tokens, single-brace `{record_id}`
     * templates) sit after the static base and survive unchanged.
     *
     * @param list<array<string, mixed>> $pages
     * @return list<array<string, mixed>>
     */
    private function rewriteInContentLinksForPrefix(array $pages, string $routePrefix): array
    {
        // Static URL bases of every in-bundle route, longest first so the most
        // specific base wins (e.g. `/cms/team-members` before `/team-members`).
        $bases = [];
        foreach ($pages as $page) {
            $routes = is_array($page['routes'] ?? null) ? $page['routes'] : [];
            foreach ($routes as $route) {
                if (!is_array($route)) {
                    continue;
                }
                $pattern = $this->asString($route['path_pattern'] ?? '');
                $base = rtrim($this->routeStaticBase($pattern), '/');
                if ($base !== '' && $base !== '/') {
                    $bases[$base] = true;
                }
            }
        }
        if ($bases === []) {
            return $pages;
        }
        $baseList = array_keys($bases);
        usort($baseList, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($pages as &$page) {
            $sections = $this->asSectionList($page['sections'] ?? null);
            if ($sections !== []) {
                $this->rewriteSectionUrlFields($sections, $baseList, $routePrefix);
                $page['sections'] = $sections;
            }
        }
        unset($page);

        return $pages;
    }

    /**
     * The static prefix of a route pattern: everything before the first
     * `{placeholder}`.
     */
    private function routeStaticBase(string $pattern): string
    {
        $bracePos = strpos($pattern, '{');

        return $bracePos === false ? $pattern : substr($pattern, 0, $bracePos);
    }

    /**
     * @param array<int, array<string, mixed>> $sections
     * @param list<string> $baseList longest-first static route bases
     * @param-out array<int, array<string, mixed>> $sections
     */
    private function rewriteSectionUrlFields(array &$sections, array $baseList, string $routePrefix): void
    {
        foreach ($sections as &$section) {
            $fields = $section['fields'] ?? null;
            if (is_array($fields)) {
                $changed = false;
                foreach (self::URL_FIELD_NAMES as $fieldName) {
                    if (!is_array($fields[$fieldName] ?? null)) {
                        continue;
                    }
                    foreach ($fields[$fieldName] as $locale => $entry) {
                        if (!is_array($entry)) {
                            continue;
                        }
                        $content = $this->asString($entry['content'] ?? '');
                        $rewritten = $this->prefixInBundleUrl($content, $baseList, $routePrefix);
                        if ($rewritten !== null) {
                            $entry['content'] = $rewritten;
                            $fields[$fieldName][$locale] = $entry;
                            $changed = true;
                        }
                    }
                }
                if ($changed) {
                    $section['fields'] = $fields;
                }
            }
            if (is_array($section['children'] ?? null)) {
                $children = $this->asSectionList($section['children']);
                $this->rewriteSectionUrlFields($children, $baseList, $routePrefix);
                $section['children'] = $children;
            }
        }
        unset($section);
    }

    /**
     * Prefix a single URL value when it targets an in-bundle route base.
     * Returns the rewritten URL, or null when the value must stay unchanged
     * (external / anchor / empty / not an in-bundle target).
     *
     * @param list<string> $baseList longest-first static route bases
     */
    private function prefixInBundleUrl(string $url, array $baseList, string $routePrefix): ?string
    {
        if ($url === '' || $url[0] !== '/') {
            return null;
        }
        foreach ($baseList as $base) {
            if ($url === $base
                || str_starts_with($url, $base . '/')
                || str_starts_with($url, $base . '?')
                || str_starts_with($url, $base . '#')
            ) {
                return $this->prefixRoute($routePrefix, $url);
            }
        }

        return null;
    }

    private function normalizeSurface(mixed $surface): string
    {
        return $surface === 'cms' ? 'cms' : 'public';
    }

    /**
     * True when this bundle is a CMS-in-CMS template/app export that must carry
     * first-class `cms_app` metadata (no dual-format / legacy silent import).
     *
     * @param array<string, mixed> $bundle
     * @param list<array<string, mixed>> $pages
     */
    private function bundleLooksLikeCmsInCms(array $bundle, array $pages): bool
    {
        foreach (is_array($bundle['tags'] ?? null) ? $bundle['tags'] : [] as $tag) {
            if (is_string($tag) && strtolower($tag) === 'cms-in-cms') {
                return true;
            }
        }

        foreach ($pages as $page) {
            if (array_key_exists('cms_app_role', $page) && $page['cms_app_role'] !== null && $page['cms_app_role'] !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Create or reuse a CMS app shell for an import.
     *
     * Keyword prefixes from the examples gallery (`demo_team_members_`) may
     * contain underscores / mixed case that are legal for page keywords but
     * illegal for cms_apps.slug. Sanitise to kebab-case before create.
     * If an empty shell with that slug already exists, reuse it; if it already
     * has pages, fail with a clear conflict so operators can delete the shell
     * (Admin → CMS Apps → Delete shell) or change the keyword prefix.
     *
     * @param array<string, mixed> $cmsAppPayload
     */
    private function resolveCmsAppIdForImport(array $cmsAppPayload, string $keywordPrefix): int
    {
        $bundleSlug = trim($this->asString($cmsAppPayload['slug'] ?? ''));
        $name = trim($this->asString($cmsAppPayload['name'] ?? $bundleSlug));
        $description = isset($cmsAppPayload['description'])
            ? $this->asString($cmsAppPayload['description'])
            : null;

        $rawSlug = $bundleSlug;
        if ($keywordPrefix !== '' && $bundleSlug !== '') {
            $rawSlug = $this->prefixKeyword($keywordPrefix, $bundleSlug);
        } elseif ($keywordPrefix !== '' && $bundleSlug === '') {
            $rawSlug = $keywordPrefix;
        }

        $slug = $this->sanitizeCmsAppSlug($rawSlug);
        if ($slug === '') {
            $slug = 'app-' . substr(md5((string) microtime(true)), 0, 8);
        }

        $existing = $this->cmsAppRepository->findOneBySlug($slug);
        if ($existing instanceof CmsApp) {
            $pageCount = (int) $this->entityManager->getRepository(Page::class)->count(['cmsApp' => $existing]);
            if ($pageCount > 0) {
                throw new ServiceException(
                    sprintf(
                        'A CMS app with slug "%s" already has %d assigned page(s). '
                        . 'Delete that app shell under Admin → CMS Apps (pages and records are kept), '
                        . 'or change the keyword prefix and import again.',
                        $slug,
                        $pageCount
                    ),
                    Response::HTTP_CONFLICT
                );
            }

            if ($name !== '' && $existing->getName() !== $name) {
                $this->cmsAppService->updateApp((int) $existing->getId(), [
                    'name' => $name,
                    'description' => $description,
                ]);
            }

            return (int) $existing->getId();
        }

        $createdApp = $this->cmsAppService->createApp(
            $name !== '' ? $name : $slug,
            $slug,
            $description !== null && trim($description) !== '' ? $description : null,
        );

        return (int) $createdApp['id'];
    }

    /**
     * Normalise an imported CMS app slug to the kebab-case contract.
     */
    private function sanitizeCmsAppSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }

    /**
     * Enforce cms_app name/slug + a valid cms_app_role on every page.
     *
     * @param array<string, mixed> $bundle
     * @param list<array<string, mixed>> $pages
     * @param array<string, mixed>|null $cmsAppPayload
     */
    private function assertCmsInCmsBundleContract(array $bundle, array $pages, ?array $cmsAppPayload): void
    {
        unset($bundle);

        if ($cmsAppPayload === null) {
            throw new ServiceException(
                'CMS-in-CMS bundles require a top-level "cms_app" object with name and slug. '
                . 'Legacy page-only CMS app bundles are no longer supported.',
                Response::HTTP_BAD_REQUEST
            );
        }

        $name = trim($this->asString($cmsAppPayload['name'] ?? ''));
        $slug = trim($this->asString($cmsAppPayload['slug'] ?? ''));
        if ($name === '' || $slug === '') {
            throw new ServiceException(
                'cms_app.name and cms_app.slug are required on CMS-in-CMS bundles.',
                Response::HTTP_BAD_REQUEST
            );
        }

        if ($this->sanitizeCmsAppSlug($slug) === '') {
            throw new ServiceException(
                'cms_app.slug must contain at least one letter or number '
                . '(lowercase letters, numbers and hyphens after import normalisation).',
                Response::HTTP_BAD_REQUEST
            );
        }

        if ($pages === []) {
            throw new ServiceException(
                'CMS-in-CMS bundles must include at least one page with a cms_app_role.',
                Response::HTTP_BAD_REQUEST
            );
        }

        foreach ($pages as $page) {
            $keyword = $this->asString($page['keyword'] ?? '(unknown)');
            if (!array_key_exists('cms_app_role', $page) || $page['cms_app_role'] === null || $page['cms_app_role'] === '') {
                throw new ServiceException(
                    sprintf('Page "%s" is missing required cms_app_role for a CMS-in-CMS bundle.', $keyword),
                    Response::HTTP_BAD_REQUEST
                );
            }
            $role = $this->asString($page['cms_app_role']);
            if (!CmsAppRole::isValid($role)) {
                throw new ServiceException(
                    sprintf(
                        'Page "%s" has invalid cms_app_role "%s". Allowed: %s.',
                        $keyword,
                        $role,
                        implode(', ', CmsAppRole::ALL)
                    ),
                    Response::HTTP_BAD_REQUEST
                );
            }
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = $this->asString($value);

        return $string === '' ? null : $string;
    }

    /**
     * Coerce an import option into a de-duplicated list of positive group ids.
     * Accepts an array of ints / numeric strings; anything else yields [].
     *
     * @return list<int>
     */
    private function intListFromOption(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $item) {
            if (is_numeric($item) && (int) $item > 0) {
                $ids[(int) $item] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @return array{level:string, code:string, message:string, page_keyword:?string}
     */
    private function issue(string $level, string $code, string $message, ?string $pageKeyword): array
    {
        return [
            'level' => $level,
            'code' => $code,
            'message' => $message,
            'page_keyword' => $pageKeyword,
        ];
    }

    /**
     * @param array<string, mixed> $bundle
     * @param list<array{level:string, code:string, message:string, page_keyword:?string}> $issues
     * @param-out list<array{level:string, code:string, message:string, page_keyword:?string}> $issues
     */
    private function validateLegacyBundleNavigationIgnored(array $bundle, array &$issues): void
    {
        $navigation = $bundle['navigation'] ?? null;
        if (!is_array($navigation)) {
            return;
        }

        $assignments = $navigation['assignments'] ?? null;
        if (!is_array($assignments) || $assignments === []) {
            return;
        }

        $issues[] = $this->issue(
            self::ISSUE_WARNING,
            'navigation_membership_ignored',
            'This bundle contains legacy navigation assignments. Menu membership is not imported; assign pages to menus in the Navigation builder after import.',
            null,
        );
    }
}
