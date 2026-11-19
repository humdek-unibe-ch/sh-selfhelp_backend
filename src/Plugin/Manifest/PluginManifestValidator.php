<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Manifest;

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
 *     by one of the plugin's own permissions).
 */
final class PluginManifestValidator
{
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

        return array_merge($errors, $this->validateAdminPagePermissions($data));
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
