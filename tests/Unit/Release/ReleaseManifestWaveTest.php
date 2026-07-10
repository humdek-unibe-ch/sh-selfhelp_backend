<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Release;

use PHPUnit\Framework\TestCase;

/**
 * Release-manifest floor for the unreleased DB-routing / CMS-apps wave.
 * Registry pairing uses supports.frontend (not shared SemVer alone).
 * Mobile pairing is intentionally one-directional via mobile supports.core.
 */
final class ReleaseManifestWaveTest extends TestCase
{
    use \App\Tests\Support\NarrowsJson;

    public function testSupportsFrontendRequires063ForCore036(): void
    {
        $manifest = $this->loadManifest();
        self::assertSame('core', $manifest['kind'] ?? null);
        $supports = self::asArray($manifest['supports'] ?? null);
        self::assertSame('>=0.1.63 <0.2.0', $supports['frontend'] ?? null);
    }

    public function testCore036WithFrontend063IsSupportedByDeclaredRange(): void
    {
        $range = $this->frontendSupportRange();
        self::assertTrue($this->satisfiesPre1Range('0.1.63', $range));
    }

    public function testCore036RejectsFrontendBelow063(): void
    {
        $range = $this->frontendSupportRange();
        self::assertFalse($this->satisfiesPre1Range('0.1.62', $range));
    }

    public function testDoesNotDeclareSupportsMobile(): void
    {
        $manifest = $this->loadManifest();
        $supports = self::asArray($manifest['supports'] ?? null);
        self::assertIsString($supports['frontend'] ?? null);
        self::assertArrayNotHasKey(
            'mobile',
            $supports,
            'core does not declare supports.mobile; mobile→core is one-directional via mobile supports.core',
        );
    }

    public function testAuthDeepLinkPatternsMatchSeededPageRoutes(): void
    {
        // Mobile pre-auth deep-link ownership keeps these patterns locally;
        // they must stay aligned with the seeded page_routes migration.
        $migration = (string) file_get_contents(
            dirname(__DIR__, 3) . '/migrations/Version20260710093044.php',
        );
        self::assertStringContainsString("'/validate/{user_id}/{token}'", $migration);
        self::assertStringContainsString("'/reset/{user_id}/{token}'", $migration);
        self::assertStringContainsString("'token' => '[A-Za-z0-9._~-]+'", $migration);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadManifest(): array
    {
        $path = dirname(__DIR__, 3) . '/release-manifest.json';
        self::assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return self::asArray($decoded);
    }

    private function frontendSupportRange(): string
    {
        $manifest = $this->loadManifest();
        $supports = self::asArray($manifest['supports'] ?? null);
        $range = $supports['frontend'] ?? null;
        self::assertIsString($range);

        return $range;
    }

    /**
     * Minimal checker for the wave's `>=X.Y.Z <0.2.0` style ranges without
     * requiring composer/semver in the unit-test autoload path.
     */
    private function satisfiesPre1Range(string $version, string $range): bool
    {
        if (!preg_match('/^>=(\d+\.\d+\.\d+)\s+<(\d+\.\d+\.\d+)$/', trim($range), $m)) {
            self::fail('Unexpected supports.frontend range: ' . $range);
        }

        return version_compare($version, $m[1], '>=') && version_compare($version, $m[2], '<');
    }
}
