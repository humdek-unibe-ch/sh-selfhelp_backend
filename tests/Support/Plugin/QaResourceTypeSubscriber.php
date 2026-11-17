<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support\Plugin;

use App\Plugin\Event\DataAccessResourcesEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * QA double for a plugin that lists the resources of its data-access
 * resource type (H6). Registered in `config/services_test.yaml`; answers only
 * for its own qa_-prefixed type code, so it never touches a real one.
 */
final class QaResourceTypeSubscriber implements EventSubscriberInterface
{
    public const TYPE_CODE = 'qa_catalogue_survey';

    public static function getSubscribedEvents(): array
    {
        return [DataAccessResourcesEvent::class => 'onResources'];
    }

    public function onResources(DataAccessResourcesEvent $event): void
    {
        if ($event->resourceTypeCode !== self::TYPE_CODE) {
            return;
        }
        $event->addResource(7, 'QA second survey');
        $event->addResource(3, 'QA first survey', 'qa description');
    }
}
