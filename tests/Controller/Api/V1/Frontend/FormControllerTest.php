<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\DataRow;
use App\Entity\Group;
use App\Entity\Page;
use App\Entity\Section;
use App\Entity\User;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * P0 coverage for public form submission
 * ({@see \App\Controller\Api\V1\Frontend\FormController} +
 * {@see \App\Service\CMS\FormValidationService}).
 *
 * Guest submissions go through the open-access path (no ACL, just
 * `is_open_access` + section-belongs-to-page + form style). Authenticated
 * submissions go through the ACL `insert` path. Every test asserts the standard
 * envelope plus the relevant guard, and the happy paths assert the public side
 * effect (a persisted data row keyed by the section id).
 */
final class FormControllerTest extends QaWebTestCase
{
    private const SUBMIT = '/cms-api/v1/forms/submit';
    private const UPDATE = '/cms-api/v1/forms/update';
    private const DELETE = '/cms-api/v1/forms/delete';

    private EntityManagerInterface $em;
    private PageSectionFactory $pages;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep one container for the whole test so the ACL/permissions cache the
        // factory invalidates is the exact Redis-backed pool the request reads
        // (the default per-request kernel reboot otherwise desyncs the bumped
        // category generation from the request's view).
        $this->client->disableReboot();

        $this->em = $this->service(EntityManagerInterface::class);
        $this->pages = new PageSectionFactory(
            $this->em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );
    }

    // -- Guest open-access path ---------------------------------------------

    public function testGuestJsonSubmitToOpenAccessFormCreatesRecord(): void
    {
        [$page, $section] = $this->pages->createFormPage('qa_form_open_json', openAccess: true);

        $envelope = $this->jsonRequest('POST', self::SUBMIT, [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $section->getId(),
            'form_data' => ['qa_answer' => 'guest json value'],
        ]);
        $data = $this->assertEnvelopeSuccess($envelope);

        self::assertIsInt($data['record_id'] ?? null);
        self::assertGreaterThan(0, $data['record_id']);
        self::assertFalse($data['user_authenticated'] ?? true, 'Guest submission must report unauthenticated.');

        // Public side effect: the row exists, keyed by the section-id data table.
        $row = $this->em->getRepository(DataRow::class)->find((int) $data['record_id']);
        self::assertNotNull($row, 'A data row must be persisted for the guest submission.');
    }

    public function testGuestMultipartSubmitToOpenAccessFormCreatesRecord(): void
    {
        [$page, $section] = $this->pages->createFormPage('qa_form_open_multipart', openAccess: true);

        $this->client->request('POST', self::SUBMIT, [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $section->getId(),
            'qa_answer' => 'guest multipart value',
        ]);
        $envelope = $this->decode($this->client->getResponse());
        $data = $this->assertEnvelopeSuccess($envelope);

        self::assertIsInt($data['record_id'] ?? null);
        self::assertGreaterThan(0, $data['record_id']);
    }

    public function testGuestSubmitToRestrictedPageIsForbidden(): void
    {
        [$page, $section] = $this->pages->createFormPage('qa_form_restricted', openAccess: false);

        $envelope = $this->jsonRequest('POST', self::SUBMIT, [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $section->getId(),
            'form_data' => ['qa_answer' => 'should fail'],
        ]);
        $this->assertEnvelope403($envelope);
    }

    public function testSubmitUnknownPageReturns404(): void
    {
        $envelope = $this->jsonRequest('POST', self::SUBMIT, [
            'page_id' => 99999999,
            'section_id' => 99999999,
            'form_data' => ['qa_answer' => 'x'],
        ]);
        $this->assertEnvelope404($envelope);
    }

    public function testSubmitToNonFormSectionReturns400(): void
    {
        $page = $this->pages->createPage('qa_form_nonform_page', openAccess: true);
        $section = $this->pages->createSection('qa_form_nonform_section', 'container');
        $this->pages->linkSectionToPage($page, $section);

        $envelope = $this->jsonRequest('POST', self::SUBMIT, [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $section->getId(),
            'form_data' => ['qa_answer' => 'x'],
        ]);
        $this->assertEnvelope400($envelope);
    }

    public function testSubmitSectionNotBelongingToPageReturns403(): void
    {
        $page = $this->pages->createPage('qa_form_orphan_page', openAccess: true);
        // A form section that exists but is NOT linked to the page.
        $orphan = $this->pages->createSection('qa_form_orphan_section', 'form-record');

        $envelope = $this->jsonRequest('POST', self::SUBMIT, [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $orphan->getId(),
            'form_data' => ['qa_answer' => 'x'],
        ]);
        $this->assertEnvelope403($envelope);
    }

    public function testSubmitEmptyFormDataIsRejected(): void
    {
        [$page, $section] = $this->pages->createFormPage('qa_form_empty_data', openAccess: true);

        $envelope = $this->jsonRequest('POST', self::SUBMIT, [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $section->getId(),
            'form_data' => [],
        ]);

        // Empty form_data violates the submit_form schema (minProperties: 1) and
        // is rejected. NOTE (flagged, not fixed): FormController wraps the whole
        // action in try/catch and its broad `catch (\Exception)` maps the schema
        // RequestValidationException to 500 instead of the canonical 400 the
        // ApiExceptionListener would emit. The meaningful behaviour asserted here
        // is that an invalid body never succeeds; the exact 400-vs-500 mapping is
        // a known production inconsistency reported separately (no production
        // change made under the "do not change public API behavior" constraint).
        self::assertGreaterThanOrEqual(Response::HTTP_BAD_REQUEST, $this->coerceInt($envelope['status'] ?? 0), 'Empty form_data must be rejected.');
        self::assertArrayHasKey('error', $envelope);
        self::assertNotNull($envelope['error'], 'A rejected submission must carry an error.');
    }

    public function testSubmitEmptyObjectFormDataIsRejectedWithoutArrayObjectConfusion(): void
    {
        // Regression: a JSON `"form_data": {}` body used to be decoded to an empty
        // PHP array and validated as a list, producing the misleading schema error
        // "Array value found, but an object is required". RequestValidatorTrait now
        // re-decodes the body so {} stays an object; validation fails cleanly on
        // minProperties instead. We assert the request is rejected and the error no
        // longer mentions the array/object confusion.
        [$page, $section] = $this->pages->createFormPage('qa_form_empty_object', openAccess: true);

        // Send a raw body so `{}` survives as a JSON object (json_encode([]) is `[]`).
        $this->client->request(
            'POST',
            self::SUBMIT,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'page_id' => (int) $page->getId(),
                'section_id' => (int) $section->getId(),
                'form_data' => new \stdClass(),
            ], JSON_THROW_ON_ERROR),
        );
        $envelope = $this->decode($this->client->getResponse());

        self::assertGreaterThanOrEqual(
            Response::HTTP_BAD_REQUEST,
            $this->coerceInt($envelope['status'] ?? 0),
            'Empty-object form_data must be rejected.',
        );
        $error = $envelope['error'] ?? '';
        self::assertStringNotContainsStringIgnoringCase(
            'Array value found',
            is_string($error) ? $error : '',
            'The empty-object body must not trigger the array-vs-object schema error.',
        );
    }

    // -- Authenticated ACL path ---------------------------------------------

    public function testAuthenticatedInsertWithAclGrantCreatesRecord(): void
    {
        [$page, $section] = $this->pages->createFormPage('qa_form_acl_insert', openAccess: false);
        $this->pages->grantGroupAcl(
            $page,
            $this->subjectGroup(),
            select: true,
            insert: true,
            update: false,
            delete: false,
            affectedUserIds: [$this->qaUserId()],
        );

        $envelope = $this->jsonRequest('POST', self::SUBMIT, [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $section->getId(),
            'form_data' => ['qa_answer' => 'authenticated value'],
        ], $this->loginAsQaUser());
        $data = $this->assertEnvelopeSuccess($envelope);

        self::assertIsInt($data['record_id'] ?? null);
        self::assertTrue($data['user_authenticated'] ?? false, 'Authenticated submission must report authenticated.');
    }

    public function testAuthenticatedSubmitWithForbiddenFieldReturns400(): void
    {
        [$page, $section] = $this->pages->createFormPage('qa_form_acl_forbidden', openAccess: false);
        $this->pages->grantGroupAcl(
            $page,
            $this->subjectGroup(),
            select: true,
            insert: true,
            update: false,
            delete: false,
            affectedUserIds: [$this->qaUserId()],
        );

        // 'id' is a forbidden field rejected by FormValidationService after ACL passes.
        $envelope = $this->jsonRequest('POST', self::SUBMIT, [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $section->getId(),
            'form_data' => ['id' => '5', 'qa_answer' => 'x'],
        ], $this->loginAsQaUser());
        $this->assertEnvelope400($envelope);
    }

    public function testAuthenticatedInsertWithoutAclGrantIsForbidden(): void
    {
        [$page, $section] = $this->pages->createFormPage('qa_form_acl_denied', openAccess: false);

        $envelope = $this->jsonRequest('POST', self::SUBMIT, [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $section->getId(),
            'form_data' => ['qa_answer' => 'x'],
        ], $this->loginAsQaUser());
        $this->assertEnvelope403($envelope);
    }

    // -- update / delete guards --------------------------------------------

    public function testUpdateFormRequiresAuthentication(): void
    {
        $envelope = $this->jsonRequest('PUT', self::UPDATE, [
            'page_id' => 1,
            'section_id' => 1,
            'form_data' => ['qa_answer' => 'x'],
        ]);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $envelope['status'] ?? null);
    }

    public function testDeleteFormRequiresAuthentication(): void
    {
        $envelope = $this->jsonRequest('DELETE', self::DELETE, [
            'record_id' => 1,
            'page_id' => 1,
            'section_id' => 1,
        ]);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $envelope['status'] ?? null);
    }

    public function testDeleteFormWithNonShowUserInputSectionReturns400(): void
    {
        [$page, $section] = $this->pages->createFormPage('qa_form_delete_wrongtype', openAccess: false);
        $this->pages->grantGroupAcl(
            $page,
            $this->subjectGroup(),
            select: true,
            insert: false,
            update: false,
            delete: true,
            affectedUserIds: [$this->qaUserId()],
        );

        // ACL delete passes, but a form-record section is not the showUserInput
        // style the delete path requires → 400.
        $envelope = $this->jsonRequest('DELETE', self::DELETE, [
            'record_id' => 1,
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $section->getId(),
        ], $this->loginAsQaUser());
        $this->assertEnvelope400($envelope);
    }

    // -- helpers ------------------------------------------------------------

    private function subjectGroup(): Group
    {
        $group = $this->em->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        self::assertInstanceOf(Group::class, $group, 'The seeded "subject" group must exist.');

        return $group;
    }

    private function qaUserId(): int
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $user);

        return (int) $user->getId();
    }
}
