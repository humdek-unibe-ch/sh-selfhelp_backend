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
use App\Entity\SectionsHierarchy;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\Admin\PageVersionService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;

/**
 * F05: a condition hides a section from the page, so it must also refuse the
 * write. A hidden form used to accept submit, update and delete as long as the
 * caller knew the section id.
 *
 * A write is allowed only when the section is visible in at least one place of
 * the page tree: its own condition and the condition of every ancestor on the
 * way to the page must hold for the current visitor. A condition that cannot be
 * evaluated (invalid JSON) fails closed, like on the render path.
 */
#[PhpUnitGroup('security')]
final class FormConditionRecheckTest extends QaWebTestCase
{
    private const SUBMIT = '/cms-api/v1/forms/submit';
    private const UPDATE = '/cms-api/v1/forms/update';
    private const DELETE = '/cms-api/v1/forms/delete';

    private const HIDDEN = '{"==":[1,2]}';
    private const VISIBLE = '{"==":[1,1]}';

    private EntityManagerInterface $em;
    private PageSectionFactory $pages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->em = $this->service(EntityManagerInterface::class);
        $this->pages = new PageSectionFactory(
            $this->em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );
    }

    public function testSubmitToAHiddenFormIsRefusedAndStoresNothing(): void
    {
        [$page, $form] = $this->pages->createFormPage('qa_cond_hidden', openAccess: false);
        $this->grantSubjects($page);
        $this->setCondition($form, self::HIDDEN);

        $envelope = $this->submit($this->loginAsQaUser(), $page, $form);

        $this->assertEnvelope403($envelope);
        self::assertSame(0, $this->rowCount($form), 'A refused write must not store anything.');
    }

    public function testSubmitToAFormInsideAHiddenAncestorIsRefused(): void
    {
        $page = $this->pages->createPage('qa_cond_ancestor', openAccess: false);
        $parent = $this->pages->createSection('qa_cond_ancestor_parent', 'container');
        $form = $this->pages->createSection('qa_cond_ancestor_form', 'form-record');
        $this->pages->linkSectionToPage($page, $parent, 10);
        $this->nest($parent, $form);
        $this->grantSubjects($page);
        $this->setCondition($parent, self::HIDDEN);

        $envelope = $this->submit($this->loginAsQaUser(), $page, $form);

        $this->assertEnvelope403($envelope);
        self::assertSame(0, $this->rowCount($form));
    }

    public function testGuestSubmitToAHiddenOpenAccessFormIsRefused(): void
    {
        [$page, $form] = $this->pages->createFormPage('qa_cond_guest', openAccess: true);
        $this->setCondition($form, self::HIDDEN);

        $envelope = $this->submit(null, $page, $form);

        $this->assertEnvelope403($envelope);
        self::assertSame(0, $this->rowCount($form));
    }

    public function testAConditionThatCannotBeEvaluatedFailsClosed(): void
    {
        [$page, $form] = $this->pages->createFormPage('qa_cond_invalid', openAccess: false);
        $this->grantSubjects($page);
        $this->setCondition($form, '{ this is not json');

        $envelope = $this->submit($this->loginAsQaUser(), $page, $form);

        $this->assertEnvelope403($envelope);
        self::assertSame(0, $this->rowCount($form));
    }

    public function testUpdateAndDeleteAreRefusedOnceTheFormBecomesHidden(): void
    {
        [$page, $form] = $this->pages->createFormPage('qa_cond_flip', openAccess: false);
        $delete = $this->pages->createSection('qa_cond_flip_delete', 'entry-record-delete');
        $this->pages->linkSectionToPage($page, $delete, 20);
        $this->grantSubjects($page);

        $token = $this->loginAsQaUser();
        $recordId = $this->assertEnvelopeSuccess($this->submit($token, $page, $form))['record_id'] ?? null;
        self::assertIsInt($recordId);
        $this->bindDeleteToFormTable($delete, $form);

        // The author now hides both the form and the delete trigger.
        $this->setCondition($form, self::HIDDEN);
        $this->setCondition($delete, self::HIDDEN);

        $update = $this->jsonRequest('PUT', self::UPDATE, [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $form->getId(),
            'form_data' => ['qa_answer' => 'changed while hidden'],
            'update_based_on' => ['record_id' => $recordId],
        ], $token);
        $this->assertEnvelope403($update);

        $deletion = $this->jsonRequest('DELETE', self::DELETE, [
            'record_id' => $recordId,
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $delete->getId(),
        ], $token);
        $this->assertEnvelope403($deletion);

        self::assertSame('first', $this->cellValue($recordId), 'The hidden form must not be updated.');
        self::assertFalse($this->isDeleted($recordId), 'The hidden delete trigger must not delete.');
    }

    public function testAVisibleConditionStillAllowsTheWrite(): void
    {
        [$page, $form] = $this->pages->createFormPage('qa_cond_visible', openAccess: false);
        $this->grantSubjects($page);
        $this->setCondition($form, self::VISIBLE);

        $envelope = $this->submit($this->loginAsQaUser(), $page, $form);

        $data = $this->assertEnvelopeSuccess($envelope);
        self::assertIsInt($data['record_id'] ?? null);
    }

    public function testTheConditionOfThePublishedPageDecidesAndADraftEditChangesNothingUntilItIsPublished(): void
    {
        [$page, $form] = $this->pages->createFormPage('qa_cond_published_shows', openAccess: false);
        $this->grantSubjects($page);
        $this->setCondition($form, self::VISIBLE);
        $this->publish($page);
        $this->setCondition($form, self::HIDDEN); // the author hides the form in the draft only
        $participant = $this->loginAsQaUser();
        $editor = $this->loginAsQaAdmin();

        $public = $this->submit($participant, $page, $form);
        $preview = $this->submit($editor, $page, $form, preview: true);

        self::assertIsInt($this->assertEnvelopeSuccess($public)['record_id'] ?? null, 'Visitors keep the published, visible form.');
        $this->assertEnvelope403($preview);
        self::assertSame(403, $this->submit($participant, $page, $form, preview: true)['status'] ?? null, 'A participant cannot preview the draft.');
        self::assertSame(1, $this->rowCount($form), 'Only the public write stored a record.');
    }

    public function testAFormThePublishedPageHidesStaysRefusedWhileOnlyTheDraftShowsIt(): void
    {
        [$page, $form] = $this->pages->createFormPage('qa_cond_published_hides', openAccess: false);
        $this->grantSubjects($page);
        $this->setCondition($form, self::HIDDEN);
        $this->publish($page);
        $this->setCondition($form, self::VISIBLE); // the author shows the form in the draft only
        $participant = $this->loginAsQaUser();
        $editor = $this->loginAsQaAdmin();

        $public = $this->submit($participant, $page, $form);
        $preview = $this->submit($editor, $page, $form, preview: true);

        $this->assertEnvelope403($public);
        self::assertSame(403, $this->submit($participant, $page, $form, preview: true)['status'] ?? null);
        self::assertIsInt($this->assertEnvelopeSuccess($preview)['record_id'] ?? null, 'A preview shows what is about to be published.');
        self::assertSame(1, $this->rowCount($form), 'Only the preview write stored a record.');
    }

    public function testAnAncestorConditionOfThePublishedPageDecidesTheSameWay(): void
    {
        $page = $this->pages->createPage('qa_cond_published_ancestor', openAccess: false);
        $parent = $this->pages->createSection('qa_cond_published_ancestor_parent', 'container');
        $form = $this->pages->createSection('qa_cond_published_ancestor_form', 'form-record');
        $this->pages->linkSectionToPage($page, $parent, 10);
        $this->nest($parent, $form);
        $this->grantSubjects($page);
        $this->setCondition($parent, self::HIDDEN);
        $this->publish($page);
        $this->setCondition($parent, self::VISIBLE);
        $participant = $this->loginAsQaUser();
        $editor = $this->loginAsQaAdmin();

        $this->assertEnvelope403($this->submit($participant, $page, $form));
        self::assertSame(403, $this->submit($participant, $page, $form, preview: true)['status'] ?? null);
        self::assertIsInt($this->assertEnvelopeSuccess($this->submit($editor, $page, $form, preview: true))['record_id'] ?? null);
    }

    // -- helpers ----------------------------------------------------------------

    private function publish(Page $page): void
    {
        $versions = $this->service(PageVersionService::class);
        $version = $versions->createVersion((int) $page->getId());
        $versions->publishVersion((int) $page->getId(), (int) $version->getId());
        $this->pages->invalidatePageScopedCaches();
    }

    /**
     * @return array<string, mixed>
     */
    private function submit(?string $token, Page $page, Section $form, bool $preview = false): array
    {
        return $this->jsonRequest('POST', self::SUBMIT, [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $form->getId(),
            'form_data' => ['qa_answer' => 'first'],
        ] + ($preview ? ['preview' => true] : []), $token);
    }

    private function grantSubjects(Page $page): void
    {
        $group = $this->em->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        self::assertInstanceOf(Group::class, $group);
        $this->pages->grantGroupAcl($page, $group, select: true, insert: true, update: true, delete: true);
    }

    private function setCondition(Section $section, string $condition): void
    {
        $managed = $this->em->getRepository(Section::class)->find($section->getId());
        self::assertInstanceOf(Section::class, $managed);
        $managed->setCondition($condition);
        $this->em->flush();
        // The page tree (with each section's condition) is served from a cached hierarchy.
        $this->pages->invalidatePageScopedCaches();
    }

    private function nest(Section $parent, Section $child): void
    {
        $link = new SectionsHierarchy();
        $link->setParentSection($parent);
        $link->setChildSection($child);
        $link->setPosition(10);
        $this->em->persist($link);
        $this->em->flush();
        $this->pages->invalidatePageScopedCaches();
    }

    private function bindDeleteToFormTable(Section $delete, Section $form): void
    {
        $table = $this->em->getRepository(DataTable::class)->findOneBy(['name' => (string) $form->getId()]);
        self::assertInstanceOf(DataTable::class, $table);
        $field = $this->em->getRepository(Field::class)->findOneBy(['name' => 'data_table']);
        self::assertInstanceOf(Field::class, $field);
        $language = $this->em->getRepository(Language::class)->find(1);
        self::assertInstanceOf(Language::class, $language);
        $managed = $this->em->getRepository(Section::class)->find($delete->getId());
        self::assertInstanceOf(Section::class, $managed);

        $translation = new SectionsFieldsTranslation();
        $translation->setSection($managed);
        $translation->setField($field);
        $translation->setLanguage($language);
        $translation->setContent((string) $table->getId());
        $this->em->persist($translation);
        $this->em->flush();
    }

    private function rowCount(Section $form): int
    {
        return self::coerceInt($this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM data_rows r INNER JOIN data_tables t ON t.id = r.id_data_tables WHERE t.name = :name',
            ['name' => (string) $form->getId()],
        ));
    }

    private function cellValue(int $recordId): ?string
    {
        $value = $this->em->getConnection()->fetchOne(
            'SELECT c.value FROM data_cells c INNER JOIN data_cols col ON col.id = c.id_data_cols '
            . 'WHERE c.id_data_rows = :row AND col.field_key = \'qa_answer\' AND c.id_languages = 1',
            ['row' => $recordId],
        );

        return $value === false ? null : self::coerceString($value);
    }

    private function isDeleted(int $recordId): bool
    {
        $deletedId = $this->service(LookupService::class)
            ->getLookupIdByValue('actionTriggerTypes', LookupService::ACTION_TRIGGER_TYPES_DELETED);
        $current = $this->em->getConnection()->fetchOne(
            'SELECT id_action_trigger_types FROM data_rows WHERE id = :id',
            ['id' => $recordId],
        );

        return self::coerceInt($current) === $deletedId;
    }
}
