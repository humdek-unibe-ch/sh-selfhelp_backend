<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Auth;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Group;
use App\Entity\Role;
use App\Entity\User;
use App\Service\Sync\AclStampService;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;

/**
 * The access stamp (live-sync contract C5): every API response to a signed-in
 * caller carries `X-Acl-Stamp`, a short fingerprint of the caller's own
 * `acl_version` and the versions of their groups and roles. Any change to the
 * caller's access moves it; an unrelated change does not; a guest gets none.
 */
#[PhpUnitGroup('security')]
final class AclStampHeaderTest extends QaWebTestCase
{
    private const USER_DATA = '/cms-api/v1/auth/user-data';

    public function testASignedInCallerGetsTheStampOnEveryResponseAndItEqualsUserData(): void
    {
        $token = $this->loginAsQaUser();

        $data = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::USER_DATA, null, $token));
        $header = $this->stampHeader();

        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $header);
        self::assertSame($header, $data['acl_stamp'] ?? null, '/auth/user-data returns the same stamp the header carries.');

        // Another endpoint: the same stamp.
        $this->jsonRequest('GET', '/cms-api/v1/pages', null, $token);
        self::assertSame($header, $this->stampHeader());

        // An error response carries it too.
        $this->jsonRequest('GET', '/cms-api/v1/pages/by-keyword/qa_no_such_page_for_stamp', null, $token);
        self::assertSame($header, $this->stampHeader());
    }

    public function testTheStampIsTheDocumentedFingerprint(): void
    {
        $token = $this->loginAsQaUser();
        $this->jsonRequest('GET', self::USER_DATA, null, $token);

        $user = $this->user(QaBaselineFixture::QA_USER_EMAIL);
        $connection = $this->em()->getConnection();
        $groups = $this->joined($connection->fetchFirstColumn("SELECT CONCAT(g.id, ':', COALESCE(g.acl_version, '')) FROM rel_groups_users ug JOIN `groups` g ON g.id = ug.id_groups WHERE ug.id_users = ? ORDER BY g.id", [$user->getId()]));
        $roles = $this->joined($connection->fetchFirstColumn("SELECT CONCAT(r.id, ':', COALESCE(r.acl_version, '')) FROM rel_roles_users ru JOIN roles r ON r.id = ru.id_roles WHERE ru.id_users = ? ORDER BY r.id", [$user->getId()]));

        $expected = substr(hash('sha256', ($user->getAclVersion() ?? '') . "
" . $groups . "
" . $roles), 0, 16);

        self::assertSame($expected, $this->stampHeader());
    }

    /**
     * @param list<mixed> $pairs
     */
    private function joined(array $pairs): string
    {
        return implode(',', array_map(static fn (mixed $pair): string => is_string($pair) ? $pair : '', $pairs));
    }

    public function testAGuestGetsNoStamp(): void
    {
        $this->jsonRequest('GET', '/cms-api/v1/pages');

        self::assertFalse($this->client->getResponse()->headers->has('X-Acl-Stamp'));
    }

    public function testTheStampMovesWhenTheUsersGroupChangesButNotForAnotherGroup(): void
    {
        $token = $this->loginAsQaUser();
        $this->jsonRequest('GET', self::USER_DATA, null, $token);
        $before = $this->stampHeader();

        $foreign = $this->groupNotOf($this->user(QaBaselineFixture::QA_USER_EMAIL));
        $foreign->bumpAclVersion();
        $this->em()->flush();
        $this->jsonRequest('GET', self::USER_DATA, null, $token);
        self::assertSame($before, $this->stampHeader(), 'A group the user is not in does not move their stamp.');

        // The request above booted a fresh kernel: load the user again before writing.
        $own = $this->firstGroupOf($this->user(QaBaselineFixture::QA_USER_EMAIL));
        $own->bumpAclVersion();
        $this->em()->flush();
        $this->jsonRequest('GET', self::USER_DATA, null, $token);
        self::assertNotSame($before, $this->stampHeader(), 'A change to one of the user\'s groups moves the stamp.');
    }

    public function testTheStampMovesWhenARoleOfTheUserChanges(): void
    {
        $token = $this->loginAsQaAdmin();
        $this->jsonRequest('GET', self::USER_DATA, null, $token);
        $before = $this->stampHeader();

        $admin = $this->user(QaBaselineFixture::QA_ADMIN_EMAIL);
        $role = $admin->getUserRoles()->first();
        self::assertInstanceOf(Role::class, $role);
        $role->bumpAclVersion();
        $this->em()->flush();

        $this->jsonRequest('GET', self::USER_DATA, null, $token);
        self::assertNotSame($before, $this->stampHeader());
    }

    public function testTheStampMovesWhenTheUsersOwnVersionChanges(): void
    {
        $token = $this->loginAsQaUser();
        $this->jsonRequest('GET', self::USER_DATA, null, $token);
        $before = $this->stampHeader();

        $user = $this->user(QaBaselineFixture::QA_USER_EMAIL);
        $user->bumpAclVersion();
        $this->em()->flush();

        $this->jsonRequest('GET', self::USER_DATA, null, $token);
        self::assertNotSame($before, $this->stampHeader());
    }

    public function testUserDataCarriesTheCurrentStampEvenWhileItsOtherFieldsAreCached(): void
    {
        $token = $this->loginAsQaUser();
        $this->jsonRequest('GET', self::USER_DATA, null, $token);

        $own = $this->firstGroupOf($this->user(QaBaselineFixture::QA_USER_EMAIL));
        $own->bumpAclVersion();
        $this->em()->flush();
        // The cached part of user data is left as it is: the stamp is read fresh.

        $data = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::USER_DATA, null, $token));
        self::assertSame($this->stampHeader(), $data['acl_stamp'] ?? null);
    }

    public function testTheStampIsIndependentOfTheOrderOfTheMemberships(): void
    {
        self::assertSame(
            AclStampService::compose('u', [3 => 'c', 1 => 'a'], [9 => 'z', 2 => 'y']),
            AclStampService::compose('u', [1 => 'a', 3 => 'c'], [2 => 'y', 9 => 'z']),
        );
        self::assertNotSame(
            AclStampService::compose('u', [1 => 'a'], []),
            AclStampService::compose('u', [], [1 => 'a']),
            'A group and a role with the same id and version are different things.',
        );
        self::assertSame(16, strlen(AclStampService::compose(null, [], [])));
    }

    // -- helpers ------------------------------------------------------------

    private function stampHeader(): string
    {
        $header = $this->client->getResponse()->headers->get('X-Acl-Stamp');
        self::assertIsString($header, 'The response carries X-Acl-Stamp.');

        return $header;
    }

    private function em(): EntityManagerInterface
    {
        return $this->service(EntityManagerInterface::class);
    }

    private function user(string $email): User
    {
        $this->em()->clear();
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function firstGroupOf(User $user): Group
    {
        $group = $user->getGroups()->first();
        self::assertInstanceOf(Group::class, $group, 'The QA persona is in a group.');

        return $group;
    }

    private function groupNotOf(User $user): Group
    {
        $own = array_map(static fn (Group $g): ?int => $g->getId(), $user->getGroups()->toArray());
        foreach ($this->em()->getRepository(Group::class)->findAll() as $group) {
            if (!in_array($group->getId(), $own, true)) {
                return $group;
            }
        }
        self::fail('The baseline has a group the QA user is not in.');
    }
}
