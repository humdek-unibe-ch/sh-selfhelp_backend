<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Lookup;

use App\Service\Core\LookupService;

/**
 * Static mapping of every core `lookup` type code to its
 * {@see LookupExtensionPolicy} class.
 *
 * Plugins ask {@see \App\Service\Core\LookupService::getLookups()} for
 * a type code; the service dispatches {@see \App\Plugin\Event\LookupRegistryEvent}
 * to collect contributions and then asks this registry whether each
 * contribution's `ownership` claim matches the host's known policy
 * for the type. Mismatches are rejected silently from the response
 * (logged for the doctor command).
 *
 * Plugin-owned type codes are NOT enumerated here — they are claimed
 * by plugins at install time via `plugin.json#lookups`. When a type
 * is missing from this map AND a contribution claims ownership =
 * `plugin_owned`, the registry records the (typeCode → pluginId)
 * binding so subsequent listeners cannot squat on someone else's
 * plugin-owned type.
 *
 * Add a new core type code by mapping it here whenever a new
 * `lookups` row group is created.
 */
final class LookupPolicyRegistry
{
    /**
     * @var array<string, LookupExtensionPolicy::CLOSED|LookupExtensionPolicy::PLUGIN_EXTENDABLE|LookupExtensionPolicy::PLUGIN_OWNED>
     */
    private const CORE_POLICIES = [
        // --- Core, immutable from plugins ---
        LookupService::TRANSACTION_TYPES => LookupExtensionPolicy::CLOSED,
        LookupService::TRANSACTION_BY => LookupExtensionPolicy::CLOSED,
        LookupService::SCHEDULED_JOBS_STATUS => LookupExtensionPolicy::CLOSED,
        LookupService::SCHEDULED_JOBS_SEARCH_DATE_TYPES => LookupExtensionPolicy::CLOSED,
        LookupService::PAGE_ACCESS_TYPES => LookupExtensionPolicy::CLOSED,
        LookupService::HOOK_TYPES => LookupExtensionPolicy::CLOSED,
        LookupService::ASSET_TYPES => LookupExtensionPolicy::CLOSED,
        LookupService::GROUP_TYPES => LookupExtensionPolicy::CLOSED,
        LookupService::USER_TYPES => LookupExtensionPolicy::CLOSED,
        LookupService::USER_STATUS => LookupExtensionPolicy::CLOSED,
        LookupService::STYLE_TYPE => LookupExtensionPolicy::CLOSED,
        LookupService::WEEKDAYS => LookupExtensionPolicy::CLOSED,
        LookupService::TIME_PERIOD => LookupExtensionPolicy::CLOSED,
        LookupService::TIMEZONES => LookupExtensionPolicy::CLOSED,
        LookupService::ACTION_SCHEDULE_TYPES => LookupExtensionPolicy::CLOSED,
        LookupService::ACTION_TRIGGER_TYPES => LookupExtensionPolicy::CLOSED,
        LookupService::AUDIT_ACTIONS => LookupExtensionPolicy::CLOSED,
        LookupService::PERMISSION_RESULTS => LookupExtensionPolicy::CLOSED,
        LookupService::RESOURCE_TYPES => LookupExtensionPolicy::CLOSED,

        // --- Core, plugin-extendable ---
        LookupService::JOB_TYPES => LookupExtensionPolicy::PLUGIN_EXTENDABLE,
        LookupService::NOTIFICATION_TYPES => LookupExtensionPolicy::PLUGIN_EXTENDABLE,
        LookupService::PLUGINS => LookupExtensionPolicy::PLUGIN_EXTENDABLE,
    ];

    /**
     * Plugin-owned type codes are bound at registration time so two
     * plugins cannot both claim ownership of the same type code.
     *
     * @var array<string,string>  typeCode => pluginId
     */
    private array $pluginOwnedBindings = [];

    /**
     * Returns the policy that governs `$typeCode`, or `null` when the
     * type is unknown (neither in the static core map nor claimed by
     * a plugin). The lookup service uses `null` to mean "no
     * contributions allowed" — safer than silent acceptance.
     */
    public function policyFor(string $typeCode): ?string
    {
        if (isset(self::CORE_POLICIES[$typeCode])) {
            return self::CORE_POLICIES[$typeCode];
        }
        if (isset($this->pluginOwnedBindings[$typeCode])) {
            return LookupExtensionPolicy::PLUGIN_OWNED;
        }
        return null;
    }

    /**
     * Returns the plugin id that owns `$typeCode`, or `null` when the
     * type is core-owned or not yet claimed.
     */
    public function ownerPluginId(string $typeCode): ?string
    {
        return $this->pluginOwnedBindings[$typeCode] ?? null;
    }

    /**
     * Claim a type code for a plugin. First-come-first-served — a
     * second plugin trying to claim the same type code is rejected.
     * Core-owned type codes (in {@see CORE_POLICIES}) can never be
     * claimed as plugin-owned.
     *
     * @return bool true when the binding was accepted, false when
     *              rejected (already owned by core or another plugin).
     */
    public function tryClaimPluginOwned(string $typeCode, string $pluginId): bool
    {
        if (isset(self::CORE_POLICIES[$typeCode])) {
            return false;
        }
        if (isset($this->pluginOwnedBindings[$typeCode]) && $this->pluginOwnedBindings[$typeCode] !== $pluginId) {
            return false;
        }
        $this->pluginOwnedBindings[$typeCode] = $pluginId;
        return true;
    }

    /**
     * Determines whether a plugin contribution with the declared
     * `ownership` is acceptable for `$typeCode`. The rules:
     *
     *   - CLOSED: no contributions accepted.
     *   - PLUGIN_EXTENDABLE: only `plugin_extendable` contributions are
     *     accepted; `plugin_owned`/`closed` mismatches the host's
     *     declared policy and is refused.
     *   - PLUGIN_OWNED: only the owning plugin may add `plugin_owned`
     *     entries. Other plugins are refused.
     *   - Unknown (null): refused — plugins can't introduce new type
     *     codes through the runtime event; they must declare them in
     *     `plugin.json#lookups` so the installer registers ownership.
     */
    public function isContributionAllowed(string $typeCode, string $contributionOwnership, string $contributingPluginId): bool
    {
        if (!LookupExtensionPolicy::isValid($contributionOwnership)) {
            return false;
        }
        $policy = $this->policyFor($typeCode);
        if ($policy === null) {
            return false;
        }
        return match ($policy) {
            LookupExtensionPolicy::CLOSED => false,
            LookupExtensionPolicy::PLUGIN_EXTENDABLE => $contributionOwnership === LookupExtensionPolicy::PLUGIN_EXTENDABLE,
            LookupExtensionPolicy::PLUGIN_OWNED => $contributionOwnership === LookupExtensionPolicy::PLUGIN_OWNED
                && $this->pluginOwnedBindings[$typeCode] === $contributingPluginId,
            default => false,
        };
    }
}
