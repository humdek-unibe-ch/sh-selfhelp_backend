<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS\Admin;

use App\Repository\PageRepository;
use App\Service\CMS\Frontend\PageViewTrackerService;
use App\Tests\Support\QaWebTestCase;
use App\Tests\Support\Security\PermissionMatrixProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Dashboard analytics: anonymous page-view tracking aggregates into
 * `page_views` / `page_view_referrers`, and the admin summary/today endpoints
 * expose series, totals, top pages, referrers, and the operations snapshot.
 * Admin-only via `admin.analytics.read`.
 */
#[Group('integration')]
#[Group('security')]
final class AdminAnalyticsTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    public function testTrackedViewsAggregateIntoAdminSummaryAndTodaySnapshot(): void
    {
        /** @var PageRepository $pageRepo */
        $pageRepo = self::getContainer()->get(PageRepository::class);
        $homePage = $pageRepo->findOneBy(['keyword' => 'home']);
        self::assertNotNull($homePage);
        $homeId = $homePage->getId();
        self::assertIsInt($homeId);

        /** @var PageViewTrackerService $tracker */
        $tracker = self::getContainer()->get(PageViewTrackerService::class);

        // Two web views from the same guest (1 unique, 2 views), one mobile view
        // from another guest, plus an external referrer on the first hit.
        $guestA = Request::create('/cms-api/v1/pages/by-keyword/home', 'GET');
        $guestA->headers->set('User-Agent', 'qa-agent-a');
        $guestA->headers->set('X-Referrer-Host', 'qa-search.example');
        $guestA->server->set('REMOTE_ADDR', '203.0.113.10');
        $tracker->recordView($homeId, 'web', $guestA);
        $tracker->recordView($homeId, 'web', $guestA);

        $guestB = Request::create('/cms-api/v1/pages/by-keyword/home', 'GET');
        $guestB->headers->set('User-Agent', 'qa-agent-b');
        $guestB->server->set('REMOTE_ADDR', '203.0.113.11');
        $tracker->recordView($homeId, 'mobile', $guestB);

        $admin = $this->loginAsQaAdmin();
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');

        $summaryEnvelope = $this->jsonRequest(
            'GET',
            '/cms-api/v1/admin/analytics/summary?from=' . $today . '&to=' . $today . '&granularity=day&platform=all',
            null,
            $admin,
        );
        $summary = $this->assertEnvelopeSuccess($summaryEnvelope);

        $range = $summary['range'];
        self::assertIsArray($range);
        self::assertSame($today, $range['from']);
        self::assertSame('day', $range['granularity']);

        $totals = $summary['totals'];
        self::assertIsArray($totals);
        $views = $totals['views'];
        self::assertIsInt($views);
        $mobileTotals = $totals['mobile'];
        self::assertIsArray($mobileTotals);
        $mobileViews = $mobileTotals['views'];
        self::assertIsInt($mobileViews);
        $webTotals = $totals['web'];
        self::assertIsArray($webTotals);

        self::assertGreaterThanOrEqual(3, $views);
        self::assertGreaterThanOrEqual(2, $views - $mobileViews);
        self::assertGreaterThanOrEqual(1, $mobileViews);
        self::assertGreaterThanOrEqual(1, $webTotals['unique_visitors']);

        $series = $summary['series'];
        self::assertIsArray($series);
        $periods = array_column($series, 'period');
        self::assertContains($today, $periods);

        $topPages = $summary['top_pages'];
        self::assertIsArray($topPages);
        $topKeywords = array_column($topPages, 'keyword');
        self::assertContains('home', $topKeywords);

        $referrers = $summary['referrers'];
        self::assertIsArray($referrers);
        $referrerHosts = array_column($referrers, 'host');
        self::assertContains('qa-search.example', $referrerHosts);

        $todayEnvelope = $this->jsonRequest('GET', '/cms-api/v1/admin/analytics/today', null, $admin);
        $snapshot = $this->assertEnvelopeSuccess($todayEnvelope);

        self::assertSame($today, $snapshot['date']);

        $visits = $snapshot['visits'];
        self::assertIsArray($visits);
        self::assertGreaterThanOrEqual(3, $visits['views_today']);
        self::assertGreaterThanOrEqual(1, $visits['mobile_views_today']);

        $scheduledJobs = $snapshot['scheduled_jobs'];
        self::assertIsArray($scheduledJobs);
        self::assertArrayHasKey('due_today', $scheduledJobs);
        self::assertArrayHasKey('by_status', $scheduledJobs);

        $dataBlock = $snapshot['data'];
        self::assertIsArray($dataBlock);
        self::assertArrayHasKey('entries_today', $dataBlock);
        self::assertArrayHasKey('total_tables', $dataBlock);
    }

    public function testVisitorHashIsAnonymousAndRotatesWithSubject(): void
    {
        /** @var PageRepository $pageRepo */
        $pageRepo = self::getContainer()->get(PageRepository::class);
        $homePage = $pageRepo->findOneBy(['keyword' => 'home']);
        self::assertNotNull($homePage);
        $homeId = (int) $homePage->getId();

        /** @var PageViewTrackerService $tracker */
        $tracker = self::getContainer()->get(PageViewTrackerService::class);

        $guestA = Request::create('/x', 'GET');
        $guestA->headers->set('User-Agent', 'qa-agent-hash-a');
        $guestA->server->set('REMOTE_ADDR', '203.0.113.20');
        $guestB = Request::create('/x', 'GET');
        $guestB->headers->set('User-Agent', 'qa-agent-hash-b');
        $guestB->server->set('REMOTE_ADDR', '203.0.113.21');

        $tracker->recordView($homeId, 'web', $guestA);
        $tracker->recordView($homeId, 'web', $guestB);

        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = self::getContainer()->get(\Doctrine\DBAL\Connection::class);
        /** @var list<array<string, mixed>> $rows */
        $rows = $connection->fetchAllAssociative(
            'SELECT visitor_hash FROM page_views WHERE id_pages = ? AND platform = ? ORDER BY id DESC LIMIT 2',
            [$homeId, 'web'],
        );

        self::assertCount(2, $rows);
        $hashes = [];
        foreach ($rows as $row) {
            self::assertIsString($row['visitor_hash']);
            $hashes[] = $row['visitor_hash'];
        }
        self::assertNotSame($hashes[0], $hashes[1], 'distinct guests must produce distinct visitor hashes');
        foreach ($hashes as $hash) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $hash);
            self::assertStringNotContainsString('203.0.113', $hash, 'raw IP must never be stored');
        }
    }

    public function testAnalyticsEndpointsAreAdminOnly(): void
    {
        $this->assertAdminOnlyMatrix('GET', '/cms-api/v1/admin/analytics/summary');
        $this->assertAdminOnlyMatrix('GET', '/cms-api/v1/admin/analytics/today');
    }
}
