<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\CMS\Frontend;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Page;
use App\Entity\Section;
use App\Entity\User;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\Admin\PageVersionService;
use App\Service\Core\LookupService;
use App\Service\Core\VariableResolverService;
use App\Service\Sync\ClockBoundaries;
use App\Service\Sync\PageTableReadIndex;
use App\Tests\Support\Factories\DataGridFactory;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Symfony\Bridge\PhpUnit\Attribute\TimeSensitive;
use Symfony\Bridge\PhpUnit\ClockMock;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The `sync` block of a page payload (live-sync contract C6): `depends_on` names
 * what the page is made of; `valid_until` is the earliest moment time alone
 * changes the result (a clock condition or clock text flips), never closer than
 * five minutes when it comes from a text, and now + 5 minutes when the clock is
 * used in a way that cannot be bounded. The server's cache never keeps a page
 * beyond that moment.
 *
 * The clock is frozen with ClockMock (the bridge mocks `time()` and `date()` for
 * code in `App\`); ClockMock does not reach `new \DateTime('now')`.
 */
#[PhpUnitGroup('sync')]
#[PhpUnitGroup('time-sensitive')]
#[TimeSensitive(ClockBoundaries::class)]
#[TimeSensitive(VariableResolverService::class)]
final class PageSyncBlockTest extends QaWebTestCase
{
    private const KEYWORD_PREFIX = 'qa_syncblock_';
    private const LANGUAGE_ID = 2;

    private PageSectionFactory $pages;
    private DataGridFactory $grid;

    protected function setUp(): void
    {
        parent::setUp();
        $em = $this->service(\Doctrine\ORM\EntityManagerInterface::class);
        $this->pages = new PageSectionFactory(
            $em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );
        $this->grid = new DataGridFactory($em->getConnection());
        $this->service(PageTableReadIndex::class)->invalidate();
    }

    protected function tearDown(): void
    {
        ClockMock::withClockMock(false);
        parent::tearDown();
    }

    public function testAPageWithoutClockOrTablesHasNoValidUntilAndDependsOnItselfAndTheGlobalValues(): void
    {
        $page = $this->openPage('plain');
        $this->textSection($page, 'plain_text', 'Hello', 10);

        $sync = $this->syncOf($page);

        self::assertNull($sync['valid_until']);
        self::assertSame(['page_draft:' . $page->getId(), 'global_values'], $sync['depends_on'], 'An unpublished page is served as its draft.');
    }

    public function testAPublishedPageDependsOnItsPublishedTag(): void
    {
        $page = $this->openPage('published');
        $this->textSection($page, 'published_text', 'Hello', 10);
        $this->actAsAdmin();
        $versions = $this->service(PageVersionService::class);
        $version = $versions->createVersion((int) $page->getId());
        $versions->publishVersion((int) $page->getId(), (int) $version->getId());
        $this->pages->invalidatePageScopedCaches();

        $sync = $this->syncOf($page);

        self::assertSame(['page:' . $page->getId(), 'global_values'], $sync['depends_on']);
    }

    public function testEveryTableThePageReadsIsListedOnce(): void
    {
        $tableA = $this->grid->table('qa_syncblock_table_a', ['title' => 'text']);
        $tableB = $this->grid->table('qa_syncblock_table_b', ['title' => 'text']);
        $page = $this->openPage('tables');
        foreach ([$tableA, $tableB, $tableA] as $index => $tableId) {
            $view = $this->pages->createSection('qa_syncblock_tables_view_' . $index, 'data-view');
            $this->pages->linkSectionToPage($page, $view, 10 + $index);
            $this->pages->setSectionField($view, 'data_table', (string) $tableId);
        }

        $sync = $this->syncOf($page);

        self::assertSame(
            ['page_draft:' . $page->getId(), 'table:' . $tableA, 'table:' . $tableB, 'global_values'],
            $sync['depends_on'],
        );
    }

    public function testAConditionOnTheDateGivesTheNextMidnightItFlipsAt(): void
    {
        $now = $this->at('2026-11-20 12:00:00');
        $page = $this->openPage('date_condition');
        $this->textSection($page, 'date_condition_text', 'From December', 10, '{">=":[{"var":"current_date"},"2026-12-01"]}');

        $sync = $this->syncOf($page);

        self::assertSame($this->utc($this->localMoment('2026-12-01 00:00:00')), $sync['valid_until']);
        self::assertGreaterThan($now, $this->localMoment('2026-12-01 00:00:00'));
    }

    public function testTheOperatorDecidesWhetherTheDayItselfOrTheNextDayFlipsIt(): void
    {
        $this->at('2026-11-20 12:00:00');
        $after = $this->openPage('op_after');
        $this->textSection($after, 'op_after_text', 'After the 25th', 10, '{">":[{"var":"current_date"},"2026-11-25"]}');
        $mirrored = $this->openPage('op_mirrored');
        $this->textSection($mirrored, 'op_mirrored_text', 'After the 25th', 10, '{"<":["2026-11-25",{"var":"current_date"}]}');

        $expected = $this->utc($this->localMoment('2026-11-26 00:00:00'));
        self::assertSame($expected, $this->syncOf($after)['valid_until'], '`> 25th` is true from the 26th.');
        self::assertSame($expected, $this->syncOf($mirrored)['valid_until'], 'The same condition written the other way round.');
    }

    public function testAConditionOnTheTimeOfDayGivesTheNextOccurrence(): void
    {
        $this->at('2026-11-20 07:00:00');
        $page = $this->openPage('time_condition');
        $this->textSection($page, 'time_condition_text', 'Open', 10, '{">=":[{"var":"current_time"},"08:30"]}');

        $sync = $this->syncOf($page);

        self::assertSame($this->utc($this->localMoment('2026-11-20 08:30:00')), $sync['valid_until']);
    }

    public function testAClockTextGivesTheNextMidnightButNeverLessThanFiveMinutes(): void
    {
        $now = $this->at('2026-11-20 12:00:00');
        $page = $this->openPage('clock_text');
        $this->textSection($page, 'clock_text_text', 'Today is {{system.current_date}}', 10);

        $far = $this->syncOf($page);
        self::assertSame($this->utc($this->localMoment('2026-11-21 00:00:00')), $far['valid_until'], 'The next change is the next midnight.');

        // Two minutes before midnight the next change is closer than five minutes: five minutes is the floor.
        $late = $this->at('2026-11-20 23:58:00');
        $this->pages->invalidatePageScopedCaches();
        $near = $this->syncOf($page);
        self::assertSame($this->utc($late + 300), $near['valid_until']);
        self::assertGreaterThan($now, $late);
    }

    public function testAClockTokenThatChangesEveryMinuteGivesFiveMinutes(): void
    {
        $now = $this->at('2026-11-20 12:00:00');
        $page = $this->openPage('clock_time_text');
        $this->textSection($page, 'clock_time_text_text', 'It is {{system.current_time}}', 10);

        self::assertSame($this->utc($now + 300), $this->syncOf($page)['valid_until']);
    }

    public function testAClockUsedInAWayThatCannotBeBoundedGivesFiveMinutes(): void
    {
        $now = $this->at('2026-11-20 12:00:00');
        $page = $this->openPage('unbounded');
        // The clock against a value that is not a moment the server can read.
        $this->textSection($page, 'unbounded_text', 'Odd', 10, '{"==":[{"var":"current_date"},{"var":"no_such_variable"}]}');

        self::assertSame($this->utc($now + 300), $this->syncOf($page)['valid_until']);
    }

    public function testTheEarliestOfSeveralMomentsWins(): void
    {
        $this->at('2026-11-20 12:00:00');
        $page = $this->openPage('earliest');
        $this->textSection($page, 'earliest_a', 'A', 10, '{">=":[{"var":"current_date"},"2026-12-24"]}');
        $this->textSection($page, 'earliest_b', 'B', 20, '{">=":[{"var":"current_date"},"2026-11-25"]}');

        self::assertSame($this->utc($this->localMoment('2026-11-25 00:00:00')), $this->syncOf($page)['valid_until']);
    }

    public function testACachedPageIsNotServedPastItsValidUntil(): void
    {
        $this->at('2026-11-20 12:00:00');
        $page = $this->openPage('cached');
        $this->textSection($page, 'cached_always', 'Always', 10);
        $this->textSection($page, 'cached_december', 'From December', 20, '{">=":[{"var":"current_date"},"2026-12-01"]}');

        $before = $this->pageOf($page);
        self::assertSame(['Always'], $this->texts($before), 'Before December the second text is not shown.');
        // Served again from the cache while the clock has not reached the moment.
        $this->at('2026-11-30 23:00:00');
        self::assertSame(['Always'], $this->texts($this->pageOf($page)));

        // The clock passes the moment: the cached result must not be served any more.
        $this->at('2026-12-01 00:00:05');
        $after = $this->pageOf($page);

        self::assertSame(['Always', 'From December'], $this->texts($after), 'The page is built again once its moment has passed.');
        self::assertNull($this->syncOfPayload($after)['valid_until'] ?? null, 'Nothing flips any more after the date.');
    }

    // -- helpers ------------------------------------------------------------

    private function openPage(string $name): Page
    {
        return $this->pages->createPage(self::KEYWORD_PREFIX . $name, true);
    }

    private function textSection(Page $page, string $name, string $text, int $position, ?string $condition = null): Section
    {
        $section = $this->pages->createSection(self::KEYWORD_PREFIX . $name, 'text');
        $this->pages->linkSectionToPage($page, $section, $position);
        $this->pages->setSectionField($section, 'text', $text, self::LANGUAGE_ID);
        if ($condition !== null) {
            $section->setCondition($condition);
            $this->service(\Doctrine\ORM\EntityManagerInterface::class)->flush();
            $this->pages->invalidatePageScopedCaches();
        }

        return $section;
    }

    /**
     * Freeze the clock at a local wall-clock time (the default timezone the app reads the date in).
     */
    private function at(string $local): int
    {
        $moment = $this->localMoment($local);
        ClockMock::withClockMock($moment);

        return $moment;
    }

    private function localMoment(string $local): int
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone(date_default_timezone_get())))->getTimestamp();
    }

    private function utc(int $moment): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $moment);
    }

    /**
     * @return array{depends_on: list<string>, valid_until: string|null}
     */
    private function syncOf(Page $page): array
    {
        return $this->syncOfPayload($this->pageOf($page));
    }

    /**
     * @param array<string, mixed> $data
     * @return array{depends_on: list<string>, valid_until: string|null}
     */
    private function syncOfPayload(array $data): array
    {
        $sync = $data['sync'] ?? null;
        self::assertIsArray($sync, 'The page payload carries a sync block.');
        self::assertIsArray($sync['depends_on'] ?? null);
        self::assertArrayHasKey('valid_until', $sync);

        return [
            'depends_on' => array_values(array_map(static fn (mixed $tag): string => is_string($tag) ? $tag : '', $sync['depends_on'])),
            'valid_until' => is_string($sync['valid_until']) ? $sync['valid_until'] : null,
        ];
    }

    /**
     * @return array<string, mixed> the `data` of the page endpoint as a guest gets it
     */
    private function pageOf(Page $page): array
    {
        return $this->assertEnvelopeSuccess($this->jsonRequest(
            'GET',
            '/cms-api/v1/pages/by-keyword/' . $page->getKeyword() . '?language_id=' . self::LANGUAGE_ID,
        ));
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string> the texts of the top-level text sections, in order
     */
    private function texts(array $data): array
    {
        $page = $data['page'] ?? null;
        self::assertIsArray($page);
        $sections = $page['sections'] ?? null;
        self::assertIsArray($sections);

        $texts = [];
        foreach ($sections as $section) {
            self::assertIsArray($section);
            $field = $section['text'] ?? null;
            if (is_array($field) && is_string($field['content'] ?? null)) {
                $texts[] = $field['content'];
            }
        }

        return $texts;
    }

    private function actAsAdmin(): void
    {
        $em = $this->service(\Doctrine\ORM\EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_ADMIN_EMAIL]);
        self::assertInstanceOf(User::class, $admin);
        $this->service(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
    }
}
