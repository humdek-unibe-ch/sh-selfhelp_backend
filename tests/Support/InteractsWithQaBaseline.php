<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Tests\Support;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Shared QA-baseline awareness for {@see QaWebTestCase} and
 * {@see QaKernelTestCase}: a single fail-fast assertion that the database
 * was seeded with the CURRENT fixture version, so a missing or stale
 * baseline produces a clear, actionable message instead of confusing
 * downstream failures (plan §10 / §32).
 */
trait InteractsWithQaBaseline
{
    /**
     * Assert the QA baseline is present and matches QA_FIXTURE_VERSION.
     * The version is read back from the marker user's display name (seeded
     * by QaBaselineFixture) so we verify what is actually IN the database,
     * not just the constant in code.
     */
    protected function assertQaBaselineLoaded(EntityManagerInterface $em): void
    {
        $repo = $em->getRepository(User::class);

        $admin = $repo->findOneBy(['email' => QaBaselineFixture::QA_ADMIN_EMAIL]);
        if (!$admin instanceof User) {
            self::fail(
                'QA baseline fixture not loaded (qa.admin@selfhelp.test missing). '
                . 'Run: composer test:reset-db'
            );
        }

        self::assertContains(
            'ROLE_ADMIN',
            $admin->getRoles(),
            'qa.admin must carry ROLE_ADMIN like a production admin (QA fixture integrity).'
        );

        $marker = $repo->findOneBy(['email' => QaBaselineFixture::QA_FIXTURE_MARKER_EMAIL]);
        if (!$marker instanceof User) {
            self::fail(
                'QA fixture version marker missing. The DB was seeded by an older fixture. '
                . 'Run: composer test:reset-db'
            );
        }

        self::assertSame(
            QaBaselineFixture::QA_FIXTURE_VERSION,
            $marker->getName(),
            sprintf(
                'QA fixture version mismatch: DB has "%s", code expects "%s". Run: composer test:reset-db',
                (string) $marker->getName(),
                QaBaselineFixture::QA_FIXTURE_VERSION
            )
        );
    }

    /**
     * Read the QA fixture version currently stored in the database (marker
     * user display name), or null if the marker is absent.
     */
    protected function readQaFixtureVersion(EntityManagerInterface $em): ?string
    {
        $marker = $em->getRepository(User::class)
            ->findOneBy(['email' => QaBaselineFixture::QA_FIXTURE_MARKER_EMAIL]);

        return $marker instanceof User ? $marker->getName() : null;
    }
}
