<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Plugin\Security;

use App\Plugin\Security\SignedPayloadBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cross-implementation byte-equality contract. The fixture pair
 * `<case>.input.json` + `<case>.expected.txt` is also produced by
 * `sh2-plugin-registry/scripts/sign.mjs build-payload`. Both
 * implementations MUST emit exactly the bytes in `.expected.txt`,
 * otherwise the host can't verify what CI signed.
 */
final class SignedPayloadBuilderTest extends TestCase
{
    private SignedPayloadBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new SignedPayloadBuilder();
    }

    #[DataProvider('fixtures')]
    public function testProducesByteIdenticalCanonicalPayload(string $inputPath, string $expectedPath): void
    {
        $input = json_decode((string) file_get_contents($inputPath), true);
        $expected = (string) file_get_contents($expectedPath);

        $actual = $this->builder->build($input);

        self::assertSame($expected, $actual, sprintf(
            'PHP SignedPayloadBuilder output for %s does not match the canonical fixture. ' .
            'If you changed the canonicalisation rules, regenerate the fixture via sign.mjs and update the JS impl too.',
            basename($inputPath)
        ));
    }

    public function testRequiresMandatoryFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder->build([]);
    }

    /**
     * @return iterable<string, array{string,string}>
     */
    public static function fixtures(): iterable
    {
        $dir = __DIR__ . '/../../fixtures/signed-payload';
        foreach (glob($dir . '/*.input.json') ?: [] as $input) {
            $name = basename($input, '.input.json');
            $expected = $dir . '/' . $name . '.expected.txt';
            yield $name => [$input, $expected];
        }
    }
}
