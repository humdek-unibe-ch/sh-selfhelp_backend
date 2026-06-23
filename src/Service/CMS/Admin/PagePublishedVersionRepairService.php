<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\CMS\Admin;

use App\Entity\Page;
use App\Repository\PageRepository;
use App\Service\Core\BaseService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Repairs legacy/manual dangling published-version pointers before Doctrine
 * hydrates a Page entity.
 */
class PagePublishedVersionRepairService extends BaseService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PageRepository $pageRepository,
    ) {
    }

    public function loadPageRepairingDanglingPublishedVersion(int $pageId): ?Page
    {
        $connection = $this->entityManager->getConnection();
        $row = $connection->fetchAssociative(
            'SELECT id_published_page_versions FROM pages WHERE id = :pageId',
            ['pageId' => $pageId]
        );

        if ($row === false) {
            return null;
        }

        $publishedVersionId = $row['id_published_page_versions'] ?? null;
        if ($publishedVersionId !== null) {
            $versionCount = $connection->fetchOne(
                'SELECT COUNT(*) FROM page_versions WHERE id = :versionId',
                ['versionId' => $this->asInt($publishedVersionId)]
            );
            $versionExists = $versionCount === 1 || $versionCount === '1';

            if (!$versionExists) {
                $connection->executeStatement(
                    'UPDATE pages SET id_published_page_versions = NULL WHERE id = :pageId',
                    ['pageId' => $pageId]
                );
                $this->entityManager->clear();
            }
        }

        return $this->pageRepository->find($pageId);
    }
}
