<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS;

use App\Tests\Support\QaWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The admin "Referrers" card shows `page_view_referrers`. The web client sends
 * the external referrer host of a visit as `X-Referrer-Host`; the mobile app
 * sends none. This proves the real page endpoint turns that header into rows:
 * an external host is counted once per page view, and nothing is counted for a
 * missing, malformed or own-host value.
 *
 * (`AdminAnalyticsTest` covers the aggregation into the admin summary.)
 */
#[Group('integration')]
final class PageViewReferrerTest extends QaWebTestCase
{
    private const PAGE_URI = '/cms-api/v1/pages/by-keyword/home';

    public function testAnExternalReferrerHostIsCountedOncePerPageView(): void
    {
        $before = $this->referrerViews('qa-referrer.example');

        $this->visitHome('qa-referrer.example');
        self::assertSame($before + 1, $this->referrerViews('qa-referrer.example'));

        $this->visitHome('qa-referrer.example');
        self::assertSame($before + 2, $this->referrerViews('qa-referrer.example'), 'Every page view counts once.');
    }

    public function testTheHostIsStoredInLowerCase(): void
    {
        $this->visitHome('QA-Mixed.Example');

        self::assertSame(1, $this->referrerViews('qa-mixed.example'));
    }

    public function testAVisitWithoutTheHeaderCountsNoReferrer(): void
    {
        $rows = $this->referrerRowCount();
        $views = array_sum($this->referrerViewsByHost());

        // What the mobile app sends: no header at all.
        $this->visitHome(null);

        self::assertSame($rows, $this->referrerRowCount());
        self::assertSame($views, array_sum($this->referrerViewsByHost()));
    }

    public function testTheOwnHostIsNotCountedAsAReferrer(): void
    {
        // The browser client's default host in tests is `localhost`.
        $this->visitHome('localhost');

        self::assertSame(0, $this->referrerViews('localhost'));
    }

    #[DataProvider('malformedHosts')]
    public function testAMalformedHostIsIgnored(string $value): void
    {
        $views = array_sum($this->referrerViewsByHost());

        $this->visitHome($value);

        self::assertSame($views, array_sum($this->referrerViewsByHost()), sprintf('"%s" must not be counted.', $value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedHosts(): iterable
    {
        yield 'url instead of host' => ['https://example.org/some/path'];
        yield 'host with port' => ['example.org:8080'];
        yield 'spaces' => ['not a host'];
        yield 'too long' => [str_repeat('a', 191) . '.example'];
    }

    // -- helpers --------------------------------------------------------------

    private function visitHome(?string $referrerHost): void
    {
        $headers = $referrerHost !== null ? ['HTTP_X_REFERRER_HOST' => $referrerHost] : [];

        $envelope = $this->jsonRequest('GET', self::PAGE_URI, null, null, $headers);
        $this->assertEnvelopeSuccess($envelope);
    }

    private function referrerViews(string $host): int
    {
        return $this->coerceInt($this->connection()->fetchOne(
            'SELECT COALESCE(SUM(views), 0) FROM page_view_referrers WHERE view_date = UTC_DATE() AND referrer_host = ?',
            [$host],
        ));
    }

    private function referrerRowCount(): int
    {
        return $this->coerceInt($this->connection()->fetchOne('SELECT COUNT(*) FROM page_view_referrers'));
    }

    /**
     * @return array<string, int> today's views per referrer host
     */
    private function referrerViewsByHost(): array
    {
        $rows = $this->connection()->fetchAllKeyValue(
            'SELECT referrer_host, views FROM page_view_referrers WHERE view_date = UTC_DATE()',
        );

        $result = [];
        foreach ($rows as $host => $views) {
            $result[(string) $host] = $this->coerceInt($views);
        }

        return $result;
    }

    private function connection(): Connection
    {
        return $this->service(EntityManagerInterface::class)->getConnection();
    }
}
