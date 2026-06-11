<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

/**
 * License-policy gate for the distributed backend Docker images.
 *
 * Reads the JSON output of `composer licenses --format=json` and classifies every
 * dependency against docker/license-policy.json:
 *
 *   - allowed         -> ok
 *   - reviewRequired  -> warning (listed, does not fail the build)
 *   - blocked/unknown -> failure, unless ALLOW_LICENSE_OVERRIDE=1 (explicit
 *                        reviewer/legal approval, per the plan)
 *
 * Usage:
 *   composer licenses --no-dev --format=json > licenses.json
 *   php scripts/check-license-policy.php licenses.json
 *
 * Exit code 0 = compliant (or approved override), 1 = blocked/unknown licenses.
 */

$licensesFile = $argv[1] ?? 'licenses.json';
$policyFile = __DIR__ . '/../docker/license-policy.json';

/**
 * @return array<string, mixed>
 */
$readJson = static function (string $path): array {
    if (!is_file($path)) {
        fwrite(STDERR, "license-policy: file not found: {$path}\n");
        exit(2);
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        fwrite(STDERR, "license-policy: cannot read: {$path}\n");
        exit(2);
    }
    /** @var array<string, mixed>|null $decoded */
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "license-policy: invalid JSON: {$path}\n");
        exit(2);
    }

    return $decoded;
};

$licenses = $readJson($licensesFile);
$policy = $readJson($policyFile);

/** @var list<string> $allowed */
$allowed = array_map('strval', $policy['allowed'] ?? []);
/** @var list<string> $review */
$review = array_map('strval', $policy['reviewRequired'] ?? []);
/** @var list<string> $blocked */
$blocked = array_map('strval', $policy['blocked'] ?? []);
/** @var list<string> $firstParty */
$firstParty = array_map('strval', $policy['firstPartyPackages'] ?? []);

/** @var array<string, mixed> $dependencies */
$dependencies = is_array($licenses['dependencies'] ?? null) ? $licenses['dependencies'] : [];

$reviewHits = [];
$blockedHits = [];
$unknownHits = [];
$okCount = 0;

foreach ($dependencies as $package => $info) {
    $package = (string) $package;
    if (in_array($package, $firstParty, true)) {
        continue;
    }
    $pkgLicenses = [];
    if (is_array($info) && isset($info['license']) && is_array($info['license'])) {
        $pkgLicenses = array_map('strval', $info['license']);
    }
    if ($pkgLicenses === []) {
        $unknownHits[$package] = ['<none declared>'];
        continue;
    }

    // A package is acceptable if ANY of its (OR-ed) licenses is allowed.
    $isAllowed = false;
    $isReview = false;
    $isBlocked = false;
    foreach ($pkgLicenses as $lic) {
        if (in_array($lic, $allowed, true)) {
            $isAllowed = true;
        } elseif (in_array($lic, $review, true)) {
            $isReview = true;
        } elseif (in_array($lic, $blocked, true)) {
            $isBlocked = true;
        }
    }

    if ($isAllowed) {
        ++$okCount;
    } elseif ($isReview) {
        $reviewHits[$package] = $pkgLicenses;
    } elseif ($isBlocked) {
        $blockedHits[$package] = $pkgLicenses;
    } else {
        $unknownHits[$package] = $pkgLicenses;
    }
}

$fmt = static function (array $hits): string {
    $lines = [];
    foreach ($hits as $package => $lics) {
        $lines[] = sprintf('    - %s (%s)', $package, implode(', ', $lics));
    }

    return implode("\n", $lines);
};

echo "License policy report (distributed backend images)\n";
echo str_repeat('-', 50) . "\n";
echo sprintf("allowed:        %d package(s)\n", $okCount);
echo sprintf("review-needed:  %d package(s)\n", count($reviewHits));
echo sprintf("blocked:        %d package(s)\n", count($blockedHits));
echo sprintf("unknown:        %d package(s)\n", count($unknownHits));

if ($reviewHits !== []) {
    echo "\nReview-required licenses (allowed in build, flagged for legal review):\n" . $fmt($reviewHits) . "\n";
}
if ($blockedHits !== []) {
    echo "\nBLOCKED licenses:\n" . $fmt($blockedHits) . "\n";
}
if ($unknownHits !== []) {
    echo "\nUNKNOWN / undeclared licenses:\n" . $fmt($unknownHits) . "\n";
}

$failures = count($blockedHits) + count($unknownHits);
if ($failures === 0) {
    echo "\nOK: all distributed dependencies are within the allowed/review policy.\n";
    exit(0);
}

if (getenv('ALLOW_LICENSE_OVERRIDE') === '1') {
    echo "\nWARNING: {$failures} blocked/unknown license(s) present, but ALLOW_LICENSE_OVERRIDE=1 (explicit reviewer approval). Continuing.\n";
    exit(0);
}

fwrite(STDERR, "\nFAIL: {$failures} blocked/unknown license(s). Resolve them or re-run with ALLOW_LICENSE_OVERRIDE=1 after legal approval.\n");
exit(1);
