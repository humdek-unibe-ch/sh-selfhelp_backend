<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Plugin\Security;

use App\Plugin\Security\SignedPayloadBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Cross-implementation byte-equality test for the canonical signed
 * payload.
 *
 * The PHP `SignedPayloadBuilder` and the Node `sign.mjs build-payload`
 * MUST emit byte-identical output for every plugin shape; otherwise
 * the host cannot verify what CI signed. The fixture-only test in
 * `SignedPayloadBuilderTest` covers the PHP-side bytes; this test
 * additionally invokes the Node implementation (when available) on
 * the same fixture inputs and asserts the same bytes come back.
 *
 * Skipped when:
 *   - Node is not on PATH.
 *   - The shared registry checkout is not at
 *     <repo>/../sh2-plugin-registry/scripts/sign.mjs
 *     (CI installs it explicitly; local dev requires a sibling
 *     clone).
 */
final class CrossImplPayloadParityTest extends TestCase
{
    public function testNodeBuildPayloadMatchesPhp(): void
    {
        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not on PATH; skipping cross-impl parity check.');
        }
        $script = $this->locateSignScript();
        if ($script === null) {
            self::markTestSkipped(
                'sign.mjs not found at ../sh2-plugin-registry/scripts/sign.mjs; ' .
                'clone the registry repo beside this checkout to run the parity test locally.',
            );
        }

        $php = new SignedPayloadBuilder();
        $fixtureDir = __DIR__ . '/../../fixtures/signed-payload';
        $inputs = glob($fixtureDir . '/*.input.json') ?: [];
        self::assertNotEmpty($inputs, 'No signed-payload fixtures discovered.');

        foreach ($inputs as $inputPath) {
            $name = basename($inputPath, '.input.json');
            $input = (string) file_get_contents($inputPath);
            $expected = $php->build(json_decode($input, true));

            $proc = new Process([$node, $script, 'build-payload', '--input', $inputPath]);
            $proc->run();

            self::assertSame(
                0,
                $proc->getExitCode(),
                sprintf(
                    'sign.mjs build-payload failed for fixture %s (stderr=%s)',
                    $name,
                    $proc->getErrorOutput(),
                ),
            );
            self::assertSame(
                $expected,
                $proc->getOutput(),
                sprintf(
                    'sign.mjs build-payload output for %s diverged from SignedPayloadBuilder. ' .
                    'Either canonicalisation rule changed; sync both impls.',
                    $name,
                ),
            );
        }
    }

    private function findNode(): ?string
    {
        $candidates = ['node', 'node.exe'];
        foreach ($candidates as $candidate) {
            $proc = new Process([$candidate, '--version']);
            $proc->run();
            if ($proc->getExitCode() === 0) {
                return $candidate;
            }
        }
        return null;
    }

    private function locateSignScript(): ?string
    {
        $candidate = __DIR__ . '/../../../../plugins/sh2-plugin-registry/scripts/sign.mjs';
        $real = realpath($candidate);
        return $real === false ? null : $real;
    }
}
