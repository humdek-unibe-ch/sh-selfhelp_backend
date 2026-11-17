<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support\Plugin;

use App\Plugin\Event\SectionFieldBindingEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * QA double for a plugin that refuses a section field value pointing at one
 * of its resources the editor may not use (H7). Registered in
 * `config/services_test.yaml`; it refuses only its own qa- marker value, so it
 * never touches a real save.
 */
final class QaSectionFieldBindingSubscriber implements EventSubscriberInterface
{
    public const FORBIDDEN_VALUE = 'qa-h7-forbidden-resource';

    public static function getSubscribedEvents(): array
    {
        return [SectionFieldBindingEvent::class => 'onBinding'];
    }

    public function onBinding(SectionFieldBindingEvent $event): void
    {
        if ($event->value === self::FORBIDDEN_VALUE) {
            $event->deny('You may not use this QA resource.');
        }
    }
}
