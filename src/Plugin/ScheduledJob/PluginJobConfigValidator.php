<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\ScheduledJob;

use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;

/**
 * Checks the `plugin_config` of an action job (contract C12) against the flat
 * `configSchema` its plugin declared for the job type.
 *
 * The schema is the plugin's own, so it is applied as written with one
 * addition: a property the schema does not declare is refused. A job config
 * holds only what the type names, and only declared properties can be bound
 * to a resource (`resourceFields`). `format: variable-text` is a hint for the
 * editor and an unknown format to the validator, so it checks nothing; the
 * value is plain text that may hold `{{variables}}`.
 *
 * It does not decide what the failure means to the caller; it returns the
 * failures and {@see \App\Service\CMS\Admin\ActionPluginJobValidator} turns
 * them into the 400 `invalid_plugin_job_config`.
 */
final class PluginJobConfigValidator
{
    /**
     * @param array<string, mixed> $schema the flat `configSchema` of the job type
     * @param mixed $config the decoded `plugin_config`; null counts as an empty object
     *
     * @return list<array{path: string, message: string}> the failures, each with the path of the property inside `plugin_config` (empty for the object itself); empty when the config is valid
     */
    public function validate(array $schema, mixed $config): array
    {
        $config ??= [];
        if (!is_array($config) || ($config !== [] && array_is_list($config))) {
            return [['path' => '', 'message' => 'The plugin configuration must be an object.']];
        }

        $data = json_decode((string) json_encode($config === [] ? new \stdClass() : $config));
        $schemaObject = json_decode((string) json_encode($this->closed($schema)));

        $validator = new Validator();
        $validator->validate($data, $schemaObject, Constraint::CHECK_MODE_NORMAL);

        $failures = [];
        foreach ($validator->getErrors() as $error) {
            if (!is_array($error)) {
                continue;
            }
            $property = $error['property'] ?? '';
            $message = $error['message'] ?? '';
            $failures[] = [
                'path' => is_scalar($property) ? trim((string) $property, '.') : '',
                'message' => is_scalar($message) && (string) $message !== '' ? (string) $message : 'The value is not valid.',
            ];
        }

        return $failures;
    }

    /**
     * The schema with an object `properties` (an empty one decodes as a list) and closed to undeclared properties.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function closed(array $schema): array
    {
        $schema['type'] = 'object';
        $schema['properties'] = ($schema['properties'] ?? []) === [] ? new \stdClass() : $schema['properties'];
        $schema['additionalProperties'] = false;

        return $schema;
    }
}
