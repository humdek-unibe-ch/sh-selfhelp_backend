<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace Humdek\QaLiveBundle\Resource;

use App\Plugin\Event\DataAccessResourcesEvent;
use Humdek\QaLiveBundle\QaLiveCatalogue;
use Humdek\QaLiveBundle\Repository\QaLiveItemRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class QaLiveResourceSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly QaLiveItemRepository $items)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [DataAccessResourcesEvent::class => 'onResources'];
    }

    public function onResources(DataAccessResourcesEvent $event): void
    {
        if ($event->resourceTypeCode !== QaLiveCatalogue::TYPE_CODE) {
            return;
        }

        foreach ($this->items->listAll() as $item) {
            $id = $item->getId();
            if ($id === null) {
                continue;
            }
            $event->addResource($id, $item->getTitle());
        }
    }
}