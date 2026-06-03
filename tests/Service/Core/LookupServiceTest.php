<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\Core;

use App\Entity\Lookup;
use App\Service\Core\LookupService;
use App\Tests\Support\QaKernelTestCase;

/**
 * Behavioural coverage for {@see LookupService} (plan Phase 8: lookup
 * fallback/cache). Asserts known lookups resolve to entity + id, unknown
 * lookups fall back to null (deny, not crash), and repeated calls are stable.
 */
final class LookupServiceTest extends QaKernelTestCase
{
    private LookupService $lookups;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lookups = $this->service(LookupService::class);
    }

    public function testGetAllLookupsReturnsANonEmptyList(): void
    {
        $all = $this->lookups->getAllLookups();

        self::assertNotEmpty($all, 'The seeded baseline must expose lookups.');
    }

    public function testFindByTypeAndCodeResolvesAKnownLookup(): void
    {
        $lookup = $this->lookups->findByTypeAndCode(
            LookupService::SCHEDULED_JOBS_STATUS,
            LookupService::SCHEDULED_JOBS_STATUS_CANCELLED,
        );

        self::assertInstanceOf(Lookup::class, $lookup);
        self::assertSame(LookupService::SCHEDULED_JOBS_STATUS_CANCELLED, $lookup->getLookupCode());
    }

    public function testFindByTypeAndCodeReturnsNullForUnknownLookup(): void
    {
        self::assertNull(
            $this->lookups->findByTypeAndCode('qa_unknown_type', 'qa_unknown_code'),
            'Unknown lookups must resolve to null, not throw.',
        );
    }

    public function testLookupIdByCodeMatchesTheResolvedEntity(): void
    {
        $id = $this->lookups->getLookupIdByCode(
            LookupService::SCHEDULED_JOBS_STATUS,
            LookupService::SCHEDULED_JOBS_STATUS_CANCELLED,
        );
        $entity = $this->lookups->findByTypeAndCode(
            LookupService::SCHEDULED_JOBS_STATUS,
            LookupService::SCHEDULED_JOBS_STATUS_CANCELLED,
        );

        self::assertNotNull($id);
        self::assertInstanceOf(Lookup::class, $entity);
        self::assertSame((int) $entity->getId(), $id, 'getLookupIdByCode must agree with findByTypeAndCode.');
    }

    public function testLookupIdByCodeIsNullForUnknownCode(): void
    {
        self::assertNull($this->lookups->getLookupIdByCode('qa_unknown_type', 'qa_unknown_code'));
    }

    public function testCachedLookupReadsAreStable(): void
    {
        $first = $this->lookups->getLookupIdByCode(
            LookupService::SCHEDULED_JOBS_STATUS,
            LookupService::SCHEDULED_JOBS_STATUS_CANCELLED,
        );
        $second = $this->lookups->getLookupIdByCode(
            LookupService::SCHEDULED_JOBS_STATUS,
            LookupService::SCHEDULED_JOBS_STATUS_CANCELLED,
        );

        self::assertSame($first, $second, 'Repeated lookup reads (cache hit) must be identical.');
    }
}
