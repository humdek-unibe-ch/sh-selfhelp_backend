<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Plugin\Registry\Unified;

use App\Plugin\Registry\Unified\CompatibilityError;
use App\Plugin\Registry\Unified\PluginRelease;
use App\Plugin\Registry\Unified\PluginReleaseResolver;
use App\Plugin\Registry\Unified\UnifiedRegistryClient;
use App\Plugin\Security\PluginSignatureVerifier;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One-registry / two-installers architecture smoke test.
 *
 * Proves the FINAL architecture end-to-end against the SINGLE shared signed
 * fixture (`tests/fixtures/registry/unified`) that the Manager tests also
 * consume:
 *
 *   1. one signed `registry.json` is loaded;
 *   2. the Manager half resolves + signature-verifies a core Docker release
 *      (image + digest metadata present, advisory at the CMS boundary);
 *   3. the backend half resolves a plugin release from the SAME registry via
 *      `releaseUrl` -> signed `PluginRelease` -> `.shplugin` artifact;
 *   4. the newest COMPATIBLE plugin version is selected by default;
 *   5. an incompatible plugin version is shown + blocked with a visible reason;
 *   6. an installed plugin stays compatible across an allowed core patch update;
 *   7. an incompatible core minor update is blocked BECAUSE of the installed
 *      plugin's compatibility range, with a visible standardized reason.
 *
 * The signing key is re-derived from the same deterministic dev seed the
 * fixture generator used, so nothing secret is checked in.
 */
