<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\Versioning;

use App\Service\CMS\DataRevision\ContentRef;
use App\Service\Versioning\VersionRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contract C26: version references of every kind, and nothing else.
 * ContentRef::PATTERN is unchanged (this helper does not own content references).
 */
final class VersionRefTest extends TestCase
{
    /**
     * @return array<string, array{string, array{kind: string, id: int, version: int}}>
     */
    public static function wellFormed(): array
    {
        return [
            'a record' => ['record:7@v3', ['kind' => 'record', 'id' => 7, 'version' => 3]],
            'a page' => ['page:12@v1', ['kind' => 'page', 'id' => 12, 'version' => 1]],
            'an action' => ['action:4@v2', ['kind' => 'action', 'id' => 4, 'version' => 2]],
            'an asset' => ['asset:9@v5', ['kind' => 'asset', 'id' => 9, 'version' => 5]],
            'a plugin item' => ['res:qa_item:5@v2', ['kind' => 'res:qa_item', 'id' => 5, 'version' => 2]],
            'the shortest type' => ['res:ab:1@v1', ['kind' => 'res:ab', 'id' => 1, 'version' => 1]],
            'a type with digits' => ['res:qa_item_2:12@v10', ['kind' => 'res:qa_item_2', 'id' => 12, 'version' => 10]],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformed(): array
    {
        return [
            'empty' => [''],
            'a draft page (content ref only)' => ['page:7@draft'],
            'no version' => ['record:7'],
            'empty version' => ['record:7@v'],
            'letter id' => ['record:x@v1'],
            'negative id' => ['record:-7@v1'],
            'zero id' => ['record:0@v1'],
            'zero version' => ['record:7@v0'],
            'version without v' => ['record:7@3'],
            'unknown kind' => ['file:5@v2'],
            'type in capitals' => ['res:Qa_item:5@v2'],
            'type of one character' => ['res:q:5@v2'],
            'trailing space' => ['record:7@v1 '],
            'leading space' => [' record:7@v1'],
            'trailing line break' => ["record:7@v1\n"],
            'longer than the column' => ['record:' . str_repeat('1', 200) . '@v1'],
        ];
    }

    /**
     * @param array{kind: string, id: int, version: int} $expected
     */
    #[DataProvider('wellFormed')]
    public function testAWellFormedReferenceIsAcceptedAndParsed(string $ref, array $expected): void
    {
        self::assertTrue(VersionRef::isWellFormed($ref));
        self::assertSame($expected, VersionRef::parse($ref));
    }

    #[DataProvider('malformed')]
    public function testAMalformedReferenceIsRefused(string $ref): void
    {
        self::assertFalse(VersionRef::isWellFormed($ref));
        self::assertNull(VersionRef::parse($ref));
    }

    public function testBuildersProduceEachKind(): void
    {
        self::assertSame('record:7@v3', VersionRef::record(7, 3));
        self::assertSame('page:12@v1', VersionRef::page(12, 1));
        self::assertSame('action:4@v2', VersionRef::action(4, 2));
        self::assertSame('asset:9@v5', VersionRef::asset(9, 5));
        self::assertSame('res:qa_item:5@v2', VersionRef::resource('qa_item', 5, 2));
    }

    public function testBuildersRefuseNonPositiveIdsAndVersions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VersionRef::record(0, 1);
    }

    public function testBuildersRefuseAnInvalidResourceType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VersionRef::resource('Bad-Type', 1, 1);
    }

    public function testAReferenceOverTheColumnLengthIsRefusedByParse(): void
    {
        $tooLong = 'record:' . str_repeat('9', VersionRef::MAX_LENGTH) . '@v1';
        self::assertGreaterThan(VersionRef::MAX_LENGTH, strlen($tooLong));
        self::assertNull(VersionRef::parse($tooLong));
        self::assertFalse(VersionRef::isWellFormed($tooLong));
    }

    public function testContentRefPatternIsUnchanged(): void
    {
        self::assertSame(
            '/^(page:\d+@(v\d+|draft)|res:[a-z][a-z0-9_]{1,63}:\d+@v\d+)$/D',
            ContentRef::PATTERN,
            'VersionRef must not change ContentRef::PATTERN.',
        );
        self::assertTrue(ContentRef::isWellFormed('page:7@draft'));
        self::assertFalse(VersionRef::isWellFormed('page:7@draft'));
    }
}
