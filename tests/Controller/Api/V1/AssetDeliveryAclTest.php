<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Asset;
use App\Entity\Group;
use App\Entity\User;
use App\Service\CMS\Admin\AssetStorageService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\GroupFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Security regression coverage for asset DELIVERY (Testing Rule 27).
 *
 * Bytes are only ever served through `GET /cms-api/v1/assets/{uuid}`, and each
 * request is authorized against the asset's own groups:
 *  - an asset with no groups is public (anonymous callers included),
 *  - otherwise it is readable by anyone in ANY of its groups,
 *  - the admin role reads every asset.
 */
#[TestGroup('security')]
final class AssetDeliveryAclTest extends QaWebTestCase
{
    private const BODY = 'qa-delivery-body';

    private AssetStorageService $storage;
    private EntityManagerInterface $entityManager;

    /** @var list<string> files written by the test, removed in tearDown */
    private array $writtenFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $container = self::getContainer();
        /** @var AssetStorageService $storage */
        $storage = $container->get(AssetStorageService::class);
        $this->storage = $storage;
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $this->entityManager = $em;
    }

    protected function tearDown(): void
    {
        foreach ($this->writtenFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testAnonymousCallerIsRefusedAnAssetThatIsNotPublic(): void
    {
        $asset = $this->createAsset('qa-private.txt', 'text/plain', ['subject']);

        $this->client->request('GET', '/cms-api/v1/assets/' . $asset->getUuid());

        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'An asset with groups must never reach an anonymous caller');
        self::assertStringNotContainsString(self::BODY, (string) $this->client->getResponse()->getContent());
    }

    public function testAnonymousCallerReceivesAnAssetWithoutGroupsWithItsStoredTypeAndName(): void
    {
        $asset = $this->createAsset('qa-public.txt', 'text/plain', []);

        $this->client->request('GET', '/cms-api/v1/assets/' . $asset->getUuid());

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), 'An asset without groups must be publicly readable');
        self::assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        // Downloaded as the uuid plus the extension of the stored mime type.
        self::assertStringContainsString($asset->getUuid() . '.txt', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString(self::BODY, $this->streamedBody());
    }

    /**
     * `qa.editor` is made a member of `qa_asset_viewers` only: an asset shared
     * just with `qa_asset_outsiders` is refused, one shared with both is served.
     */
    public function testUserReceivesOnlyAssetsSharedWithOneOfTheirGroups(): void
    {
        $this->customGroup('qa_asset_outsiders');
        $this->addEditorToGroup($this->customGroup('qa_asset_viewers'));

        $outsidersOnly = $this->createAsset('qa-outsiders.txt', 'text/plain', ['qa_asset_outsiders']);
        $shared = $this->createAsset('qa-shared.txt', 'text/plain', ['qa_asset_outsiders', 'qa_asset_viewers']);
        $token = $this->loginAs(QaBaselineFixture::QA_EDITOR_EMAIL);
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];

        $this->client->request('GET', '/cms-api/v1/assets/' . $outsidersOnly->getUuid(), [], [], $headers);
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'A user outside every asset group must be refused');

        $this->client->request('GET', '/cms-api/v1/assets/' . $shared->getUuid(), [], [], $headers);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'Membership in any one group is enough');
    }

    public function testTheAdminRoleReadsAnAssetOutsideItsGroups(): void
    {
        $this->customGroup('qa_asset_outsiders');
        $asset = $this->createAsset('qa-outsiders-only.txt', 'text/plain', ['qa_asset_outsiders']);

        $editor = $this->loginAs(QaBaselineFixture::QA_EDITOR_EMAIL);
        $this->client->request('GET', '/cms-api/v1/assets/' . $asset->getUuid(), [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $editor]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $admin = $this->loginAs(QaBaselineFixture::QA_ADMIN_EMAIL);
        $this->client->request('GET', '/cms-api/v1/assets/' . $asset->getUuid(), [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $admin]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'The admin role reads every asset');
    }

    public function testUnknownUuidIsNotFound(): void
    {
        $this->client->request('GET', '/cms-api/v1/assets/' . $this->storage->generateUuid());

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Regression: an uploaded SVG opened directly ran its script on the app's
     * origin. Markup assets must be sandboxed; plain files must not be, so the
     * browser's PDF viewer keeps working.
     */
    public function testSvgAssetIsServedSandboxedSoItsScriptCannotRun(): void
    {
        $svg = $this->createAsset(
            'qa-script.svg',
            'image/svg+xml',
            [],
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        );

        $this->client->request('GET', '/cms-api/v1/assets/' . $svg->getUuid());

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
        self::assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testPlainAssetIsServedWithoutTheSandboxPolicy(): void
    {
        $asset = $this->createAsset('qa-plain.txt', 'text/plain', []);

        $this->client->request('GET', '/cms-api/v1/assets/' . $asset->getUuid());

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->headers->has('Content-Security-Policy'));
    }

    /** A custom `qa_` group; the seed has only `subject`. */
    private function customGroup(string $name): Group
    {
        return (new GroupFactory($this->entityManager))->createGroup($name);
    }

    /** Add the QA editor persona to the given group (it already is in `subject`). */
    private function addEditorToGroup(Group $group): void
    {
        $users = new UserFactory(
            $this->entityManager,
            $this->service(UserPasswordHasherInterface::class),
            $this->service(LookupService::class),
        );
        $editor = $this->entityManager->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_EDITOR_EMAIL]);
        self::assertInstanceOf(User::class, $editor);
        $users->addToGroup($editor, $group);
    }

    /**
     * Persist an asset stored under its uuid, as an upload would.
     *
     * @param list<string> $groupNames
     */
    private function createAsset(string $fileName, string $mimeType, array $groupNames, string $body = self::BODY): Asset
    {
        $uuid = $this->storage->generateUuid();

        $type = $this->entityManager->getRepository(\App\Entity\Lookup::class)->findOneBy([
            'typeCode' => LookupService::ASSET_TYPES,
            'lookupCode' => LookupService::ASSET_TYPES_ASSET,
        ]);
        self::assertInstanceOf(\App\Entity\Lookup::class, $type);

        $asset = new Asset();
        $asset->setUuid($uuid);
        $asset->setAssetType($type);
        $asset->setName($fileName);
        $asset->setMimeType($mimeType);
        foreach ($groupNames as $name) {
            $group = $this->entityManager->getRepository(Group::class)->findOneBy(['name' => $name]);
            self::assertInstanceOf(Group::class, $group, sprintf('Group "%s" must exist (seeded `subject` or created by the test)', $name));
            $asset->addGroup($group);
        }
        $this->entityManager->persist($asset);
        $this->entityManager->flush();

        $root = $this->storage->storageRoot();
        if (!is_dir($root)) {
            mkdir($root, 0755, true);
        }
        $path = $this->storage->absolutePath($uuid);
        file_put_contents($path, $body);
        $this->writtenFiles[] = $path;

        return $asset;
    }

    /** BinaryFileResponse streams, so capture the body explicitly. */
    private function streamedBody(): string
    {
        ob_start();
        $this->client->getResponse()->sendContent();

        return (string) ob_get_clean();
    }
}
