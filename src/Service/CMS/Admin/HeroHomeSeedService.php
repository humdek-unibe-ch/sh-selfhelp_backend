<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\Admin;

use App\Entity\Page;
use App\Repository\PageRepository;
use App\Repository\SectionRepository;
use App\Service\CMS\NavigationCacheInvalidator;
use App\Service\Core\BaseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Seeds the polished hero-home example onto the system `home` page when it still
 * carries the untouched baseline content from the reference-data migration.
 */
class HeroHomeSeedService extends BaseService
{
    private const BUNDLE_PATH = '/docs/examples/hero-home.bundle.json';

    public function __construct(
        private readonly PageRepository $pageRepository,
        private readonly SectionRepository $sectionRepository,
        private readonly SectionRelationshipService $sectionRelationshipService,
        private readonly SectionExportImportService $sectionExportImportService,
        private readonly NavigationCacheInvalidator $navigationCacheInvalidator,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $projectDir,
    ) {
    }

    public function isUntouchedDefaultHome(?Page $page = null): bool
    {
        $page ??= $this->pageRepository->findOneBy(['keyword' => 'home']);
        if (!$page instanceof Page) {
            return false;
        }

        $pageId = $page->getId();
        if (!is_int($pageId)) {
            return false;
        }

        $connection = $this->entityManager->getConnection();
        $baselineRaw = $connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM sections s
                INNER JOIN rel_pages_sections ps ON ps.id_sections = s.id
                WHERE ps.id_pages = :pageId
                  AND s.name LIKE 'home-sys%'
                SQL,
            ['pageId' => $pageId],
        );
        $baselineCount = is_numeric($baselineRaw) ? (int) $baselineRaw : 0;

        $heroMigratedRaw = $connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM sections s
                INNER JOIN rel_pages_sections ps ON ps.id_sections = s.id
                WHERE ps.id_pages = :pageId
                  AND s.name LIKE 'hero-home-mig%'
                SQL,
            ['pageId' => $pageId],
        );
        $heroMigratedCount = is_numeric($heroMigratedRaw) ? (int) $heroMigratedRaw : 0;
        if ($heroMigratedCount > 0) {
            return false;
        }

        if ($baselineCount === 0) {
            return false;
        }

        $customRaw = $connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM sections s
                INNER JOIN rel_pages_sections ps ON ps.id_sections = s.id
                WHERE ps.id_pages = :pageId
                  AND s.name NOT LIKE 'home-sys%'
                SQL,
            ['pageId' => $pageId],
        );
        $customCount = is_numeric($customRaw) ? (int) $customRaw : 0;

        return $customCount === 0;
    }

    /**
     * @return array{seeded: bool, reason: string}
     */
    public function seedHeroHomeIfUntouched(bool $force = false): array
    {
        $page = $this->pageRepository->findOneBy(['keyword' => 'home']);
        if (!$page instanceof Page) {
            return ['seeded' => false, 'reason' => 'home page not found'];
        }

        $pageId = $page->getId();
        if (!is_int($pageId)) {
            return ['seeded' => false, 'reason' => 'home page id missing'];
        }

        if (!$force && !$this->isUntouchedDefaultHome($page)) {
            return ['seeded' => false, 'reason' => 'home page was already customized'];
        }

        $bundlePath = rtrim($this->projectDir, '/\\') . self::BUNDLE_PATH;
        if (!is_file($bundlePath)) {
            $this->throwNotFound('Hero home bundle not found at ' . self::BUNDLE_PATH);
        }

        $raw = file_get_contents($bundlePath);
        if ($raw === false) {
            throw new \RuntimeException('Unable to read hero home bundle.');
        }

        /** @var array<string, mixed> $bundle */
        $bundle = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $pages = $bundle['pages'] ?? null;
        if (!is_array($pages) || !isset($pages[0]) || !is_array($pages[0])) {
            $this->throwBadRequest('Hero home bundle is missing pages[0].');
        }

        /** @var list<array<string, mixed>> $sections */
        $sections = is_array($pages[0]['sections'] ?? null) ? $pages[0]['sections'] : [];
        if ($sections === []) {
            $this->throwBadRequest('Hero home bundle has no sections.');
        }

        $this->entityManager->beginTransaction();
        try {
            foreach ($this->sectionRepository->getSectionIdsForPage($pageId) as $sectionId) {
                $this->sectionRelationshipService->deleteSection((int) $sectionId);
            }

            $this->sectionExportImportService->importSectionsToPage($pageId, $sections);
            $this->navigationCacheInvalidator->invalidateForPage($pageId);
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw $e instanceof \App\Exception\ServiceException
                ? $e
                : new \App\Exception\ServiceException(
                    'Hero home seed failed: ' . $e->getMessage(),
                    Response::HTTP_INTERNAL_SERVER_ERROR,
                    ['previous' => $e->getMessage()],
                );
        }

        return ['seeded' => true, 'reason' => 'hero sections imported onto home'];
    }
}
