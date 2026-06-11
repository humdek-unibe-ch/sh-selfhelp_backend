<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

/**
 * Generator for the ONE shared, signed unified-registry test fixture consumed
 * by BOTH installers' tests:
 *
 *   - backend: tests/Plugin/Registry/Unified/* (this repo);
 *   - manager: sh-manager/packages/registry (consumes the same documents).
 *
 * It is the unified-registry sibling of the Manager's `scripts/sign-fixtures.mts`.
 * The signing key is DETERMINISTIC (derived from a fixed dev seed) so the
 * fixtures are reproducible and the consuming tests can re-derive the public
 * key without a checked-in secret. The keyId matches the Manager dev fixtures
 * (`selfhelp-dev-fixture`) so the documents are interchangeable. It is a test
 * identity only — the production registry trusts solely the `prod` key.
 *
 * Regenerate with:  php tests/fixtures/registry/unified/sign-fixtures.php
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use App\Plugin\Registry\Unified\CanonicalJson;

const KEY_ID = 'selfhelp-dev-fixture';
const SEED_PHRASE = 'selfhelp-dev-registry-signing-key-v1';
const BASE_URL = 'https://registry.selfhelp.test';

$seed = hash('sha256', SEED_PHRASE, true); // 32 raw bytes
$keypair = sodium_crypto_sign_seed_keypair($seed);
$secretKey = sodium_crypto_sign_secretkey($keypair);
$publicKey = sodium_crypto_sign_publickey($keypair);

$baseDir = __DIR__;
$artifactsDir = $baseDir . '/artifacts';
$coreDir = $baseDir . '/releases/core';
$pluginsDir = $baseDir . '/releases/plugins';
foreach ([$artifactsDir, $coreDir, $pluginsDir] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
        fwrite(STDERR, "Failed to create $dir\n");
        exit(1);
    }
}

/**
 * Sign a release document: attaches a `security` block with the exact
 * canonical bytes that were signed (`signedPayload`) + its sha256.
 *
 * @param array<string,mixed> $doc release document WITHOUT a security block
 * @return array<string,mixed>
 */
function signDocument(array $doc, string $secretKey): array
{
    if ($secretKey === '') {
        throw new \InvalidArgumentException('secret key must not be empty');
    }
    $payload = CanonicalJson::encode($doc);
    $signature = sodium_crypto_sign_detached($payload, $secretKey);
    $doc['security'] = [
        'signature' => base64_encode($signature),
        'keyId' => KEY_ID,
        'signedPayload' => $payload,
        'signedPayloadSha256' => 'sha256:' . hash('sha256', $payload),
    ];
    return $doc;
}

/**
 * @param array<string,mixed> $data
 */
