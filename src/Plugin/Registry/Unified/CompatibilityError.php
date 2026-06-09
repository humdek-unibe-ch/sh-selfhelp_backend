<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Registry\Unified;

/**
 * The single, standardized compatibility-error object used by BOTH the core
 * update preflight ({@see \App\Service\System\SystemUpdateService}) and the
 * plugin install/update flow ({@see PluginReleaseResolver}).
 *
 * Mirrors the shared TypeScript contract consumed by the frontend and the
 * Manager so an admin/operator sees the same shape regardless of which
 * installer raised it:
 *
 * ```json
 * {
 *   "component": "plugin",
 *   "component_id": "sh2-shp-survey-js",
 *   "current_version": "0.1.0",
 *   "target_version": "0.2.0",
 *   "required_range": ">=0.1.0 <0.2.0",
 *   "blocking": true,
 *   "message": "Plugin sh2-shp-survey-js is not compatible with SelfHelp 0.2.0."
 * }
 * ```
 */
final class CompatibilityError
{
    public const COMPONENT_CORE = 'core';
    public const COMPONENT_PLUGIN = 'plugin';
    public const COMPONENT_FRONTEND = 'frontend';

    public function __construct(
        public readonly string $component,
        public readonly string $componentId,
        public readonly ?string $currentVersion,
        public readonly ?string $targetVersion,
        public readonly string $requiredRange,
        public readonly bool $blocking,
        public readonly string $message,
    ) {
    }

    /**
     * Build the canonical compatibility error for a plugin that cannot run on
     * the requested SelfHelp core version.
     */
    public static function pluginIncompatibleWithCore(
        string $pluginId,
        ?string $currentVersion,
        ?string $targetVersion,
        string $requiredCoreRange,
        string $coreVersion,
        bool $blocking = true,
    ): self {
        return new self(
            component: self::COMPONENT_PLUGIN,
            componentId: $pluginId,
            currentVersion: $currentVersion,
            targetVersion: $targetVersion,
            requiredRange: $requiredCoreRange,
            blocking: $blocking,
            message: sprintf(
                'Plugin %s is not compatible with SelfHelp %s (requires core %s).',
                $pluginId,
                $coreVersion,
                $requiredCoreRange,
            ),
        );
    }

    /**
     * Build the canonical compatibility error when no published plugin version
     * is compatible with the requested core version at all.
     */
    public static function pluginNoCompatibleVersion(
        string $pluginId,
        ?string $currentVersion,
        string $coreVersion,
        bool $blocking = true,
    ): self {
        return new self(
            component: self::COMPONENT_PLUGIN,
            componentId: $pluginId,
            currentVersion: $currentVersion,
            targetVersion: null,
            requiredRange: '*',
            blocking: $blocking,
            message: sprintf(
                'Plugin %s has no published version compatible with SelfHelp %s.',
                $pluginId,
                $coreVersion,
            ),
        );
    }

    /**
     * Build the canonical compatibility error for a CORE update that is blocked
     * by an installed plugin whose declared core range does not admit the target
     * core version. The blocking `component` is the plugin (it is what must be
     * updated/removed), `target_version` is the CORE target, and `required_range`
     * is the plugin's required core range. Used by the core update preflight
     * ({@see \App\Service\System\SystemUpdateService}) so it speaks the same
     * shape as the plugin install/update flow.
     */
    public static function coreUpdateBlockedByPlugin(
        string $pluginId,
        ?string $installedPluginVersion,
        string $coreTargetVersion,
        string $requiredCoreRange,
        bool $pinned = false,
        bool $blocking = true,
    ): self {
        return new self(
            component: self::COMPONENT_PLUGIN,
            componentId: $pluginId,
            currentVersion: $installedPluginVersion,
            targetVersion: $coreTargetVersion,
            requiredRange: $requiredCoreRange,
            blocking: $blocking,
            message: sprintf(
                'Plugin %s requires SelfHelp %s and is not compatible with target version %s.%s',
                $pluginId,
                $requiredCoreRange,
                $coreTargetVersion,
                $pinned
                    ? ' This plugin is pinned, so it will not be auto-updated; unpin and update it to a compatible version, or remove it, before updating core.'
                    : ' Update or remove the plugin, or choose a compatible target version.',
            ),
        );
    }

    /**
     * Build the canonical compatibility error for the plugin-API axis.
     */
    public static function pluginIncompatibleWithApi(
        string $pluginId,
        ?string $currentVersion,
        ?string $targetVersion,
        string $requiredApiRange,
        string $pluginApiVersion,
        bool $blocking = true,
    ): self {
        return new self(
            component: self::COMPONENT_PLUGIN,
            componentId: $pluginId,
            currentVersion: $currentVersion,
            targetVersion: $targetVersion,
            requiredRange: $requiredApiRange,
            blocking: $blocking,
            message: sprintf(
                'Plugin %s requires plugin API %s, but the host provides %s.',
                $pluginId,
                $requiredApiRange,
                $pluginApiVersion,
            ),
        );
    }

    /**
     * @return array{
     *     component: string,
     *     component_id: string,
     *     current_version: string|null,
     *     target_version: string|null,
     *     required_range: string,
     *     blocking: bool,
     *     message: string
     * }
     */
    public function toArray(): array
    {
        return [
            'component' => $this->component,
            'component_id' => $this->componentId,
            'current_version' => $this->currentVersion,
            'target_version' => $this->targetVersion,
            'required_range' => $this->requiredRange,
            'blocking' => $this->blocking,
            'message' => $this->message,
        ];
    }
}
