<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Auth;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\User;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L9: issuing a token must not touch the user's username. Login, token
 * refresh and impersonation each wrote the email into `user_name`.
 */
final class UsernameKeptOnTokenIssueTest extends QaWebTestCase
{
    private const USERNAME = 'qa_l9_username';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot();
        $this->em = $this->service(EntityManagerInterface::class);

        $this->em->getConnection()->executeStatement(
            'UPDATE users SET user_name = ? WHERE email = ?',
            [self::USERNAME, QaBaselineFixture::QA_USER_EMAIL]
        );
    }

    public function testLoginKeepsTheUsername(): void
    {
        $this->login();

        self::assertSame(self::USERNAME, $this->storedUsername(), 'Logging in must not replace the username');
    }

    public function testTokenRefreshKeepsTheUsername(): void
    {
        $refreshToken = $this->login()['refresh_token'] ?? null;
        self::assertIsString($refreshToken);
        $this->restoreUsername();

        $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', '/cms-api/v1/auth/refresh-token', ['refresh_token' => $refreshToken])
        );

        self::assertSame(self::USERNAME, $this->storedUsername(), 'Refreshing a token must not replace the username');
    }

    public function testImpersonationKeepsTheImpersonatedUsersUsername(): void
    {
        $userId = $this->em->getConnection()->fetchOne(
            'SELECT id FROM users WHERE email = ?',
            [QaBaselineFixture::QA_USER_EMAIL]
        );
        self::assertIsNumeric($userId);

        $this->assertEnvelopeSuccess($this->jsonRequest(
            'POST',
            sprintf('/cms-api/v1/admin/users/%d/impersonate', (int) $userId),
            null,
            $this->loginAsQaAdmin()
        ));

        self::assertSame(self::USERNAME, $this->storedUsername(), 'Impersonating a user must not replace their username');
    }

    /**
     * @return array<string, mixed>
     */
    private function login(): array
    {
        return $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/auth/login', [
            'email' => QaBaselineFixture::QA_USER_EMAIL,
            'password' => QaBaselineFixture::QA_PASSWORD,
        ]));
    }

    /** Undo a login's damage so the refresh test isolates the refresh. */
    private function restoreUsername(): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE users SET user_name = ? WHERE email = ?',
            [self::USERNAME, QaBaselineFixture::QA_USER_EMAIL]
        );
        $this->em->clear();
    }

    private function storedUsername(): mixed
    {
        return $this->em->getConnection()->fetchOne(
            'SELECT user_name FROM users WHERE email = ?',
            [QaBaselineFixture::QA_USER_EMAIL]
        );
    }
}
