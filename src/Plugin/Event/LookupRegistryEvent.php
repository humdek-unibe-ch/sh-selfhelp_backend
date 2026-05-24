<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Event;

use App\Plugin\Lookup\LookupExtensionPolicy;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when {@see \App\Service\Core\LookupService} resolves a
 * type code. Plugins may contribute additional rows or, when they own
 * a type code, declare the full list.
 *
 * Each contribution carries the plugin's claimed ownership policy
 * (one of {@see LookupExtensionPolicy::ALL}). The host then asks
 * {@see \App\Plugin\Lookup\LookupPolicyRegistry::isContributionAllowed()}
 * whether the contribution matches the registered policy for the type
 * code; mismatched contributions are silently dropped (and logged for
 * the doctor command).
 *
 * Runtime insert/update/delete is NOT done through this event —
 * lookup mutations happen exclusively through plugin install/update
 * migrations. This event surfaces virtual entries that haven't been
 * persisted yet (or simply augment a cached read).
 */
final class LookupRegistryEvent extends Event
{
    /**
     * @var array<int, array{
     *   pluginId: string,
     *   typeCode: string,
     *   ownership: LookupExtensionPolicy::CLOSED|LookupExtensionPolicy::PLUGIN_EXTENDABLE|LookupExtensionPolicy::PLUGIN_OWNED,
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
        if (!LookupExtensionPolicy::isValid($ownership)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid lookup ownership "%s" for type "%s". Expected one of: %s.',
                $ownership,
                $typeCode,
                implode(', ', LookupExtensionPolicy::ALL),
            ));
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
     *   ownership: LookupExtensionPolicy::CLOSED|LookupExtensionPolicy::PLUGIN_EXTENDABLE|LookupExtensionPolicy::PLUGIN_OWNED,
     *   entries: array<int, array{code: string, value: string, description?: string}>,
     * }>
     */
    public function getContributions(): array
    {
        return $this->contributions;
    }
}
