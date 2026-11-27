<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * A page view is counted only when the client says it is a visit: `X-Page-View: 1`.
 * A revalidation, a refetch after a change notice or a prefetch fetches the same
 * page but must not move the analytics (F135).
 */
#[Group('integration')]
final class PageViewHeaderTest extends QaWebTestCase
{
    private const PAGE_URI = '/cms-api/v1/pages/by-keyword/home';

    public function testAVisitMarkedWithTheHeaderIsCounted(): void
    {
        $before = $this->views();

        $this->fetch(['HTTP_X_PAGE_VIEW' => '1']);

        self::assertSame($before + 1, $this->views());
    }

    public function testAFetchWithoutTheHeaderIsNotCounted(): void
    {
        $before = $this->views();

        $this->fetch([]);
        $this->fetch([]);

        self::assertSame($before, $this->views(), 'A refetch is not a visit.');
    }

    public function testAnyOtherValueIsNotCounted(): void
    {
        $before = $this->views();

        $this->fetch(['HTTP_X_PAGE_VIEW' => '0']);
        $this->fetch(['HTTP_X_PAGE_VIEW' => 'true']);

        self::assertSame($before, $this->views());
    }

    public function testTheReferrerOfAnUnmarkedFetchIsNotCountedEither(): void
    {
        $this->fetch(['HTTP_X_REFERRER_HOST' => 'qa-unmarked.example']);

        self::assertSame(0, $this->referrerViews('qa-unmarked.example'));
    }

    /**
     * @param array<string, string> $headers
     */
    private function fetch(array $headers): void
    {
        $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::PAGE_URI, null, null, $headers));
    }

    private function views(): int
    {
        return $this->coerceInt($this->service(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(views), 0) FROM page_views WHERE view_date = UTC_DATE()',
        ));
    }

    private function referrerViews(string $host): int
    {
        return $this->coerceInt($this->service(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(views), 0) FROM page_view_referrers WHERE view_date = UTC_DATE() AND referrer_host = ?',
            [$host],
        ));
    }
}
