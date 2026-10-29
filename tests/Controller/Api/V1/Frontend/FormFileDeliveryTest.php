<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Field;
use App\Entity\Group;
use App\Entity\Language;
use App\Entity\Section;
use App\Entity\SectionsFieldsTranslation;
use App\Entity\SectionsHierarchy;
use App\Entity\Style;
use App\Entity\User;
use App\Service\ACL\ACLService;
use App\Service\Auth\UserContextService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\QaWebTestCase;
use App\Tests\Support\Security\PermissionMatrixProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Regression (D4/D6): form uploads were written to `public/uploads/form-files/`
 * and served raw by the web server — readable by anyone with the path, and an
 * uploaded SVG ran its script on the backend origin. Files now live outside the
 * document root and are only delivered by GET /forms/files/{record_id}/{field}
 * to the record owner or users with READ on the form's data table, sandboxed.
 */
#[TestGroup('security')]
final class FormFileDeliveryTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    private const SUBMIT = '/cms-api/v1/forms/submit';
    private const FIELD = 'qa_doc';
    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

    private EntityManagerInterface $em;
    private PageSectionFactory $pages;
    private string $projectDir;

    /** @var list<string> */
    private array $storedPaths = [];

    private ?Section $fileInput = null;

    private int $formId = 0;

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
            @unlink($this->projectDir . '/public/' . $path);
        }
        if ($this->formId > 0) {
            foreach (glob($this->projectDir . '/var/form-files/user_*/section_' . $this->formId) ?: [] as $dir) {
                $this->removeDir($dir);
            }
        }

        parent::tearDown();
    }

    public function testOwnerDownloadsAllFilesOfAMultiFileFieldAsZip(): void
    {
        [$recordId] = $this->submitAsQaUser('qa-a.txt', "qa a\n", moreFiles: ['qa-b.txt' => "qa b\n"]);

        $response = $this->get(sprintf('/cms-api/v1/forms/files/%d/%s/zip', $recordId, self::FIELD), $this->loginAsQaUser());
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/zip', $response->headers->get('Content-Type'));
        self::assertSame(['qa-a.txt', 'qa-b.txt'], $this->zipEntries($response));

        self::assertSame(Response::HTTP_FORBIDDEN, $this->get(sprintf('/cms-api/v1/forms/files/%d/%s/zip', $recordId, self::FIELD), $this->loginAsQaGuest())->getStatusCode());
    }

    public function testAdminDownloadsTheShownRecordsFilesAsOneZip(): void
    {
        [$recordId] = $this->submitAsQaUser('qa-a.txt', "qa a\n", moreFiles: ['qa-b.txt' => "qa b\n"]);
        $url = sprintf('/cms-api/v1/admin/data/tables/%d/files?record_ids=%d', $this->formId, $recordId);

        $response = $this->get($url, $this->loginAsQaAdmin());
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(
            [sprintf('record_%d/%s/qa-a.txt', $recordId, self::FIELD), sprintf('record_%d/%s/qa-b.txt', $recordId, self::FIELD)],
            $this->zipEntries($response),
        );

        // Admin-only (route permission admin.data.read): the uploader is not enough here.
        foreach ($this->forbiddenPersonaTokens() as $label => $token) {
            self::assertSame(Response::HTTP_FORBIDDEN, $this->get($url, $token)->getStatusCode(), $label);
        }
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->get($url, null)->getStatusCode());
    }

    private function get(string $url, ?string $token): Response
    {
        $context = $this->service(UserContextService::class);
        (new \ReflectionProperty(UserContextService::class, 'userResolved'))->setValue($context, false);

        $headers = $token !== null ? ['HTTP_Authorization' => 'Bearer ' . $token] : [];
        $this->client->request('GET', $url, [], [], $headers);

        return $this->client->getResponse();
    }

    /**
     * @return list<string>
     */
    private function zipEntries(Response $response): array
    {
        // The zip is deleted after it is sent once; read the body the client captured.
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $tmp = (string) tempnam(sys_get_temp_dir(), 'qa_zip_');
        file_put_contents($tmp, $this->client->getInternalResponse()->getContent());
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($tmp) === true, 'Response must be a valid zip.');
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();
        @unlink($tmp);
        sort($names);

        return $names;
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $entry) {
            is_dir($entry) ? $this->removeDir($entry) : @unlink($entry);
        }
        @rmdir($dir);
    }

    public function testOwnerDownloadsTheFileWhichIsNoLongerInThePublicFolder(): void
    {
        [$recordId, $path] = $this->submitAsQaUser('qa-notes.txt', "qa private notes\n");

        self::assertFileDoesNotExist($this->projectDir . '/public/' . $path, 'Uploads must not be web-served.');

        $response = $this->download($recordId, self::FIELD, $this->loginAsQaUser());
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame("qa private notes\n", $this->body($response));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
    }

    public function testDataTableReaderMayDownloadButOtherUsersAndAnonymousMayNot(): void
    {
        [$recordId] = $this->submitAsQaUser('qa-notes.txt', "qa private notes\n");

        self::assertSame(Response::HTTP_OK, $this->download($recordId, self::FIELD, $this->loginAsQaAdmin())->getStatusCode());
        self::assertSame(Response::HTTP_FORBIDDEN, $this->download($recordId, self::FIELD, $this->loginAsQaGuest())->getStatusCode());
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->download($recordId, self::FIELD, null)->getStatusCode());
    }

    public function testBrowserDoesNotKeepFilesForAfterLogout(): void
    {
        [$recordId] = $this->submitAsQaUser('qa-notes.txt', "qa private notes\n");

        $file = $this->download($recordId, self::FIELD, $this->loginAsQaUser());
        self::assertTrue($file->headers->hasCacheControlDirective('no-store'), (string) $file->headers->get('Cache-Control'));

        $zip = $this->get(sprintf('/cms-api/v1/forms/files/%d/%s/zip', $recordId, self::FIELD), $this->loginAsQaUser());
        self::assertTrue($zip->headers->hasCacheControlDirective('no-store'), (string) $zip->headers->get('Cache-Control'));
    }

    public function testUploadedSvgIsViewableInlineButSandboxed(): void
    {
        // Inline so "View" can show it; the sandbox CSP keeps its script from running.
        [$recordId] = $this->submitAsQaUser('qa-pic.svg', self::SVG);

        $response = $this->download($recordId, self::FIELD, $this->loginAsQaUser());
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testUnknownFieldOrRecordIsNotFound(): void
    {
        [$recordId] = $this->submitAsQaUser('qa-notes.txt', "qa private notes\n");
        $token = $this->loginAsQaAdmin();

        self::assertSame(Response::HTTP_NOT_FOUND, $this->download($recordId, 'qa_missing', $token)->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $this->download(999_999_999, self::FIELD, $token)->getStatusCode());
    }

    public function testLoggedInUserCanSubmitAFormWhoseOnlyFieldIsAFile(): void
    {
        // Regression: files travel outside form_data, so an upload-only form was
        // rejected for logged-in users with "Form data cannot be empty".
        [$recordId, $path] = $this->submitAsQaUser('qa-only.txt', "qa upload only
", withNote: false);

        self::assertGreaterThan(0, $recordId);
        self::assertStringStartsWith('uploads/form-files/', $path);
    }

    public function testFilesPublicSwitchAppliesLiveToAlreadyUploadedFiles(): void
    {
        [$recordId] = $this->submitAsQaUser('qa-photo.txt', "qa public photo\n");

        $this->setFilesPublic('1');
        self::assertSame(Response::HTTP_OK, $this->download($recordId, self::FIELD, null)->getStatusCode());

        $this->setFilesPublic('0');
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->download($recordId, self::FIELD, null)->getStatusCode());
    }

    private function setFilesPublic(string $value): void
    {
        $field = $this->em->getRepository(Field::class)->findOneBy(['name' => 'files_public']);
        $language = $this->em->getRepository(Language::class)->find(1);
        self::assertInstanceOf(Field::class, $field, 'The files_public field must be seeded.');
        self::assertInstanceOf(Language::class, $language);
        $section = $this->em->find(Section::class, (int) $this->fileInput?->getId());
        self::assertInstanceOf(Section::class, $section);

        $translation = $this->em->getRepository(SectionsFieldsTranslation::class)
            ->findOneBy(['section' => $section, 'field' => $field]);
        if (!$translation instanceof SectionsFieldsTranslation) {
            $translation = new SectionsFieldsTranslation();
            $translation->setSection($section);
            $translation->setField($field);
            $translation->setLanguage($language);
            $this->em->persist($translation);
        }
        $translation->setContent($value);
        $this->em->flush();
    }

    /**
     * @param array<string, string> $moreFiles extra name => contents; makes it a multi-file upload
     * @return array{0: int, 1: string}
     */
    private function submitAsQaUser(string $fileName, string $contents, bool $withNote = true, array $moreFiles = []): array
    {
        [$page, $form] = $this->pages->createFormPage('qa_form_file_delivery', openAccess: false);
        $this->formId = (int) $form->getId();
        $this->addFileInput($form);
        $this->pages->grantGroupAcl($page, $this->subjectGroup(), select: true, insert: true, update: false, delete: false, affectedUserIds: [$this->qaUserId()]);

        $uploads = [];
        foreach ([$fileName => $contents] + $moreFiles as $name => $body) {
            $tmp = (string) tempnam(sys_get_temp_dir(), 'qa_');
            file_put_contents($tmp, $body);
            $uploads[] = new UploadedFile($tmp, $name, null, null, true);
        }
        $this->client->request(
            'POST',
            self::SUBMIT,
            ['page_id' => (int) $page->getId(), 'section_id' => (int) $form->getId()] + ($withNote ? ['qa_note' => 'qa note'] : []),
            [self::FIELD => count($uploads) === 1 ? $uploads[0] : $uploads],
            ['HTTP_Authorization' => 'Bearer ' . $this->loginAsQaUser()],
        );
        $data = $this->assertEnvelopeSuccess($this->decode($this->client->getResponse()));
        $recordId = self::coerceInt($data['record_id']);

        $path = self::coerceString($this->em->getConnection()->fetchOne(
            "SELECT value FROM data_cells WHERE id_data_rows = :id AND value LIKE 'uploads/form-files/%'",
            ['id' => $recordId],
        ));
        $this->storedPaths[] = $path;

        return [$recordId, $path];
    }

    private function download(int $recordId, string $field, ?string $token): Response
    {
        // disableReboot keeps one UserContextService; drop its per-request user
        // memo so each request resolves its own caller (as in production).
        $context = $this->service(UserContextService::class);
        (new \ReflectionProperty(UserContextService::class, 'userResolved'))->setValue($context, false);

        $headers = $token !== null ? ['HTTP_Authorization' => 'Bearer ' . $token] : [];
        $this->client->request('GET', sprintf('/cms-api/v1/forms/files/%d/%s', $recordId, $field), [], [], $headers);

        return $this->client->getResponse();
    }

    private function body(Response $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    private function addFileInput(Section $form): void
    {
        $style = $this->em->getRepository(Style::class)->findOneBy(['name' => 'file-input']);
        $nameField = $this->em->getRepository(Field::class)->findOneBy(['name' => 'name']);
        $language = $this->em->getRepository(Language::class)->find(1);
        self::assertInstanceOf(Style::class, $style);
        self::assertInstanceOf(Field::class, $nameField);
        self::assertInstanceOf(Language::class, $language);

        $input = new Section();
        $input->setName('qa_fd_upload');
        $input->setStyle($style);
        $this->em->persist($input);
        $this->fileInput = $input;

        $link = new SectionsHierarchy();
        $link->setParentSection($form);
        $link->setChildSection($input);
        $link->setPosition(10);
        $this->em->persist($link);

        $translation = new SectionsFieldsTranslation();
        $translation->setSection($input);
        $translation->setField($nameField);
        $translation->setLanguage($language);
        $translation->setContent(self::FIELD);
        $this->em->persist($translation);
        $this->em->flush();
    }

    private function subjectGroup(): Group
    {
        $group = $this->em->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        self::assertInstanceOf(Group::class, $group);

        return $group;
    }

    private function qaUserId(): int
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $user);

        return (int) $user->getId();
    }
}
