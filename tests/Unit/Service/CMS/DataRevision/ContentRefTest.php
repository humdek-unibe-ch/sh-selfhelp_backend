<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\CMS\DataRevision;

use App\Service\CMS\DataRevision\ContentRef;
use App\Service\CMS\DataRevision\RecordWriteContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contract C19: the three shapes of a content reference, and nothing else.
 */
final class ContentRefTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function wellFormed(): array
    {
        return [
            'a published page version' => ['page:7@v3'],
            'the draft of a page' => ['page:7@draft'],
            'page id zero is still a number' => ['page:0@v1'],
            'a large page id and version' => ['page:2147483647@v99999'],
            'a version of a plugin item' => ['res:qa_item:5@v2'],
            'the shortest type name' => ['res:ab:1@v1'],
            'a type with digits and underscores' => ['res:qa_item_2:12@v10'],
            'the longest type name (64 characters)' => ['res:a' . str_repeat('b', 63) . ':1@v1'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformed(): array
    {
        return [
            'empty' => [''],
            'a page without a version' => ['page:7'],
            'a page with an empty version' => ['page:7@v'],
            'a page with a letter as id' => ['page:x@v1'],
            'a page with a version that is not a number' => ['page:7@v1x'],
            'a negative page id' => ['page:-7@v1'],
            'a page version without the v' => ['page:7@3'],
            'an item reference of a draft' => ['res:qa_item:5@draft'],
            'a type in capitals' => ['res:Qa_item:5@v2'],
            'a type of one character' => ['res:q:5@v2'],
            'a type that starts with a digit' => ['res:1ab:5@v2'],
            'a type with a dash' => ['res:qa-item:5@v2'],
            'an item reference without the item' => ['res:qa_item:@v2'],
            'an item reference without the version' => ['res:qa_item:5'],
            'capitals in the scheme' => ['RES:qa_item:5@v2'],
            'an unknown scheme' => ['file:5@v2'],
            'a trailing space' => ['page:7@draft '],
            'a leading space' => [' page:7@draft'],
            'a trailing line break' => ["page:7@draft\n"],
            'two references' => ['page:7@v1,page:7@v2'],
            'a type of 65 characters' => ['res:a' . str_repeat('b', 64) . ':1@v1'],
            'longer than the column' => ['res:qa_item:' . str_repeat('1', 200) . '@v1'],
        ];
    }

    #[DataProvider('wellFormed')]
    public function testAWellFormedReferenceIsAccepted(string $ref): void
    {
        self::assertTrue(ContentRef::isWellFormed($ref));
        ContentRef::assertWellFormed($ref);
        self::assertSame($ref, (new RecordWriteContext())->withContentRef($ref)->contentRef);
    }

    #[DataProvider('malformed')]
    public function testAMalformedReferenceIsRefusedAndTheMessageNamesIt(string $ref): void
    {
        self::assertFalse(ContentRef::isWellFormed($ref));

        try {
            ContentRef::assertWellFormed($ref);
            self::fail('A malformed reference must be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('is not a content reference', $e->getMessage());
            self::assertStringContainsString('"' . $ref . '"', $e->getMessage(), 'The message names the reference it refuses.');
        }

        $this->expectException(\InvalidArgumentException::class);
        (new RecordWriteContext())->withContentRef($ref);
    }

    public function testTheLimitIsTheSizeOfTheColumn(): void
    {
        $padding = ContentRef::MAX_LENGTH - strlen('page:@draft');

        $exact = 'page:' . str_repeat('9', $padding) . '@draft';
        self::assertSame(ContentRef::MAX_LENGTH, strlen($exact));
        self::assertTrue(ContentRef::isWellFormed($exact), 'A reference of exactly the size of the column is accepted.');

        // The pattern alone accepts one more digit; the column cannot hold it.
        $over = 'page:' . str_repeat('9', $padding + 1) . '@draft';
        self::assertSame(1, preg_match(ContentRef::PATTERN, $over));
        self::assertFalse(ContentRef::isWellFormed($over));
    }

    public function testAPageReferenceNamesThePublishedVersionOrTheDraft(): void
    {
        self::assertSame('page:7@v3', ContentRef::page(7, 3));
        self::assertSame('page:7@draft', ContentRef::page(7, null));
        self::assertTrue(ContentRef::isWellFormed(ContentRef::page(7, 3)));
        self::assertTrue(ContentRef::isWellFormed(ContentRef::page(7, null)));
    }

    public function testAnItemReferenceIsTakenApart(): void
    {
        self::assertSame(['type' => 'qa_item', 'id' => 5, 'version' => 2], ContentRef::resource('res:qa_item:5@v2'));
        self::assertNull(ContentRef::resource('page:5@v2'), 'a page reference is not an item reference');
        self::assertNull(ContentRef::resource('res:qa_item:5@draft'));
        self::assertNull(ContentRef::resource("res:qa_item:5@v2\n"));
        self::assertNull(ContentRef::resource(''));
    }

    public function testTheContextKeepsEverythingElseWhenAReferenceIsAdded(): void
    {
        $guard = static function (): void {
        };
        $base = RecordWriteContext::interactive('quick_action', 4, $guard);

        $with = $base->withContentRef('page:7@v3');

        self::assertSame('quick_action', $with->source);
        self::assertSame(4, $with->expectedVersion);
        self::assertTrue($with->interactive);
        self::assertSame($guard, $with->targetGuard);
        self::assertNull($base->contentRef, 'the context it was built from is not changed');

        $renamed = $with->withSource('admin');
        self::assertSame('page:7@v3', $renamed->contentRef, 'a new source keeps the reference');
    }

    public function testARestoreTakesTheReferenceOfTheStateItBringsBack(): void
    {
        $context = RecordWriteContext::interactive('form', 3)->withContentRef('page:7@v3');

        $restore = $context->restoringFrom(1, 'page:7@v1');
        self::assertSame(1, $restore->restoredFromVersion);
        self::assertSame('page:7@v1', $restore->contentRef);
        self::assertSame(3, $restore->expectedVersion);

        self::assertNull($context->restoringFrom(1)->contentRef, 'a revision without a reference restores without one');
    }
}
