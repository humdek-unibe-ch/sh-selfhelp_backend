<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Golden;

use App\Entity\Group;
use App\Entity\Page;
use App\Entity\PageAclGroup;
use App\Entity\Style;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\HttpFoundation\Response;

/**
 * Golden form-data management workflow:
 *
 *   admin creates a qa_ page + a form-record section through the admin API (the
 *   data table is auto-created and admin CRUD auto-granted) -> the subject group
 *   is granted ACL insert -> a subject user submits the form (POST /forms/submit)
 *   and then updates the SAME record (PUT /forms/update with
 *   update_based_on.record_id) -> admin reads the rows through /admin/data,
 *   warms the cache, deletes the record (soft-delete) -> the default read hides
 *   the row while exclude_deleted=false still shows it.
 *
 * Asserts the domain-visible effects (plan §13/§16): the auto-created data table
 * is reachable by section id, update targets the same record id (one row, not a
 * second insert), the soft-delete is invalidated from the data-table cache
 * (default read drops to zero) yet remains retrievable with
 * exclude_deleted=false. Cache invalidation is asserted through this workflow
 * (plan: "assert cache invalidation through the data workflow"). All data is
 * qa_-prefixed and rolled back by the DAMA transaction.
 */
#[TestGroup('golden')]
final class FormDataManagementWorkflowTest extends QaWebTestCase
{
    private const KEYWORD = 'qa_form_data_workflow';
    private const URL = '/qa-form-data-workflow';
    private const FIELD = 'qa_field';

    private EntityManagerInterface $em;
    private PageSectionFactory $pages;

    protected function setUp(): void
    {
        parent::setUp();

        // Shared container so the ACL grant the factory invalidates is the pool
        // the submit request reads (proven PublicPageRenderingWorkflowTest pattern).
        $this->client->disableReboot();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->pages = new PageSectionFactory(
            $this->em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );
    }

    public function testSubmitUpdateReadSoftDeleteFormRecord(): void
    {
        $admin = $this->loginAsQaAdmin();

        // 1. Create the page, then a form-record section on it. Creating a
        //    form-style section auto-creates the data table (named after the
        //    section id) and the listener auto-grants the admin role CRUD.
        $pageData = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
                'keyword' => self::KEYWORD,
                'pageAccessTypeCode' => 'web',
                'url' => self::URL,
            ], $admin),
            Response::HTTP_CREATED
        );
        self::assertIsInt($pageData['id'] ?? null);
        $pageId = (int) $pageData['id'];

        $sectionData = $this->assertEnvelopeSuccess(
            $this->jsonRequest(
                'POST',
                sprintf('/cms-api/v1/admin/pages/%d/sections/create', $pageId),
                ['styleId' => $this->styleId('form-record'), 'position' => 10, 'name' => 'qa_form_section'],
                $admin
            ),
            Response::HTTP_CREATED
        );
        self::assertIsInt($sectionData['id'] ?? null);
        $sectionId = (int) $sectionData['id'];

        // 2. The page-create flow already granted the subject group ACL *select*
        //    only. Form submit + update both check the page 'insert' ACL, so
        //    upgrade the existing subject row in place — a fresh insert would
        //    collide with the (subject, page) row created above. Then drop the
        //    page-scoped caches so the submit request observes the upgrade.
        $page = $this->em->getRepository(Page::class)->find($pageId);
        self::assertInstanceOf(Page::class, $page);
        $subjectAcl = $this->em->getRepository(PageAclGroup::class)->findOneBy([
            'group' => $this->subjectGroup(),
            'page' => $page,
        ]);
        self::assertInstanceOf(PageAclGroup::class, $subjectAcl, 'createPage must seed a base subject ACL row.');
        $subjectAcl->setAclInsert(true)->setAclUpdate(true);
        $this->em->flush();
        $this->pages->invalidatePageScopedCaches();

        $user = $this->loginAsQaUser();

        // 3. Submit the form as the subject user.
        $submit = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
                'page_id' => $pageId,
                'section_id' => $sectionId,
                'form_data' => [self::FIELD => 'qa_value_1'],
            ], $user)
        );
        self::assertIsInt($submit['record_id'] ?? null, 'Submit must return an integer record id.');
        $recordId = (int) $submit['record_id'];

        // 4. Update the SAME record via update_based_on.record_id.
        $update = $this->assertEnvelopeSuccess(
            $this->jsonRequest('PUT', '/cms-api/v1/forms/update', [
                'page_id' => $pageId,
                'section_id' => $sectionId,
                'form_data' => [self::FIELD => 'qa_value_2'],
                'update_based_on' => ['record_id' => $recordId],
            ], $user)
        );
        self::assertSame($recordId, $update['record_id'] ?? null, 'Update must target the same record id (no second insert).');

        // 5. Admin reads the rows (table name = section id). One row, updated value.
        $rowsAfterUpdate = $this->readRows($sectionId, $admin);
        self::assertCount(1, $rowsAfterUpdate, 'Submit + update of the same record must leave exactly one row.');
        self::assertTrue($this->rowsContain($rowsAfterUpdate, 'qa_value_2'), 'Row must carry the updated value.');
        self::assertFalse($this->rowsContain($rowsAfterUpdate, 'qa_value_1'), 'Updated row must no longer carry the original value.');

        // 6. Warm the cache: an identical read is stable.
        self::assertCount(1, $this->readRows($sectionId, $admin), 'Warm read must be stable.');

        // 7. Delete the record (soft-delete) across all entries (admin scope).
        $deleted = $this->assertEnvelopeSuccess(
            $this->jsonRequest(
                'DELETE',
                sprintf('/cms-api/v1/admin/data/records/%d?table_name=%d&own_entries_only=false', $recordId, $sectionId),
                null,
                $admin
            )
        );
        self::assertTrue($deleted['deleted'] ?? null, 'Delete response must report deleted=true.');

        // 8. Default read hides the soft-deleted row (cache was invalidated).
        self::assertCount(0, $this->readRows($sectionId, $admin), 'Default read must hide the soft-deleted row.');

        // 9. exclude_deleted=false still shows it (soft-delete, not physical).
        $withDeleted = $this->readRows($sectionId, $admin, excludeDeleted: false);
        self::assertCount(1, $withDeleted, 'exclude_deleted=false must reveal the soft-deleted row.');
        self::assertTrue($this->rowsContain($withDeleted, 'qa_value_2'), 'Soft-deleted row keeps its last value.');
    }

    // -- helpers ------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function readRows(int $sectionId, string $token, bool $excludeDeleted = true): array
    {
        $envelope = $this->assertEnvelopeSuccess(
            $this->jsonRequest(
                'GET',
                sprintf('/cms-api/v1/admin/data?table_name=%d&exclude_deleted=%s', $sectionId, $excludeDeleted ? 'true' : 'false'),
                null,
                $token
            )
        );

        $rows = [];
        foreach ($this->asList($envelope['rows'] ?? null) as $row) {
            $rows[] = $this->asArray($row);
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function rowsContain(array $rows, string $needle): bool
    {
        foreach ($rows as $row) {
            if (str_contains((string) json_encode($row), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function styleId(string $name): int
    {
        $style = $this->em->getRepository(Style::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Style::class, $style, sprintf('Seeded style "%s" must exist.', $name));

        return (int) $style->getId();
    }

    private function subjectGroup(): Group
    {
        $group = $this->em->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        self::assertInstanceOf(Group::class, $group, 'The seeded "subject" group must exist.');

        return $group;
    }
}
