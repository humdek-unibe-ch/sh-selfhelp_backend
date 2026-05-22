<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when `LookupService` resolves a type code. Plugins may
 * contribute additional rows or, when they own a type code, declare
 * the full list.
 *
 * Lookup ownership policies:
 *   - `closed`            — core-owned; plugins may read but not extend.
 *   - `plugin_extendable` — core-owned; plugins may add entries.
 *   - `plugin_owned`      — fully owned by one plugin.
 *
 * The `LookupRegistryRulesService` validates additions before they are
 * surfaced. Runtime insert/update/delete is NOT done through this
 * event — lookup mutations happen exclusively through plugin
 * install/update migrations.
 */
final class LookupRegistryEvent extends Event
{
    /**
     * @var array<int, array{
     *   pluginId: string,
     *   typeCode: string,
     *   ownership: 'closed'|'plugin_extendable'|'plugin_owned',
     *   entries: array<int, array{code: string, value: string, description?: string}>,
     * }>
     */
    private array $contributions = [];

    public function __construct(private readonly ?string $filterTypeCode = null)
    {
    }

    /**
     * Limit notifications to a specific type code, or null to receive
     * all contributions.
     */
    public function getFilterTypeCode(): ?string
    {
        return $this->filterTypeCode;
    }

    /**
     * @param array<int, array{code: string, value: string, description?: string}> $entries
     */
    public function addContribution(
        string $pluginId,
        string $typeCode,
        string $ownership,
        array $entries,
    ): void {
        if ($this->filterTypeCode !== null && $this->filterTypeCode !== $typeCode) {
            return;
        }
        if (!in_array($ownership, ['closed', 'plugin_extendable', 'plugin_owned'], true)) {
            throw new \InvalidArgumentException(sprintf('Invalid ownership "%s" for type "%s".', $ownership, $typeCode));
        }
        $this->contributions[] = [
            'pluginId' => $pluginId,
            'typeCode' => $typeCode,
            'ownership' => $ownership,
            'entries' => $entries,
        ];
    }

    /**
     * @return array<int, array{
     *   pluginId: string,
     *   typeCode: string,
     *   ownership: 'closed'|'plugin_extendable'|'plugin_owned',
     *   entries: array<int, array{code: string, value: string, description?: string}>,
     * }>
     */
    public function getContributions(): array
    {
        return $this->contributions;
    }
}
