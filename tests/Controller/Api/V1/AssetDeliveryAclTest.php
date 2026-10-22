<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\AssetFolder;
use App\Entity\Group;
use App\Repository\AssetFolderRepository;
use App\Service\CMS\Admin\AssetFolderAclService;
use App\Service\CMS\Admin\AssetStorageService;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;

/**
 * Security regression coverage for asset DELIVERY (Testing Rule 27).
 *
 * The gap this closes: assets used to be served straight off disk from
 * `public/uploads/assets/`, so `GET /uploads/assets/champ/<file>` returned the
 * bytes to ANYONE with the URL — folder ACLs only ever filtered the admin
 * listing endpoint, never the files themselves. Delivery now runs through
 * `GET /cms-api/v1/assets/{folder}/{filename}`, which authorizes every request.
 *
 * Asserts the three cases that matter:
 *  - a closed folder is refused to an anonymous caller (the reported hole),
 *  - an open-access folder is served to an anonymous caller (public assets keep
 *    working, which is what makes moving files out of `public/` survivable),
 *  - path traversal cannot escape the folder.
 */
#[TestGroup('security')]
final class AssetDeliveryAclTest extends QaWebTestCase
{
    private const CLOSED_FOLDER = 'qa_delivery_closed';
    private const OPEN_FOLDER = 'qa_delivery_open';
    private const FILE_NAME = 'qa-pixel.txt';
    private const SVG_NAME = 'qa-script.svg';

    private AssetFolderAclService $aclService;
    private AssetFolderRepository $folderRepo;
    private AssetStorageService $storage;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        // The kernel is booted by parent::setUp()'s createClient(); pull services
        // from that container rather than booting a second one.
        $container = self::getContainer();
        /** @var AssetFolderAclService $aclService */
        $aclService = $container->get(AssetFolderAclService::class);
        $this->aclService = $aclService;
        /** @var AssetFolderRepository $folderRepo */
        $folderRepo = $container->get(AssetFolderRepository::class);
        $this->folderRepo = $folderRepo;
        /** @var AssetStorageService $storage */
        $storage = $container->get(AssetStorageService::class);
        $this->storage = $storage;
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $this->entityManager = $em;

        // Both folders exist; only OPEN_FOLDER is flagged open-access. Grants are
        // created through the normal service so the folder rows exist.
        $subjectId = $this->groupId('subject');
        $this->aclService->updateGroupAssetAcls($subjectId, [
            ['folder' => self::CLOSED_FOLDER, 'access_level' => 'read'],
            ['folder' => self::OPEN_FOLDER, 'access_level' => 'read'],
        ]);
        $this->aclService->setFolderOpenAccess(self::OPEN_FOLDER, true);

