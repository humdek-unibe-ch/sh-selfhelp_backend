<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Tests\Smoke;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Tests\Support\PerfBudget;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * The foundation smoke test (Slice 1A). Proves the whole test stack is
 * wired correctly end to end:
 *   - the QA baseline fixture loaded at the expected version;
 *   - a seeded persona can perform a REAL JWT login;
 *   - an authenticated admin request returns the standard success envelope;
 *   - login meets its performance budget (plan §28).
 *
 * If this passes on a fresh checkout, the foundation is sound.
 *
 * @group smoke
 */
#[Group('smoke')]
final class QaLoginSmokeTest extends QaWebTestCase
{
    public function testQaAdminCanLoginAndListAdminPagesAfterFreshFixture(): void
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $fixtureVersion = $this->readQaFixtureVersion($em);

        // Make the applied fixture version visible in the run output so a
        // failure tells you immediately which baseline was seeded (plan §32).
        fwrite(STDOUT, sprintf("\nQA_FIXTURE_VERSION=%s\n", (string) $fixtureVersion));

        self::assertSame(
            QaBaselineFixture::QA_FIXTURE_VERSION,
            $fixtureVersion,
            'Database was seeded with a different QA fixture version. Run: composer test:reset-db'
        );

        $startedAt = microtime(true);
        $token = $this->loginAsQaAdmin();
        $loginMs = (microtime(true) - $startedAt) * 1000;

        self::assertNotSame('', $token, 'qa.admin must receive a non-empty access token');

        // Performance budget for login (plan §28): hard-fail above 2× budget.
        PerfBudget::assertWithinBudget($loginMs, PerfBudget::LOGIN_MS, 'login');

        $listStartedAt = microtime(true);
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/pages', null, $token);
        PerfBudget::assertWithinBudget(
            (microtime(true) - $listStartedAt) * 1000,
            PerfBudget::ADMIN_PAGES_LIST_MS,
            'admin pages list'
        );
        $data = $this->assertEnvelopeSuccess($envelope);

        self::assertArrayHasKey('logged_in', $envelope);
        self::assertTrue($envelope['logged_in'], 'Authenticated admin request must report logged_in=true');
        self::assertIsArray($data, 'Admin pages list must return an array payload');
    }
}
