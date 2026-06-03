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
use App\Service\Cache\Core\CacheService;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Coverage for the self-service profile API
 * ({@see \App\Controller\Api\V1\Auth\ProfileController}): update name,
 * timezone, password, and delete account.
 *
 * Every mutation is exercised against the seeded qa.guest persona (the
 * simplest production-shaped user) and rolled back by DAMA. Each test asserts
 * the standard envelope, the public side effect (the user-data the endpoint
 * returns, the persisted password hash, the deleted row) and an ACL-version
 * bump where the service invalidates user caches. Failure paths
 * (wrong/short/duplicate input, unauthenticated) are covered too — the class
 * is tagged `security` for the CI `--group=security` tier.
 */
#[Group('security')]
final class ProfileControllerTest extends QaWebTestCase
{
    private const NAME_URI = '/cms-api/v1/auth/user/name';
    private const TIMEZONE_URI = '/cms-api/v1/auth/user/timezone';
    private const PASSWORD_URI = '/cms-api/v1/auth/user/password';
    private const ACCOUNT_URI = '/cms-api/v1/auth/user/account';
    private const USER_DATA_URI = '/cms-api/v1/auth/user-data';

    // -- updateName ---------------------------------------------------------

    public function testUpdateNameChangesNameAndBumpsAclVersion(): void
    {
        $token = $this->loginAsQaGuest();
        $before = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::USER_DATA_URI, null, $token));

        $newName = 'QA Guest Renamed';
        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('PUT', self::NAME_URI, ['name' => $newName], $token)
        );

        self::assertSame($newName, $data['name'], 'The endpoint must return the updated name.');
        self::assertNotSame(
            $before['acl_version'],
            $data['acl_version'],
            'Updating the profile must bump acl_version so the frontend invalidates its cache.'
        );

        // Persisted effect, observed from a clean identity map.
        $this->em()->clear();
        $reloaded = $this->em()->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_GUEST_EMAIL]);
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame($newName, $reloaded->getName());
    }

    public function testUpdateNameRejectsInvalidPayload(): void
    {
        // Missing required `name` -> request-schema validation failure (400).
        $this->assertEnvelope400(
            $this->jsonRequest('PUT', self::NAME_URI, ['unexpected' => 'x'], $this->loginAsQaGuest())
        );
    }

    public function testUpdateNameRequiresAuthentication(): void
    {
        $this->assertEnvelope401($this->jsonRequest('PUT', self::NAME_URI, ['name' => 'X'], null));
    }

    // -- updateTimezone -----------------------------------------------------

    public function testUpdateTimezoneSetsCallerTimezone(): void
    {
        $timezone = $this->em()->getRepository(Lookup::class)->findOneBy(['typeCode' => 'timezones']);
        self::assertInstanceOf(Lookup::class, $timezone, 'Seeded baseline must contain timezone lookups.');
        $timezoneId = (int) $timezone->getId();

        // The lookups cache is Redis-backed and escapes the DAMA transaction,
        // so a stale `lookup_by_id_*` entry from an earlier run could otherwise
        // make this assertion non-deterministic. Drop just this item so the
        // service reads the current timezone row from the DB.
        $this->service(CacheService::class)
            ->withCategory(CacheService::CATEGORY_LOOKUPS)
            ->invalidateItem("lookup_by_id_{$timezoneId}");

        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('PUT', self::TIMEZONE_URI, ['timezone_id' => $timezoneId], $this->loginAsQaGuest())
        );

        self::assertIsArray($data['timezone']);
        self::assertSame($timezoneId, $data['timezone']['id']);
        self::assertSame($timezone->getLookupCode(), $data['timezone']['code']);
    }

    public function testUpdateTimezoneRejectsUnknownId(): void
    {
        $this->assertEnvelope400(
            $this->jsonRequest('PUT', self::TIMEZONE_URI, ['timezone_id' => 2147483646], $this->loginAsQaGuest())
        );
    }

    public function testUpdateTimezoneRejectsNonTimezoneLookup(): void
    {
        // Any lookup that is NOT a timezone must be rejected by the service
        // guard ("The provided ID is not a valid timezone").
        $nonTimezone = $this->em()->createQueryBuilder()
            ->select('l')
            ->from(Lookup::class, 'l')
            ->where('l.typeCode != :tz')
            ->setParameter('tz', 'timezones')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(Lookup::class, $nonTimezone);

        $this->assertEnvelope400(
            $this->jsonRequest('PUT', self::TIMEZONE_URI, ['timezone_id' => (int) $nonTimezone->getId()], $this->loginAsQaGuest())
        );
    }

    // -- updatePassword -----------------------------------------------------

    public function testUpdatePasswordRejectsWrongCurrentPassword(): void
    {
        $this->assertEnvelope400(
            $this->jsonRequest('PUT', self::PASSWORD_URI, [
                'current_password' => 'definitely-not-the-password',
                'new_password' => 'BrandNewQaPassw0rd!2026',
            ], $this->loginAsQaGuest())
        );
    }

    public function testUpdatePasswordPersistsNewHash(): void
    {
        $newPassword = 'BrandNewQaPassw0rd!2026';
        $this->assertEnvelopeSuccess(
            $this->jsonRequest('PUT', self::PASSWORD_URI, [
                'current_password' => QaBaselineFixture::QA_PASSWORD,
                'new_password' => $newPassword,
            ], $this->loginAsQaGuest())
        );

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_GUEST_EMAIL]);
        self::assertInstanceOf(User::class, $reloaded);

        $hasher = $this->hasher();
        self::assertTrue($hasher->isPasswordValid($reloaded, $newPassword), 'New password must verify.');
        self::assertFalse(
            $hasher->isPasswordValid($reloaded, QaBaselineFixture::QA_PASSWORD),
            'Old password must no longer verify.'
        );
    }

    // -- deleteAccount ------------------------------------------------------

    public function testDeleteAccountRejectsEmailMismatch(): void
    {
        $this->assertEnvelope400(
            $this->jsonRequest('DELETE', self::ACCOUNT_URI, [
                'email_confirmation' => 'not-my-email@selfhelp.test',
            ], $this->loginAsQaGuest())
        );

        // The account must still exist after a rejected delete.
        $this->em()->clear();
        self::assertNotNull(
            $this->em()->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_GUEST_EMAIL])
        );
    }

    public function testDeleteAccountRemovesTheUser(): void
    {
        $this->assertEnvelopeSuccess(
            $this->jsonRequest('DELETE', self::ACCOUNT_URI, [
                'email_confirmation' => QaBaselineFixture::QA_GUEST_EMAIL,
            ], $this->loginAsQaGuest())
        );

        $this->em()->clear();
        self::assertNull(
            $this->em()->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_GUEST_EMAIL]),
            'The account must be deleted (DAMA rolls this back after the test).'
        );
    }

    public function testDeleteAccountRequiresAuthentication(): void
    {
        $this->assertEnvelope401(
            $this->jsonRequest('DELETE', self::ACCOUNT_URI, ['email_confirmation' => QaBaselineFixture::QA_GUEST_EMAIL], null)
        );
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
