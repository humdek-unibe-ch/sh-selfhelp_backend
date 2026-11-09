<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\Entity\Field;
use App\Entity\Language;
use App\Entity\Section;
use App\Entity\SectionsFieldsTranslation;
use App\Entity\SectionsHierarchy;
use App\Entity\Style;
use App\Service\ACL\ACLService;
use App\Service\Auth\UserContextService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Regression (D3): form uploads were only kept when the field NAME contained
 * "file" — a `file-input` named `qa_attachment` lost its file while the form
 * still reported success. A field is a file field when its section style is
 * `file-input`; a file sent for any other field is rejected, never dropped.
 */
final class FormFileFieldSubmitTest extends QaWebTestCase
{
    private const SUBMIT = '/cms-api/v1/forms/submit';

    private EntityManagerInterface $em;
    private PageSectionFactory $pages;
    private string $projectDir;

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
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        $this->projectDir = is_string($projectDir) ? $projectDir : '';
    }

    protected function tearDown(): void
    {
        foreach ($this->storedPaths as $path) {
            @unlink($this->projectDir . '/var/form-files/' . substr($path, strlen('uploads/form-files/')));
        }

        parent::tearDown();
    }

    public function testFileInputWithoutFileInItsNameStoresTheUpload(): void
    {
        [$page, $form] = $this->pages->createFormPage('qa_form_file_field', openAccess: true);
        $this->addChild($form, 'qa_ff_upload', 'file-input', 'qa_attachment');

        $this->client->request(
            'POST',
            self::SUBMIT,
            ['page_id' => (int) $page->getId(), 'section_id' => (int) $form->getId()],
            ['qa_attachment' => $this->upload('qa-notes.txt')],
        );
        $data = $this->assertEnvelopeSuccess($this->decode($this->client->getResponse()));

        $stored = $this->cellValues(self::coerceInt($data['record_id']));
        self::assertCount(1, $stored, 'The upload must be saved, not silently dropped.');
        $path = $stored[0];
        $this->storedPaths[] = $path;
        self::assertStringContainsString(
            sprintf('uploads/form-files/user_%d/section_%d/field_qa_attachment/', UserContextService::GUEST_USER_ID, (int) $form->getId()),
            $path,
        );
        self::assertStringEndsWith('_qa-notes.txt', $path, 'The extension must not be doubled (.txt.txt).');
        self::assertFileExists($this->projectDir . '/var/form-files/' . substr($path, strlen('uploads/form-files/')));
    }

    public function testFileSentForANonFileFieldIsRejected(): void
    {
        [$page, $form] = $this->pages->createFormPage('qa_form_file_mismatch', openAccess: true);
        $this->addChild($form, 'qa_ff_text', 'text-input', 'qa_profile_file');

        $this->client->request(
            'POST',
            self::SUBMIT,
            ['page_id' => (int) $page->getId(), 'section_id' => (int) $form->getId()],
            ['qa_profile_file' => $this->upload('qa-notes.txt')],
        );

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->rowCount((int) $form->getId()), 'A rejected submission must not create a record.');
    }

    private function addChild(Section $form, string $sectionName, string $styleName, string $inputName): void
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
    }

    private function upload(string $name): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'qa_');
        file_put_contents($path, "qa upload content\n");

        return new UploadedFile($path, $name, 'text/plain', null, true);
    }

    /**
     * @return list<string>
     */
    private function cellValues(int $recordId): array
    {
        /** @var list<string> $values */
        $values = $this->em->getConnection()->fetchFirstColumn(
            'SELECT value FROM data_cells WHERE id_data_rows = :id',
            ['id' => $recordId],
        );

        return $values;
    }

    private function rowCount(int $formSectionId): int
    {
        return self::coerceInt($this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM data_rows r JOIN data_tables t ON t.id = r.id_data_tables WHERE t.name = :name',
            ['name' => (string) $formSectionId],
        ));
    }
}
