<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Plugin;

use App\Tests\Support\NarrowsJson;
use PHPUnit\Framework\TestCase;

/**
 * Static shape guard for the public manifest endpoint.
 *
 * The endpoint is consumed by the frontend `PluginRuntime`, the
 * frontend `plugins:sync` build script, and the mobile `plugins:sync`
 * script. All three rely on:
 *
 *   - `pluginId` being the canonical plugin identifier (no duplicate
 *     `id` field, which used to be emitted alongside as a backward-
 *     compat alias).
 *   - The response schema's plugin item using `additionalProperties:
 *     false` so the contract can't silently grow optional fields the
 *     consumers don't know about.
 *   - The controller emitting exactly the field set the schema lists.
 *
 * This test parses the schema + the controller source lexically — no
 * kernel boot is required — so the assertion runs in every CI lane
 * regardless of DB / Symfony availability.
 */
final class PluginManifestShapeTest extends TestCase
{
    use NarrowsJson;

    private const SCHEMA_PATH = __DIR__ . '/../../../../../config/schemas/api/v1/responses/frontend/plugin_manifest.json';
    private const CONTROLLER_PATH = __DIR__ . '/../../../../../src/Controller/Api/V1/Plugin/PluginManifestController.php';

    public function testSchemaForbidsDeprecatedIdField(): void
    {
        $itemProps = $this->pluginItemProps();

        self::assertArrayNotHasKey(
            'id',
            $itemProps,
            'plugin_manifest.json must not advertise a top-level `id` field. ' .
            'The canonical identifier is `pluginId`; the duplicate `id` alias was removed.'
        );
        self::assertArrayHasKey(
            'pluginId',
            $itemProps,
            'plugin_manifest.json must advertise `pluginId` as the canonical identifier.'
        );
    }

    public function testSchemaIsAdditionalPropertiesFalse(): void
    {
        $item = $this->pluginItemSchema();

        self::assertArrayHasKey('additionalProperties', $item);
        self::assertFalse(
            $item['additionalProperties'],
            'plugin_manifest.json must set additionalProperties:false on plugin items so the contract stays tight.'
        );
    }

    public function testControllerDoesNotEmitDeprecatedIdField(): void
    {
        $source = (string) file_get_contents(self::CONTROLLER_PATH);
        self::assertNotSame('', $source);

        // The controller body must not include a literal `'id' =>`
        // entry inside the plugin item array; that would re-introduce
        // the duplicate identifier that the schema now forbids.
        self::assertStringNotContainsString(
            "'id' => \$plugin->getId(),",
            $source,
            'PluginManifestController must not emit the deprecated `id` field.'
        );
        self::assertStringContainsString(
            "'pluginId' => \$plugin->getPluginId(),",
            $source,
            'PluginManifestController must emit the canonical `pluginId` field.'
        );
    }

    public function testControllerFieldsMatchSchema(): void
    {
        $schemaFields = array_keys($this->pluginItemProps());
        sort($schemaFields);

        $source = (string) file_get_contents(self::CONTROLLER_PATH);

        // Locate the `$plugins[] = [ ... ];` array literal and
        // collect every `'fieldName' =>` key inside it. This catches
        // both `'enabled' => $plugin->isEnabled()` and
        // `'capabilities' => $capabilities`-style entries (which use
        // local variables and would be missed by a `\$plugin->`-
        // anchored regex).
        $emitted = [];
        if (preg_match('/\$plugins\[\]\s*=\s*\[(?P<body>.+?)\];/s', $source, $arrayMatch)) {
            if (preg_match_all("/'([a-zA-Z][a-zA-Z0-9_]*)'\s*=>/", $arrayMatch['body'], $matches)) {
                $emitted = array_values(array_unique($matches[1]));
            }
        }
        sort($emitted);

        $missing = array_diff($schemaFields, $emitted);
        $extra = array_diff($emitted, $schemaFields);

        self::assertSame(
            [],
            array_merge(
                array_map(static fn(string $f) => 'missing:' . $f, $missing),
                array_map(static fn(string $f) => 'extra:' . $f, $extra),
            ),
            sprintf(
                "Controller / schema drift detected.\n  missing from controller: %s\n  emitted but not in schema: %s",
                $missing === [] ? '-' : implode(', ', $missing),
                $extra === [] ? '-' : implode(', ', $extra),
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSchema(): array
    {
        $raw = (string) file_get_contents(self::SCHEMA_PATH);

        return self::asArray(json_decode($raw, true), 'plugin_manifest.json is not valid JSON.');
    }

    /**
     * The `plugins[].items` sub-schema (object describing a single plugin entry).
     *
     * @return array<string, mixed>
     */
    private function pluginItemSchema(): array
    {
        return self::asArray($this->jsonGet(
            $this->loadSchema(),
            'properties',
            'data',
            'properties',
            'plugins',
            'items'
        ));
    }

    /**
     * The `properties` map of a single plugin item.
     *
     * @return array<string, mixed>
     */
    private function pluginItemProps(): array
    {
        return self::asArray($this->jsonGet($this->pluginItemSchema(), 'properties'));
    }
}
