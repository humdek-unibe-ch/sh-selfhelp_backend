<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Plugin\Registry\Unified;

use App\Plugin\Registry\Unified\MalformedRegistryException;
use App\Plugin\Registry\Unified\PluginRelease;
use App\Plugin\Registry\Unified\UnifiedRegistryClient;
use App\Plugin\Security\PluginSignatureVerifier;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * End-to-end backend consumption of the SHARED signed unified-registry fixture:
 * read `RegistryIndex.plugins[]` refs -> follow `releaseUrl` -> parse + verify
 * the signed `PluginRelease` -> download + checksum the `.shplugin` artifact.
 *
 * The signature is verified with a key re-derived from the same deterministic
 * dev seed the fixture generator used (no checked-in secret), proving the
 * backend verifies exactly what the publisher signed.
 */
#[Group('plugin')]
final class UnifiedRegistryClientTest extends TestCase
{
    private const BASE_URL = 'https://registry.selfhelp.test';

    private function fixtureDir(): string
    {
        return \dirname(__DIR__, 3) . '/fixtures/registry/unified';
    }

    private function trustedVerifier(): PluginSignatureVerifier
    {
        $seed = hash('sha256', 'selfhelp-dev-registry-signing-key-v1', true);
        $keypair = sodium_crypto_sign_seed_keypair($seed);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        return new PluginSignatureVerifier(
            ['selfhelp-official-2026' => base64_encode($publicKey)],
            true,
            new NullLogger(),
            'test',
        );
    }

    /**
     * MockHttpClient that serves the on-disk fixture files keyed by URL path.
     *
     * @param array<string,string> $overrides path => replacement body
     */
    private function fixtureHttpClient(array $overrides = []): HttpClientInterface
    {
        $dir = $this->fixtureDir();
        return new MockHttpClient(static function (string $method, string $url) use ($dir, $overrides): MockResponse {
            $path = ltrim(substr($url, \strlen(self::BASE_URL)), '/');
            if (isset($overrides[$path])) {
                return new MockResponse($overrides[$path]);
            }
            $file = $dir . '/' . $path;
            if (!is_file($file)) {
                return new MockResponse('not found', ['http_code' => 404]);
            }
            $body = file_get_contents($file);
            return new MockResponse($body === false ? '' : $body);
        });
    }

    public function testFetchesIndexAndVerifiesAllPluginReleasesFromSharedFixture(): void
    {
        $client = new UnifiedRegistryClient($this->fixtureHttpClient(), $this->trustedVerifier(), new NullLogger());

        $index = $client->fetchIndex(self::BASE_URL . '/registry.json');
        self::assertCount(2, $index->plugins);

        $byId = $client->fetchPluginReleases($index);
        self::assertArrayHasKey('sh2-shp-survey-js', $byId);
        $versions = array_map(static fn (PluginRelease $r): string => $r->version, $byId['sh2-shp-survey-js']);
        self::assertSame(['0.2.0', '0.1.0'], $versions);
    }

    public function testFetchPluginReleaseVerifiesSignatureAndRefMatch(): void
    {
        $client = new UnifiedRegistryClient($this->fixtureHttpClient(), $this->trustedVerifier(), new NullLogger());
        $index = $client->fetchIndex(self::BASE_URL . '/registry.json');
        $ref = $index->pluginRefsById()['sh2-shp-survey-js'][1]; // 0.1.0

        $release = $client->fetchPluginRelease($index->resolveUrl($ref->releaseUrl), [], $ref);

        self::assertSame('0.1.0', $release->version);
        self::assertTrue($release->official);
    }

    public function testTamperedReleaseDocumentFailsSignatureVerification(): void
    {
        // Flip `official` to false but keep the original signature -> the
        // canonical payload no longer matches the signed bytes.
        $original = (string) file_get_contents($this->fixtureDir() . '/releases/plugins/sh2-shp-survey-js-0.1.0.json');
        $decoded = json_decode($original, true);
        self::assertIsArray($decoded);
        $decoded['official'] = false;
        // Drop signedPayload so the verifier recomputes the canonical form from
        // the (tampered) document and detects the mismatch.
        unset($decoded['security']['signedPayload'], $decoded['security']['signedPayloadSha256']);
        $tampered = json_encode($decoded);
        self::assertIsString($tampered);

        $client = new UnifiedRegistryClient(
            $this->fixtureHttpClient(['releases/plugins/sh2-shp-survey-js-0.1.0.json' => $tampered]),
            $this->trustedVerifier(),
            new NullLogger(),
        );

        $this->expectException(MalformedRegistryException::class);
        $this->expectExceptionMessageMatches('/signature verification failed/');
        $client->fetchPluginRelease(self::BASE_URL . '/releases/plugins/sh2-shp-survey-js-0.1.0.json');
    }

    public function testRefPointingAtWrongReleaseIsRejected(): void
    {
        $client = new UnifiedRegistryClient($this->fixtureHttpClient(), $this->trustedVerifier(), new NullLogger());
        $index = $client->fetchIndex(self::BASE_URL . '/registry.json');
        $ref010 = $index->pluginRefsById()['sh2-shp-survey-js'][1];

        // Point the 0.1.0 ref at the 0.2.0 document.
        $this->expectException(MalformedRegistryException::class);
        $this->expectExceptionMessageMatches('/points at a release document/');
        $client->fetchPluginRelease(self::BASE_URL . '/releases/plugins/sh2-shp-survey-js-0.2.0.json', [], $ref010);
    }

    public function testMalformedJsonIsRejectedWithClearError(): void
    {
        $client = new UnifiedRegistryClient(
            $this->fixtureHttpClient(['registry.json' => 'not json']),
            $this->trustedVerifier(),
            new NullLogger(),
        );

        $this->expectException(MalformedRegistryException::class);
        $this->expectExceptionMessageMatches('/not a JSON object/');
        $client->fetchIndex(self::BASE_URL . '/registry.json');
    }

    public function testDownloadArchiveVerifiesSha256(): void
    {
        $client = new UnifiedRegistryClient($this->fixtureHttpClient(), $this->trustedVerifier(), new NullLogger());
        $index = $client->fetchIndex(self::BASE_URL . '/registry.json');
        $release = $client->fetchPluginRelease(self::BASE_URL . '/releases/plugins/sh2-shp-survey-js-0.1.0.json');

        $dest = sys_get_temp_dir() . '/sh-unified-' . uniqid('', true) . '.shplugin';
        try {
            $written = $client->downloadArchive($release, $dest, $index->resolveUrl($release->archiveUrl));
            self::assertGreaterThan(0, $written);
            self::assertFileExists($dest);
        } finally {
            @unlink($dest);
        }
    }

    public function testDownloadArchiveRejectsChecksumMismatch(): void
    {
        $tamperedArchive = "TAMPERED BYTES\n";
        $client = new UnifiedRegistryClient(
            $this->fixtureHttpClient(['artifacts/sh2-shp-survey-js-0.1.0.shplugin' => $tamperedArchive]),
            $this->trustedVerifier(),
            new NullLogger(),
        );
        $release = $client->fetchPluginRelease(self::BASE_URL . '/releases/plugins/sh2-shp-survey-js-0.1.0.json');

        $dest = sys_get_temp_dir() . '/sh-unified-' . uniqid('', true) . '.shplugin';
        $this->expectException(MalformedRegistryException::class);
        $this->expectExceptionMessageMatches('/does not match the registry-declared/');
        try {
            $client->downloadArchive($release, $dest, self::BASE_URL . '/artifacts/sh2-shp-survey-js-0.1.0.shplugin');
        } finally {
            @unlink($dest);
        }
    }
}
