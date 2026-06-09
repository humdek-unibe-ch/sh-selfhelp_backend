<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin\Plugin;

use App\Plugin\Archive\PluginArchiveExtractor;
use App\Plugin\Archive\PluginArchiveInspectionService;
use App\Plugin\Archive\PluginArchiveValidator;
use App\Plugin\Manifest\PluginManifestLoader;
use App\Plugin\Security\PluginCapabilityValidator;
use App\Plugin\Security\PluginSignatureVerifier;
use App\Plugin\Security\SignedPayloadBuilder;
use App\Plugin\Versioning\PluginCompatibilityValidator;
use App\Tests\Controller\Api\V1\BaseControllerTest;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller-level tests for the inspect-archive trust-helper flow.
 *
 * Covers four cases:
 *
 *   1. **Happy path** — a `.shplugin` signed by a keyId that is NOT
 *      in `SELFHELP_PLUGIN_TRUSTED_KEYS` is rejected on the first
 *      inspect call with `signature.unknownKey.keyId=<id>`. Re-posting
 *      with the matching `(trustedKeyId, trustedKeyBase64)` multipart
 *      override flips the response to `signatureStatus='verified'`.
 *   2. **Duplicate keyId precedence** — when an env-resolved verifier
 *      already trusts `keyId=foo` with key A and the operator submits
 *      an override `(foo, keyB)`, key A still wins. The override is
 *      silently ignored (verification proceeds against the original
 *      key, the helper's contract that env keys always dominate).
 *      This branch exercises the in-process inspection service with a
 *      hand-built verifier so we don't have to reconfigure the test
 *      kernel's env.
 *   3. **Invalid base64** — `trustedKeyBase64='not!valid'` is rejected
 *      at the controller layer with HTTP 400 before reaching any
 *      signature work. Same for "decoded length != 32 bytes".
 *   4. **Half-supplied override** — supplying only `trustedKeyId`
 *      (without `trustedKeyBase64`) or the inverse returns HTTP 400.
 *
 * The fixture builds a real signed `.shplugin` per test from a fresh
 * Ed25519 keypair, mirroring the canonical SurveyJS signed-payload
 * fixture pair under `tests/fixtures/signed-payload/` (which is the
 * unit-level reference for `SignedPayloadBuilder`).
 */
class InspectArchiveTrustOverrideTest extends BaseControllerTest
{
    private const KEY_ID = 'acme-test-2026';
    private const PLUGIN_ID = 'sh-trust-helper-test';
    private const PLUGIN_VERSION = '1.0.0';
    private const PACKAGE_NAME = 'humdek/sh-trust-helper-test';

    private string $workDir;
    private Filesystem $fs;
    private string $publicKeyBase64;
    private string $privateKey;
    private string $archivePath;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('sodium_crypto_sign_keypair')) {
            self::markTestSkipped('ext-sodium is required.');
        }

        $this->fs = new Filesystem();
        $this->workDir = sys_get_temp_dir() . '/sh-inspect-trust-' . bin2hex(random_bytes(4));
        $this->fs->mkdir($this->workDir);

        $kp = sodium_crypto_sign_keypair();
        $this->publicKeyBase64 = base64_encode(sodium_crypto_sign_publickey($kp));
        $this->privateKey = sodium_crypto_sign_secretkey($kp);
        $this->archivePath = $this->buildSignedArchive();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->workDir)) {
            $this->fs->remove($this->workDir);
        }
        parent::tearDown();
    }

    /**
     * Static cache so we only suffer the MySQL connection timeout once
     * per test class even when several tests call into the controller
     * via the auth/login flow. Without this every test would re-pay
     * the full DB-connect retry budget.
     */
    private static ?bool $adminAvailable = null;

    private function ensureAdminAvailable(): void
    {
        if (self::$adminAvailable === false) {
            $this->markTestSkipped('Admin login not available (cached from earlier test in this class).');
        }
        try {
            $this->getAdminAccessToken();
            self::$adminAvailable = true;
        } catch (\Throwable $e) {
            self::$adminAvailable = false;
            $this->markTestSkipped('Admin login not available: ' . $e->getMessage());
        }
    }

    public function testHappyPathInspectArchiveSignedByUnknownKeyAcceptsTrustOverride(): void
    {
        $this->ensureAdminAvailable();

        // Step 1 — first inspect with no override. The test env's
        // SELFHELP_PLUGIN_TRUSTED_KEYS is empty by default, so the
        // signature fails with the recoverable "unknown key" branch
        // and the response carries `signature.unknownKey`.
        $first = $this->postInspect();
        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), 'inspect must always return 200 (errors are reported in body).');
        $firstData = $this->asArray($first['data'] ?? null);
        $this->assertFalse($firstData['ok'] ?? null);
        $firstSig = $this->asArray($firstData['signature'] ?? null);
        $this->assertSame('invalid', $firstSig['status'] ?? null);
        $this->assertSame(self::KEY_ID, $firstSig['keyId'] ?? null);
        $unknownKey = $this->asArray($firstSig['unknownKey'] ?? null, 'The empty-trusted-keys path is recoverable; unknownKey must be populated.');
        $this->assertSame(self::KEY_ID, $unknownKey['keyId'] ?? null);
        $this->assertStringContainsString(self::KEY_ID, $this->asString($unknownKey['envSnippet'] ?? null));

        // Step 2 — re-post with the matching override. Verification
        // now succeeds for this single request only; env / lock files
        // are untouched.
        $second = $this->postInspect([
            'trustedKeyId' => self::KEY_ID,
            'trustedKeyBase64' => $this->publicKeyBase64,
        ]);
        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $secondSig = $this->asArray($this->asArray($second['data'] ?? null)['signature'] ?? null);
        $this->assertSame('verified', $secondSig['status'] ?? null);
        $this->assertNull($secondSig['unknownKey'] ?? null, 'unknownKey must clear on a successful verify.');
    }

    public function testRejectsInvalidTrustedKeyBase64(): void
    {
        $this->ensureAdminAvailable();
        $this->client->request(
            'POST',
            '/cms-api/v1/admin/plugins/inspect-archive',
            [
                'trustedKeyId' => self::KEY_ID,
                'trustedKeyBase64' => 'not!!!valid_base64$$$',
            ],
            ['archive' => $this->uploadedArchive()],
            ['HTTP_Authorization' => 'Bearer ' . $this->getAdminAccessToken()],
        );
        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertNotNull($body, 'Response body must be JSON.');
        $message = is_array($body) && isset($body['error']) ? $this->coerceString($body['error']) : '';
        $this->assertStringContainsString('trustedKeyBase64', $message);
    }

    public function testRejectsTrustedKeyBase64WithWrongLength(): void
    {
        $this->ensureAdminAvailable();
        // 8 bytes encoded — valid base64 but not a 32-byte Ed25519 key.
        $shortKey = base64_encode(random_bytes(8));
        $this->client->request(
            'POST',
            '/cms-api/v1/admin/plugins/inspect-archive',
            [
                'trustedKeyId' => self::KEY_ID,
                'trustedKeyBase64' => $shortKey,
            ],
            ['archive' => $this->uploadedArchive()],
            ['HTTP_Authorization' => 'Bearer ' . $this->getAdminAccessToken()],
        );
        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $message = is_array($body) && isset($body['error']) ? $this->coerceString($body['error']) : '';
        $this->assertStringContainsString('32 bytes', $message);
    }

    public function testRejectsHalfSuppliedOverrideKeyIdOnly(): void
    {
        $this->ensureAdminAvailable();
        $this->client->request(
            'POST',
            '/cms-api/v1/admin/plugins/inspect-archive',
            ['trustedKeyId' => self::KEY_ID],
            ['archive' => $this->uploadedArchive()],
            ['HTTP_Authorization' => 'Bearer ' . $this->getAdminAccessToken()],
        );
        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $message = is_array($body) && isset($body['error']) ? $this->coerceString($body['error']) : '';
        $this->assertStringContainsString('must be provided together', $message);
    }

    public function testRejectsHalfSuppliedOverrideBase64Only(): void
    {
        $this->ensureAdminAvailable();
        $this->client->request(
            'POST',
            '/cms-api/v1/admin/plugins/inspect-archive',
            ['trustedKeyBase64' => $this->publicKeyBase64],
            ['archive' => $this->uploadedArchive()],
            ['HTTP_Authorization' => 'Bearer ' . $this->getAdminAccessToken()],
        );
        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $message = is_array($body) && isset($body['error']) ? $this->coerceString($body['error']) : '';
        $this->assertStringContainsString('must be provided together', $message);
    }

    /**
     * Duplicate-keyId precedence: when the env-resolved verifier
     * already trusts a keyId, an inspect-archive override that targets
     * the same keyId is silently ignored. The original (env-trusted)
     * key remains the one used to verify the signature.
     *
     * The kernel test env normally has empty `SELFHELP_PLUGIN_TRUSTED_KEYS`
     * so we cannot exercise this through the HTTP boundary directly
     * without env mutation. We build the inspection service in-process
     * with a pre-seeded verifier and assert on `inspect()` directly.
     * The controller layer is a thin pass-through here — the
     * precedence guarantee lives entirely in
     * `PluginSignatureVerifier::withAdditionalTrustedKey()`.
     */
    public function testDuplicateKeyIdPrecedenceOverrideIsIgnored(): void
    {
        // Bogus second key — distinct from the real one used to sign
        // the archive. The override below points keyId=acme-test-2026
        // at this bogus key. If the override were honoured, signature
        // verification would fail because the bogus key cannot validate
        // the real signature; if precedence is honoured (env wins),
        // verification succeeds against the real key.
        $bogusKp = sodium_crypto_sign_keypair();
        $bogusPubB64 = base64_encode(sodium_crypto_sign_publickey($bogusKp));

        $container = static::getContainer();

        /** @var PluginArchiveExtractor $extractor */
        $extractor = $container->get(PluginArchiveExtractor::class);
        /** @var PluginManifestLoader $manifestLoader */
        $manifestLoader = $container->get(PluginManifestLoader::class);
        // Built in-process below to construct the test PluginArchiveValidator.
        $signedPayloadBuilder = new SignedPayloadBuilder();
        /** @var PluginCompatibilityValidator $compatibility */
        $compatibility = $container->get(PluginCompatibilityValidator::class);
        /** @var PluginCapabilityValidator $capabilities */
        $capabilities = $container->get(PluginCapabilityValidator::class);
        /** @var \App\Plugin\Lifecycle\PluginApiRouteSynchronizer $apiRouteSynchronizer */
        $apiRouteSynchronizer = $container->get(\App\Plugin\Lifecycle\PluginApiRouteSynchronizer::class);

        // Pre-seeded verifier mirrors what the env-resolved verifier
        // would look like if SELFHELP_PLUGIN_TRUSTED_KEYS contained
        // `acme-test-2026=<realPub>`.
        $envVerifier = new PluginSignatureVerifier(
            trustedKeys: [self::KEY_ID => $this->publicKeyBase64],
            requireSignature: true,
            appEnv: 'test',
        );
        $envValidator = new PluginArchiveValidator(
            $manifestLoader,
            $signedPayloadBuilder,
            $envVerifier,
        );
        $service = new PluginArchiveInspectionService(
            $extractor,
            $manifestLoader,
            $envValidator,
            $compatibility,
            $capabilities,
            $envVerifier,
            $apiRouteSynchronizer,
        );

        $upload = $this->uploadedArchive();
        $result = $service->inspect($upload, [
            'keyId' => self::KEY_ID,
            'publicKeyBase64' => $bogusPubB64,
        ]);

        $this->assertSame(
            'verified',
            $result['signature']['status'],
            'Env-pinned trusted key MUST win over a same-keyId per-request override. errors=' . json_encode($result['errors']),
        );
        $this->assertNull($result['signature']['unknownKey']);
    }

    /**
     * @param array<string,string> $extraFields
     * @return array<string,mixed>
     */
    private function postInspect(array $extraFields = []): array
    {
        $this->client->request(
            'POST',
            '/cms-api/v1/admin/plugins/inspect-archive',
            $extraFields,
            ['archive' => $this->uploadedArchive()],
            ['HTTP_Authorization' => 'Bearer ' . $this->getAdminAccessToken()],
        );
        $raw = (string) $this->client->getResponse()->getContent();

        return $this->asArray(json_decode($raw, true), 'Response was not JSON: ' . $raw);
    }

    private function uploadedArchive(): UploadedFile
    {
        // Each request gets its own UploadedFile with an independent
        // path so Symfony's HttpFoundation can move/copy it without
        // racing against the previous test step.
        $copy = $this->workDir . '/upload-' . bin2hex(random_bytes(3)) . '.shplugin';
        copy($this->archivePath, $copy);
        return new UploadedFile(
            $copy,
            self::PLUGIN_ID . '-' . self::PLUGIN_VERSION . '.shplugin',
            'application/zip',
            null,
            true,
        );
    }

    /**
     * Builds a real, signature-verified `.shplugin` in `$this->workDir`
     * and returns the absolute path. The archive is signed by
     * `self::KEY_ID` against the per-test private key, with the
     * canonical signed payload computed by `SignedPayloadBuilder` so
     * the host's `PluginArchiveValidator` re-derives byte-equality.
     */
    private function buildSignedArchive(): string
    {
        $stagingDir = $this->workDir . '/staging-' . bin2hex(random_bytes(3));
        $artifactsDir = $stagingDir . '/artifacts';
        $this->fs->mkdir($artifactsDir);

        $esm = "export const register = () => ({ id: '" . self::PLUGIN_ID . "' });\n";
        file_put_contents($artifactsDir . '/plugin.esm.js', $esm);

        $esmHash = hash_file('sha256', $artifactsDir . '/plugin.esm.js');
        file_put_contents(
            $artifactsDir . '/SHA256SUMS',
            sprintf("%s  artifacts/plugin.esm.js\n", $esmHash),
        );

        $manifest = [
            'id' => self::PLUGIN_ID,
            'name' => 'Trust Helper Test Fixture',
            'version' => self::PLUGIN_VERSION,
            'pluginApiVersion' => '0.1.0',
            'description' => 'Synthetic plugin used only by InspectArchiveTrustOverrideTest.',
            'compatibility' => ['selfhelp' => '>=0.1.0 <0.2.0', 'php' => '^8.4'],
            'backend' => [
                'bundleClass' => 'Humdek\\TrustHelperTest\\TrustHelperTestBundle',
                'composer' => ['package' => self::PACKAGE_NAME, 'version' => self::PLUGIN_VERSION],
            ],
            'frontend' => [
                'runtime' => ['entrypoint' => 'dist/plugin.esm.js', 'format' => 'esm'],
            ],
            'security' => [
                'trustLevel' => 'reviewed',
                'capabilities' => ['backendBundle', 'frontendStyles'],
            ],
            // Connected archive: the backend Composer package is resolved from
            // Packagist/VCS, the archive itself only ships the frontend bundle.
            // The block is part of the canonical signed payload (see
            // SignedPayloadBuilder + tests/fixtures/signed-payload), so it must
            // be present in the manifest for the validator to re-derive
            // byte-equality.
            'archive' => ['mode' => 'connected'],
        ];
        file_put_contents(
            $stagingDir . '/plugin.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );

        $payloadBuilder = new SignedPayloadBuilder();
        $signedPayload = $payloadBuilder->build([
            'pluginId' => self::PLUGIN_ID,
            'version' => self::PLUGIN_VERSION,
            'composer' => ['package' => self::PACKAGE_NAME, 'version' => self::PLUGIN_VERSION],
            'runtime' => ['entrypointUrl' => 'artifacts/plugin.esm.js', 'format' => 'esm'],
            'checksums' => ['frontendEsm' => 'sha256-' . $esmHash],
            'compatibility' => ['selfhelp' => '>=0.1.0 <0.2.0', 'php' => '^8.4'],
            // Matches the manifest's connected archive block so the host's
            // PluginArchiveValidator recomputes the identical canonical payload.
            'archive' => ['mode' => 'connected'],
        ]);

        $privateKey = $this->privateKey;
        if ($privateKey === '') {
            self::fail('Test Ed25519 private key must not be empty.');
        }
        $signature = base64_encode(sodium_crypto_sign_detached($signedPayload, $privateKey));
        file_put_contents(
            $stagingDir . '/signature.json',
            json_encode([
                'keyId' => self::KEY_ID,
                'signature' => $signature,
                'signedPayload' => $signedPayload,
            ], JSON_PRETTY_PRINT) . "\n",
        );

        $archivePath = $this->workDir . '/' . self::PLUGIN_ID . '-' . self::PLUGIN_VERSION . '.shplugin';
        $zip = new \ZipArchive();
        if ($zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create test ZIP at ' . $archivePath);
        }
        $zip->addFile($stagingDir . '/plugin.json', 'plugin.json');
        $zip->addFile($stagingDir . '/signature.json', 'signature.json');
        $zip->addFile($artifactsDir . '/SHA256SUMS', 'artifacts/SHA256SUMS');
        $zip->addFile($artifactsDir . '/plugin.esm.js', 'artifacts/plugin.esm.js');
        $zip->close();

        return $archivePath;
    }
}
