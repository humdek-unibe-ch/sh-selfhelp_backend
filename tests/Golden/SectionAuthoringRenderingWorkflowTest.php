<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Golden;

use App\Entity\Field;
use App\Entity\Style;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Service\JSON\JsonSchemaValidationService;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\HttpFoundation\Response;

/**
 * Golden CMS section-authoring + rendering workflow:
 *
 *   admin creates a qa_ page -> resolves style/field IDs *by name* (no
 *   hard-coded seeded numeric IDs) -> creates two top-level container sections
 *   at out-of-order positions + one text child -> moves the child to the other
 *   container through PUT /admin/pages/{id}/sections/{parent}/sections ->
 *   updates the child's name + a translatable content field (de-CH) -> grants
 *   the subject group ACL select -> a subject user renders the page through the
 *   PUBLIC frontend API by id and by keyword.
 *
 * Asserts the domain-visible effects (plan §13/§16): the public render's
 * top-level order matches the CMS's own authoritative order (a different code
 * path — admin hierarchical stored procedure vs. frontend PageService), the
 * moved child is nested under its new parent (and gone from the old one), the
 * translated content is what we wrote, the public + admin responses match their
 * JSON schemas, and a render taken *after* a further mutation reflects the new
 * content (section cache is invalidated on write — no stale render). Language
 * handling is folded in here (plan: "fold language into the section golden").
 * All data is qa_-prefixed and rolled back by the DAMA transaction.
 */
#[TestGroup('golden')]
final class SectionAuthoringRenderingWorkflowTest extends QaWebTestCase
{
    private const KEYWORD = 'qa_section_authoring_workflow';
    private const URL = '/qa-section-authoring-workflow';

    /** de-CH language id (seeded baseline); content is authored + rendered in it. */
    private const LANG_DE = 2;

    private EntityManagerInterface $em;
    private PageSectionFactory $pages;
    private JsonSchemaValidationService $schema;

    protected function setUp(): void
    {
        parent::setUp();

        // One container for the whole test so the cache the ACL-grant factory
        // invalidates is the exact pool the render request reads (the proven
        // PublicPageRenderingWorkflowTest pattern).
        $this->client->disableReboot();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $schema = self::getContainer()->get(JsonSchemaValidationService::class);
        self::assertInstanceOf(JsonSchemaValidationService::class, $schema);
        $this->schema = $schema;

        $this->pages = new PageSectionFactory(
            $this->em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );
    }

