<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\DataRow;
use App\Entity\DataTable;
use App\Entity\Field;
use App\Entity\Group;
use App\Entity\Language;
use App\Entity\Page;
use App\Entity\Section;
use App\Entity\SectionsFieldsTranslation;
use App\Entity\User;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\Factories\RoleDataAccessFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Record edit mode permission matrix for
 * {@see \App\Controller\Api\V1\Frontend\FormController::updateForm} with an
 * explicit `update_based_on.record_id` (the CMS-in-CMS edit-by-URL flow).
 *
 * The centralized rule ({@see DataAccessSecurityService::canUpdateOwnedRecord})
 * covers three ownership modes:
 *  - own record: always editable (diary mode, own_entries_only default on);
 *  - foreign record on an own_entries_only section: never editable;
 *  - foreign record on a shared section (own_entries_only=0): requires UPDATE
 *    data-access permission on the form's data table (collaborative/admin
 *    CMS-in-CMS mode; the admin role override grants it implicitly).
 */
#[PhpUnitGroup('security')]
final class FormRecordEditModeTest extends QaWebTestCase
{
    private const SUBMIT = '/cms-api/v1/forms/submit';
    private const UPDATE = '/cms-api/v1/forms/update';

    private EntityManagerInterface $em;
    private PageSectionFactory $pages;
    private UserFactory $users;
    private RoleDataAccessFactory $grants;

    protected function setUp(): void
    {
        parent::setUp();

        // NOTE: unlike FormControllerTest this class does NOT disableReboot():
        // each test switches identity between requests (owner submits, another
        // user updates), and without a reboot the stateful UserContextService
        // keeps the FIRST request's memoized user for the whole kernel,
        // silently running the update as the owner.
        $this->em = $this->service(EntityManagerInterface::class);
        $this->pages = new PageSectionFactory(
            $this->em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );
        $this->users = new UserFactory(
            $this->em,
            $this->service(UserPasswordHasherInterface::class),
            $this->service(LookupService::class),
        );
        $this->grants = new RoleDataAccessFactory(
            $this->em,
            $this->service(LookupService::class),
            $this->service(DataAccessSecurityService::class),
        );
    }

    // -- Own record ----------------------------------------------------------

    public function testOwnerUpdatesOwnRecordByExplicitRecordId(): void
    {
        [$page, $section] = $this->setUpFormPage('qa_editmode_own', ownEntriesOnly: true);
        $token = $this->loginAsQaUser();
        $recordId = $this->submitRecord($token, $page, $section, 'initial value');

        $envelope = $this->updateRecord($token, $page, $section, $recordId, 'updated value');
        $data = $this->assertEnvelopeSuccess($envelope);
        self::assertSame($recordId, $data['record_id'] ?? null, 'The addressed record must be updated in place.');

        // Public side effect: still exactly one row in the form's data table
        // (updated, not duplicated), owned by the original author.
        $rows = $this->tableRows($section);
        self::assertCount(1, $rows, 'Updating by record_id must not create a new row.');
        self::assertSame($this->qaUserId(), $rows[0]->getIdUsers(), 'Ownership must be preserved on update.');
    }

    // -- Foreign record, own_entries_only section ------------------------------

    public function testForeignUpdateOnOwnEntriesOnlySectionIsForbidden(): void
    {
        [$page, $section] = $this->setUpFormPage('qa_editmode_strict', ownEntriesOnly: true);
        $recordId = $this->submitRecord($this->loginAsQaUser(), $page, $section, 'owner value');

        $otherToken = $this->loginAsFactoryUser('qa.editmode.strict@selfhelp.test');
        $envelope = $this->updateRecord($otherToken, $page, $section, $recordId, 'hijacked');
        $this->assertEnvelope403($envelope);
    }

    // -- Foreign record, shared section (own_entries_only=0) -------------------

    public function testForeignUpdateWithoutTablePermissionIsForbiddenOnSharedSection(): void
    {
        [$page, $section] = $this->setUpFormPage('qa_editmode_shared_deny', ownEntriesOnly: false);
        $recordId = $this->submitRecord($this->loginAsQaUser(), $page, $section, 'owner value');

        $otherToken = $this->loginAsFactoryUser('qa.editmode.deny@selfhelp.test');
        $envelope = $this->updateRecord($otherToken, $page, $section, $recordId, 'no permission');
        $this->assertEnvelope403($envelope);
    }

