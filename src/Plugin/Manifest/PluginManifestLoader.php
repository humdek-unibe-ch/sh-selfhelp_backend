<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Manifest;

/**
 * Reads `plugin.json` from disk, decodes it, and runs it through the
 * `PluginManifestValidator`. On success returns a `PluginManifest`
 * DTO; on failure throws with the aggregated validator errors.
 *
 * Two entry points are provided:
 *
 *   - `loadFromFile($path)` — reads from a file on disk.
 *   - `loadFromArray($data)` — used for tests and when the manifest
 *     was already decoded (e.g. fetched from a registry).
 */
final class PluginManifestLoader
{
    public function __construct(
        private readonly PluginManifestValidator $validator,
    ) {
    }

    public function loadFromFile(string $path): PluginManifest
    {
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('plugin.json not found at "%s".', $path));
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException(sprintf('Failed to read plugin.json at "%s".', $path));
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException(sprintf('plugin.json at "%s" is not a JSON object.', $path));
        }
        return $this->loadFromArray($data);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function loadFromArray(array $data): PluginManifest
    {
        $errors = $this->validator->validate($data);
        if ($errors !== []) {
            throw new \RuntimeException(
                'plugin.json is invalid:' . PHP_EOL . '  - ' . implode(PHP_EOL . '  - ', $errors)
            );
        }
        return new PluginManifest($data);
    }
}
