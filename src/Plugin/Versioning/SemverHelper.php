<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Versioning;

/**
 * Small, self-contained semver helper used by the plugin manager.
 *
 * Composer's semver library is the canonical reference for plugin
 * package resolution; this helper covers the smaller subset the plugin
 * layer itself needs (compatibility ranges in `plugin.json`, comparing
 * installed plugin versions to registry entries). It is *not* a
 * general-purpose semver library — it deliberately mirrors the
 * narrowly-scoped contract used by `@selfhelp/shared/plugin-sdk` so
 * backend + frontend agree on what "matches" means.
 *
 * Supported range syntax:
 *   - `1.2.3` (exact)
 *   - `^1.2.3` (compatible with 1.x)
 *   - `~1.2.3` (compatible with 1.2.x)
 *   - `>=1.2.3`, `<=1.2.3`, `>1.2.3`, `<1.2.3`
 *   - `>=1.2.3 <2.0.0` (and-joined)
 *   - `1.2.3 || 1.3.x || ^2.0` (or-joined)
 *   - `*` / empty (matches anything)
 *
 * Versions with pre-release/build metadata are parsed but only the
 * numeric `MAJOR.MINOR.PATCH` parts participate in comparison
 * (matches the existing simplified comparator in
 * `@selfhelp/shared/plugin-sdk/version.ts`).
 */
