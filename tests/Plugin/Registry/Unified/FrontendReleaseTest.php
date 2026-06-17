<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Plugin\Registry\Unified;

use App\Plugin\Registry\Unified\FrontendRelease;
use App\Plugin\Registry\Unified\MalformedRegistryException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Parsing of the signed FRONTEND release document the CMS reads for the
 * frontend-only update compatibility preflight. The backend only needs the
 * `backendCompatibility.requiredCoreRange` (and the security block to verify the
 * signature), but it validates the document shape so a malformed advisory fails
 * loudly instead of silently weakening the frontend ⇄ core compatibility gate.
 */
#[Group('plugin')]
final class FrontendReleaseTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private function validDoc(): array
    {
        return [
            'kind' => 'selfhelp-frontend-release',
            'id' => 'selfhelp-frontend-0.1.0',
            'version' => '0.1.0',
            'channel' => 'stable',
            'image' => 'ghcr.io/humdek-unibe-ch/selfhelp-frontend:0.1.0',
            'digest' => 'sha256:' . str_repeat('4', 64),
            'backendCompatibility' => [
                'requiredCoreRange' => '>=0.1.0 <0.2.0',
                'requiredApiVersion' => '0.1.0',
            ],
            'security' => [
                'signature' => 'c2ln',
                'keyId' => 'selfhelp-dev-fixture',
            ],
        ];
    }

    public function testParsesTheCompatibilityRangeAndCoreFields(): void
    {
        $release = FrontendRelease::fromArray($this->validDoc(), 'frontend release (test)');

        self::assertSame('selfhelp-frontend-0.1.0', $release->id);
        self::assertSame('0.1.0', $release->version);
        self::assertSame('stable', $release->channel);
        self::assertSame('>=0.1.0 <0.2.0', $release->requiredCoreRange);
        self::assertSame('0.1.0', $release->requiredApiVersion);
        self::assertFalse($release->blocked);
    }

    public function testRejectsAWrongKind(): void
    {
        $doc = $this->validDoc();
        $doc['kind'] = 'selfhelp-core-release';

        $this->expectException(MalformedRegistryException::class);
        $this->expectExceptionMessageMatches('/expected kind/');
        FrontendRelease::fromArray($doc, 'frontend release (test)');
    }

    public function testRejectsAnUnknownChannel(): void
    {
        $doc = $this->validDoc();
        $doc['channel'] = 'experimental';

        $this->expectException(MalformedRegistryException::class);
        $this->expectExceptionMessageMatches('/channel must be one of/');
        FrontendRelease::fromArray($doc, 'frontend release (test)');
    }

    public function testRejectsAMissingRequiredCoreRange(): void
    {
        $doc = $this->validDoc();
        // Replace the sub-object with one missing requiredCoreRange.
        $doc['backendCompatibility'] = ['requiredApiVersion' => '0.1.0'];

        $this->expectException(MalformedRegistryException::class);
        $this->expectExceptionMessageMatches('/requiredCoreRange/');
        FrontendRelease::fromArray($doc, 'frontend release (test)');
    }

    public function testRejectsAMissingSecurityBlock(): void
    {
        $doc = $this->validDoc();
        unset($doc['security']);

        $this->expectException(MalformedRegistryException::class);
        $this->expectExceptionMessageMatches('/requires a security block/');
        FrontendRelease::fromArray($doc, 'frontend release (test)');
    }
}
