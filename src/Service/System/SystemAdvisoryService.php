<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Service\System;

use App\Plugin\Versioning\SemverHelper;
use App\Repository\Plugin\PluginRepository;

/**
 * Filters the registry security-advisory feed down to advisories that actually
 * affect THIS instance's installed components (core, frontend, and each
 * installed plugin), so the maintenance UI can show a focused, actionable list.
 *
 * Read-only and fail-soft: the feed is fetched through
 * {@see SystemRegistryGatewayInterface} (connected installs only). When the
 * registry is unreachable the result reports `available: false` with an empty
 * list, so the UI shows "could not check advisories" instead of blocking.
 *
 * Matching reuses {@see SemverHelper} (the same narrow range semantics the
 * plugin layer and `@selfhelp/shared` already agree on) — no new dependency.
 */
final class SystemAdvisoryService
{
    public function __construct(
        private readonly SystemRegistryGatewayInterface $gateway,
        private readonly SystemInstanceService $instance,
        private readonly PluginRepository $pluginRepository,
    ) {
    }

    /**
     * @return array{
     *     available: bool,
     *     advisories: list<array{
     *         id: string,
     *         severity: string,
     *         recommended_action: string,
     *         blocked: bool,
     *         details_url: string|null,
     *         affected: list<array{kind: string, id: string, installed_version: string}>,
     *         fixed_versions: list<string>
     *     }>
     * }
     */
    public function getAdvisories(): array
    {
        $feed = $this->gateway->fetchAdvisories();
        if ($feed === null) {
            return ['available' => false, 'advisories' => []];
        }

        $rawAdvisories = $feed['advisories'] ?? null;
        if (!is_array($rawAdvisories)) {
            return ['available' => true, 'advisories' => []];
        }

        $installed = $this->installedComponents();

        $advisories = [];
        foreach ($rawAdvisories as $advisory) {
            if (!is_array($advisory)) {
                continue;
            }
            $matched = $this->matchAffected($advisory, $installed);
            if ($matched === []) {
                continue;
            }
            $advisories[] = [
                'id' => $this->str($advisory['id'] ?? null),
                'severity' => $this->str($advisory['severity'] ?? null, 'low'),
                'recommended_action' => $this->str($advisory['recommendedAction'] ?? null),
                'blocked' => (bool) ($advisory['blocked'] ?? false),
                'details_url' => is_string($advisory['detailsUrl'] ?? null) ? $advisory['detailsUrl'] : null,
                'affected' => $matched,
                'fixed_versions' => $this->fixedVersionsFor($advisory, $matched),
            ];
        }

        return ['available' => true, 'advisories' => $advisories];
    }

    /**
     * The components installed on this instance, each with the exact version the
     * advisory ranges are matched against.
     *
     * @return list<array{kind: string, id: string, version: string}>
     */
    private function installedComponents(): array
    {
        $components = [
            ['kind' => 'core', 'id' => 'selfhelp-core', 'version' => $this->instance->getCmsVersion()],
            ['kind' => 'frontend', 'id' => 'selfhelp-frontend', 'version' => $this->instance->getFrontendVersion()],
        ];

        foreach ($this->pluginRepository->findAllOrderedByName() as $plugin) {
            $components[] = ['kind' => 'plugin', 'id' => $plugin->getPluginId(), 'version' => $plugin->getVersion()];
        }

        return $components;
    }

    /**
     * Returns the installed components an advisory's `affected` ranges match.
     * A plugin entry MUST name its `id`; core/frontend may omit it (one component
     * per instance) but must match when present.
     *
     * @param array<array-key, mixed> $advisory
     * @param list<array{kind: string, id: string, version: string}> $installed
     * @return list<array{kind: string, id: string, installed_version: string}>
     */
    private function matchAffected(array $advisory, array $installed): array
    {
        $affected = $advisory['affected'] ?? null;
        if (!is_array($affected)) {
            return [];
        }

        $matched = [];
        foreach ($affected as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $kind = $this->str($entry['kind'] ?? null);
            $entryId = is_string($entry['id'] ?? null) && $entry['id'] !== '' ? $entry['id'] : null;
            $range = $this->str($entry['versions'] ?? null);
            if ($kind === '' || $range === '') {
                continue;
            }

            foreach ($installed as $component) {
                if ($component['kind'] !== $kind) {
                    continue;
                }
                // A plugin advisory must target a specific plugin id.
                if ($kind === 'plugin' && $entryId === null) {
                    continue;
                }
                if ($entryId !== null && $entryId !== $component['id']) {
                    continue;
                }
                if (SemverHelper::satisfies($component['version'], $range)) {
                    $matched[] = [
                        'kind' => $component['kind'],
                        'id' => $component['id'],
                        'installed_version' => $component['version'],
                    ];
                }
            }
        }

        return $matched;
    }

    /**
     * Fixed versions relevant to the matched components (so the UI can tell the
     * operator exactly which version clears the advisory for what they run).
     *
     * @param array<array-key, mixed> $advisory
     * @param list<array{kind: string, id: string, installed_version: string}> $matched
     * @return list<string>
     */
    private function fixedVersionsFor(array $advisory, array $matched): array
    {
        $fixed = $advisory['fixed'] ?? null;
        if (!is_array($fixed)) {
            return [];
        }

        $versions = [];
        foreach ($fixed as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $fixedKind = $this->str($entry['kind'] ?? null);
            $fixedId = is_string($entry['id'] ?? null) && $entry['id'] !== '' ? $entry['id'] : null;
            $fixedVersion = $this->str($entry['version'] ?? null);
            if ($fixedVersion === '') {
                continue;
            }

            foreach ($matched as $component) {
                if ($component['kind'] === $fixedKind && ($fixedId === null || $fixedId === $component['id'])) {
                    $versions[$fixedVersion] = true;
                    break;
                }
            }
        }

        return array_keys($versions);
    }

    private function str(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }
}
