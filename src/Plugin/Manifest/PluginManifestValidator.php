<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Manifest;

use App\Plugin\Versioning\SemverHelper;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;

/**
 * Validates a decoded `plugin.json` against the canonical schema at
 * `docs/plugins/plugin-manifest.schema.json`.
 *
 * Validation is strict: unknown top-level fields, missing required
 * fields, bad enum values, and invalid version strings all reject the
 * manifest. A second pass enforces cross-field invariants the JSON
 * Schema cannot express on its own:
 *
 *   - `security.capabilities` must include `backendBundle` when
 *     `backend` is present;
 *   - `security.capabilities` must include `databaseMigrations` when
 *     the backend declares `migrationsNamespace`;
 *   - `security.capabilities` must include `mobileStyles` when
 *     `mobile` is present;
 *   - `security.trustLevel: 'untrusted'` forbids `backend.*` entirely;
 *   - every `adminPages[].permission` must be a `permissions[].key` the
 *     same manifest declares (rule 4.13: a plugin admin page is guarded
 *     by one of the plugin's own permissions);
 *   - the keys added by plugin API 0.2.0 (`resourceFields`,
 *     `cacheCategories`, `settings`, `rateLimits`, `jsonLogicOperations`,
 *     `listens`, `recurringJobs`, and the new `scheduledJobs` keys) need
 *     `pluginApiVersion` 0.2.0 or newer, and their cross references hold:
 *     a resource field names a declared field and a declared resource type,
 *     an action-plannable job has a label, names do not repeat, a secret has
 *     no default, a JSON Logic name does not reuse a core operation, a
 *     recurring job names a declared job type and a cron that has five
 *     fields and runs at most every 5 minutes.
 *
 * What must be unique across **enabled plugins** (`settings[].envOverride`,
 * the namespace of a `jsonLogicOperations[].name`) cannot be judged from one
 * manifest: {@see \App\Plugin\Lifecycle\PluginDependencyGuard::assertCanActivate()}
 * refuses it at install, update and enable.
 */
final class PluginManifestValidator
{
    /** First plugin API version that knows the keys of contracts C2, C22, C24 and C25. */
    private const HOOKS_API_VERSION = '0.2.0';

    /** Top-level manifest keys that came with {@see HOOKS_API_VERSION}. */
    private const HOOK_TOP_LEVEL_KEYS = ['cacheCategories', 'settings', 'rateLimits', 'jsonLogicOperations', 'listens', 'recurringJobs'];

    /** Keys of a `scheduledJobs` entry that came with {@see HOOKS_API_VERSION}. */
    private const HOOK_JOB_KEYS = ['label', 'actionPlannable', 'configSchema', 'resourceFields', 'timeoutSeconds'];

    /** Closest two runs of a recurring job may be (contract C25). */
    private const MIN_RECURRING_GAP_MINUTES = 5;

    /**
     * The operations of the JSON Logic library the conditions run on
     * (`JWadhams\JsonLogic`). A plugin operation never reuses one of them.
     */
    private const CORE_JSON_LOGIC_OPERATIONS = [
        '==', '===', '!=', '!==', '>', '>=', '<', '<=', '%', '!', '!!', '+', '-', '*', '/',
        'if', '?:', 'and', 'or', 'var', 'missing', 'missing_some', 'in', 'cat', 'substr',
        'merge', 'max', 'min', 'map', 'filter', 'reduce', 'all', 'none', 'some', 'log',
    ];

