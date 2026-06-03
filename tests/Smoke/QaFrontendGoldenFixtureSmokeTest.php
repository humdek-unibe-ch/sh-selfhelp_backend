<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Tests\Smoke;

use App\DataFixtures\Test\QaBaselineFixture;
use App\DataFixtures\Test\QaFrontendGoldenFixture;
use App\Entity\Group;
use App\Entity\Page;
use App\Entity\PageAclGroup;
use App\Entity\Section;
use App\Entity\SectionsFieldsTranslation;
use App\Entity\SectionsHierarchy;
use App\Entity\User;
use App\Repository\SectionRepository;
use App\Service\ACL\ACLService;
use App\Tests\Support\QaKernelTestCase;
use PHPUnit\Framework\Attributes\Group as TestGroup;

/**
 * Smoke proof that `composer test:reset-db` seeds the canonical QA frontend
 * golden fixture ({@see QaFrontendGoldenFixture}) the frontend golden E2E
 * drives. If this passes on a fresh `test:reset-db`, a developer can run
 * `npm run test:golden` in the frontend with zero manual QA exports.
 *
 * Asserts the public, domain-visible contract the spec depends on
 * (Testing Rule 17 — public effects first):
 *   - the `qa-feedback` page exists at `/qa-feedback` and is ACL-gated
 *     (not open access);
 *   - the stored procedure that feeds the frontend renderer returns a
 *     `form-log` section with a `text-input` child;
 *   - that child's CMS `name` is `qa_message` (the DOM `name` the spec fills);
 *   - the form carries a non-empty `alert_success` (what makes the spec's
 *     "Success" alert appear);
 *   - the seeded `subject` group — and therefore qa.user@selfhelp.test —
 *     can both view (select) and submit (insert) the page.
 *
 * @group smoke
 */
#[TestGroup('smoke')]
final class QaFrontendGoldenFixtureSmokeTest extends QaKernelTestCase
{
    public function testResetDbSeedsQaFeedbackPageFormFieldAndSubjectAcl(): void
    {
        $page = $this->em->getRepository(Page::class)
            ->findOneBy(['keyword' => QaFrontendGoldenFixture::QA_FORM_PAGE_KEYWORD]);
        self::assertInstanceOf(
            Page::class,
            $page,
            'qa-feedback page missing. The QA frontend golden fixture was not seeded — run: composer test:reset-db'
        );
        self::assertSame(QaFrontendGoldenFixture::QA_FORM_PAGE_URL, $page->getUrl());
        self::assertFalse(
            (bool) $page->isOpenAccess(),
            'qa-feedback must be ACL-gated so the golden spec exercises the authenticated form path.'
        );
        $pageId = (int) $page->getId();

        // The renderer (and FormValidationService::validateSectionBelongsToPage)
        // both read this stored procedure, so assert the form + child input
        // surface through it exactly as the frontend will see them.
        $flatSections = $this->service(SectionRepository::class)->fetchSectionsHierarchicalByPageId($pageId);
        $styleNames = [];
        foreach ($flatSections as $row) {
            if (isset($row['style_name']) && is_string($row['style_name'])) {
                $styleNames[] = $row['style_name'];
            }
        }
        self::assertContains('form-log', $styleNames, 'qa-feedback must carry a form-log section.');
        self::assertContains('text-input', $styleNames, 'qa-feedback must carry a text-input child for qa_message.');

        // Structural assertions on the seeded graph.
        $formSection = $this->em->getRepository(Section::class)
            ->findOneBy(['name' => QaFrontendGoldenFixture::QA_FORM_SECTION_NAME]);
        self::assertInstanceOf(Section::class, $formSection);
        self::assertSame('form-log', $formSection->getStyle()?->getName());

        $inputSection = $this->em->getRepository(Section::class)
            ->findOneBy(['name' => QaFrontendGoldenFixture::QA_FORM_INPUT_SECTION_NAME]);
        self::assertInstanceOf(Section::class, $inputSection);
        self::assertSame('text-input', $inputSection->getStyle()?->getName());

        // The input is a child of the form section (rel_sections_hierarchy).
        $hierarchy = $this->em->getRepository(SectionsHierarchy::class)
            ->findOneBy(['parentSection' => $formSection, 'childSection' => $inputSection]);
        self::assertInstanceOf(SectionsHierarchy::class, $hierarchy, 'qa_message input must be a child of the form section.');

        // The child's CMS `name` field == qa_message (the DOM name the spec fills).
        $nameField = $this->fieldContent($inputSection, 'name');
        self::assertSame(
            QaFrontendGoldenFixture::QA_FORM_FIELD_NAME,
            $nameField,
            'The input section name field must equal qa_message.'
        );

        // The form's success alert message must be present (per content locale)
        // — without it the frontend FormStyle never renders the "Success" alert.
        self::assertNotSame('', (string) $this->fieldContent($formSection, 'alert_success'),
            'The form must seed a non-empty alert_success so the success alert renders.');

        // The subject group ACL row grants select + insert.
        $subjectGroup = $this->em->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        self::assertInstanceOf(Group::class, $subjectGroup);
        $acl = $this->em->getRepository(PageAclGroup::class)
            ->findOneBy(['group' => $subjectGroup, 'page' => $page]);
        self::assertInstanceOf(PageAclGroup::class, $acl, 'subject group must have an ACL row on qa-feedback.');
        self::assertTrue($acl->getAclSelect(), 'subject must be able to view qa-feedback.');
        self::assertTrue($acl->getAclInsert(), 'subject must be able to submit qa-feedback.');

        // End-to-end permission: qa.user (subject member) resolves view + submit.
        $qaUser = $this->em->getRepository(User::class)
            ->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $qaUser);
        $aclService = $this->service(ACLService::class);
        self::assertTrue(
            $aclService->hasAccess((int) $qaUser->getId(), $pageId, 'select'),
            'qa.user must resolve select access to qa-feedback through the subject group.'
        );
        self::assertTrue(
            $aclService->hasAccess((int) $qaUser->getId(), $pageId, 'insert'),
            'qa.user must resolve insert access to qa-feedback through the subject group.'
        );
    }

    /**
     * Read the first stored content for a section field by field name (any
     * language), or '' when the field has no translation row.
     */
    private function fieldContent(Section $section, string $fieldName): string
    {
        $rows = $this->em->getRepository(SectionsFieldsTranslation::class)
            ->createQueryBuilder('sft')
            ->select('sft.content')
            ->leftJoin('sft.field', 'f')
            ->where('sft.section = :section')
            ->andWhere('f.name = :fieldName')
            ->setParameter('section', $section)
            ->setParameter('fieldName', $fieldName)
            ->getQuery()
            ->getScalarResult();

        foreach ($rows as $row) {
            if (is_array($row) && isset($row['content']) && is_string($row['content'])) {
                return $row['content'];
            }
        }

        return '';
    }
}
