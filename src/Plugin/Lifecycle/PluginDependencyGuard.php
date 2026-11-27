<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Lifecycle;

use App\Entity\Plugin\Plugin;
use App\Exception\ServiceException;
use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Versioning\SemverHelper;
use App\Repository\Plugin\PluginRepository;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the `dependencies` and `conflicts` of plugin manifests (contract
 * C21). Both used to be read and never checked.
 *
 *   - {@see assertCanActivate()} runs for install, update and enable: every
 *     **required** dependency must be installed, enabled and within its
 *     version range (409 `plugin_dependency_missing`), and no enabled plugin
 *     may be in conflict with this one (409 `plugin_conflict`), in either
 *     direction: this manifest's `conflicts` against the enabled plugins, and
 *     the enabled plugins' `conflicts` against this plugin. The same call
 *     refuses a name that must be unique across enabled plugins (contract C2):
 *     a `settings[].envOverride` is one environment variable and the namespace
 *     of a `jsonLogicOperations[].name` (the part before the dot) belongs to
 *     one plugin (409 `plugin_manifest_collision`).
 *   - {@see assertNoEnabledDependents()} runs for disable, uninstall and
 *     purge: no enabled plugin may require the plugin (409
 *     `plugin_dependents_enabled`). {@see assertUpdateKeepsDependents()}
 *     runs for update: the new version must stay within the range every
 *     enabled dependent requires (same error).
 *
 * A dependency with `required: false` is a hint and never blocks. Safe mode
 * does not go through this class.
 */
final class PluginDependencyGuard
{
    public const ERROR_DEPENDENCY_MISSING = 'plugin_dependency_missing';
    public const ERROR_DEPENDENTS_ENABLED = 'plugin_dependents_enabled';
    public const ERROR_CONFLICT = 'plugin_conflict';
    public const ERROR_MANIFEST_COLLISION = 'plugin_manifest_collision';

    /** What {@see sharedNamesOf()} reports: an environment variable name and a JSON Logic namespace. */
    private const KIND_ENV_OVERRIDE = 'env_override';
    private const KIND_JSON_LOGIC_NAMESPACE = 'json_logic_namespace';

    public function __construct(private readonly PluginRepository $plugins)
    {
    }

    /**
     * @throws ServiceException 409 `plugin_dependency_missing` (data `unmet`), `plugin_conflict` (data `conflicts`)
     *         or `plugin_manifest_collision` (data `collisions`)
     */
    public function assertCanActivate(PluginManifest $manifest): void
    {
        $unmet = [];
        foreach ($manifest->getDependencies() as $dependency) {
            $pluginId = $dependency['pluginId'] ?? null;
            if (!is_string($pluginId) || ($dependency['required'] ?? true) !== true) {
                continue;
            }
            $range = is_string($dependency['version'] ?? null) ? $dependency['version'] : '*';

            $installed = $this->plugins->findOneByPluginId($pluginId);
            $reason = match (true) {
                !$installed instanceof Plugin => 'missing',
                !$installed->isEnabled() => 'disabled',
                !SemverHelper::satisfies($installed->getVersion(), $range) => 'out_of_range',
                default => null,
            };
            if ($reason !== null) {
                $unmet[] = [
                    'plugin_id' => $pluginId,
                    'version' => $range,
                    'reason' => $reason,
                    'installed_version' => $installed instanceof Plugin ? $installed->getVersion() : null,
                ];
            }
        }
        if ($unmet !== []) {
            throw new ServiceException(self::ERROR_DEPENDENCY_MISSING, Response::HTTP_CONFLICT, ['unmet' => $unmet]);
        }

        $conflicts = $this->conflictsOf($manifest);
        if ($conflicts !== []) {
            throw new ServiceException(self::ERROR_CONFLICT, Response::HTTP_CONFLICT, ['conflicts' => $conflicts]);
        }

        $collisions = $this->collisionsOf($manifest);
        if ($collisions !== []) {
            throw new ServiceException(self::ERROR_MANIFEST_COLLISION, Response::HTTP_CONFLICT, ['collisions' => $collisions]);
        }
    }

    /**
     * @throws ServiceException 409 `plugin_dependents_enabled` (data `dependents`)
     */
    public function assertNoEnabledDependents(string $pluginId): void
    {
        $dependents = [];
        foreach ($this->plugins->findEnabled() as $other) {
            if ($other->getPluginId() === $pluginId) {
                continue;
            }
            foreach ((new PluginManifest($other->getManifestJson()))->getDependencies() as $dependency) {
                if (($dependency['pluginId'] ?? null) === $pluginId && ($dependency['required'] ?? true) === true) {
                    $dependents[] = [
                        'plugin_id' => $other->getPluginId(),
                        'version' => is_string($dependency['version'] ?? null) ? $dependency['version'] : '*',
                    ];
                }
            }
        }

        if ($dependents !== []) {
            throw new ServiceException(self::ERROR_DEPENDENTS_ENABLED, Response::HTTP_CONFLICT, ['dependents' => $dependents]);
        }
    }

