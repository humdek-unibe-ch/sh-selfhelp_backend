<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\ScheduledJob;

use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Registry\PluginRegistryService;
use App\Service\Action\ActionConfig;

/**
 * The job types an action may plan (contract C12): the `scheduledJobs` entries
 * of the **enabled** plugins that set `actionPlannable`, each with the label the
 * action editor shows, the flat `configSchema` the job's `plugin_config` is
 * checked against and the `resourceFields` that bind plugin items.
 *
 * It is the one place that answers "is this `job_type` of an action a plugin
 * job type". The route that lists the types, the check on action save and the
 * action planner ask it; a type of a disabled or uninstalled plugin is unknown.
 * The plugin list comes from {@see PluginRegistryService}, which every plugin
 * change resets.
 *
 * A type that has the code of a core action job type (`add_group`,
 * `notification`, ...) is never offered: the core type wins, so a plugin cannot
 * change what an existing action means.
 *
 * @phpstan-type JobType array{
 *     type: string,
 *     plugin_id: string,
 *     label: string,
 *     config_schema: array<string, mixed>,
 *     resource_fields: array<string, string>
 * }
 */
final class PluginActionJobTypes
{
    /** The `job_type` values an action already knows; a plugin type never takes one of them. */
    public const CORE_JOB_TYPES = [
        ActionConfig::JOB_TYPE_ADD_GROUP,
        ActionConfig::JOB_TYPE_REMOVE_GROUP,
        ActionConfig::JOB_TYPE_NOTIFICATION,
        ActionConfig::JOB_TYPE_NOTIFICATION_WITH_REMINDER,
        ActionConfig::JOB_TYPE_NOTIFICATION_WITH_REMINDER_FOR_DIARY,
    ];

    public function __construct(private readonly PluginRegistryService $plugins)
    {
    }

    /**
     * Whether the `job_type` of an action job is one of the core values (or none at all), so that
     * it is not looked up among the plugin job types.
     */
    public static function isCoreType(string $type): bool
    {
        return $type === '' || in_array($type, self::CORE_JOB_TYPES, true);
    }

    /**
     * @return list<JobType> sorted by label, then type; one entry per type
     */
    public function all(): array
    {
        $types = [];
        foreach ($this->plugins->getEnabled() as $plugin) {
            $manifest = new PluginManifest($plugin->getManifestJson());
            $resourceFields = $manifest->getJobResourceFields();
            foreach ($manifest->getActionJobTypes() as $job) {
                $type = $job['type'] ?? null;
                if (!is_string($type) || self::isCoreType($type) || isset($types[$type])) {
                    continue;
                }
                $label = $job['label'] ?? null;
                $schema = $job['configSchema'] ?? null;

                $types[$type] = [
                    'type' => $type,
                    'plugin_id' => $plugin->getPluginId(),
                    'label' => is_string($label) && trim($label) !== '' ? $label : $type,
                    'config_schema' => $this->schemaOf($schema),
                    'resource_fields' => $resourceFields[$type] ?? [],
                ];
            }
        }

        $list = array_values($types);
        usort($list, static fn (array $a, array $b): int => [$a['label'], $a['type']] <=> [$b['label'], $b['type']]);

        return $list;
    }

    /**
     * @return JobType|null the type, or null when no enabled plugin offers it to actions
     */
    public function find(string $type): ?array
    {
        foreach ($this->all() as $entry) {
            if ($entry['type'] === $type) {
                return $entry;
            }
        }

        return null;
    }

    public function isPluginType(string $type): bool
    {
        return $this->find($type) !== null;
    }

    /**
     * @return array<string, mixed> the schema as a string-keyed map; an empty object schema when the manifest gave none
     */
    private function schemaOf(mixed $schema): array
    {
        if (!is_array($schema)) {
            return ['type' => 'object', 'properties' => []];
        }

        $map = [];
        foreach ($schema as $key => $value) {
            $map[(string) $key] = $value;
        }

        return $map;
    }
}