final class SemverHelper
{
    /**
     * @return array{major:int, minor:int, patch:int, pre:?string, build:?string}|null
     */
    public static function parse(string $version): ?array
    {
        $trimmed = ltrim(trim($version), 'vV=');
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:-([0-9A-Za-z\.-]+))?(?:\+([0-9A-Za-z\.-]+))?$/', $trimmed, $m) !== 1) {
            return null;
        }

        return [
            'major' => (int) $m[1],
            'minor' => isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0,
            'patch' => isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 0,
            'pre' => $m[4] ?? null,
            'build' => $m[5] ?? null,
        ];
    }

    public static function compare(string $a, string $b): int
    {
        $pa = self::parse($a);
        $pb = self::parse($b);
        if ($pa === null || $pb === null) {
            return strcmp($a, $b);
        }
        foreach (['major', 'minor', 'patch'] as $part) {
            if ($pa[$part] !== $pb[$part]) {
                return $pa[$part] <=> $pb[$part];
            }
        }
        // Pre-release versions sort BEFORE the matching final release.
        if (($pa['pre'] ?? null) !== ($pb['pre'] ?? null)) {
            if (($pa['pre'] ?? null) === null) {
                return 1;
            }
            if (($pb['pre'] ?? null) === null) {
                return -1;
            }
            return strcmp((string) $pa['pre'], (string) $pb['pre']);
        }
        return 0;
    }

    public static function satisfies(string $version, string $range): bool
    {
        $range = trim($range);
        if ($range === '' || $range === '*') {
            return true;
        }
        $parsed = self::parse($version);
        if ($parsed === null) {
            return false;
        }

        foreach (explode('||', $range) as $orPart) {
            $orPart = trim($orPart);
            if ($orPart === '' || $orPart === '*') {
                return true;
            }
            if (self::satisfiesAnd($parsed, $orPart)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array{major:int, minor:int, patch:int, pre:?string, build:?string} $version
     */
    private static function satisfiesAnd(array $version, string $andRange): bool
    {
        $parts = preg_split('/\s+/', trim($andRange)) ?: [];
        $tokens = [];
        $expectOperand = false;
        $pending = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if ($expectOperand) {
                $tokens[] = $pending . $part;
                $expectOperand = false;
                $pending = '';
                continue;
            }
            if (in_array($part, ['>=', '<=', '>', '<', '^', '~', '='], true)) {
                $pending = $part;
                $expectOperand = true;
            } else {
                $tokens[] = $part;
            }
        }

        foreach ($tokens as $token) {
            if (!self::matchesSingle($version, $token)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array{major:int, minor:int, patch:int, pre:?string, build:?string} $version
     */
    private static function matchesSingle(array $version, string $token): bool
    {
        $token = trim($token);
        if ($token === '' || $token === '*') {
            return true;
        }
        if ($token[0] === '^') {
            return self::matchesCaret($version, substr($token, 1));
        }
        if ($token[0] === '~') {
            return self::matchesTilde($version, substr($token, 1));
        }
        if (str_starts_with($token, '>=')) {
            return self::compareTo($version, substr($token, 2)) >= 0;
        }
        if (str_starts_with($token, '<=')) {
            return self::compareTo($version, substr($token, 2)) <= 0;
        }
        if (str_starts_with($token, '>')) {
            return self::compareTo($version, substr($token, 1)) > 0;
        }
        if (str_starts_with($token, '<')) {
            return self::compareTo($version, substr($token, 1)) < 0;
        }
        if (str_contains($token, 'x') || str_contains($token, 'X')) {
            return self::matchesWildcard($version, $token);
        }
        if (str_starts_with($token, '=')) {
            return self::compareTo($version, substr($token, 1)) === 0;
        }
        return self::compareTo($version, $token) === 0;
    }

    /**
     * @param array{major:int, minor:int, patch:int, pre:?string, build:?string} $version
     */
    private static function matchesCaret(array $version, string $candidate): bool
    {
        $target = self::parse($candidate);
        if ($target === null) {
            return false;
        }
        if ($version['major'] !== $target['major']) {
            return false;
        }
        if ($target['major'] === 0) {
            if ($version['minor'] !== $target['minor']) {
                return false;
            }
        }
        return self::compareTo($version, $candidate) >= 0;
    }

    /**
     * @param array{major:int, minor:int, patch:int, pre:?string, build:?string} $version
     */
    private static function matchesTilde(array $version, string $candidate): bool
    {
        $target = self::parse($candidate);
        if ($target === null) {
            return false;
        }
        if ($version['major'] !== $target['major']) {
            return false;
        }
        if ($version['minor'] !== $target['minor']) {
            return false;
        }
        return self::compareTo($version, $candidate) >= 0;
    }

    /**
     * @param array{major:int, minor:int, patch:int, pre:?string, build:?string} $version
     */
    private static function matchesWildcard(array $version, string $token): bool
    {
        $parts = explode('.', strtolower($token));
        $expected = [
            'major' => $parts[0] ?? '*',
            'minor' => $parts[1] ?? '*',
            'patch' => $parts[2] ?? '*',
        ];
        foreach ($expected as $name => $value) {
            if ($value === '*' || $value === 'x' || $value === '') {
                continue;
            }
            if (!ctype_digit($value)) {
                return false;
            }
            if ($version[$name] !== (int) $value) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array{major:int, minor:int, patch:int, pre:?string, build:?string} $version
     */
    private static function compareTo(array $version, string $candidate): int
    {
        $target = self::parse($candidate);
        if ($target === null) {
            return 1;
        }
        foreach (['major', 'minor', 'patch'] as $part) {
            if ($version[$part] !== $target[$part]) {
                return $version[$part] <=> $target[$part];
            }
        }
        if (($version['pre'] ?? null) !== ($target['pre'] ?? null)) {
            if (($version['pre'] ?? null) === null) {
                return 1;
            }
            if (($target['pre'] ?? null) === null) {
                return -1;
            }
            return strcmp((string) $version['pre'], (string) $target['pre']);
        }
        return 0;
    }

    /**
     * Determine the kind of change from `$from` to `$to`:
     *   - "patch" when only the patch component increases.
     *   - "minor" when minor changes (DB migration carried).
     *   - "major" when major changes (breaking).
     *   - "downgrade" when `$to` < `$from`.
     *   - "same" when versions are equal.
     */
    public static function diffKind(string $from, string $to): string
    {
        $cmp = self::compare($from, $to);
        if ($cmp === 0) {
            return 'same';
        }
        if ($cmp > 0) {
            return 'downgrade';
        }
        $a = self::parse($from);
        $b = self::parse($to);
        if ($a === null || $b === null) {
            return 'unknown';
        }
        if ($a['major'] !== $b['major']) {
            return 'major';
        }
        if ($a['minor'] !== $b['minor']) {
            return 'minor';
        }
        return 'patch';
    }
}
