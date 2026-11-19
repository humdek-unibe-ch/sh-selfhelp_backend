<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\Entity\DataTable;
use App\Entity\Field;
use App\Entity\Group;
use App\Entity\Language;
use App\Entity\Page;
use App\Entity\Section;
use App\Entity\SectionsFieldsTranslation;
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
 * F127: a form write must only ever touch a record of the table that the
 * submitted section is bound to.
 *
 * The update and delete endpoints used to look the target row up globally
 * (`find($recordId)`) and check ownership only. A caller holding rights on
 * table A could therefore reach a row of table B through a section bound to A:
 * an OWN row of B (own_entries_only sections) and a FOREIGN row of B (shared
 * sections with an A-level grant). Every case below must be refused as "record
 * not found" (the same answer as a record that does not exist, so nothing leaks)
 * and must leave the row of table B untouched.
 *
 * The positive controls prove the fix does not block the legitimate same-table
 * flows.
 */
#[PhpUnitGroup('security')]
final class FormRecordBindingTest extends QaWebTestCase
{
    private const SUBMIT = '/cms-api/v1/forms/submit';
    private const UPDATE = '/cms-api/v1/forms/update';
    private const DELETE = '/cms-api/v1/forms/delete';

    private EntityManagerInterface $em;
    private PageSectionFactory $pages;
    private UserFactory $users;
    private RoleDataAccessFactory $grants;

