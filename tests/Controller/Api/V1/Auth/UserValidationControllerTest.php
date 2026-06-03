<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Auth;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Lookup;
use App\Entity\User;
use App\Service\Core\LookupService;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Coverage for the public account-validation flow
 * ({@see \App\Controller\Api\V1\Auth\UserValidationController}):
 *   - GET  /validate/{user_id}/{token}            (check token before form)
 *   - POST /validate/{user_id}/{token}/complete   (set password/name)
 *
 * Both endpoints compare the route token against `user.token`. The seeded QA
 * personas have a NULL token, so the success path sets a deterministic token
 * in-test on qa.guest (rolled back by DAMA). completeValidation schedules a
 * welcome-email ScheduledJob through the null mailer — no real outbound. The
 * tests assert the envelope, the public account state (unblocked, token
 * cleared, password reset, name set) and the negative paths (wrong token,
 * unknown user, schema violation).
 */
#[Group('security')]
final class UserValidationControllerTest extends QaWebTestCase
{
    /**
     * Fixed (never random) tokens written onto the persona under test. The
     * route requirement is `[a-f0-9]{32}`, so both must be 32 lowercase hex
     * chars (WRONG_TOKEN matches the pattern but not the stored value, so it
     * reaches the controller and is rejected as invalid rather than 404ing).
     */
    private const TOKEN = 'abcdef0123456789abcdef0123456789';
    private const WRONG_TOKEN = '0123456789abcdef0123456789abcdef';

    public function testValidateTokenAcceptsTheCorrectToken(): void
    {
        $userId = $this->seedTokenOnGuest();

        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', $this->validateUri($userId, self::TOKEN), null, null)
        );

        self::assertSame($userId, $data['user_id']);
        self::assertSame(QaBaselineFixture::QA_GUEST_EMAIL, $data['email']);
        self::assertTrue($data['token_valid']);
    }

    public function testValidateTokenRejectsAWrongToken(): void
    {
        $userId = $this->seedTokenOnGuest();

        $this->assertEnvelope400(
            $this->jsonRequest('GET', $this->validateUri($userId, self::WRONG_TOKEN), null, null)
        );
    }

    public function testValidateTokenReturns404ForUnknownUser(): void
    {
        $this->assertEnvelope404(
            $this->jsonRequest('GET', $this->validateUri(2147483646, self::TOKEN), null, null)
        );
    }

    public function testCompleteValidationActivatesTheAccount(): void
    {
        $userId = $this->seedTokenOnGuest();
        $newPassword = 'QaValidatedPassw0rd!2026';
        $newName = 'QA Validated Guest';

        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', $this->validateUri($userId, self::TOKEN) . '/complete', [
                'password' => $newPassword,
                'name' => $newName,
            ], null)
        );

        self::assertSame($userId, $data['user_id']);
        self::assertSame($newName, $data['name']);

        // Public account state after activation.
        $this->em()->clear();
        $user = $this->em()->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $user);
        self::assertNull($user->getToken(), 'Validation must consume (null) the token.');
        self::assertFalse($user->isBlocked(), 'Validated account must be unblocked.');
        self::assertSame($newName, $user->getName());
        self::assertTrue(
            $this->hasher()->isPasswordValid($user, $newPassword),
            'The new password must verify after validation.'
        );
    }

    public function testCompleteValidationRejectsWrongToken(): void
    {
        $userId = $this->seedTokenOnGuest();

        $this->assertEnvelope400(
            $this->jsonRequest('POST', $this->validateUri($userId, self::WRONG_TOKEN) . '/complete', [
                'password' => 'QaValidatedPassw0rd!2026',
                'name' => 'QA Validated Guest',
            ], null)
        );
    }

    public function testCompleteValidationRejectsMissingPassword(): void
    {
        $userId = $this->seedTokenOnGuest();

        // `password` is required by requests/auth/complete_validation.
        $this->assertEnvelope400(
            $this->jsonRequest('POST', $this->validateUri($userId, self::TOKEN) . '/complete', [
                'name' => 'QA Validated Guest',
            ], null)
        );
    }

    private function validateUri(int $userId, string $token): string
    {
        return sprintf('/cms-api/v1/validate/%d/%s', $userId, $token);
    }

    /**
     * Write the fixed QA token onto qa.guest and return the user id. DAMA
     * rolls the write back after the test.
     */
    private function seedTokenOnGuest(): int
    {
        $em = $this->em();
        $guest = $em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_GUEST_EMAIL]);
        self::assertInstanceOf(User::class, $guest);
        $guest->setToken(self::TOKEN);
        // Seeded personas are ACTIVE; completeValidation() refuses to re-validate
        // an active account, so seed the realistic invited pre-validation state.
        $invited = $em->getRepository(Lookup::class)->findOneBy([
            'typeCode' => LookupService::USER_STATUS,
            'lookupCode' => LookupService::USER_STATUS_INVITED,
        ]);
        self::assertInstanceOf(Lookup::class, $invited, 'The invited user-status lookup must be seeded.');
        $guest->setStatus($invited);
        $em->flush();

        return (int) $guest->getId();
    }

    private function em(): EntityManagerInterface
    {
        return $this->service(EntityManagerInterface::class);
    }

    private function hasher(): UserPasswordHasherInterface
    {
        return $this->service(UserPasswordHasherInterface::class);
    }
}
