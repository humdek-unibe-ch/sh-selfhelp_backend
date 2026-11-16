<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\Entity\Field;
use App\Entity\Group;
use App\Entity\Language;
use App\Entity\Page;
use App\Entity\Section;
use App\Entity\SectionsFieldsTranslation;
use App\Entity\SectionsHierarchy;
use App\Entity\Style;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\FormFileUploadService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A form that has a file input and a translatable text, the shape of the Team members template
 * (portrait + bio), through the two ways a client sends a form:
 *
 * - multipart carries every value as text, so a translatable value arrives as the JSON text of its
 *   `[{language_id, value}, ...]` list. The JSON request receives the list itself. Both must store one
 *   cell per language (the text of the list used to be stored as one cell and shown as JSON);
 * - a client that has no file to send for an edit may send an empty value for the file input (the web
 *   form sent `{}`). That must leave the stored file alone, not be refused as "not a scalar value".
 */
#[PhpUnitGroup('form-list')]
final class FormMultipartFieldsTest extends QaWebTestCase
{
    private EntityManagerInterface $em;
    private PageSectionFactory $pages;
    private string $formFilesRoot;

    /** @var list<string> */
    private array $storedPaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot();

        $this->em = $this->service(EntityManagerInterface::class);
        $this->pages = new PageSectionFactory(
            $this->em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );
        $formFiles = self::getContainer()->get(FormFileUploadService::class);
        self::assertInstanceOf(FormFileUploadService::class, $formFiles);
        $this->formFilesRoot = $formFiles->storageRoot();
    }

    protected function tearDown(): void
    {
        foreach ($this->storedPaths as $path) {
            @unlink($this->formFilesRoot . '/' . substr($path, strlen('uploads/form-files/')));
        }

        parent::tearDown();
    }

    public function testATranslatableValueSentWithAFileIsStoredOneCellPerLanguage(): void
    {
        [$page, $form, $upload, $bio] = $this->world('qa_mp_translatable');
        $token = $this->loginAsQaUser();

        $recordId = $this->createWithPortrait($token, $page, $form, [
            ['language_id' => 2, 'value' => 'Hallo Welt'],
            ['language_id' => 3, 'value' => 'Hello world'],
        ]);

        self::assertSame(
            [['id_languages' => 2, 'value' => 'Hallo Welt'], ['id_languages' => 3, 'value' => 'Hello world']],
            $this->cellsOf($recordId, $bio),
            'One cell per language, not the JSON text of the list in one cell.',
        );
        self::assertCount(1, $this->cellsOf($recordId, $upload), 'The portrait is stored once.');
    }

    public function testAnEmptyFileValueOnAnEditKeepsTheStoredFile(): void
    {
        [$page, $form, $upload, $bio] = $this->world('qa_mp_edit');
        $token = $this->loginAsQaUser();
        $recordId = $this->createWithPortrait($token, $page, $form, [
            ['language_id' => 2, 'value' => 'Hallo Welt'],
            ['language_id' => 3, 'value' => 'Hello world'],
        ]);
        $portraitBefore = $this->cellsOf($recordId, $upload);
        self::assertCount(1, $portraitBefore);

        // What the web form sends for an edit without a new picture: the file input as an empty object.
        $updated = $this->jsonRequest('PUT', '/cms-api/v1/forms/update', [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $form->getId(),
            'form_data' => [
                'qa_bio' => [
                    ['language_id' => 2, 'value' => 'Hallo Schweiz'],
                    ['language_id' => 3, 'value' => 'Hello Switzerland'],
                ],
                'qa_attachment' => new \stdClass(),
            ],
            'update_based_on' => ['record_id' => $recordId],
            'expected_version' => 1,
        ], $token);
        $this->assertEnvelopeSuccess($updated);

        self::assertSame($portraitBefore, $this->cellsOf($recordId, $upload), 'The stored portrait stays.');
        self::assertSame(
            [['id_languages' => 2, 'value' => 'Hallo Schweiz'], ['id_languages' => 3, 'value' => 'Hello Switzerland']],
            $this->cellsOf($recordId, $bio),
        );
    }

    // -- helpers --------------------------------------------------------------

    /**
     * @return array{0: Page, 1: Section, 2: Section, 3: Section} page, form, file input, translatable text
     */
    private function world(string $prefix): array
    {
        [$page, $form] = $this->pages->createFormPage($prefix, openAccess: false);
        $upload = $this->addChild($form, $prefix . '_upload', 'file-input', 'qa_attachment');
        $bio = $this->addChild($form, $prefix . '_bio', 'textarea', 'qa_bio');
        $subject = $this->em->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        self::assertInstanceOf(Group::class, $subject);
        $this->pages->grantGroupAcl($page, $subject, select: true, insert: true, update: true, delete: true);

        return [$page, $form, $upload, $bio];
    }

    /**
     * A multipart submit (a file is part of it) with the translatable text as the JSON text of its list.
     *
     * @param list<array{language_id: int, value: string}> $translations
     */
    private function createWithPortrait(string $token, Page $page, Section $form, array $translations): int
    {
        $this->client->request(
            'POST',
            '/cms-api/v1/forms/submit',
            [
                'page_id' => (int) $page->getId(),
                'section_id' => (int) $form->getId(),
                'qa_bio' => json_encode($translations, JSON_THROW_ON_ERROR),
            ],
            ['qa_attachment' => $this->upload('qa-portrait.txt')],
            ['HTTP_Authorization' => 'Bearer ' . $token],
        );
        $data = $this->assertEnvelopeSuccess($this->decode($this->client->getResponse()));
        $recordId = self::coerceInt($data['record_id'] ?? null);

        $paths = $this->em->getConnection()->fetchFirstColumn(
            'SELECT value FROM data_cells WHERE id_data_rows = :id AND value LIKE :prefix',
            ['id' => $recordId, 'prefix' => 'uploads/form-files/%'],
        );
        foreach ($paths as $path) {
            $this->storedPaths[] = self::coerceString($path);
        }

        return $recordId;
    }

    /**
     * @return list<array{id_languages: int, value: string}>
     */
    private function cellsOf(int $recordId, Section $input): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT c.id_languages, c.value FROM data_cells c JOIN data_cols col ON col.id = c.id_data_cols'
            . ' WHERE c.id_data_rows = :row AND col.field_key = :key ORDER BY c.id_languages',
            ['row' => $recordId, 'key' => 'section_' . (int) $input->getId()],
        );

        $cells = [];
        foreach ($rows as $row) {
            $cells[] = ['id_languages' => self::coerceInt($row['id_languages'] ?? null), 'value' => self::coerceString($row['value'] ?? null)];
        }

        return $cells;
    }

    private function addChild(Section $form, string $sectionName, string $styleName, string $inputName): Section
    {
        $style = $this->em->getRepository(Style::class)->findOneBy(['name' => $styleName]);
        self::assertInstanceOf(Style::class, $style);
        $child = new Section();
        $child->setName($sectionName);
        $child->setStyle($style);
        $this->em->persist($child);

        $link = new SectionsHierarchy();
        $link->setParentSection($form);
        $link->setChildSection($child);
        $link->setPosition(10);
        $this->em->persist($link);

        $nameField = $this->em->getRepository(Field::class)->findOneBy(['name' => 'name']);
        $language = $this->em->getRepository(Language::class)->find(1);
        self::assertInstanceOf(Field::class, $nameField);
        self::assertInstanceOf(Language::class, $language);
        $translation = new SectionsFieldsTranslation();
        $translation->setSection($child);
        $translation->setField($nameField);
        $translation->setLanguage($language);
        $translation->setContent($inputName);
        $this->em->persist($translation);
        $this->em->flush();

        return $child;
    }

    private function upload(string $name): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'qa_');
        file_put_contents($path, "qa portrait\n");

        return new UploadedFile($path, $name, 'text/plain', null, true);
    }
}