    /**
     * An update may not leave an enabled plugin without a dependency it requires: the new version of
     * `$pluginId` must still be within the range every enabled dependent asks for.
     *
     * @throws ServiceException 409 `plugin_dependents_enabled` (data `dependents`, as {@see assertNoEnabledDependents()})
     */
    public function assertUpdateKeepsDependents(string $pluginId, string $newVersion): void
    {
        $dependents = [];
        foreach ($this->plugins->findEnabled() as $other) {
            if ($other->getPluginId() === $pluginId) {
                continue;
            }
            foreach ((new PluginManifest($other->getManifestJson()))->getDependencies() as $dependency) {
                if (($dependency['pluginId'] ?? null) !== $pluginId || ($dependency['required'] ?? true) !== true) {
                    continue;
                }
                $range = is_string($dependency['version'] ?? null) ? $dependency['version'] : '*';
                if (!SemverHelper::satisfies($newVersion, $range)) {
                    $dependents[] = ['plugin_id' => $other->getPluginId(), 'version' => $range];
                }
            }
        }

        if ($dependents !== []) {
            throw new ServiceException(self::ERROR_DEPENDENTS_ENABLED, Response::HTTP_CONFLICT, ['dependents' => $dependents]);
        }
    }

    /**
     * @return list<array{plugin_id: string, version: string, reason: string|null}>
     */
    private function conflictsOf(PluginManifest $manifest): array
    {
        $found = [];

        foreach ($manifest->getConflicts() as $conflict) {
            $otherId = $conflict['pluginId'] ?? null;
            if (!is_string($otherId) || $otherId === $manifest->getPluginId()) {
                continue;
            }
            $range = is_string($conflict['version'] ?? null) ? $conflict['version'] : '*';
            $other = $this->plugins->findOneByPluginId($otherId);
            if ($other instanceof Plugin && $other->isEnabled() && SemverHelper::satisfies($other->getVersion(), $range)) {
                $found[] = [
                    'plugin_id' => $otherId,
                    'version' => $range,
                    'reason' => is_string($conflict['reason'] ?? null) ? $conflict['reason'] : null,
                ];
            }
        }

        foreach ($this->plugins->findEnabled() as $other) {
            if ($other->getPluginId() === $manifest->getPluginId()) {
                continue;
            }
            foreach ((new PluginManifest($other->getManifestJson()))->getConflicts() as $conflict) {
                $range = is_string($conflict['version'] ?? null) ? $conflict['version'] : '*';
                if (($conflict['pluginId'] ?? null) === $manifest->getPluginId() && SemverHelper::satisfies($manifest->getVersion(), $range)) {
                    $found[] = [
                        'plugin_id' => $other->getPluginId(),
                        'version' => $range,
                        'reason' => is_string($conflict['reason'] ?? null) ? $conflict['reason'] : null,
                    ];
                }
            }
        }

        return $found;
    }

    /**
     * The names the manifest takes that another enabled plugin already holds
     * (contract C2). The plugin itself is skipped, so an update does not
     * collide with its own earlier version.
     *
     * @return list<array{kind: string, value: string, plugin_id: string}>
     */
    private function collisionsOf(PluginManifest $manifest): array
    {
        $mine = self::sharedNamesOf($manifest);
        if ($mine[self::KIND_ENV_OVERRIDE] === [] && $mine[self::KIND_JSON_LOGIC_NAMESPACE] === []) {
            return [];
        }

        $found = [];
        foreach ($this->plugins->findEnabled() as $other) {
            if ($other->getPluginId() === $manifest->getPluginId()) {
                continue;
            }
            $theirs = self::sharedNamesOf(new PluginManifest($other->getManifestJson()));
            foreach ([self::KIND_ENV_OVERRIDE, self::KIND_JSON_LOGIC_NAMESPACE] as $kind) {
                foreach (array_intersect($mine[$kind], $theirs[$kind]) as $value) {
                    $found[] = ['kind' => $kind, 'value' => $value, 'plugin_id' => $other->getPluginId()];
                }
            }
        }

        return $found;
    }

    /**
     * @return array{env_override: list<string>, json_logic_namespace: list<string>} the names that may belong to one plugin only
     */
    private static function sharedNamesOf(PluginManifest $manifest): array
    {
        $envOverrides = [];
        foreach ($manifest->getSettings() as $setting) {
            $name = $setting['envOverride'] ?? null;
            if (is_string($name) && $name !== '') {
                $envOverrides[$name] = $name;
            }
        }

        $namespaces = [];
        foreach ($manifest->getJsonLogicOperations() as $operation) {
            $name = $operation['name'] ?? null;
            if (is_string($name) && str_contains($name, '.')) {
                $namespace = explode('.', $name, 2)[0];
                $namespaces[$namespace] = $namespace;
            }
        }

        return [
            self::KIND_ENV_OVERRIDE => array_values($envOverrides),
            self::KIND_JSON_LOGIC_NAMESPACE => array_values($namespaces),
        ];
    }
}
