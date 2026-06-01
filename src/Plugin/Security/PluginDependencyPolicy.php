<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Security;

use App\Plugin\PackageManager\PluginComposerRoot;

/**
 * Soft check that surfaces drift between a plugin package's
 * `composer.json#require` block and the host-provided packages exposed
 * via `var/plugin-composer/composer.json#provide`.
 *
 * Plugin packages may declare host-provided packages
 * (`symfony/*`, `doctrine/*`, `psr/*`, …) in their own `require` —
 * Composer's constraint solver then validates them against the
 * `provide` block in the plugin Composer root, so no duplicate vendor
 * tree is ever fetched. The downside of `provide` is that drift only
 * surfaces at `composer require` time, often as a confusing solver
 * error. This policy moves the drift check earlier so the operator
 * sees a clear warning right before the install proceeds.
 *
 * Two output channels:
 *
 *   - `warnings()` — informational. The package depends on a
 *     host-provided package, and Composer's solver should accept it
 *     against the provided version. We surface the entry so an
 *     operator auditing the install can confirm the version range.
 *
 *   - `violations()` — likely-failure. The constraint cannot be
 *     satisfied by the host's resolved version (semver compare).
 *     The installer can choose to log + continue (current default)
 *     or refuse the install in a future strict mode.
 *
 * The policy is intentionally pure: no DB, no IO besides reading the
 * host's `installed.json`. Decision (warn vs. block) lives in the
 * caller; this class only reports.
 */
final class PluginDependencyPolicy
{
    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    /**
     * Inspect the plugin package's `composer.json#require` block
     * (passed as a decoded associative array) and return a structured
     * report of host-provided dependencies + any constraint
     * mismatches.
     *
     * @param array<string,mixed> $packageRequire `require` block
     *
     * @return array{
     *     warnings: list<array{package:string, constraint:string, hostVersion:string}>,
     *     violations: list<array{package:string, constraint:string, hostVersion:string, reason:string}>
     * }
     */
    public function inspect(array $packageRequire): array
    {
        $hostProvided = $this->loadHostProvided();
        $warnings = [];
        $violations = [];

        foreach ($packageRequire as $package => $constraint) {
            if (!is_string($constraint)) {
                continue;
            }
            if (!$this->isHostProvided($package)) {
                continue;
            }
            $hostVersion = $hostProvided[$package] ?? null;
            if ($hostVersion === null) {
                $warnings[] = [
                    'package' => $package,
                    'constraint' => $constraint,
                    'hostVersion' => '(not installed in host)',
                ];
                continue;
            }

            if (!$this->constraintSatisfiedByHost($constraint, $hostVersion)) {
                $violations[] = [
                    'package' => $package,
                    'constraint' => $constraint,
                    'hostVersion' => $hostVersion,
                    'reason' => sprintf(
                        'Host-provided "%s" is at "%s" but the plugin requires "%s". '
                        . 'Composer will reject the install if the constraint cannot be satisfied. '
                        . 'Pin the plugin to the host-compatible major or upgrade the host.',
                        $package,
                        $hostVersion,
                        $constraint,
                    ),
                ];
                continue;
            }

            $warnings[] = [
                'package' => $package,
                'constraint' => $constraint,
                'hostVersion' => $hostVersion,
            ];
        }

        return [
            'warnings' => $warnings,
            'violations' => $violations,
        ];
    }

    private function isHostProvided(string $package): bool
    {
        foreach (PluginComposerRoot::HOST_PROVIDED_PREFIXES as $prefix) {
            if (str_starts_with($package, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<string,string>
     */
    private function loadHostProvided(): array
    {
        $installedJson = $this->projectDir
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'composer'
            . DIRECTORY_SEPARATOR . 'installed.json';
        if (!is_file($installedJson)) {
            return [];
        }
        $raw = @file_get_contents($installedJson);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $packages = $decoded['packages'] ?? [];
        if (!is_array($packages)) {
            return [];
        }
        $out = [];
        foreach ($packages as $pkg) {
            if (!is_array($pkg)) {
                continue;
            }
            $name = $pkg['name'] ?? null;
            $version = $pkg['version'] ?? null;
            if (is_string($name) && $name !== '' && is_string($version) && $version !== '') {
                $out[$name] = ltrim($version, 'vV');
            }
        }
        return $out;
    }

    /**
     * Lightweight constraint satisfaction check. Supports the common
     * cases that show up in plugin manifests:
     *
     *   - `*` / `>=0` — always satisfied;
     *   - `^X.Y[.Z]` — host major must match X, host >= X.Y[.Z];
     *   - `~X.Y[.Z]` — host major.minor must match X.Y, host >= X.Y[.Z];
     *   - `>=X.Y.Z` / `>X.Y.Z` / `<=X.Y.Z` / `<X.Y.Z` / `==X.Y.Z` —
     *     direct numeric comparison via `version_compare`;
     *   - bare `X.Y.Z` — exact match;
     *   - or-clauses (`||`) — satisfied if any branch is.
     *
     * Constraints we don't recognise return `true` so we never block
     * an install on this soft check — Composer will run anyway and
     * either resolve cleanly or fail with its own error.
     */
    private function constraintSatisfiedByHost(string $constraint, string $hostVersion): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') {
            return true;
        }
        $branches = preg_split('/\s*\|\|\s*/', $constraint) ?: [$constraint];
        foreach ($branches as $branch) {
            if ($this->branchSatisfied(trim($branch), $hostVersion)) {
                return true;
            }
        }
        return false;
    }

    private function branchSatisfied(string $branch, string $hostVersion): bool
    {
        if ($branch === '' || $branch === '*') {
            return true;
        }
        if (preg_match('/^\^(\d+(?:\.\d+){0,2})/', $branch, $m) === 1) {
            $base = $m[1];
            $hostMajor = (int) explode('.', $hostVersion)[0];
            $baseMajor = (int) explode('.', $base)[0];
            if ($hostMajor !== $baseMajor) {
                return false;
            }
            return version_compare($hostVersion, $base, '>=');
        }
        if (preg_match('/^~(\d+\.\d+)(?:\.(\d+))?/', $branch, $m) === 1) {
            $minor = $m[1];
            $patch = isset($m[2]) ? '.' . $m[2] : '';
            $hostParts = explode('.', $hostVersion);
            $hostMinor = isset($hostParts[1]) ? $hostParts[0] . '.' . $hostParts[1] : $hostVersion;
            if ($hostMinor !== $minor) {
                return false;
            }
            return version_compare($hostVersion, $minor . $patch, '>=');
        }
        if (preg_match('/^(>=|<=|<>|!=|==|=|>|<)\s*(.+)$/', $branch, $m) === 1) {
            $op = $m[1] === '=' ? '==' : $m[1];
            return version_compare($hostVersion, trim($m[2]), $op);
        }
        if (preg_match('/^\d+(?:\.\d+){0,3}(?:-.+)?$/', $branch) === 1) {
            return version_compare($hostVersion, $branch, '==');
        }
        return true;
    }
}
