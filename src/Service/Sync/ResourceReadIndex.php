<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\Plugin\Resource\ResourceFieldMap;
use App\Repository\SectionRepository;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\Effective\EffectivePage;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Where a plugin resource is used (contracts C2, C5, C7): the pages whose
 * draft holds it, the pages whose published version holds it, and the actions
 * whose job config holds it.
 *
 *   - A section holds a resource through a field a plugin lists in
 *     `styles[].resourceFields`; the pages are the ones that contain the
 *     section anywhere in their tree (draft) and the stored version JSON
 *     (published). {@see PageResourceScanner} reads one page.
 *   - An action holds a resource through a property a plugin lists in
 *     `scheduledJobs[].resourceFields`, in the `plugin_config` of a job of
 *     that type (`blocks[].jobs[]` of the action config).
 *
 * Like {@see PageTableReadIndex}, the answer is a plain derivation of the
 * configuration, kept as one item of the pages cache category: it is rebuilt
 * after any page, section or publish change ({@see invalidate()}, called by
 * `ChangeNoticeService`), when the plugin field lists change, and after a short
 * time as a safety net. A writer of action configs calls {@see invalidate()}
 * too, because the action part of the index reads them. It is a cache, never a
 * source of truth.
 *
 * The index maps resource type, then resource id, to page or action ids;
 * `signature` ties it to the plugin field lists it was built for.
 *
 * @phpstan-type Index array{
 *     signature: string,
 *     draft: array<string, array<int, list<int>>>,
 *     published: array<string, array<int, list<int>>>,
 *     actions: array<string, array<int, list<int>>>
 * }
 */
final class ResourceReadIndex
{
    private const KEY = 'sync_resource_read_index';
    private const TTL_SECONDS = 300;