        // Real bytes on disk for each folder.
        foreach ([self::CLOSED_FOLDER, self::OPEN_FOLDER] as $folder) {
            $dir = $this->storage->folderPath($folder);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($dir . '/' . self::FILE_NAME, 'qa-delivery-body');
        }
        file_put_contents(
            $this->storage->folderPath(self::OPEN_FOLDER) . '/' . self::SVG_NAME,
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        );
    }

    protected function tearDown(): void
    {
        foreach ([self::CLOSED_FOLDER, self::OPEN_FOLDER] as $folder) {
            foreach ([self::FILE_NAME, self::SVG_NAME] as $name) {
                $file = $this->storage->folderPath($folder) . '/' . $name;
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($this->storage->folderPath($folder))) {
                rmdir($this->storage->folderPath($folder));
            }
        }

        $subject = $this->entityManager->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        if ($subject instanceof Group) {
            $this->aclService->updateGroupAssetAcls((int) $subject->getId(), []);
        }
        foreach ([self::CLOSED_FOLDER, self::OPEN_FOLDER] as $folder) {
            $row = $this->folderRepo->findOneByFolder($folder);
            if ($row instanceof AssetFolder) {
                $this->entityManager->remove($row);
            }
        }
        $this->entityManager->flush();

        parent::tearDown();
    }

    public function testAnonymousCallerIsRefusedAssetBytesFromAClosedFolder(): void
    {
        $this->client->request('GET', sprintf(
            '/cms-api/v1/assets/%s/%s',
            self::CLOSED_FOLDER,
            self::FILE_NAME
        ));

        self::assertSame(
            403,
            $this->client->getResponse()->getStatusCode(),
            'A closed folder must never serve bytes to an anonymous caller'
        );
        self::assertStringNotContainsString(
            'qa-delivery-body',
            (string) $this->client->getResponse()->getContent(),
            'The refused response must not leak the file body'
        );
    }

    public function testAnonymousCallerReceivesAssetBytesFromAnOpenAccessFolder(): void
    {
        $this->client->request('GET', sprintf(
            '/cms-api/v1/assets/%s/%s',
            self::OPEN_FOLDER,
            self::FILE_NAME
        ));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), 'An open-access folder must be publicly readable');

        // BinaryFileResponse streams, so capture the body explicitly.
        ob_start();
        $response->sendContent();
        $body = (string) ob_get_clean();
        self::assertStringContainsString('qa-delivery-body', $body);
    }

    /**
     * Regression: an uploaded SVG opened directly ran its script on the app's
     * origin. Markup assets must be sandboxed; plain files must not be, so the
     * browser's PDF viewer keeps working.
     */
    public function testSvgAssetIsServedSandboxedSoItsScriptCannotRun(): void
    {
        $this->client->request('GET', sprintf('/cms-api/v1/assets/%s/%s', self::OPEN_FOLDER, self::SVG_NAME));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
        self::assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testPlainAssetIsServedWithoutTheSandboxPolicy(): void
    {
        $this->client->request('GET', sprintf('/cms-api/v1/assets/%s/%s', self::OPEN_FOLDER, self::FILE_NAME));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->headers->has('Content-Security-Policy'));
    }

    /**
     * The reported scenario: a therapist-group user who holds a grant on one
     * folder but not another must be refused the ungranted folder's BYTES, not
     * merely have it hidden from the admin listing.
     *
     * `qa.editor` is in the `therapist` group, and setUp grants only `subject`,
     * so the closed folder is ungranted for this caller.
     */
    public function testTherapistWithoutAGrantIsRefusedTheClosedFoldersBytes(): void
    {
        $token = $this->loginAs(QaBaselineFixture::QA_EDITOR_EMAIL);

        $this->client->request(
            'GET',
            sprintf('/cms-api/v1/assets/%s/%s', self::CLOSED_FOLDER, self::FILE_NAME),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        self::assertSame(
            403,
            $this->client->getResponse()->getStatusCode(),
            'A logged-in user without a grant on the folder must not receive its bytes'
        );
    }

    /**
     * The same caller DOES receive an open-access folder, so marking a folder
     * public is what keeps legitimately-public assets rendering.
     */
    public function testTherapistReceivesTheOpenAccessFoldersBytes(): void
    {
        $token = $this->loginAs(QaBaselineFixture::QA_EDITOR_EMAIL);

        $this->client->request(
            'GET',
            sprintf('/cms-api/v1/assets/%s/%s', self::OPEN_FOLDER, self::FILE_NAME),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testTraversalCannotEscapeTheRequestedFolder(): void
    {
        // Reach for the open folder's file from inside the closed folder. The
        // route requirement rejects separators, so this must never yield bytes.
        $this->client->request('GET', sprintf(
            '/cms-api/v1/assets/%s/..%%2F..%%2F%s/%s',
            self::CLOSED_FOLDER,
            self::OPEN_FOLDER,
            self::FILE_NAME
        ));

        self::assertNotSame(
            200,
            $this->client->getResponse()->getStatusCode(),
            'Path traversal must not serve a file from another folder'
        );
    }

    private function groupId(string $name): int
    {
        $group = $this->entityManager->getRepository(Group::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Group::class, $group, sprintf('Seeded "%s" group is required', $name));

        return (int) $group->getId();
    }
}
