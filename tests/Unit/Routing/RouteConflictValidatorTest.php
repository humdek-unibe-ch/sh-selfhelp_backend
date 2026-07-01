<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Routing;

use App\Repository\PageRouteRepository;
use App\Routing\RouteConflictValidator;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the global public-route conflict guard (issue #30).
 *
 * The validator enforces the two GLOBAL guarantees the resolver relies on that
 * the per-page DB unique key cannot: no two ACTIVE routes share the exact
 * pattern (duplicate), and no two active routes share the same path SHAPE — the
 * pattern with every `{placeholder}` collapsed to a wildcard (ambiguous). A
 * static segment and a dynamic segment at the same position have different
 * shapes and stay allowed, because the resolver tries static patterns first.
 */
final class RouteConflictValidatorTest extends TestCase
{
    public function testNoConflictForDistinctShapes(): void
    {
        $validator = $this->validatorWithExisting([
            $this->existing(1, 12, 'team-detail', '/team/{record_id}'),
        ]);

        // A static sibling at the same depth has a different shape, so it is
        // allowed (the resolver matches `/team/list` before `/team/{record_id}`).
        $conflicts = $validator->findConflictsForSet(
            [['path_pattern' => '/team/list']],
            excludePageId: 99
        );

        self::assertSame([], $conflicts);
    }

    public function testDuplicatePatternAgainstAnotherPageIsRejected(): void
    {
        $validator = $this->validatorWithExisting([
            $this->existing(1, 12, 'team', '/team'),
        ]);

        $conflicts = $validator->findConflictsForSet(
            [['path_pattern' => '/team']],
            excludePageId: 99
        );

        self::assertCount(1, $conflicts);
        self::assertSame(RouteConflictValidator::TYPE_DUPLICATE, $conflicts[0]['type']);
        self::assertSame('/team', $conflicts[0]['path_pattern']);
        self::assertSame('team', $conflicts[0]['conflicting_keyword']);
    }

    public function testAmbiguousSameShapeAgainstAnotherPageIsRejected(): void
    {
        $validator = $this->validatorWithExisting([
            $this->existing(1, 12, 'team-detail', '/team/{record_id}'),
        ]);

        // Same shape `/team/{*}` with a differently-named param: both could match
        // the same incoming path, so the resolver could not stay deterministic.
        $conflicts = $validator->findConflictsForSet(
            [['path_pattern' => '/team/{slug}']],
            excludePageId: 99
        );

        self::assertCount(1, $conflicts);
        self::assertSame(RouteConflictValidator::TYPE_AMBIGUOUS, $conflicts[0]['type']);
        self::assertSame('/team/{slug}', $conflicts[0]['path_pattern']);
        self::assertSame('/team/{record_id}', $conflicts[0]['conflicting_pattern']);
    }

    public function testStaticAndDynamicAtSameDepthStayAllowed(): void
    {
        // The seeded reset flow: a static canonical `/reset` plus the
        // parameterized `/reset/{user_id}/{token}` must never conflict.
        $validator = $this->validatorWithExisting([]);

        $conflicts = $validator->findConflictsForSet([
            ['path_pattern' => '/reset'],
            ['path_pattern' => '/reset/{user_id}/{token}'],
        ], excludePageId: 10);

        self::assertSame([], $conflicts);
    }

    public function testInternalExactDuplicateWithinSubmittedSet(): void
    {
        $validator = $this->validatorWithExisting([]);

        $conflicts = $validator->findConflictsForSet([
            ['path_pattern' => '/a/{id}'],
            ['path_pattern' => '/a/{id}'],
        ], excludePageId: null);

        self::assertCount(1, $conflicts);
        self::assertSame(RouteConflictValidator::TYPE_DUPLICATE, $conflicts[0]['type']);
    }

    public function testInternalShapeCollisionWithinSubmittedSet(): void
    {
        $validator = $this->validatorWithExisting([]);

        $conflicts = $validator->findConflictsForSet([
            ['path_pattern' => '/a/{id}'],
            ['path_pattern' => '/a/{slug}'],
        ], excludePageId: null);

        self::assertCount(1, $conflicts);
        self::assertSame(RouteConflictValidator::TYPE_AMBIGUOUS, $conflicts[0]['type']);
    }

    public function testShapeCollapsesEveryPlaceholderToAWildcard(): void
    {
        $validator = $this->validatorWithExisting([]);

        self::assertSame('/team/{*}', $validator->shape('/team/{record_id}'));
        self::assertSame('/reset/{*}/{*}', $validator->shape('/reset/{user_id}/{token}'));
        self::assertSame('/about', $validator->shape('/about'));
    }

    public function testFindAllConflictsReportsEachConflictingPairOnce(): void
    {
        $repo = $this->createStub(PageRouteRepository::class);
        $repo->method('findAllActivePatterns')->willReturn([
            $this->existing(1, 10, 'team-a', '/team/{record_id}'),
            $this->existing(2, 11, 'team-b', '/team/{slug}'),     // ambiguous with #1
            $this->existing(3, 12, 'about-a', '/about'),
            $this->existing(4, 13, 'about-b', '/about'),          // duplicate of #3
        ]);
        $validator = new RouteConflictValidator($repo);

        $conflicts = $validator->findAllConflicts();

        self::assertCount(2, $conflicts);
        $types = array_map(static fn (array $c): string => $c['type'], $conflicts);
        self::assertContains(RouteConflictValidator::TYPE_AMBIGUOUS, $types);
        self::assertContains(RouteConflictValidator::TYPE_DUPLICATE, $types);
    }

    /**
     * @param list<array{id:int, page_id:int, keyword:string, path_pattern:string, requirements:array<string,string>, priority:int}> $existing
     */
    private function validatorWithExisting(array $existing): RouteConflictValidator
    {
        $repo = $this->createStub(PageRouteRepository::class);
        $repo->method('findAllActivePatterns')->willReturn($existing);

        return new RouteConflictValidator($repo);
    }

    /**
     * @return array{id:int, page_id:int, keyword:string, path_pattern:string, requirements:array<string,string>, priority:int}
     */
    private function existing(int $id, int $pageId, string $keyword, string $pattern): array
    {
        return [
            'id' => $id,
            'page_id' => $pageId,
            'keyword' => $keyword,
            'path_pattern' => $pattern,
            'requirements' => [],
            'priority' => 0,
        ];
    }
}