    public function testForeignUpdateWithTableUpdatePermissionSucceedsOnSharedSection(): void
    {
        [$page, $section] = $this->setUpFormPage('qa_editmode_shared_grant', ownEntriesOnly: false);
        $recordId = $this->submitRecord($this->loginAsQaUser(), $page, $section, 'owner value');

        // Grant the second user UPDATE data access on the form's data table
        // through a qa role (the production grant model, no admin override).
        $editor = $this->users->createUser(
            'qa.editmode.grant@selfhelp.test',
            'QA Edit Grantee',
            groups: [$this->subjectGroup()],
        );
        $role = $this->grants->createRole('qa_editmode_update_role');
        $this->grants->assignRoleToUser($editor, $role);
        $this->grants->grantDataTableAccess($role, $this->tableId($section), DataAccessSecurityService::PERMISSION_UPDATE);

        $envelope = $this->updateRecord(
            $this->loginAs('qa.editmode.grant@selfhelp.test'),
            $page,
            $section,
            $recordId,
            'collaboratively edited'
        );
        $data = $this->assertEnvelopeSuccess($envelope);
        self::assertSame($recordId, $data['record_id'] ?? null);

        // Ownership must NOT transfer to the editing user.
        $rows = $this->tableRows($section);
        self::assertCount(1, $rows);
        self::assertSame($this->qaUserId(), $rows[0]->getIdUsers(), 'Editing a foreign record must not steal ownership.');
    }

    public function testAdminRoleOverrideAllowsForeignUpdateOnSharedSection(): void
    {
        [$page, $section] = $this->setUpFormPage('qa_editmode_shared_admin', ownEntriesOnly: false);
        // qa.admin is in the seeded admin group; grant that group page ACL too.
        $this->pages->grantGroupAcl(
            $page,
            $this->groupByName('admin'),
            select: true,
            insert: true,
            update: true,
            delete: false,
        );
        $recordId = $this->submitRecord($this->loginAsQaUser(), $page, $section, 'owner value');

        $envelope = $this->updateRecord($this->loginAsQaAdmin(), $page, $section, $recordId, 'admin edited');
        $data = $this->assertEnvelopeSuccess($envelope);
        self::assertSame($recordId, $data['record_id'] ?? null, 'Admins edit any record (CMS-in-CMS mode).');
    }

    // -- Helpers ---------------------------------------------------------------

    /**
     * Page + form-record section with subject-group select/insert/update ACL;
     * optionally opts the section out of own_entries_only (shared mode).
     *
     * @return array{0: Page, 1: Section}
     */
    private function setUpFormPage(string $keyword, bool $ownEntriesOnly): array
    {
        [$page, $section] = $this->pages->createFormPage($keyword, openAccess: false);
        $this->pages->grantGroupAcl(
            $page,
            $this->subjectGroup(),
            select: true,
            insert: true,
            update: true,
            delete: false,
        );
        if (!$ownEntriesOnly) {
            $this->setSectionField($section, 'own_entries_only', '0');
        }

        return [$page, $section];
    }

    private function submitRecord(string $token, Page $page, Section $section, string $value): int
    {
        $envelope = $this->jsonRequest('POST', self::SUBMIT, [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $section->getId(),
            'form_data' => ['qa_answer' => $value],
        ], $token);
        $data = $this->assertEnvelopeSuccess($envelope);
        self::assertIsInt($data['record_id'] ?? null);

        return $data['record_id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function updateRecord(string $token, Page $page, Section $section, int $recordId, string $value): array
    {
        return $this->jsonRequest('PUT', self::UPDATE, [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $section->getId(),
            'form_data' => ['qa_answer' => $value],
            'update_based_on' => ['record_id' => $recordId],
        ], $token);
    }

    private function loginAsFactoryUser(string $email): string
    {
        $this->users->createUser($email, 'QA Foreign Editor', groups: [$this->subjectGroup()]);

        return $this->loginAs($email);
    }

    /**
     * All rows of the section's data table (name = section id).
     *
     * @return list<DataRow>
     */
    private function tableRows(Section $section): array
    {
        $table = $this->em->getRepository(DataTable::class)->findOneBy(['name' => (string) $section->getId()]);
        self::assertInstanceOf(DataTable::class, $table, 'The form submission must have created its data table.');

        return $this->em->getRepository(DataRow::class)->findBy(['dataTable' => $table]);
    }

    private function tableId(Section $section): int
    {
        $table = $this->em->getRepository(DataTable::class)->findOneBy(['name' => (string) $section->getId()]);
        self::assertInstanceOf(DataTable::class, $table);

        return (int) $table->getId();
    }

    private function setSectionField(Section $section, string $fieldName, string $content): void
    {
        $field = $this->em->getRepository(Field::class)->findOneBy(['name' => $fieldName]);
        self::assertInstanceOf(Field::class, $field, sprintf('Missing seeded field "%s".', $fieldName));
        $language = $this->em->getRepository(Language::class)->find(1);
        self::assertInstanceOf(Language::class, $language);

        $translation = new SectionsFieldsTranslation();
        $translation->setSection($section);
        $translation->setField($field);
        $translation->setLanguage($language);
        $translation->setContent($content);
        $this->em->persist($translation);
        $this->em->flush();
    }

    private function subjectGroup(): Group
    {
        return $this->groupByName('subject');
    }

    private function groupByName(string $name): Group
    {
        $group = $this->em->getRepository(Group::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Group::class, $group, sprintf('The seeded "%s" group must exist. Run: composer test:reset-db', $name));

        return $group;
    }

    private function qaUserId(): int
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $user);

        return (int) $user->getId();
    }
}
