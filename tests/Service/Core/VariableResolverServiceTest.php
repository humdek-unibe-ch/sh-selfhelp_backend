<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\Core;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\User;
use App\Service\Core\VariableResolverService;
use App\Tests\Support\QaKernelTestCase;

/**
 * Behavioural coverage for {@see VariableResolverService::getAllVariables()} —
 * the variable set used by conditions and content interpolation (plan Phase 8:
 * platform/language/time variables).
 *
 * `includeGlobalVars: false` keeps the assertions independent of the optional
 * sh-global-values page so the system-variable contract is what is verified.
 */
final class VariableResolverServiceTest extends QaKernelTestCase
{
    private VariableResolverService $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = $this->service(VariableResolverService::class);
    }

    public function testAnonymousContextHasSafeDefaults(): void
    {
        $vars = $this->resolver->getAllVariables(null, 7, false);

        self::assertSame([], $vars['user_group'], 'Anonymous user_group must be empty.');
        self::assertSame(7, $vars['language'], 'language must echo the supplied id for anonymous callers.');
        self::assertSame('', $vars['last_login']);
        self::assertSame('web', $vars['platform'], 'No request => web platform.');
        self::assertSame('SelfHelp', $vars['project_name']);
        self::assertSame('', $vars['user_name']);
        self::assertSame('', $vars['user_email']);
    }

    public function testTimeVariablesAreCurrentAndWellFormed(): void
    {
        $vars = $this->resolver->getAllVariables(null, 1, false);

        self::assertSame(date('Y-m-d'), $vars['current_date']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $this->coerceString($vars['current_datetime']));
        self::assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $this->coerceString($vars['current_time']));
    }

    public function testAuthenticatedUserExposesGroupMembership(): void
    {
        $userId = $this->userId(QaBaselineFixture::QA_USER_EMAIL);

        $vars = $this->resolver->getAllVariables($userId, 1, false);

        self::assertIsArray($vars['user_group']);
        self::assertContains('subject', $vars['user_group'], 'qa.user belongs to the subject group.');
    }

    private function userId(string $email): int
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user, "{$email} must be seeded. Run: composer test:reset-db");

        return (int) $user->getId();
    }
}
