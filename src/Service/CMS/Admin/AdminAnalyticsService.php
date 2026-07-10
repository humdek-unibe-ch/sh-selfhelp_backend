<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\CMS\Admin;

use App\Service\Core\BaseService;
use App\Service\Core\LookupService;
use Doctrine\DBAL\Connection;

/**
 * Read-only aggregates for the admin dashboard: page-view analytics from the
 * anonymous `page_views` / `page_view_referrers` daily tables plus a
 * "today's operations" snapshot (scheduled jobs, submitted data, visits).
 *
 * Uniques are privacy-preserving visitor-days: the visitor hash rotates daily,
 * so counting distinct hashes over multi-day ranges sums the daily uniques
 * instead of deduplicating returning visitors across days.
 *
 * Not cached: admin-only traffic, cheap indexed aggregates, and the numbers
 * change with every tracked view.
 */
class AdminAnalyticsService extends BaseService
{
    private const TOP_LIMIT = 10;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Page-view series + totals + top pages + referrers for a date range.
     *
     * @return array<string, mixed>
     */
    public function getSummary(?string $from, ?string $to, string $granularity, string $platform): array
    {
        $granularity = $granularity === 'month' ? 'month' : 'day';
        $platform = in_array($platform, ['web', 'mobile'], true) ? $platform : 'all';
        $toDate = $this->parseDate($to) ?? new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $fromDate = $this->parseDate($from) ?? $toDate->modify('-29 days');
        if ($fromDate > $toDate) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        $params = ['from' => $fromDate->format('Y-m-d'), 'to' => $toDate->format('Y-m-d')];
        $platformFilter = '';
        if ($platform !== 'all') {
            $platformFilter = ' AND platform = :platform';
            $params['platform'] = $platform;
        }

        $periodExpr = $granularity === 'month'
            ? "DATE_FORMAT(view_date, '%Y-%m')"
            : "DATE_FORMAT(view_date, '%Y-%m-%d')";

        /** @var list<array<string, mixed>> $seriesRows */
        $seriesRows = $this->connection->fetchAllAssociative(
            "SELECT {$periodExpr} AS period, platform, SUM(views) AS views, COUNT(DISTINCT visitor_hash) AS uniques
             FROM page_views
             WHERE view_date BETWEEN :from AND :to{$platformFilter}
             GROUP BY period, platform
             ORDER BY period ASC",
            $params,
        );

        /** @var array<string, array{period: string, web_views: int, mobile_views: int, web_uniques: int, mobile_uniques: int}> $series */
        $series = [];
        foreach ($seriesRows as $row) {
            $period = self::stringOf($row['period'] ?? null);
            if (!isset($series[$period])) {
                $series[$period] = [
                    'period' => $period,
                    'web_views' => 0,
                    'mobile_views' => 0,
                    'web_uniques' => 0,
                    'mobile_uniques' => 0,
                ];
            }
            $key = ($row['platform'] ?? null) === 'mobile' ? 'mobile' : 'web';
            $series[$period][$key . '_views'] += self::intOf($row['views'] ?? null);
            $series[$period][$key . '_uniques'] += self::intOf($row['uniques'] ?? null);
        }

        /** @var list<array<string, mixed>> $totalRows */
        $totalRows = $this->connection->fetchAllAssociative(
            "SELECT platform, SUM(views) AS views, COUNT(DISTINCT visitor_hash) AS uniques
             FROM page_views
             WHERE view_date BETWEEN :from AND :to{$platformFilter}
             GROUP BY platform",
            $params,
        );
        $totals = [
            'views' => 0,
            'unique_visitors' => 0,
            'web' => ['views' => 0, 'unique_visitors' => 0],
            'mobile' => ['views' => 0, 'unique_visitors' => 0],
        ];
        foreach ($totalRows as $row) {
            $key = ($row['platform'] ?? null) === 'mobile' ? 'mobile' : 'web';
            $views = self::intOf($row['views'] ?? null);
            $uniques = self::intOf($row['uniques'] ?? null);
            $totals[$key]['views'] += $views;
            $totals[$key]['unique_visitors'] += $uniques;
            $totals['views'] += $views;
            $totals['unique_visitors'] += $uniques;
        }

        /** @var list<array<string, mixed>> $topRows */
        $topRows = $this->connection->fetchAllAssociative(
            "SELECT pv.id_pages AS page_id, p.keyword, p.url,
                    SUM(pv.views) AS views, COUNT(DISTINCT pv.visitor_hash) AS unique_visitors
             FROM page_views pv
             INNER JOIN pages p ON p.id = pv.id_pages
             WHERE pv.view_date BETWEEN :from AND :to{$platformFilter}
             GROUP BY pv.id_pages, p.keyword, p.url
             ORDER BY views DESC
             LIMIT " . self::TOP_LIMIT,
            $params,
        );
        $topPages = array_map(static fn (array $row): array => [
            'page_id' => self::intOf($row['page_id'] ?? null),
            'keyword' => self::stringOf($row['keyword'] ?? null),
            'url' => is_string($row['url'] ?? null) ? $row['url'] : null,
            'views' => self::intOf($row['views'] ?? null),
            'unique_visitors' => self::intOf($row['unique_visitors'] ?? null),
        ], $topRows);

        /** @var list<array<string, mixed>> $referrerRows */
        $referrerRows = $this->connection->fetchAllAssociative(
            'SELECT referrer_host AS host, SUM(views) AS views
             FROM page_view_referrers
             WHERE view_date BETWEEN :from AND :to
             GROUP BY referrer_host
             ORDER BY views DESC
             LIMIT ' . self::TOP_LIMIT,
            ['from' => $params['from'], 'to' => $params['to']],
        );
        $referrers = array_map(static fn (array $row): array => [
            'host' => self::stringOf($row['host'] ?? null),
            'views' => self::intOf($row['views'] ?? null),
        ], $referrerRows);

        return [
            'range' => [
                'from' => $fromDate->format('Y-m-d'),
                'to' => $toDate->format('Y-m-d'),
                'granularity' => $granularity,
                'platform' => $platform,
            ],
            'totals' => $totals,
            'series' => array_values($series),
            'top_pages' => $topPages,
            'referrers' => $referrers,
        ];
    }

