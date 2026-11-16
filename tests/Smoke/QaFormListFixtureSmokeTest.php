<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\DataFixtures\Test\QaBaselineFixture;
use App\DataFixtures\Test\QaFormListFixture;
use App\Entity\Group;
use App\Entity\Page;
use App\Entity\PageAclGroup;
use App\Entity\PageType;
use App\Entity\Section;
use App\Entity\User;
use App\Service\ACL\ACLService;
use App\Service\CMS\FormList\FormListConfigReader;
use App\Routing\PageRouteResolverService;
use App\Service\CMS\FormList\FormListConfigValidator;
use App\Service\Core\LookupService;
use App\Tests\Support\QaKernelTestCase;
use PHPUnit\Framework\Attributes\Group as TestGroup;

/**
 * Smoke proof that `composer test:reset-db` seeds the list fixture
 * ({@see QaFormListFixture}) the frontend spec `e2e/golden/form-list.spec.ts` and
 * the mobile flow `e2e/golden/form-list.yaml` drive: both lists of the private
 * page and the public data view exist, their configuration passes the same
 * author-time check a CMS save runs, and the subject group may use the private
 * page while guests only reach the public one.
 *
 * @group smoke
 */
#[TestGroup('smoke')]
final class QaFormListFixtureSmokeTest extends QaKernelTestCase
{
    public function testTheListsAreSeededAndTheirConfigurationIsValid(): void
    {
        $validator = $this->service(FormListConfigValidator::class);
        $reader = $this->service(FormListConfigReader::class);

        $expected = [
            QaFormListFixture::ENTRIES_LIST_SECTION_NAME => 'entry-form-list',
            QaFormListFixture::TASKS_LIST_SECTION_NAME => 'entry-form-list',
            QaFormListFixture::EVENTS_VIEW_SECTION_NAME => 'data-view',
            QaFormListFixture::CARDS_VIEW_SECTION_NAME => 'data-view',
            QaFormListFixture::WIDE_LIST_SECTION_NAME => 'entry-form-list',
            QaFormListFixture::WIDE_PAGE_LIST_SECTION_NAME => 'entry-form-list',
        ];
        foreach ($expected as $name => $style) {
            $section = $this->em->getRepository(Section::class)->findOneBy(['name' => $name]);
            self::assertInstanceOf(Section::class, $section, $name . ' is missing. The list fixture was not seeded - run: composer test:reset-db');
            self::assertSame($style, $section->getStyle()?->getName());

            $sectionId = (int) $section->getId();
            self::assertSame(
                [],
                $validator->problems($sectionId, $style, $reader->rawFields($sectionId)),
                $name . ' must pass the author-time check of a CMS save.'
            );
        }
    }

