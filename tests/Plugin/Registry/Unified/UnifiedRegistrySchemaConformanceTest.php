<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Plugin\Registry\Unified;

use JsonSchema\Validator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Proves the SHARED signed registry fixture conforms to the canonical unified
 * schemas the backend ships (config/schemas/registry/**) and to the public
 * docs index schema (docs/plugins/plugin-registry.schema.json). This is the
 * backend half of "one registry contract": the same fixture is consumed by the
 * Manager's registry tests.
 */
#[Group('plugin')]
final class UnifiedRegistrySchemaConformanceTest extends TestCase
{
    private function repoRoot(): string
    {
        // tests/Plugin/Registry/Unified -> repo root
        return \dirname(__DIR__, 4);
    }

    private function assertValidAgainst(string $dataFile, string $schemaFile): void
    {
        $data = json_decode((string) file_get_contents($dataFile));
        $schema = json_decode((string) file_get_contents($schemaFile));

        $validator = new Validator();
        $validator->validate($data, $schema);

        $errors = [];
        foreach ($validator->getErrors() as $error) {
            if (!is_array($error)) {
                continue;
            }
            $property = isset($error['property']) && is_string($error['property']) ? $error['property'] : '';
            $message = isset($error['message']) && is_string($error['message']) ? $error['message'] : '';
            $errors[] = sprintf('[%s] %s', $property, $message);
        }
        self::assertTrue($validator->isValid(), sprintf('%s is not valid against %s: %s', basename($dataFile), basename($schemaFile), implode('; ', $errors)));
    }

    public function testFixtureIndexConformsToUnifiedSchemas(): void
    {
        $root = $this->repoRoot();
        $fx = $root . '/tests/fixtures/registry/unified';
        $schemas = $root . '/config/schemas/registry';

        $this->assertValidAgainst($fx . '/registry.json', $schemas . '/registry-index.schema.json');
        $this->assertValidAgainst($fx . '/registry.json', $root . '/docs/plugins/plugin-registry.schema.json');
        $this->assertValidAgainst($fx . '/releases/core/selfhelp-core-0.1.0.json', $schemas . '/core-release.schema.json');
        $this->assertValidAgainst($fx . '/releases/plugins/sh2-shp-survey-js-0.1.0.json', $schemas . '/plugin-release.schema.json');
        $this->assertValidAgainst($fx . '/releases/plugins/sh2-shp-survey-js-0.2.0.json', $schemas . '/plugin-release.schema.json');
    }
}
