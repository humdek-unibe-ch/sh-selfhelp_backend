<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Event\Domain;

/**
 * The five domain events a plugin may listen to (contract C24) and the name
 * each one has in a manifest's `listens`. The manifest schema enumerates the
 * same five names; nothing else is delivered.
 */
final class PluginDomainEvents
{
    public const RECORD_SAVED = 'record.saved';
    public const RECORD_DELETED = 'record.deleted';
    public const USER_CREATED = 'user.created';
    public const USER_DELETED = 'user.deleted';
    public const PAGE_PUBLISHED = 'page.published';

    /** Event class => manifest name. */
    public const BY_CLASS = [
        RecordSavedEvent::class => self::RECORD_SAVED,
        RecordDeletedEvent::class => self::RECORD_DELETED,
        UserCreatedEvent::class => self::USER_CREATED,
        UserDeletedEvent::class => self::USER_DELETED,
        PagePublishedEvent::class => self::PAGE_PUBLISHED,
    ];

    /**
     * The manifest name of a domain event, or null when the object is not one of the five.
     */
    public static function nameOf(object $event): ?string
    {
        return self::BY_CLASS[$event::class] ?? null;
    }

    /**
     * @return list<string> the five manifest names
     */
    public static function names(): array
    {
        return array_values(self::BY_CLASS);
    }

    private function __construct()
    {
    }
}