    public function __construct(
        private readonly string $schemaPath,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     * @return list<string> empty list on success, list of error
     *                       messages on failure.
     */
    public function validate(array $data): array
    {
        if (!is_file($this->schemaPath)) {
            return [sprintf('Plugin manifest schema not found at %s.', $this->schemaPath)];
        }

        $raw = file_get_contents($this->schemaPath);
        if ($raw === false) {
            return [sprintf('Failed to read plugin manifest schema at %s.', $this->schemaPath)];
        }

        $schema = json_decode($raw, false);
        if (!is_object($schema)) {
            return ['Plugin manifest schema is not a valid JSON object.'];
        }

        $payload = json_decode((string) json_encode($data), false);
        $validator = new Validator();
        $validator->validate($payload, $schema, Constraint::CHECK_MODE_NORMAL);

        $errors = [];
        if (!$validator->isValid()) {
            foreach ($validator->getErrors() as $err) {
                if (!is_array($err)) {
                    continue;
                }
                $property = is_scalar($err['property'] ?? null) ? (string) $err['property'] : '';
                $message = is_scalar($err['message'] ?? null) ? (string) $err['message'] : '';
                $errors[] = sprintf('[%s] %s', $property !== '' ? $property : '(root)', $message);
            }
        }

        $errors = array_merge($errors, $this->validateCrossField($data));

        return $errors;
    }

    /**
     * @param array<string,mixed> $data
     * @return list<string>
     */
    private function validateCrossField(array $data): array
    {
        $errors = [];
        $security = is_array($data['security'] ?? null) ? $data['security'] : [];
        $capabilities = is_array($security['capabilities'] ?? null) ? $security['capabilities'] : [];
        $trustLevel = $security['trustLevel'] ?? null;

        if (isset($data['backend'])) {
            $backend = is_array($data['backend']) ? $data['backend'] : [];
            if (!in_array('backendBundle', $capabilities, true)) {
                $errors[] = '[security.capabilities] must include "backendBundle" when `backend` is declared.';
            }
            if ($trustLevel === 'untrusted') {
                $errors[] = '[backend] trust level "untrusted" forbids shipping a backend bundle.';
            }
            if (isset($backend['migrationsNamespace'])
                && !in_array('databaseMigrations', $capabilities, true)
            ) {
                $errors[] = '[security.capabilities] must include "databaseMigrations" when `backend.migrationsNamespace` is declared.';
            }
        }

        if (isset($data['mobile']) && !in_array('mobileStyles', $capabilities, true)) {
            $errors[] = '[security.capabilities] must include "mobileStyles" when `mobile` is declared.';
        }

        if (!empty($data['scheduledJobs']) && !in_array('scheduledJobs', $capabilities, true)) {
            $errors[] = '[security.capabilities] must include "scheduledJobs" when `scheduledJobs` are declared.';
        }

        if (!empty($data['realtimeTopics']) && !in_array('realtimePublish', $capabilities, true)) {
            $errors[] = '[security.capabilities] must include "realtimePublish" when `realtimeTopics` are declared.';
        }

        if (!empty($data['adminPages']) && !in_array('adminPages', $capabilities, true)) {
            $errors[] = '[security.capabilities] must include "adminPages" when `adminPages` are declared.';
        }

        if (!empty($data['styles']) && !in_array('frontendStyles', $capabilities, true)) {
            $errors[] = '[security.capabilities] must include "frontendStyles" when `styles` are declared.';
        }

        return array_merge(
            $errors,
            $this->validateAdminPagePermissions($data),
            $this->validateHookKeys($data),
        );
    }

    /**
     * The keys that plugin API 0.2.0 added (contracts C2, C22, C24, C25): they
     * need `pluginApiVersion` 0.2.0 or newer, and their cross references
     * (resource types, fields, job types, names, cron) must hold.
     *
     * @param array<string,mixed> $data
     * @return list<string>
     */
    private function validateHookKeys(array $data): array
    {
        $manifest = new PluginManifest($data);
        $styles = self::maps($data['styles'] ?? null);
        $jobs = self::maps($data['scheduledJobs'] ?? null);
        $errors = $this->validateHookApiVersion($data, $styles, $jobs);

        $resourceTypes = $manifest->getResourceTypeCodes();
        $jobTypes = [];
        foreach ($jobs as $job) {
            if (is_string($job['type'] ?? null)) {
                $jobTypes[] = $job['type'];
            }
        }

        foreach ($styles as $style) {
            $name = is_string($style['name'] ?? null) ? $style['name'] : '';
            $declared = [];
            foreach (self::maps($style['fields'] ?? null) as $field) {
                if (is_string($field['name'] ?? null)) {
                    $declared[] = $field['name'];
                }
            }
            foreach (self::strings($style['resourceFields'] ?? null) as $field => $type) {
                if (!in_array($field, $declared, true)) {
                    $errors[] = sprintf('Style "%s": resourceFields names "%s", which is not one of the style\'s fields.', $name, $field);
                }
                if (!in_array($type, $resourceTypes, true)) {
                    $errors[] = sprintf('Style "%s": resourceFields field "%s" uses resource type "%s", which the manifest does not register under lookups.extends (resourceTypes).', $name, $field, $type);
                }
            }
        }

        foreach ($jobs as $job) {
            $type = is_string($job['type'] ?? null) ? $job['type'] : '';
            $label = $job['label'] ?? null;
            if (($job['actionPlannable'] ?? false) === true && (!is_string($label) || trim($label) === '')) {
                $errors[] = sprintf('Job type "%s": actionPlannable needs a label.', $type);
            }
            $schema = is_array($job['configSchema'] ?? null) ? $job['configSchema'] : [];
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            foreach (self::strings($job['resourceFields'] ?? null) as $property => $resourceType) {
                if (!array_key_exists($property, $properties)) {
                    $errors[] = sprintf('Job type "%s": resourceFields names "%s", which is not a property of its configSchema.', $type, $property);
                }
                if (!in_array($resourceType, $resourceTypes, true)) {
                    $errors[] = sprintf('Job type "%s": resourceFields property "%s" uses resource type "%s", which the manifest does not register under lookups.extends (resourceTypes).', $type, $property, $resourceType);
                }
            }
        }

        return array_merge(
            $errors,
            $this->validateUniqueNames($data),
            $this->validateSettings($data),
            $this->validateJsonLogicOperations($data),
            $this->validateRecurringJobs($data, $jobTypes),
        );
    }

    /**
     * @param array<string,mixed> $data
     * @param list<array<string,mixed>> $styles
     * @param list<array<string,mixed>> $jobs
     * @return list<string>
     */
    private function validateHookApiVersion(array $data, array $styles, array $jobs): array
    {
        $api = $data['pluginApiVersion'] ?? null;
        if (!is_string($api) || SemverHelper::compare((string) preg_replace('/[-+].*$/', '', $api), self::HOOKS_API_VERSION) >= 0) {
            return [];
        }

        $errors = [];
        $refuse = static function (string $path) use (&$errors, $api): void {
            $errors[] = sprintf('[%s] needs pluginApiVersion %s or newer (the manifest declares %s).', $path, self::HOOKS_API_VERSION, $api);
        };

        foreach (self::HOOK_TOP_LEVEL_KEYS as $key) {
            if (array_key_exists($key, $data)) {
                $refuse($key);
            }
        }
        foreach ($styles as $style) {
            if (array_key_exists('resourceFields', $style)) {
                $refuse(sprintf('styles.%s.resourceFields', is_string($style['name'] ?? null) ? $style['name'] : ''));
            }
        }
        foreach ($jobs as $job) {
            foreach (self::HOOK_JOB_KEYS as $key) {
                if (array_key_exists($key, $job)) {
                    $refuse(sprintf('scheduledJobs.%s.%s', is_string($job['type'] ?? null) ? $job['type'] : '', $key));
                }
            }
        }

        return $errors;
    }

    /**
     * Names that must not repeat inside one manifest: cache categories and
     * rate limits (`name`), settings (`key` and `envOverride`).
     *
     * @param array<string,mixed> $data
     * @return list<string>
     */
    private function validateUniqueNames(array $data): array
    {
        $errors = [];
        foreach ([['cacheCategories', 'name'], ['rateLimits', 'name'], ['settings', 'key'], ['settings', 'envOverride']] as [$block, $field]) {
            $seen = [];
            foreach (self::maps($data[$block] ?? null) as $entry) {
                $value = $entry[$field] ?? null;
                if (!is_string($value)) {
                    continue;
                }
                if (isset($seen[$value])) {
                    $errors[] = sprintf('[%s] "%s" is declared twice in `%s`.', $field, $value, $block);
                }
                $seen[$value] = true;
            }
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $data
     * @return list<string>
     */
    private function validateSettings(array $data): array
    {
        $errors = [];
        foreach (self::maps($data['settings'] ?? null) as $setting) {
            if (($setting['secret'] ?? false) === true && array_key_exists('default', $setting)) {
                $errors[] = sprintf('Setting "%s" is a secret and cannot have a default value.', is_string($setting['key'] ?? null) ? $setting['key'] : '');
            }
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $data
     * @return list<string>
     */
    private function validateJsonLogicOperations(array $data): array
    {
        $errors = [];
        $seen = [];
        foreach (self::maps($data['jsonLogicOperations'] ?? null) as $operation) {
            $name = $operation['name'] ?? null;
            if (!is_string($name)) {
                continue;
            }
            if (in_array($name, self::CORE_JSON_LOGIC_OPERATIONS, true)) {
                $errors[] = sprintf('JSON Logic operation "%s" collides with a core operation.', $name);
            }
            if (isset($seen[$name])) {
                $errors[] = sprintf('JSON Logic operation "%s" is declared twice.', $name);
            }
            $seen[$name] = true;
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $data
     * @param list<string> $jobTypes the manifest's own `scheduledJobs[].type`
     * @return list<string>
     */
    private function validateRecurringJobs(array $data, array $jobTypes): array
    {
        $errors = [];
        $seen = [];
        foreach (self::maps($data['recurringJobs'] ?? null) as $job) {
            $name = is_string($job['name'] ?? null) ? $job['name'] : '';
            if ($name !== '' && isset($seen[$name])) {
                $errors[] = sprintf('Recurring job "%s" is declared twice.', $name);
            }
            $seen[$name] = true;

            $type = $job['type'] ?? null;
            if (is_string($type) && !in_array($type, $jobTypes, true)) {
                $errors[] = sprintf('Recurring job "%s" uses job type "%s", which the plugin does not declare in `scheduledJobs`.', $name, $type);
            }

            $cron = $job['cron'] ?? null;
            if (!is_string($cron)) {
                continue;
            }
            $schedule = CronSchedule::parse($cron);
            if ($schedule === null) {
                $errors[] = sprintf('Recurring job "%s": cron "%s" is not a valid five-field expression (minute hour day-of-month month day-of-week, UTC).', $name, $cron);
                continue;
            }
            $gap = $schedule->minimumGapMinutes();
            if ($gap === null) {
                $errors[] = sprintf('Recurring job "%s": cron "%s" never runs.', $name, $cron);
            } elseif ($gap < self::MIN_RECURRING_GAP_MINUTES) {
                $errors[] = sprintf('Recurring job "%s": cron "%s" runs every %d minute(s); the shortest allowed distance is %d minutes.', $name, $cron, $gap, self::MIN_RECURRING_GAP_MINUTES);
            }
        }

        return $errors;
    }

    /**
     * @return list<array<string,mixed>> the array entries of a list, whatever else it holds
     */
    private static function maps(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $maps = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $map = [];
            foreach ($entry as $key => $item) {
                if (is_string($key)) {
                    $map[$key] = $item;
                }
            }
            $maps[] = $map;
        }

        return $maps;
    }

    /**
     * @return array<string, string> the string entries of a string-keyed map
     */
    private static function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && is_string($item)) {
                $strings[$key] = $item;
            }
        }

        return $strings;
    }

    /**
     * Rule 4.13: an admin page's permission must be one the plugin declares
     * in its own `permissions` block. Only the key is compared; the name is
     * not checked against any convention.
     *
     * @param array<string,mixed> $data
     * @return list<string>
     */
    private function validateAdminPagePermissions(array $data): array
    {
        $adminPages = is_array($data['adminPages'] ?? null) ? $data['adminPages'] : [];
        if ($adminPages === []) {
            return [];
        }

        $declared = [];
        $permissions = is_array($data['permissions'] ?? null) ? $data['permissions'] : [];
        foreach ($permissions as $permission) {
            if (is_array($permission) && is_string($permission['key'] ?? null)) {
                $declared[$permission['key']] = true;
            }
        }

        $errors = [];
        foreach ($adminPages as $page) {
            if (!is_array($page)) {
                continue;
            }
            $key = $page['permission'] ?? null;
            if (!is_string($key) || isset($declared[$key])) {
                continue;
            }
            $slug = is_scalar($page['slug'] ?? null) ? (string) $page['slug'] : '';
            $errors[] = sprintf(
                'Admin page "%s" uses permission "%s", which the plugin does not declare.',
                $slug,
                $key,
            );
        }

        return $errors;
    }
}