    /** Keys of an action config that hold the job lists and a job's plugin config (C12). */
    private const BLOCKS = 'blocks';
    private const JOBS = 'jobs';
    private const JOB_TYPE = 'job_type';
    private const PLUGIN_CONFIG = 'plugin_config';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SectionRepository $sectionRepository,
        private readonly PageResourceScanner $scanner,
        private readonly ResourceFieldMap $fields,
        private readonly CacheService $cache,
    ) {
    }

    /**
     * @return list<int> ids of the pages whose draft (the live sections) holds the item
     */
    public function draftPagesOf(string $type, int $id): array
    {
        return $this->load()['draft'][$type][$id] ?? [];
    }

    /**
     * @return list<int> ids of the pages whose published version holds the item
     */
    public function publishedPagesOf(string $type, int $id): array
    {
        return $this->load()['published'][$type][$id] ?? [];
    }

    /**
     * @return list<int> ids of the actions with a job config that holds the item
     */
    public function actionsOf(string $type, int $id): array
    {
        return $this->load()['actions'][$type][$id] ?? [];
    }

    /**
     * Forget the index. Call it wherever a page, a section, a publish or an
     * action config changes (the same points as {@see PageTableReadIndex::invalidate()}).
     */
    public function invalidate(): void
    {
        $this->cache->withCategory(CacheService::CATEGORY_PAGES)->invalidateItem(self::KEY);
    }

    /**
     * @return Index
     */
    private function load(): array
    {
        $signature = $this->signature();
        $index = $this->read($signature);
        if ($index['signature'] !== $signature) {
            // A plugin was enabled, disabled or changed its resource fields since the index was built.
            $this->invalidate();
            $index = $this->read($signature);
        }

        return $index;
    }

    /**
     * @return Index
     */
    private function read(string $signature): array
    {
        /** @var Index $index */
        $index = $this->cache
            ->withCategory(CacheService::CATEGORY_PAGES)
            ->getItem(self::KEY, fn (): array => $this->build($signature), self::TTL_SECONDS);

        return $index;
    }

    private function signature(): string
    {
        return md5((string) json_encode([$this->fields->styles(), $this->fields->jobTypes()]));
    }

    /**
     * @return Index
     */
    private function build(string $signature): array
    {
        return [
            'signature' => $signature,
            'draft' => $this->draftReferences(),
            'published' => $this->publishedReferences(),
            'actions' => $this->actionReferences(),
        ];
    }

    /**
     * @return array<string, array<int, list<int>>> type => id => page ids
     */
    private function draftReferences(): array
    {
        $map = $this->fields->styles();
        if ($map === []) {
            return [];
        }

        $fieldNames = [];
        foreach ($map as $fields) {
            foreach (array_keys($fields) as $field) {
                $fieldNames[$field] = $field;
            }
        }

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT t.id_sections AS section_id, st.name AS style_name, f.name AS field_name, t.content AS content'
            . ' FROM sections_fields_translation t'
            . ' JOIN fields f ON f.id = t.id_fields'
            . ' JOIN sections s ON s.id = t.id_sections'
            . ' JOIN styles st ON st.id = s.id_styles'
            . ' WHERE st.name IN (?) AND f.name IN (?)',
            [array_keys($map), array_values($fieldNames)],
            [ArrayParameterType::STRING, ArrayParameterType::STRING],
        );

        /** @var array<int, array<string, array<int, int>>> $bySection section id => type => ids */
        $bySection = [];
        foreach ($rows as $row) {
            $sectionId = is_numeric($row['section_id'] ?? null) ? (int) $row['section_id'] : 0;
            $style = is_string($row['style_name'] ?? null) ? $row['style_name'] : '';
            $field = is_string($row['field_name'] ?? null) ? $row['field_name'] : '';
            $type = $map[$style][$field] ?? null;
            $id = PageResourceScanner::idOf(is_scalar($row['content'] ?? null) ? (string) $row['content'] : '');
            if ($sectionId > 0 && $type !== null && $id > 0) {
                $bySection[$sectionId][$type][$id] = $id;
            }
        }

        /** @var array<string, array<int, array<int, int>>> $pages */
        $pages = [];
        foreach ($bySection as $sectionId => $byType) {
            $pageIds = $this->sectionRepository->getPageIdsContainingSection($sectionId);
            foreach ($byType as $type => $ids) {
                foreach ($ids as $id) {
                    foreach ($pageIds as $pageId) {
                        $pages[$type][$id][$pageId] = $pageId;
                    }
                }
            }
        }

        return self::sortedLists($pages);
    }

    /**
     * @return array<string, array<int, list<int>>> type => id => page ids
     */
    private function publishedReferences(): array
    {
        if ($this->fields->styles() === []) {
            return [];
        }
        $connection = $this->entityManager->getConnection();

        /** @var array<int, int> $versionByPage */
        $versionByPage = [];
        foreach ($connection->fetchAllAssociative('SELECT id, id_published_page_versions FROM pages WHERE id_published_page_versions IS NOT NULL') as $row) {
            if (is_numeric($row['id'] ?? null) && is_numeric($row['id_published_page_versions'] ?? null)) {
                $versionByPage[(int) $row['id']] = (int) $row['id_published_page_versions'];
            }
        }
        if ($versionByPage === []) {
            return [];
        }

        $rows = $connection->fetchAllAssociative(
            'SELECT id, page_json FROM page_versions WHERE id IN (?)',
            [array_values($versionByPage)],
            [ArrayParameterType::INTEGER],
        );
        $jsonByVersion = [];
        foreach ($rows as $row) {
            if (is_numeric($row['id'] ?? null) && is_string($row['page_json'] ?? null)) {
                $decoded = json_decode($row['page_json'], true);
                if (is_array($decoded)) {
                    $jsonByVersion[(int) $row['id']] = $decoded;
                }
            }
        }

        /** @var array<string, array<int, array<int, int>>> $pages */
        $pages = [];
        foreach ($versionByPage as $pageId => $versionId) {
            if (!isset($jsonByVersion[$versionId])) {
                continue;
            }
            foreach ($this->scanner->scan($this->publishedPage($pageId, $versionId, $jsonByVersion[$versionId])) as $resource) {
                $pages[$resource['type']][$resource['id']][$pageId] = $pageId;
            }
        }

        return self::sortedLists($pages);
    }

    /**
     * @param array<array-key, mixed> $json the stored JSON of a published version
     */
    private function publishedPage(int $pageId, int $versionId, array $json): EffectivePage
    {
        $pageNode = $json['page'] ?? null;
        $sections = is_array($pageNode) && is_array($pageNode['sections'] ?? null) ? array_values($pageNode['sections']) : [];
        /** @var list<array<string, mixed>> $sections */

        return EffectivePage::published($pageId, $versionId, $sections);
    }

    /**
     * @return array<string, array<int, list<int>>> type => id => action ids
     */
    private function actionReferences(): array
    {
        $map = $this->fields->jobTypes();
        if ($map === []) {
            return [];
        }

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT id, config FROM actions WHERE config IS NOT NULL AND config LIKE :needle',
            ['needle' => '%' . self::PLUGIN_CONFIG . '%'],
        );

        /** @var array<string, array<int, array<int, int>>> $actions */
        $actions = [];
        foreach ($rows as $row) {
            $actionId = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
            $config = is_string($row['config'] ?? null) ? json_decode($row['config'], true) : null;
            if ($actionId <= 0 || !is_array($config)) {
                continue;
            }
            foreach ($this->jobsOf($config) as $job) {
                $jobType = is_string($job[self::JOB_TYPE] ?? null) ? $job[self::JOB_TYPE] : '';
                $pluginConfig = $job[self::PLUGIN_CONFIG] ?? null;
                if (!isset($map[$jobType]) || !is_array($pluginConfig)) {
                    continue;
                }
                foreach ($map[$jobType] as $property => $type) {
                    $id = PageResourceScanner::idOf(is_scalar($pluginConfig[$property] ?? null) ? (string) $pluginConfig[$property] : '');
                    if ($id > 0) {
                        $actions[$type][$id][$actionId] = $actionId;
                    }
                }
            }
        }

        return self::sortedLists($actions);
    }

    /**
     * @param array<array-key, mixed> $config an action config
     * @return list<array<array-key, mixed>> every job of every block
     */
    private function jobsOf(array $config): array
    {
        $jobs = [];
        $blocks = $config[self::BLOCKS] ?? null;
        foreach (is_array($blocks) ? $blocks : [] as $block) {
            $blockJobs = is_array($block) ? ($block[self::JOBS] ?? null) : null;
            foreach (is_array($blockJobs) ? $blockJobs : [] as $job) {
                if (is_array($job)) {
                    $jobs[] = $job;
                }
            }
        }

        return $jobs;
    }

    /**
     * @param array<string, array<int, array<int, int>>> $byTypeAndId
     * @return array<string, array<int, list<int>>> the same map with sorted lists of ids
     */
    private static function sortedLists(array $byTypeAndId): array
    {
        $sorted = [];
        foreach ($byTypeAndId as $type => $byId) {
            foreach ($byId as $id => $owners) {
                $ids = array_values($owners);
                sort($ids);
                $sorted[$type][$id] = $ids;
            }
        }

        return $sorted;
    }
}