function writeJson(string $path, array $data): void
{
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

// --- .shplugin artifacts (dummy bytes; the archive's own internal validation
//     is exercised separately by the archive test-suite). The registry release
//     pins these by sha256. ---------------------------------------------------
$artifact010 = "SELFHELP-SHPLUGIN-FIXTURE sh2-shp-survey-js 0.1.0\n";
$artifact020 = "SELFHELP-SHPLUGIN-FIXTURE sh2-shp-survey-js 0.2.0\n";
file_put_contents($artifactsDir . '/sh2-shp-survey-js-0.1.0.shplugin', $artifact010);
file_put_contents($artifactsDir . '/sh2-shp-survey-js-0.2.0.shplugin', $artifact020);

// --- core release (Docker-based; Manager-owned) ------------------------------
$coreRelease = signDocument([
    'kind' => 'selfhelp-core-release',
    'id' => 'selfhelp-core',
    'version' => '0.1.0',
    'channel' => 'stable',
    'releasedAt' => '2026-06-09T00:00:00Z',
    'minimumDirectUpgradeFrom' => '0.1.0',
    'pluginApiVersion' => '0.1.0',
    'backend' => ['image' => 'ghcr.io/humdek-unibe-ch/selfhelp-backend:0.1.0', 'digest' => 'sha256:' . str_repeat('a', 64), 'phpVersion' => '8.4'],
    'worker' => ['image' => 'ghcr.io/humdek-unibe-ch/selfhelp-worker:0.1.0', 'digest' => 'sha256:' . str_repeat('b', 64)],
    'scheduler' => ['image' => 'ghcr.io/humdek-unibe-ch/selfhelp-scheduler:0.1.0', 'digest' => 'sha256:' . str_repeat('c', 64)],
    'frontendCompatibility' => ['requiredFrontendRange' => '>=0.1.0 <0.2.0'],
    'database' => [
        'migrationRange' => '>=0.1.0 <0.2.0',
        'destructive' => false,
        'requiresBackup' => true,
        'manualConfirmationRequired' => false,
    ],
], $secretKey);
writeJson($coreDir . '/selfhelp-core-0.1.0.json', $coreRelease);

// --- plugin releases (CMS/backend-owned): multi-version, one compatible with
//     core 0.1.0 and one only compatible with core 0.2.0 ---------------------
$plugin010 = signDocument([
    'kind' => 'selfhelp-plugin-release',
    'id' => 'sh2-shp-survey-js',
    'version' => '0.1.0',
    'channel' => 'stable',
    'official' => true,
    'compatibility' => ['core' => '>=0.1.0 <0.2.0', 'pluginApi' => '>=0.1.0 <0.2.0'],
    'dependencies' => ['plugins' => []],
    'artifacts' => [
        'manifestUrl' => BASE_URL . '/releases/plugins/sh2-shp-survey-js-0.1.0.plugin.json',
        'archiveUrl' => BASE_URL . '/artifacts/sh2-shp-survey-js-0.1.0.shplugin',
        'sha256' => 'sha256:' . hash('sha256', $artifact010),
    ],
], $secretKey);
writeJson($pluginsDir . '/sh2-shp-survey-js-0.1.0.json', $plugin010);

$plugin020 = signDocument([
    'kind' => 'selfhelp-plugin-release',
    'id' => 'sh2-shp-survey-js',
    'version' => '0.2.0',
    'channel' => 'stable',
    'official' => true,
    'compatibility' => ['core' => '>=0.2.0 <0.3.0', 'pluginApi' => '>=0.1.0 <0.2.0'],
    'dependencies' => ['plugins' => []],
    'artifacts' => [
        'manifestUrl' => BASE_URL . '/releases/plugins/sh2-shp-survey-js-0.2.0.plugin.json',
        'archiveUrl' => BASE_URL . '/artifacts/sh2-shp-survey-js-0.2.0.shplugin',
        'sha256' => 'sha256:' . hash('sha256', $artifact020),
    ],
], $secretKey);
writeJson($pluginsDir . '/sh2-shp-survey-js-0.2.0.json', $plugin020);

// --- unified index referencing the release documents -------------------------
$index = [
    'schemaVersion' => '1.0.0',
    'requiresManager' => '>=0.1.0',
    'publishedAt' => '2026-06-09T00:00:00Z',
    'baseUrl' => BASE_URL,
    // Self-referential on purpose: the fixture publisher is the fixture
    // registry itself (qa convention: no real-world URLs in created test data).
    'publisher' => ['name' => 'SelfHelp (fixture)', 'url' => BASE_URL . '/'],
    'core' => [
        ['id' => 'selfhelp-core', 'version' => '0.1.0', 'channel' => 'stable', 'releaseUrl' => 'releases/core/selfhelp-core-0.1.0.json'],
    ],
    'frontend' => [],
    'scheduler' => [],
    'worker' => [],
    'plugins' => [
        ['id' => 'sh2-shp-survey-js', 'version' => '0.1.0', 'channel' => 'stable', 'releaseUrl' => 'releases/plugins/sh2-shp-survey-js-0.1.0.json'],
        ['id' => 'sh2-shp-survey-js', 'version' => '0.2.0', 'channel' => 'stable', 'releaseUrl' => 'releases/plugins/sh2-shp-survey-js-0.2.0.json'],
    ],
    'trustedKeysUrl' => 'trusted-keys.json',
];
writeJson($baseDir . '/registry.json', $index);

// --- trusted keys file (mirrors the Manager TrustedKeysFile shape) -----------
writeJson($baseDir . '/trusted-keys.json', [
    'schemaVersion' => '1.0.0',
    'keys' => [
        ['keyId' => KEY_ID, 'publicKey' => base64_encode($publicKey), 'algorithm' => 'ed25519', 'status' => 'active'],
    ],
]);

echo "Wrote unified registry fixture under tests/fixtures/registry/unified/\n";
echo 'keyId=' . KEY_ID . "\n";
echo 'publicKey(base64)=' . base64_encode($publicKey) . "\n";
