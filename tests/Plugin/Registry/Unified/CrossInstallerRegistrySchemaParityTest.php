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
 * Cross-installer SCHEMA parity — backend consumer schemas ⟷ the REAL official
 * registry repo (#6 "single-source the registry schemas; make the registry and
 * code agree").
 *
 * The registry repo (`sh2-plugin-registry`) is the CANONICAL schema owner; its
 * `*.schema.json` are the authoritative superset. The backend ships intentional
 * CONSUMER SUBSET schemas under `config/schemas/registry/**` that constrain only
 * the fields the backend actually reads. {@see UnifiedRegistrySchemaConformanceTest}
 * proves a backend-local fixture conforms to those subsets; this closes the other
 * half by validating the ACTUAL published registry documents against them, so a
 * canonical-schema change that the backend has not absorbed fails CI here instead
 * of silently breaking the live `/available` + `/install` flow.
 *
 * Skipped automatically when the sibling registry repo is not checked out (CI
 * isolation); runs in the dev workspace layout.
 */
#[Group('plugin')]
final class CrossInstallerRegistrySchemaParityTest extends TestCase
{
    private function registryRoot(): string
    {
        // The registry checkout was renamed from plugins/sh2-plugin-registry to
        // sh2-registry on 2026-06-10 (GitHub repo name unchanged); accept both
        // workspace layouts.
        $workspace = \dirname(__DIR__, 5);
        foreach (['/sh2-registry', '/plugins/sh2-plugin-registry'] as $candidate) {
            if (is_file($workspace . $candidate . '/registry.json')) {
                return $workspace . $candidate;
            }
        }

        return $workspace . '/sh2-registry';
    }

    private function backendSchemas(): string
    {
        return \dirname(__DIR__, 4) . '/config/schemas/registry';
    }

    private function assertValidAgainst(string $dataFile, string $schemaFile): void
    {
        self::assertFileExists($dataFile);
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
        self::assertTrue(
            $validator->isValid(),
            sprintf('%s does not validate against the backend %s: %s', basename($dataFile), basename($schemaFile), implode('; ', $errors)),
        );
    }

    public function testRealRegistryDocumentsValidateAgainstBackendConsumerSchemas(): void
    {
        $registry = $this->registryRoot();
        if (!is_file($registry . '/registry.json')) {
            self::markTestSkipped('sh2-plugin-registry repo not checked out alongside the backend.');
        }
        $schemas = $this->backendSchemas();

        // The canonical published index validates against the backend's subset.
        $this->assertValidAgainst($registry . '/registry.json', $schemas . '/registry-index.schema.json');

        // Every published core release validates against the backend core schema.
        foreach (glob($registry . '/releases/core/*.json') ?: [] as $coreRelease) {
            $this->assertValidAgainst($coreRelease, $schemas . '/core-release.schema.json');
        }

        // Every published plugin release validates against the backend plugin schema.
        $pluginReleases = glob($registry . '/releases/plugins/*.json') ?: [];
        self::assertNotEmpty($pluginReleases, 'registry publishes at least one plugin release');
        foreach ($pluginReleases as $pluginRelease) {
            $this->assertValidAgainst($pluginRelease, $schemas . '/plugin-release.schema.json');
        }
    }
}