#[Group('plugin')]
final class CrossInstallerArchitectureSmokeTest extends TestCase
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
            ['selfhelp-dev-fixture' => base64_encode($publicKey)],
            true,
            new NullLogger(),
            'test',
        );
    }

    private function fixtureHttpClient(): HttpClientInterface
    {
        $dir = $this->fixtureDir();
        return new MockHttpClient(static function (string $method, string $url) use ($dir): MockResponse {
            $path = ltrim(substr($url, \strlen(self::BASE_URL)), '/');
            $file = $dir . '/' . $path;
            if (!is_file($file)) {
                return new MockResponse('not found', ['http_code' => 404]);
            }
            $body = file_get_contents($file);
            return new MockResponse($body === false ? '' : $body);
        });
    }

    private function client(): UnifiedRegistryClient
    {
        return new UnifiedRegistryClient($this->fixtureHttpClient(), $this->trustedVerifier(), new NullLogger());
    }

    public function testOneSignedRegistryServesBothInstallersWithMultiVersionPluginCompatibility(): void
    {
        $client = $this->client();

        // (1) One signed registry index, consumed by BOTH installers.
        $index = $client->fetchIndex(self::BASE_URL . '/registry.json');
        self::assertCount(1, $index->core, 'registry carries exactly one core release ref');
        self::assertCount(2, $index->plugins, 'registry carries the multi-version plugin refs');

        // (2) Manager half: resolve + signature-verify the core Docker release.
        $coreRef = $index->coreRefsSorted()[0];
        $core = $client->fetchCoreRelease($index->resolveUrl($coreRef->releaseUrl), [], $coreRef);
        self::assertSame('selfhelp-core', $core->id);
        self::assertSame('0.1.0', $core->version);
        self::assertSame('0.1.0', $core->pluginApiVersion);
        self::assertStringStartsWith('sha256:', $core->backend->digest, 'backend image digest present');
        self::assertStringStartsWith('sha256:', $core->worker->digest, 'worker image digest present');
        self::assertStringStartsWith('sha256:', $core->scheduler->digest, 'scheduler image digest present');

        $coreVersion = $core->version;            // 0.1.0
        $pluginApiVersion = $core->pluginApiVersion; // 0.1.0

        // (3) Backend half: resolve plugin releases from the SAME registry.
        $byId = $client->fetchPluginReleases($index);
        self::assertArrayHasKey('sh2-shp-survey-js', $byId);
        $releases = $byId['sh2-shp-survey-js'];
        self::assertSame(['0.2.0', '0.1.0'], array_map(static fn (PluginRelease $r): string => $r->version, $releases));

        // (4) Newest COMPATIBLE selected by default on core 0.1.0: 0.1.0 (not 0.2.0).
        $resolver = new PluginReleaseResolver();
        $resolution = $resolver->resolveLatestCompatible($releases, $coreVersion, $pluginApiVersion);
        self::assertNotNull($resolution->selected);
        self::assertSame('0.1.0', $resolution->selected->version, 'newest compatible is 0.1.0, not the newer-but-incompatible 0.2.0');
        self::assertNull($resolution->error);

        // (5) The newer 0.2.0 is shown as incompatible with a visible reason.
        self::assertTrue($resolution->newerExistsButIncompatible());
        $incompatibleVersions = array_map(static fn (PluginRelease $r): string => $r->version, $resolution->incompatible);
        self::assertContains('0.2.0', $incompatibleVersions);

        $err020 = $resolver->compatibilityErrorFor($releases[0] /* 0.2.0 */, $coreVersion, $pluginApiVersion);
        self::assertInstanceOf(CompatibilityError::class, $err020);
        self::assertTrue($err020->blocking);
        self::assertSame('plugin', $err020->component);
        self::assertSame('>=0.2.0 <0.3.0', $err020->requiredRange);
        self::assertStringContainsString('0.1.0', $err020->message);

        // (6) Allowed core patch update 0.1.0 -> 0.1.5: the installed 0.1.0 stays compatible.
        $installed010 = $releases[1]; // 0.1.0
        self::assertNull(
            $resolver->compatibilityErrorFor($installed010, '0.1.5', '0.1.5', '0.1.0'),
            'installed plugin 0.1.0 remains valid after an allowed core patch update',
        );

        // (7) Incompatible core minor update 0.1.0 -> 0.2.0 is BLOCKED by the
        //     installed plugin 0.1.0 (requires core >=0.1.0 <0.2.0). Standardized,
        //     visible reason in the SAME shape the plugin flow uses.
        $blockedByPlugin = $resolver->compatibilityErrorFor($installed010, '0.2.0', '0.2.0', '0.1.0');
        self::assertInstanceOf(CompatibilityError::class, $blockedByPlugin);
        self::assertTrue($blockedByPlugin->blocking);
        self::assertSame('>=0.1.0 <0.2.0', $blockedByPlugin->requiredRange);
        self::assertStringContainsString('0.2.0', $blockedByPlugin->message);

        // The dedicated core-preflight factory produces the same standardized shape.
        $preflightError = CompatibilityError::coreUpdateBlockedByPlugin(
            pluginId: 'sh2-shp-survey-js',
            currentCoreVersion: '0.1.0',
            coreTargetVersion: '0.2.0',
            requiredCoreRange: '>=0.1.0 <0.2.0',
        );
        $shape = $preflightError->toArray();
        self::assertSame('plugin', $shape['component']);
        self::assertSame('sh2-shp-survey-js', $shape['component_id']);
        self::assertSame('0.1.0', $shape['current_version']);
        self::assertSame('0.2.0', $shape['target_version']);
        self::assertSame('>=0.1.0 <0.2.0', $shape['required_range']);
        self::assertTrue($shape['blocking']);
    }

    public function testRequestingTheNewerIncompatiblePluginVersionIsBlocked(): void
    {
        $client = $this->client();
        $index = $client->fetchIndex(self::BASE_URL . '/registry.json');
        $releases = $client->fetchPluginReleases($index)['sh2-shp-survey-js'];

        $resolver = new PluginReleaseResolver();
        // On core 0.1.0, explicitly requesting 0.2.0 must be blocked (not silently
        // downgraded), with the selected release left null.
        $resolution = $resolver->resolveVersion($releases, '0.2.0', '0.1.0', '0.1.0');
        self::assertNull($resolution->selected);
        self::assertInstanceOf(CompatibilityError::class, $resolution->error);
        self::assertTrue($resolution->error->blocking);

        // The older compatible 0.1.0 stays selectable as the latest compatible.
        self::assertNotNull($resolution->latestCompatible);
        self::assertSame('0.1.0', $resolution->latestCompatible->version);
    }

    public function testTamperedCoreReleaseFailsAdvisoryVerificationAtCmsBoundary(): void
    {
        $dir = $this->fixtureDir();
        $original = (string) file_get_contents($dir . '/releases/core/selfhelp-core-0.1.0.json');
        $decoded = json_decode($original, true);
        self::assertIsArray($decoded);
        // Bump the advisory php version but keep the original signature -> the
        // recomputed canonical payload no longer matches the signed bytes.
        self::assertIsArray($decoded['backend']);
        $decoded['backend']['phpVersion'] = '9.9';
        self::assertIsArray($decoded['security']);
        unset($decoded['security']['signedPayload'], $decoded['security']['signedPayloadSha256']);
        $tampered = json_encode($decoded);
        self::assertIsString($tampered);

        $http = new MockHttpClient(static function (string $method, string $url) use ($dir, $tampered): MockResponse {
            $path = ltrim(substr($url, \strlen(self::BASE_URL)), '/');
            if ($path === 'releases/core/selfhelp-core-0.1.0.json') {
                return new MockResponse($tampered);
            }
            $file = $dir . '/' . $path;
            $body = is_file($file) ? (string) file_get_contents($file) : 'not found';
            return new MockResponse($body, ['http_code' => is_file($file) ? 200 : 404]);
        });
        $client = new UnifiedRegistryClient($http, $this->trustedVerifier(), new NullLogger());

        $this->expectException(\App\Plugin\Registry\Unified\MalformedRegistryException::class);
        $this->expectExceptionMessageMatches('/signature verification failed/');
        $client->fetchCoreRelease(self::BASE_URL . '/releases/core/selfhelp-core-0.1.0.json');
    }
}