    protected function setUp(): void
    {
        parent::setUp();

        // No disableReboot(): identities switch between requests (owner, then a
        // foreign editor) and the stateful UserContextService must be rebuilt
        // for each request (see FormRecordEditModeTest).
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

    // -- update ---------------------------------------------------------------

    public function testOwnRecordOfAnotherTableCannotBeUpdatedThroughThisSection(): void
    {
        $world = $this->twoForms('qa_bind_own_upd', sharedA: false);
        $token = $this->loginAsQaUser();
        $recordB = $this->submit($token, $world['pageB'], $world['sectionB'], 'b-original');

        $envelope = $this->update($token, $world['pageA'], $world['sectionA'], $recordB, 'overwritten-through-A');

        $this->assertEnvelope404($envelope);
        self::assertSame('b-original', $this->cellValue($recordB, 'qa_answer'), 'The row of table B must be untouched.');
    }

    public function testForeignRecordOfAnotherTableCannotBeUpdatedThroughASharedSection(): void
    {
        $world = $this->twoForms('qa_bind_foreign_upd', sharedA: true);
        $recordB = $this->submit($this->loginAsQaUser(), $world['pageB'], $world['sectionB'], 'b-original');
        $editorToken = $this->editorWithGrantOnTableA($world['sectionA'], 'qa.bind.foreign.upd@selfhelp.test');

        $envelope = $this->update($editorToken, $world['pageA'], $world['sectionA'], $recordB, 'hijacked-through-A');

        $this->assertEnvelope404($envelope);
        self::assertSame('b-original', $this->cellValue($recordB, 'qa_answer'), 'The row of table B must be untouched.');
    }

    public function testSoftDeletedRecordCannotBeUpdated(): void
    {
        $world = $this->twoForms('qa_bind_deleted_upd', sharedA: false);
        $token = $this->loginAsQaUser();
        $record = $this->submit($token, $world['pageA'], $world['sectionA'], 'to-be-deleted');
        $this->assertEnvelopeSuccess($this->delete($token, $world['pageA'], $world['deleteA'], $record));
        self::assertTrue($this->isDeleted($record));

        $envelope = $this->update($token, $world['pageA'], $world['sectionA'], $record, 'resurrected');

        $this->assertEnvelope404($envelope);
        self::assertTrue($this->isDeleted($record), 'An update must never bring a deleted record back.');
        self::assertSame('to-be-deleted', $this->cellValue($record, 'qa_answer'));
    }

    public function testSameTableUpdateStillWorks(): void
    {
        $world = $this->twoForms('qa_bind_same_upd', sharedA: false);
        $token = $this->loginAsQaUser();
        $record = $this->submit($token, $world['pageA'], $world['sectionA'], 'first');

        $envelope = $this->update($token, $world['pageA'], $world['sectionA'], $record, 'second');

        $data = $this->assertEnvelopeSuccess($envelope);
        self::assertSame($record, $data['record_id'] ?? null);
        self::assertSame('second', $this->cellValue($record, 'qa_answer'));
    }

    // -- delete ---------------------------------------------------------------

    public function testOwnRecordOfAnotherTableCannotBeDeletedThroughThisSection(): void
    {
        $world = $this->twoForms('qa_bind_own_del', sharedA: false);
        $token = $this->loginAsQaUser();
        $recordB = $this->submit($token, $world['pageB'], $world['sectionB'], 'b-original');

        $envelope = $this->delete($token, $world['pageA'], $world['deleteA'], $recordB);

        $this->assertEnvelope404($envelope);
        self::assertFalse($this->isDeleted($recordB), 'The row of table B must not be deleted.');
    }

    public function testForeignRecordOfAnotherTableCannotBeDeletedThroughASharedSection(): void
    {
        $world = $this->twoForms('qa_bind_foreign_del', sharedA: true);
        $recordB = $this->submit($this->loginAsQaUser(), $world['pageB'], $world['sectionB'], 'b-original');
        $editorToken = $this->editorWithGrantOnTableA($world['sectionA'], 'qa.bind.foreign.del@selfhelp.test');

        $envelope = $this->delete($editorToken, $world['pageA'], $world['deleteA'], $recordB);

        $this->assertEnvelope404($envelope);
        self::assertFalse($this->isDeleted($recordB), 'The row of table B must not be deleted.');
    }

    public function testDeleteSectionWithoutADataTableIsRefused(): void
    {
        $world = $this->twoForms('qa_bind_no_table', sharedA: false);
        $token = $this->loginAsQaUser();
        $record = $this->submit($token, $world['pageA'], $world['sectionA'], 'keep-me');

        // A delete trigger that names no data table (and has none above it) cannot
        // be bound to a record's table, so it must not delete anything.
        $unbound = $this->pages->createSection('qa_bind_no_table_unbound', 'entry-record-delete');
        $this->pages->linkSectionToPage($this->freshPage($world['pageA']), $unbound, 40);

        $envelope = $this->delete($token, $world['pageA'], $unbound, $record);

        $this->assertEnvelope400($envelope);
        self::assertFalse($this->isDeleted($record));
    }

    public function testSameTableDeleteStillWorks(): void
    {
        $world = $this->twoForms('qa_bind_same_del', sharedA: false);
        $token = $this->loginAsQaUser();
        $record = $this->submit($token, $world['pageA'], $world['sectionA'], 'delete-me');

        $this->assertEnvelopeSuccess($this->delete($token, $world['pageA'], $world['deleteA'], $record));

        self::assertTrue($this->isDeleted($record));
    }

    public function testSharedSectionEditorWithDeleteGrantDeletesForeignRecordOfSameTable(): void
    {
        $world = $this->twoForms('qa_bind_shared_del', sharedA: true);
        $record = $this->submit($this->loginAsQaUser(), $world['pageA'], $world['sectionA'], 'owned-by-qa');
        $editorToken = $this->editorWithGrantOnTableA($world['sectionA'], 'qa.bind.shared.del@selfhelp.test');

        $this->assertEnvelopeSuccess($this->delete($editorToken, $world['pageA'], $world['deleteA'], $record));

        self::assertTrue($this->isDeleted($record));
    }

    // -- helpers ----------------------------------------------------------------

    /**
     * Two independent form pages (tables A and B) plus a delete trigger bound to
     * table A. Every subject-group member holds select/insert/update/delete on
     * both pages: the page ACL is deliberately NOT the thing under test, only the
     * table binding of the record is.
     *
     * @return array{pageA: Page, sectionA: Section, deleteA: Section, pageB: Page, sectionB: Section}
     */
    private function twoForms(string $prefix, bool $sharedA): array
    {
        // Build the whole graph BEFORE the first request: the test client reboots
        // the kernel between requests, which detaches every entity created earlier
        // (later steps therefore re-read sections/pages by id).
        [$pageA, $sectionA] = $this->pages->createFormPage($prefix . '_a', openAccess: false);
        [$pageB, $sectionB] = $this->pages->createFormPage($prefix . '_b', openAccess: false);
        $deleteA = $this->pages->createSection($prefix . '_delete_a', 'entry-record-delete');
        $this->pages->linkSectionToPage($pageA, $deleteA, 30);
        foreach ([$pageA, $pageB] as $page) {
            $this->pages->grantGroupAcl($page, $this->subjectGroup());
        }
        if ($sharedA) {
            $this->setSectionField($sectionA, 'own_entries_only', '0');
            $this->setSectionField($deleteA, 'own_entries_only', '0');
        }

        // Table A only exists once something was saved into it; the delete
        // trigger and the editor grant both need its id.
        $this->submit($this->loginAsQaUser(), $pageA, $sectionA, 'seed-a');
        $this->setSectionField($deleteA, 'data_table', (string) $this->tableId($sectionA));

        return [
            'pageA' => $pageA,
            'sectionA' => $sectionA,
            'deleteA' => $deleteA,
            'pageB' => $pageB,
            'sectionB' => $sectionB,
        ];
    }

    /**
     * A subject-group user whose ONLY data grant is update+delete on table A.
     */
    private function editorWithGrantOnTableA(Section $sectionA, string $email): string
    {
        $editor = $this->users->createUser($email, 'QA Binding Editor', groups: [$this->subjectGroup()]);
        $role = $this->grants->createRole('qa_binding_table_a_role');
        $this->grants->assignRoleToUser($editor, $role);
        $this->grants->grantDataTableAccess(
            $role,
            $this->tableId($sectionA),
            DataAccessSecurityService::PERMISSION_UPDATE | DataAccessSecurityService::PERMISSION_DELETE,
        );

        return $this->loginAs($email);
    }

    private function submit(string $token, Page $page, Section $section, string $value): int
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
    private function update(string $token, Page $page, Section $section, int $recordId, string $value): array
    {
        // These tests are about WHICH record a section may touch, so the request carries the version the record has:
        // a request without a version is refused before it is looked at any further (see FormRecordVersionTest).
        return $this->jsonRequest('PUT', self::UPDATE, [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $section->getId(),
            'form_data' => ['qa_answer' => $value],
            'update_based_on' => ['record_id' => $recordId],
            'expected_version' => $this->versionOf($recordId),
        ], $token);
    }

    /**
     * @return array<string, mixed>
     */
    private function delete(string $token, Page $page, Section $section, int $recordId): array
    {
        return $this->jsonRequest('DELETE', self::DELETE, [
            'record_id' => $recordId,
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $section->getId(),
            'expected_version' => $this->versionOf($recordId),
        ], $token);
    }

    private function versionOf(int $recordId): int
    {
        return self::coerceInt($this->em->getConnection()->fetchOne('SELECT version FROM data_rows WHERE id = :id', ['id' => $recordId]));
    }

    private function cellValue(int $recordId, string $fieldKey): ?string
    {
        $value = $this->em->getConnection()->fetchOne(
            'SELECT c.value FROM data_cells c INNER JOIN data_cols col ON col.id = c.id_data_cols '
            . 'WHERE c.id_data_rows = :row AND col.field_key = :key AND c.id_languages = 1',
            ['row' => $recordId, 'key' => $fieldKey],
        );

        return $value === false ? null : self::coerceString($value);
    }

    private function isDeleted(int $recordId): bool
    {
        // Re-read from the database: the ORM identity map may hold a stale row.
        $deletedId = $this->service(LookupService::class)
            ->getLookupIdByValue('actionTriggerTypes', LookupService::ACTION_TRIGGER_TYPES_DELETED);
        $current = $this->em->getConnection()->fetchOne(
            'SELECT id_action_trigger_types FROM data_rows WHERE id = :id',
            ['id' => $recordId],
        );
        self::assertNotFalse($current, 'The record row must still exist (deletion is a soft delete).');

        return self::coerceInt($current) === $deletedId;
    }

    private function tableId(Section $section): int
    {
        $table = $this->em->getRepository(DataTable::class)->findOneBy(['name' => (string) $section->getId()]);
        self::assertInstanceOf(DataTable::class, $table, 'The submission must have created the data table.');

        return (int) $table->getId();
    }

    private function freshPage(Page $page): Page
    {
        $managed = $this->em->getRepository(Page::class)->find($page->getId());
        self::assertInstanceOf(Page::class, $managed);

        return $managed;
    }

    private function freshSection(Section $section): Section
    {
        $managed = $this->em->getRepository(Section::class)->find($section->getId());
        self::assertInstanceOf(Section::class, $managed);

        return $managed;
    }

    private function setSectionField(Section $section, string $fieldName, string $content): void
    {
        $field = $this->em->getRepository(Field::class)->findOneBy(['name' => $fieldName]);
        self::assertInstanceOf(Field::class, $field, sprintf('Missing seeded field "%s".', $fieldName));
        $language = $this->em->getRepository(Language::class)->find(1);
        self::assertInstanceOf(Language::class, $language);

        $translation = new SectionsFieldsTranslation();
        $translation->setSection($this->freshSection($section));
        $translation->setField($field);
        $translation->setLanguage($language);
        $translation->setContent($content);
        $this->em->persist($translation);
        $this->em->flush();
    }

    private function subjectGroup(): Group
    {
        $group = $this->em->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        self::assertInstanceOf(Group::class, $group, 'The seeded "subject" group must exist.');

        return $group;
    }
}