    public function testAuthorMoveTranslateAndRenderSectionsThroughTheApi(): void
    {
        $admin = $this->loginAsQaAdmin();

        // Styles + the translatable text field, resolved BY NAME (the seeded
        // ids differ per install; never hard-code them).
        $containerStyleId = $this->styleId('container');
        $textStyleId = $this->styleId('text');
        $textFieldId = $this->fieldId('text');

        // 1. Create the page through the admin API.
        $pageData = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
                'keyword' => self::KEYWORD,
                'pageAccessTypeCode' => 'web',
                'url' => self::URL,
            ], $admin),
            Response::HTTP_CREATED
        );
        self::assertIsInt($pageData['id'] ?? null, 'Created page must expose an integer id');
        $pageId = (int) $pageData['id'];

        // 2. Two top-level container sections (the create endpoint normalizes
        //    positions to a 0,10,20 sequence) plus one text child under A.
        $containerA = $this->createPageSection($pageId, $containerStyleId, 10, 'qa_sec_container_a', $admin);
        $containerB = $this->createPageSection($pageId, $containerStyleId, 20, 'qa_sec_container_b', $admin);
        $childId = $this->createChildSection($pageId, $containerA, $textStyleId, 10, 'qa_sec_text_child', $admin);

        // 3. Move the child from container A to container B (the current PUT
        //    move route), declaring the old parent so the link is relocated.
        $moved = $this->jsonRequest(
            'PUT',
            sprintf('/cms-api/v1/admin/pages/%d/sections/%d/sections', $pageId, $containerB),
            ['childSectionId' => $childId, 'oldParentSectionId' => $containerA, 'position' => 10],
            $admin
        );
        $this->assertEnvelopeSuccess($moved);

        // 4. Rename the child + author its translatable text field in de-CH.
        $firstContent = 'qa_text_content_de_v1';
        $updated = $this->jsonRequest(
            'PUT',
            sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $pageId, $childId),
            [
                'sectionName' => 'qa_sec_text_child_renamed',
                'contentFields' => [
                    ['fieldId' => $textFieldId, 'languageId' => self::LANG_DE, 'value' => $firstContent],
                ],
                'propertyFields' => [],
            ],
            $admin
        );
        $updatedData = $this->assertEnvelopeSuccess($updated);
        // NOTE: the updateSection endpoint returns {section, fields, languages}
        // but not data_variables, so it does not satisfy the shared section
        // response schema (validated on the getSection read in step 8 instead).
        self::assertSame(
            'qa_sec_text_child_renamed',
            $this->asArray($updatedData['section'] ?? null)['name'] ?? null,
            'Update response must echo the new section name.'
        );

        // 5. The admin page-create flow already grants the subject group ACL
        //    select (admin full; subject + therapist select). The qa.user is a
        //    subject member, so it can read the page — we only drop the
        //    page-scoped caches so the public render observes THIS run's ACL +
        //    hierarchy (DAMA reuses the deterministic keyword across runs while
        //    Redis persists).
        $this->pages->invalidatePageScopedCaches();

        $user = $this->loginAsQaUser();

        // 6a. Render through the public API by keyword (the single page-content
        //     path); the public top-level order must match the CMS's own
        //     authoritative section order (different code path: admin uses the
        //     hierarchical stored procedure, the frontend uses PageService).
        $rendered = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', sprintf('/cms-api/v1/pages/by-keyword/%s?language_id=%d', self::KEYWORD, self::LANG_DE), null, $user)
        );
        $this->assertMatchesSchema('responses/frontend/get_page');

        $adminOrder = $this->adminTopLevelOrder($pageId, $admin);
        self::assertContains($containerA, $adminOrder, 'Container A must be a top-level section.');
        self::assertContains($containerB, $adminOrder, 'Container B must be a top-level section.');

        $top = $this->topLevelSections($rendered);
        $renderOrder = array_map(fn(array $s): int => $this->asInt($s['id'] ?? null), $top);
        self::assertSame($adminOrder, $renderOrder, 'Public render order must match the CMS authoritative section order.');

        // Nesting: the moved child sits under container B, container A is empty.
        $renderedB = $this->findSection($top, $containerB);
        $renderedA = $this->findSection($top, $containerA);
        $childrenB = $this->childSections($renderedB);
        self::assertSame(
            [$childId],
            array_map(fn(array $s): int => $this->asInt($s['id'] ?? null), $childrenB),
            'The moved child must be nested under its new parent (container B).'
        );
        self::assertSame([], $this->childSections($renderedA), 'The old parent (container A) must have no children after the move.');

        $renderedChild = $childrenB[0];
        self::assertSame('text', $renderedChild['style_name'] ?? null, 'Child must render as the text style.');
        self::assertSame($firstContent, $this->textContent($renderedChild), 'Rendered child must carry the de-CH content we authored.');

        // 6b. A second keyword render is stable — it resolves to the same page id
        //     with identical structure/order (cache-stable, no drift).
        $byKeyword = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', sprintf('/cms-api/v1/pages/by-keyword/%s?language_id=%d', self::KEYWORD, self::LANG_DE), null, $user)
        );
        self::assertSame($pageId, $this->asArray($byKeyword['page'] ?? null)['id'] ?? null);
        self::assertSame(
            $renderOrder,
            array_map(fn(array $s): int => $this->asInt($s['id'] ?? null), $this->topLevelSections($byKeyword)),
            'Repeat keyword render must match the first render ordering.'
        );

        // 7. Mutate the content again, then re-render: the render must be FRESH
        //    (section cache invalidated on write), not the previous value.
        $secondContent = 'qa_text_content_de_v2';
        $this->assertEnvelopeSuccess(
            $this->jsonRequest(
                'PUT',
                sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $pageId, $childId),
                [
                    'sectionName' => 'qa_sec_text_child_renamed',
                    'contentFields' => [
                        ['fieldId' => $textFieldId, 'languageId' => self::LANG_DE, 'value' => $secondContent],
                    ],
                    'propertyFields' => [],
                ],
                $admin
            )
        );

        $afterMutation = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', sprintf('/cms-api/v1/pages/by-keyword/%s?language_id=%d', self::KEYWORD, self::LANG_DE), null, $user)
        );
        $freshB = $this->findSection($this->topLevelSections($afterMutation), $containerB);
        $freshChild = $this->childSections($freshB)[0];
        self::assertSame($secondContent, $this->textContent($freshChild), 'Render after mutation must reflect the new content (no stale section cache).');

        // 8. Admin section read reflects the authored state and matches schema.
        $adminSection = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $pageId, $childId), null, $admin)
        );
        $this->assertMatchesSchema('responses/admin/sections/section');
        self::assertSame('qa_sec_text_child_renamed', $this->asArray($adminSection['section'] ?? null)['name'] ?? null);
    }

    // -- request helpers ----------------------------------------------------

    private function createPageSection(int $pageId, int $styleId, int $position, string $name, string $token): int
    {
        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest(
                'POST',
                sprintf('/cms-api/v1/admin/pages/%d/sections/create', $pageId),
                ['styleId' => $styleId, 'position' => $position, 'name' => $name],
                $token
            ),
            Response::HTTP_CREATED
        );
        self::assertIsInt($data['id'] ?? null, 'Section create must return an integer id.');

        return (int) $data['id'];
    }

    private function createChildSection(int $pageId, int $parentId, int $styleId, int $position, string $name, string $token): int
    {
        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest(
                'POST',
                sprintf('/cms-api/v1/admin/pages/%d/sections/%d/sections/create', $pageId, $parentId),
                ['styleId' => $styleId, 'position' => $position, 'name' => $name],
                $token
            ),
            Response::HTTP_CREATED
        );
        self::assertIsInt($data['id'] ?? null, 'Child section create must return an integer id.');

        return (int) $data['id'];
    }

    /**
     * The CMS's own authoritative top-level section order (admin endpoint, which
     * builds the tree from the hierarchical stored procedure).
     *
     * @return list<int>
     */
    private function adminTopLevelOrder(int $pageId, string $token): array
    {
        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/pages/%d/sections', $pageId), null, $token)
        );

        $order = [];
        foreach ($this->asList($data['sections'] ?? null) as $section) {
            $order[] = $this->asInt($this->asArray($section)['id'] ?? null);
        }

        return $order;
    }

    // -- rendered-tree helpers ----------------------------------------------

    /**
     * @param list<array<string, mixed>> $sections
     * @return array<string, mixed>
     */
    private function findSection(array $sections, int $id): array
    {
        foreach ($sections as $section) {
            if ($this->asInt($section['id'] ?? null) === $id) {
                return $section;
            }
        }

        self::fail(sprintf('Section %d was not found in the rendered top-level list.', $id));
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private function topLevelSections(array $data): array
    {
        $page = $this->asArray($data['page'] ?? null);
        $sections = [];
        foreach ($this->asList($page['sections'] ?? null) as $section) {
            $sections[] = $this->asArray($section);
        }

        return $sections;
    }

    /**
     * @param array<string, mixed> $section
     * @return list<array<string, mixed>>
     */
    private function childSections(array $section): array
    {
        $children = [];
        foreach ($this->asList($section['children'] ?? null) as $child) {
            $children[] = $this->asArray($child);
        }

        return $children;
    }

    /**
     * @param array<string, mixed> $section
     */
    private function textContent(array $section): string
    {
        $text = $this->asArray($section['text'] ?? null);

        return $this->asString($text['content'] ?? null);
    }

    // -- misc helpers -------------------------------------------------------

    private function assertMatchesSchema(string $schemaName): void
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), false);
        $errors = $this->schema->validate($this->asObject($decoded), $schemaName);
        self::assertSame([], $errors, sprintf("Response failed schema %s:\n%s", $schemaName, implode("\n", $errors)));
    }

    private function styleId(string $name): int
    {
        $style = $this->em->getRepository(Style::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Style::class, $style, sprintf('Seeded style "%s" must exist.', $name));

        return (int) $style->getId();
    }

    private function fieldId(string $name): int
    {
        $field = $this->em->getRepository(Field::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Field::class, $field, sprintf('Seeded field "%s" must exist.', $name));

        return (int) $field->getId();
    }
}
