<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Plugin\Registry\Unified;

use App\Plugin\Registry\Unified\CanonicalJson;
use App\Plugin\Registry\Unified\MalformedRegistryException;
use App\Plugin\Registry\Unified\PluginRelease;
use App\Plugin\Registry\Unified\RegistryIndex;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Parsing + validation of the unified `registry.json` index and the canonical
 * JSON encoder that keeps backend signature verification byte-compatible with
 * the Manager (`@shm/registry` `canonicalize`).
 */
#[Group('plugin')]
final class RegistryIndexTest extends TestCase
{
    private function fixtureDir(): string
    {
        return \dirname(__DIR__, 3) . '/fixtures/registry/unified';
    }

    /** @return array<string,mixed> */
    private function loadJson(string $relative): array
    {
        $body = file_get_contents($this->fixtureDir() . '/' . $relative);
        self::assertIsString($body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        /** @var array<string,mixed> $decoded */
        return $decoded;
    }

    public function testParsesUnifiedIndexAndGroupsPluginsByIdNewestFirst(): void
    {
        $index = RegistryIndex::fromArray($this->loadJson('registry.json'));

        self::assertSame('1.0.0', $index->schemaVersion);
        self::assertSame('https://registry.selfhelp.test', $index->baseUrl);
        self::assertCount(1, $index->core);
        self::assertCount(2, $index->plugins);

        $byId = $index->pluginRefsById();
        self::assertArrayHasKey('sh2-shp-survey-js', $byId);
        $versions = array_map(static fn ($r) => $r->version, $byId['sh2-shp-survey-js']);
        self::assertSame(['0.2.0', '0.1.0'], $versions, 'plugin refs must be newest-first');
    }

    public function testResolvesRelativeReleaseUrlAgainstBaseUrl(): void
    {
        $index = RegistryIndex::fromArray($this->loadJson('registry.json'));

        self::assertSame(
            'https://registry.selfhelp.test/releases/plugins/sh2-shp-survey-js-0.1.0.json',
            $index->resolveUrl('releases/plugins/sh2-shp-survey-js-0.1.0.json'),
        );
        self::assertSame(
            'https://cdn.example/x.json',
            $index->resolveUrl('https://cdn.example/x.json'),
            'absolute URLs are returned verbatim',
        );
    }

    public function testRejectsIndexMissingRequiredFields(): void
    {
        $this->expectException(MalformedRegistryException::class);
        RegistryIndex::fromArray(['schemaVersion' => '1.0.0']);
    }

    public function testRejectsReleaseRefWithUnknownChannel(): void
    {
        $this->expectException(MalformedRegistryException::class);
        $this->expectExceptionMessageMatches('/channel must be one of/');
        RegistryIndex::fromArray([
            'schemaVersion' => '1.0.0',
            'requiresManager' => '>=0.1.0',
            'baseUrl' => 'https://r.test',
            'plugins' => [['id' => 'p', 'version' => '1.0.0', 'channel' => 'alpha', 'releaseUrl' => 'x.json']],
        ]);
    }

    public function testParsesSignedPluginReleaseDocument(): void
    {
        $release = PluginRelease::fromArray($this->loadJson('releases/plugins/sh2-shp-survey-js-0.1.0.json'), 'fixture');

        self::assertSame('sh2-shp-survey-js', $release->id);
        self::assertSame('0.1.0', $release->version);
        self::assertSame('>=0.1.0 <0.2.0', $release->compatibilityCore);
        self::assertSame('>=0.1.0 <0.2.0', $release->compatibilityPluginApi);
        self::assertTrue($release->official);
        self::assertSame('selfhelp-dev-fixture', $release->security->keyId);
        self::assertNotNull($release->security->signedPayload);
    }

    public function testRejectsReleaseDocumentWithWrongKind(): void
    {
        $this->expectException(MalformedRegistryException::class);
        $this->expectExceptionMessageMatches('/expected kind/');
        PluginRelease::fromArray(['kind' => 'selfhelp-core-release', 'id' => 'x', 'version' => '1.0.0'], 'fixture');
    }

    public function testCanonicalJsonSortsKeysAndPreservesUnescapedSlashes(): void
    {
        $encoded = CanonicalJson::encode(['b' => 1, 'a' => ['z' => true, 'y' => 'a/b']]);

        self::assertSame('{"a":{"y":"a/b","z":true},"b":1}', $encoded);
    }

    public function testCanonicalJsonOfFixtureReleaseMatchesItsSignedPayload(): void
    {
        $raw = $this->loadJson('releases/plugins/sh2-shp-survey-js-0.1.0.json');
        $security = $raw['security'];
        self::assertIsArray($security);
        $signedPayload = $security['signedPayload'] ?? null;
        self::assertIsString($signedPayload);

        unset($raw['security']);
        self::assertSame(
            $signedPayload,
            CanonicalJson::encode($raw),
            'the canonical form of the release (minus security) must equal the signed bytes',
        );
    }
}
