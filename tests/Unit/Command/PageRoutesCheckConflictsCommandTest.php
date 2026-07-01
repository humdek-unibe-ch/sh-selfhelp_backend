<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\PageRoutesCheckConflictsCommand;
use App\Repository\PageRouteRepository;
use App\Routing\RouteConflictValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit coverage for the out-of-band route-conflict guard command (issue #30).
 *
 * The command is the `composer validate-db` / CI / post-deploy gate that the
 * write-time validator cannot be: it scans EVERY active route via
 * {@see RouteConflictValidator::findAllConflicts()} and must exit non-zero the
 * moment the DB holds a duplicate or same-shape ambiguous active route set
 * (e.g. after a raw SQL edit or a restored backup). Driven through a stubbed
 * repository so no database is required.
 */
final class PageRoutesCheckConflictsCommandTest extends TestCase
{
    public function testSucceedsWhenNoActiveRouteConflicts(): void
    {
        $tester = $this->tester([
            $this->route(1, 10, 'team', '/team'),
            $this->route(2, 11, 'team-detail', '/team/{record_id}'),
            $this->route(3, 12, 'about', '/about'),
        ]);

        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('No duplicate or ambiguous', $tester->getDisplay());
    }

    public function testFailsAndReportsWhenDuplicateOrAmbiguousRoutesExist(): void
    {
        $tester = $this->tester([
            $this->route(1, 10, 'team-a', '/team/{record_id}'),
            $this->route(2, 11, 'team-b', '/team/{slug}'),   // same shape => ambiguous
            $this->route(3, 12, 'about-a', '/about'),
            $this->route(4, 13, 'about-b', '/about'),         // exact duplicate
        ]);

        $exit = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('conflict', strtolower($display));
        // Both offending keywords/patterns are surfaced in the report table.
        self::assertStringContainsString('/team/', $display);
        self::assertStringContainsString('/about', $display);
    }

    /**
     * @param list<array{id:int, page_id:int, keyword:string, path_pattern:string, requirements:array<string,string>, priority:int}> $active
     */
    private function tester(array $active): CommandTester
    {
        $repo = $this->createStub(PageRouteRepository::class);
        $repo->method('findAllActivePatterns')->willReturn($active);

        $command = new PageRoutesCheckConflictsCommand(new RouteConflictValidator($repo));

        return new CommandTester($command);
    }

    /**
     * @return array{id:int, page_id:int, keyword:string, path_pattern:string, requirements:array<string,string>, priority:int}
     */
    private function route(int $id, int $pageId, string $keyword, string $pattern): array
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
