<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\CMS\Admin;

/**
 * Resolves curated example bundle paths. Frontend {@code examples/} is canonical;
 * backend ships minimal fallbacks for fresh-install seeding and CI.
 */
final class ExampleBundlePathResolver
{
    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    /**
     * Directories scanned for {@code *.bundle.json} catalogues (page + navigation).
     *
     * @return list<string>
     */
    public function listBundleDirectories(): array
    {
        $dirs = [];
        foreach ($this->candidateRoots() as $root) {
            foreach (['pages', 'cms-in-cms', 'navigation'] as $sub) {
                $path = $root . '/' . $sub;
                if (is_dir($path)) {
                    $dirs[] = $path;
                }
            }
            $legacy = $root . '/docs/examples/cms-in-cms';
            if (is_dir($legacy)) {
                $dirs[] = $legacy;
            }
            $legacyTop = $root . '/docs/examples';
            if (is_dir($legacyTop)) {
                $dirs[] = $legacyTop;
            }
        }

        $fixtureDir = $this->projectDir . '/tests/fixtures/examples';
        if (is_dir($fixtureDir)) {
            $dirs[] = $fixtureDir;
        }

        return array_values(array_unique($dirs));
    }

    public function resolveHeroHomeBundlePath(): string
    {
        foreach ($this->heroHomeCandidates() as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        throw new \RuntimeException('hero-home.bundle.json not found in frontend examples or backend fixtures.');
    }

    /**
     * @return list<string>
     */
    private function heroHomeCandidates(): array
    {
        $paths = [];
        foreach ($this->candidateRoots() as $root) {
            $paths[] = $root . '/examples/pages/hero-home.bundle.json';
            $paths[] = $root . '/docs/examples/hero-home.bundle.json';
        }
        $paths[] = $this->projectDir . '/tests/fixtures/examples/hero-home.bundle.json';

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function candidateRoots(): array
    {
        $roots = [
            dirname($this->projectDir),
            dirname($this->projectDir, 2),
        ];

        return array_values(array_unique($roots));
    }
}
