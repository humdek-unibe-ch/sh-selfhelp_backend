<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace Humdek\QaLiveBundle\Service;

use App\Plugin\Resource\PluginResourceServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Humdek\QaLiveBundle\Entity\QaLiveItem;
use Humdek\QaLiveBundle\QaLiveCatalogue;
use Humdek\QaLiveBundle\Repository\QaLiveItemRepository;

final class QaLiveItemService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly QaLiveItemRepository $items,
        private readonly PluginResourceServiceInterface $resources,
    ) {
    }

    public function create(string $title, int $userId): QaLiveItem
    {
        $item = new QaLiveItem();
        $item->setTitle($title);

        $this->em->wrapInTransaction(function () use ($item, $userId): void {
            $this->em->persist($item);
            $this->em->flush();
            $id = $item->getId();
            if ($id === null) {
                return;
            }
            if ($userId > 0) {
                $this->resources->grantCreator(QaLiveCatalogue::PERMISSION_CREATE, QaLiveCatalogue::TYPE_CODE, $id);
            }
        });

        $id = $item->getId();
        if ($id !== null && $id > 0) {
            $this->resources->changed(QaLiveCatalogue::TYPE_CODE, $id, false);
        }

        return $item;
    }

    public function updateTitle(int $id, string $title): ?QaLiveItem
    {
        $item = $this->items->find($id);
        if (!$item instanceof QaLiveItem) {
            return null;
        }

        $item->setTitle($title);
        $this->em->flush();
        $this->resources->changed(QaLiveCatalogue::TYPE_CODE, $id, false);

        return $item;
    }

    public function publish(int $id): ?QaLiveItem
    {
        $item = $this->items->find($id);
        if (!$item instanceof QaLiveItem) {
            return null;
        }

        $next = $item->getPublishedVersion() + 1;
        $map = $item->getVersionsMap();
        $map[$next] = $item->getTitle();
        $item->setVersionsMap($map);
        $item->setPublishedVersion($next);
        $this->em->flush();
        $this->resources->changed(QaLiveCatalogue::TYPE_CODE, $id, true);

        return $item;
    }

    public function delete(int $id): bool
    {
        $item = $this->items->find($id);
        if (!$item instanceof QaLiveItem) {
            return false;
        }

        $this->em->remove($item);
        $this->em->flush();
        $this->resources->deleted(QaLiveCatalogue::TYPE_CODE, $id);

        return true;
    }

    /** @return list<array{id: int, title: string, published_version: int}> */
    public function listAll(): array
    {
        $rows = [];
        foreach ($this->items->listAll() as $item) {
            $id = $item->getId();
            if ($id === null) {
                continue;
            }
            $rows[] = [
                'id' => $id,
                'title' => $item->getTitle(),
                'published_version' => $item->getPublishedVersion(),
            ];
        }

        return $rows;
    }

    /**
     * @return array{title: string, version: int}|null
     */
    public function resolvePublished(int $id, ?int $pinnedVersion): ?array
    {
        $item = $this->items->find($id);
        if (!$item instanceof QaLiveItem) {
            return null;
        }

        if ($item->getPublishedVersion() < 1) {
            return null;
        }

        $version = $pinnedVersion ?? $item->getPublishedVersion();
        if ($version < 1 || $version > $item->getPublishedVersion()) {
            return null;
        }

        $title = $item->titleAtVersion($version);
        if ($title === null) {
            return null;
        }

        return ['title' => $title, 'version' => $version];
    }
}