    public function testThePrivatePageIsForTheSubjectGroupAndThePublicPageIsOpen(): void
    {
        $entries = $this->em->getRepository(Page::class)->findOneBy(['keyword' => QaFormListFixture::ENTRIES_PAGE_KEYWORD]);
        $events = $this->em->getRepository(Page::class)->findOneBy(['keyword' => QaFormListFixture::EVENTS_PAGE_KEYWORD]);
        self::assertInstanceOf(Page::class, $entries);
        self::assertInstanceOf(Page::class, $events);
        self::assertSame(QaFormListFixture::ENTRIES_PAGE_URL, $entries->getUrl());
        self::assertSame(QaFormListFixture::EVENTS_PAGE_URL, $events->getUrl());
        $resolver = $this->service(PageRouteResolverService::class);
        self::assertSame(QaFormListFixture::ENTRIES_PAGE_KEYWORD, $resolver->resolve(QaFormListFixture::ENTRIES_PAGE_URL)['keyword'] ?? null, 'The entries page must be reachable by its public path.');
        self::assertSame(QaFormListFixture::EVENTS_PAGE_KEYWORD, $resolver->resolve(QaFormListFixture::EVENTS_PAGE_URL)['keyword'] ?? null, 'The events page must be reachable by its public path.');
        self::assertFalse((bool) $entries->isOpenAccess(), 'The page with the participant lists must be private.');
        self::assertTrue((bool) $events->isOpenAccess(), 'The data view page must be public.');
        foreach ([$entries, $events] as $page) {
            self::assertSame(LookupService::PAGE_ACCESS_TYPES_MOBILE_AND_WEB, $page->getPageAccessType()?->getLookupCode(), 'The QA list pages must be served to the web frontend and to the mobile app.');
            self::assertContains($page->getPageType()?->getName(), PageType::PUBLIC_NAVIGABLE_PAGE_TYPES, 'The QA list pages must be content pages, or the page tree and the menus leave them out.');
        }

        $subject = $this->em->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        self::assertInstanceOf(Group::class, $subject);
        $acl = $this->em->getRepository(PageAclGroup::class)->findOneBy(['group' => $subject, 'page' => $entries]);
        self::assertInstanceOf(PageAclGroup::class, $acl, 'The subject group must have an ACL row on the entries page.');
        self::assertTrue($acl->getAclSelect() && $acl->getAclInsert() && $acl->getAclUpdate() && $acl->getAclDelete());

        $aclService = $this->service(ACLService::class);
        foreach ([QaBaselineFixture::QA_USER_EMAIL, QaBaselineFixture::QA_EDITOR_EMAIL] as $email) {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
            self::assertInstanceOf(User::class, $user);
            foreach (['select', 'insert', 'update', 'delete'] as $mode) {
                self::assertTrue($aclService->hasAccess((int) $user->getId(), (int) $entries->getId(), $mode), $email . ' must have ' . $mode . ' on the entries page.');
            }
        }
        $guest = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_GUEST_EMAIL]);
        self::assertInstanceOf(User::class, $guest);
        self::assertFalse($aclService->hasAccess((int) $guest->getId(), (int) $entries->getId(), 'select'), 'qa.guest is in no group and must not see the entries.');
    }

    public function testTheWideFormsHoldManyInputsWithVeryLongLabelsAndTheirColumnsAreLabelledLong(): void
    {
        $reader = $this->service(FormListConfigReader::class);
        foreach ([QaFormListFixture::WIDE_LIST_SECTION_NAME, QaFormListFixture::WIDE_PAGE_LIST_SECTION_NAME] as $name) {
            $list = $this->em->getRepository(Section::class)->findOneBy(['name' => $name]);
            self::assertInstanceOf(Section::class, $list, $name);
            $fields = $reader->rawFields((int) $list->getId());

            // Stored once under the property language (id 1): field name => language id => content.
            $columns = json_decode($fields['list_columns'][1] ?? '[]', true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($columns);
            self::assertCount(QaFormListFixture::WIDE_COLUMN_COUNT, $columns, $name . ' lists twelve columns.');
            foreach ($columns as $column) {
                self::assertIsArray($column);
                $label = $column['label'] ?? null;
                self::assertIsString($label);
                self::assertGreaterThan(90, strlen($label), 'A column label is long.');
                self::assertMatchesRegularExpression('/[^\s]{40,}/', $label, 'A column label holds a run of 40 characters without a break.');
            }

            $labels = $this->em->getConnection()->fetchFirstColumn(
                'SELECT t.content FROM rel_sections_hierarchy h
                   INNER JOIN sections form ON form.id = h.id_child_section
                   INNER JOIN rel_sections_hierarchy hi ON hi.id_parent_section = form.id
                   INNER JOIN sections input ON input.id = hi.id_child_section
                   INNER JOIN sections_fields_translation t ON t.id_sections = input.id
                   INNER JOIN fields f ON f.id = t.id_fields AND f.name = \'label\'
                   INNER JOIN languages l ON l.id = t.id_languages AND l.locale = \'en-GB\'
                  WHERE h.id_parent_section = :list',
                ['list' => (int) $list->getId()]
            );
            self::assertCount(QaFormListFixture::WIDE_FIELD_COUNT, $labels, $name . ' holds the inputs of the wide form.');
            foreach ($labels as $label) {
                self::assertIsString($label);
                self::assertGreaterThan(200, strlen($label), 'An input label is a long sentence.');
                self::assertMatchesRegularExpression('/[^\s]{70,}/', $label, 'An input label holds a run of 70 characters without a break.');
            }
        }

        $page = $this->em->getRepository(Page::class)->findOneBy(['keyword' => QaFormListFixture::WIDE_PAGE_KEYWORD]);
        self::assertInstanceOf(Page::class, $page, 'The wide page is seeded.');
        self::assertFalse((bool) $page->isOpenAccess(), 'The wide page is private, like the entries page.');
        $resolver = $this->service(PageRouteResolverService::class);
        self::assertSame(QaFormListFixture::WIDE_PAGE_KEYWORD, $resolver->resolve(QaFormListFixture::WIDE_PAGE_URL)['keyword'] ?? null, 'The wide page must be reachable by its public path.');
    }

    public function testTheEventsAreInTheGenericStore(): void
    {
        $count = $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM data_rows r INNER JOIN data_tables t ON t.id = r.id_data_tables WHERE t.name = :name',
            ['name' => QaFormListFixture::EVENTS_TABLE_NAME]
        );

        self::assertSame(QaFormListFixture::EVENT_COUNT, self::coerceInt($count));
    }
}
