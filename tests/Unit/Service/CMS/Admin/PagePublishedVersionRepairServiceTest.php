<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\CMS\Admin;

use App\Entity\Page;
use App\Repository\PageRepository;
use App\Service\CMS\Admin\PagePublishedVersionRepairService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class PagePublishedVersionRepairServiceTest extends TestCase
{
    public function testLoadPageRepairsDanglingPublishedVersionPointerBeforeHydration(): void
    {
        $page = new Page();
        $this->setEntityId($page, 100);

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAssociative')
            ->with(
                'SELECT id_published_page_versions FROM pages WHERE id = :pageId',
                ['pageId' => 100]
            )
            ->willReturn(['id_published_page_versions' => 11]);
        $connection->expects($this->once())
            ->method('fetchOne')
            ->with(
                'SELECT COUNT(*) FROM page_versions WHERE id = :versionId',
                ['versionId' => 11]
            )
            ->willReturn('0');
        $connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                'UPDATE pages SET id_published_page_versions = NULL WHERE id = :pageId',
                ['pageId' => 100]
            );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('getConnection')->willReturn($connection);
        $entityManager->expects($this->once())->method('clear');

        $pageRepository = $this->createMock(PageRepository::class);
        $pageRepository->expects($this->once())
            ->method('find')
            ->with(100)
            ->willReturn($page);

        $service = new PagePublishedVersionRepairService($entityManager, $pageRepository);

        self::assertSame($page, $service->loadPageRepairingDanglingPublishedVersion(100));
    }

    private function setEntityId(Page $page, int $id): void
    {
        $reflection = new \ReflectionProperty(Page::class, 'id');
        $reflection->setValue($page, $id);
    }
}