    /**
     * Today's operations snapshot for the dashboard (UTC day window):
     * scheduled jobs due/executed today by status, data submissions, visits.
     *
     * @return array<string, mixed>
     */
    public function getTodayOperations(): array
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        $dayStart = $today . ' 00:00:00';
        $dayEnd = $today . ' 23:59:59';

        /** @var list<array<string, mixed>> $jobRows */
        $jobRows = $this->connection->fetchAllAssociative(
            'SELECT l.lookup_code AS status, COUNT(*) AS jobs
             FROM scheduled_jobs sj
             INNER JOIN lookups l ON l.id = sj.id_job_status
             WHERE sj.date_to_be_executed BETWEEN :start AND :end
             GROUP BY l.lookup_code',
            ['start' => $dayStart, 'end' => $dayEnd],
        );
        $byStatus = [];
        $dueToday = 0;
        foreach ($jobRows as $row) {
            $jobs = self::intOf($row['jobs'] ?? null);
            $byStatus[self::stringOf($row['status'] ?? null)] = $jobs;
            $dueToday += $jobs;
        }

        $executedToday = self::intOf($this->connection->fetchOne(
            'SELECT COUNT(*) FROM scheduled_jobs sj
             INNER JOIN lookups l ON l.id = sj.id_job_status
             WHERE l.lookup_code = :done AND sj.date_executed BETWEEN :start AND :end',
            ['done' => LookupService::SCHEDULED_JOBS_STATUS_DONE, 'start' => $dayStart, 'end' => $dayEnd],
        ));

        /** @var array<string, mixed>|false $dataToday */
        $dataToday = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS entries, COUNT(DISTINCT id_users) AS users
             FROM data_rows
             WHERE timestamp BETWEEN :start AND :end',
            ['start' => $dayStart, 'end' => $dayEnd],
        );

        $totalTables = self::intOf($this->connection->fetchOne('SELECT COUNT(*) FROM data_tables'));
        $totalRows = self::intOf($this->connection->fetchOne('SELECT COUNT(*) FROM data_rows'));

        /** @var list<array<string, mixed>> $visitRows */
        $visitRows = $this->connection->fetchAllAssociative(
            'SELECT platform, SUM(views) AS views, COUNT(DISTINCT visitor_hash) AS uniques
             FROM page_views
             WHERE view_date = :today
             GROUP BY platform',
            ['today' => $today],
        );
        $visits = [
            'views_today' => 0,
            'unique_visitors_today' => 0,
            'web_views_today' => 0,
            'mobile_views_today' => 0,
        ];
        foreach ($visitRows as $row) {
            $views = self::intOf($row['views'] ?? null);
            $visits['views_today'] += $views;
            $visits['unique_visitors_today'] += self::intOf($row['uniques'] ?? null);
            $visits[($row['platform'] ?? null) === 'mobile' ? 'mobile_views_today' : 'web_views_today'] += $views;
        }

        return [
            'date' => $today,
            'scheduled_jobs' => [
                'due_today' => $dueToday,
                'executed_today' => $executedToday,
                // Empty PHP arrays encode as JSON `[]`; cast so the schema's
                // object map stays an object when no jobs are due today.
                'by_status' => (object) $byStatus,
            ],
            'data' => [
                'entries_today' => is_array($dataToday) ? self::intOf($dataToday['entries'] ?? null) : 0,
                'users_submitted_today' => is_array($dataToday) ? self::intOf($dataToday['users'] ?? null) : 0,
                'total_tables' => $totalTables,
                'total_rows' => $totalRows,
            ],
            'visits' => $visits,
        ];
    }

    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));

        return $date === false ? null : $date;
    }

    private static function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function stringOf(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_numeric($value) ? (string) $value : '';
    }
}
