<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Security;

/**
 * Builds the canonical JSON document that is signed by the publisher
 * (CI workflow) and verified by the host. Output MUST be byte-identical
 * between this PHP implementation and the Node CLI shipped in
 * sh2-plugin-registry/scripts/sign.mjs.
 *
 * Canonicalisation rules:
 *
 *   - JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE.
 *   - All object keys sorted ascending (recursive).
 *   - No trailing newline.
 *   - Empty objects/arrays preserved as-is.
 *   - Optional fields included with their value when present, omitted
 *     when null/missing — never coerced to "" or 0.
 *
 * Inputs (taken from plugin.json + registry build step):
 *
 *   - pluginId        (string)
 *   - version         (string)
 *   - manifestUrl     (string, optional)
 *   - composer        ({package, version, repository?})
 *   - runtime         ({entrypointUrl, stylesheetUrl?, format, integrity?, stylesheetIntegrity?})
 *   - checksums       ({frontendEsm, frontendCss?})
 *   - compatibility   ({selfhelp, php?, node?, react?, reactNative?, expoSdk?})
 *
 * The cross-impl test (tests/Plugin/Security/SignedPayloadBuilderTest.php
 * + tests/fixtures/signed-payload/*.json) feeds the same input through
 * the PHP and JS builders and asserts byte-equality.
 */
final class SignedPayloadBuilder
{
    /**
     * @param array<string,mixed> $input
     */
    public function build(array $input): string
    {
        $normalised = $this->normalise($input);
        $sorted = $this->sortRecursive($normalised);
        $json = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('SignedPayloadBuilder: failed to encode canonical JSON: ' . json_last_error_msg());
        }
        return $json;
    }

    /**
     * Normalises the caller's input into the canonical shape. Missing
     * optional sections are dropped (not nulled). Required sections
     * raise an explicit error so the cross-impl tests fail loudly on
     * publisher-side bugs.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function normalise(array $input): array
    {
        $out = [];

        $out['pluginId'] = $this->requireString($input, 'pluginId');
        $out['version']  = $this->requireString($input, 'version');

        if (isset($input['manifestUrl']) && is_string($input['manifestUrl']) && $input['manifestUrl'] !== '') {
            $out['manifestUrl'] = $input['manifestUrl'];
        }

        $composer = $input['composer'] ?? null;
        if (!is_array($composer)) {
            throw new \InvalidArgumentException('SignedPayloadBuilder: "composer" must be an object.');
        }
        $composerOut = [
            'package' => $this->requireString($composer, 'package', 'composer.package'),
            'version' => $this->requireString($composer, 'version', 'composer.version'),
        ];
        if (isset($composer['repository']) && is_array($composer['repository'])) {
            $repo = $composer['repository'];
            $repoOut = [
                'type' => $this->requireString($repo, 'type', 'composer.repository.type'),
                'url'  => $this->requireString($repo, 'url', 'composer.repository.url'),
            ];
            if (isset($repo['reference']) && is_string($repo['reference']) && $repo['reference'] !== '') {
                $repoOut['reference'] = $repo['reference'];
            }
            $composerOut['repository'] = $repoOut;
        }
        $out['composer'] = $composerOut;

        $runtime = $input['runtime'] ?? null;
        if (!is_array($runtime)) {
            throw new \InvalidArgumentException('SignedPayloadBuilder: "runtime" must be an object.');
        }
        $runtimeOut = [
            'entrypointUrl' => $this->requireString($runtime, 'entrypointUrl', 'runtime.entrypointUrl'),
            'format'        => $this->requireString($runtime, 'format', 'runtime.format'),
        ];
        foreach (['stylesheetUrl', 'integrity', 'stylesheetIntegrity'] as $opt) {
            if (isset($runtime[$opt]) && is_string($runtime[$opt]) && $runtime[$opt] !== '') {
                $runtimeOut[$opt] = $runtime[$opt];
            }
        }
        $out['runtime'] = $runtimeOut;

        $checksums = $input['checksums'] ?? null;
        if (!is_array($checksums)) {
            throw new \InvalidArgumentException('SignedPayloadBuilder: "checksums" must be an object.');
        }
        $checksumsOut = [
            'frontendEsm' => $this->requireString($checksums, 'frontendEsm', 'checksums.frontendEsm'),
        ];
        if (isset($checksums['frontendCss']) && is_string($checksums['frontendCss']) && $checksums['frontendCss'] !== '') {
            $checksumsOut['frontendCss'] = $checksums['frontendCss'];
        }
        $out['checksums'] = $checksumsOut;

        $compat = $input['compatibility'] ?? null;
        if (!is_array($compat)) {
            throw new \InvalidArgumentException('SignedPayloadBuilder: "compatibility" must be an object.');
        }
        $compatOut = [
            'selfhelp' => $this->requireString($compat, 'selfhelp', 'compatibility.selfhelp'),
        ];
        foreach (['php', 'node', 'react', 'reactNative', 'expoSdk'] as $opt) {
            if (isset($compat[$opt]) && is_string($compat[$opt]) && $compat[$opt] !== '') {
                $compatOut[$opt] = $compat[$opt];
            }
        }
        $out['compatibility'] = $compatOut;

        return $out;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function requireString(array $data, string $key, ?string $label = null): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException(sprintf('SignedPayloadBuilder: "%s" is required and must be a non-empty string.', $label ?? $key));
        }
        return $value;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function sortRecursive($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        // List-style arrays are kept in order; only assoc arrays are sorted.
        if (array_is_list($value)) {
            return array_map(fn($v) => $this->sortRecursive($v), $value);
        }
        ksort($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = $this->sortRecursive($v);
        }
        return $out;
    }
}
