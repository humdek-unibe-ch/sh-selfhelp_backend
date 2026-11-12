<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Entity\Role;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\Factories\RoleDataAccessFactory;
use App\Tests\Support\Factories\RoleFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The option list of an image picker in the section inspector is what the
 * CALLER may manage, and it is read when the section is read, never taken
 * from a cache another caller filled: the cached section payload holds no
 * option lists. Two editors with different asset grants open the same section
 * in any order and each sees exactly their own assets; the admin role sees all.
 */
#[TestGroup('security')]
final class SectionPickerOptionsTest extends QaWebTestCase
{
    private const OWNER_EMAIL = 'qa.picker.owner@selfhelp.test';
    private const OTHER_EMAIL = 'qa.picker.other@selfhelp.test';
    private const OWNER_ROLE = 'qa_picker_owner_role';
    private const OTHER_ROLE = 'qa_picker_other_role';
    private const PERMISSIONS = ['admin.page.read', 'admin.asset.read', 'admin.asset.create', 'admin.asset.update', 'admin.asset.delete'];
    private const ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private int $pageId;
    private int $sectionId;

    protected function setUp(): void
    {
        parent::setUp();

        $em = $this->entityManager();
        $roles = new RoleFactory($em);
        $users = new UserFactory($em, $this->service(UserPasswordHasherInterface::class), $this->service(LookupService::class));
        $dataAccess = new RoleDataAccessFactory($em, $this->service(LookupService::class), $this->service(DataAccessSecurityService::class));
        $pages = new PageSectionFactory(
            $em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );

        $ownerRole = $roles->createRole(self::OWNER_ROLE);
        $otherRole = $roles->createRole(self::OTHER_ROLE);
        foreach ([$ownerRole, $otherRole] as $role) {
            foreach (self::PERMISSIONS as $permission) {
                $roles->grantPermission($role, $permission);
            }
        }
        $users->createUser(self::OWNER_EMAIL, 'QA Picker Owner', roles: [$ownerRole]);
        $users->createUser(self::OTHER_EMAIL, 'QA Picker Other', roles: [$otherRole]);

        // Both editors may open the page, so the only difference between them
        // is which assets they manage.
        $page = $pages->createPage('qa_picker_page', false);
        $section = $pages->createSection('qa_picker_image', 'image');
        $pages->linkSectionToPage($page, $section, 10);
        $dataAccess->grantPageAccess($ownerRole, (int) $page->getId(), DataAccessSecurityService::PERMISSION_READ);
        $dataAccess->grantPageAccess($otherRole, (int) $page->getId(), DataAccessSecurityService::PERMISSION_READ);
        $this->pageId = (int) $page->getId();
        $this->sectionId = (int) $section->getId();
    }

    public function testEachEditorSeesOnlyTheirOwnAssetsInTheImagePickerWhoeverOpenedTheSectionFirst(): void
    {
        $owner = ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->loginAs(self::OWNER_EMAIL)];
        $other = ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->loginAs(self::OTHER_EMAIL)];
        $admin = ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->loginAsQaAdmin()];

        // A real image: the type is read from the bytes, and only images are
        // offered by an image picker.
        $path = (string) tempnam(sys_get_temp_dir(), 'qa_asset');
        file_put_contents($path, base64_decode(self::ONE_PIXEL_PNG, true));
        $this->client->request('POST', '/cms-api/v1/admin/assets', [], ['file' => new UploadedFile($path, 'qa-picker-owned.png', null, null, true)], $owner);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $assetId = $this->data()['id'] ?? null;
        self::assertIsInt($assetId);

        try {
            // The editor WITHOUT a grant on the asset opens the section first: that
            // read must not decide what the others are shown.
            self::assertFalse($this->pickerOffers($other, 'qa-picker-owned'), 'No grant: the asset is not offered');
            self::assertTrue($this->pickerOffers($admin, 'qa-picker-owned'), 'The admin role manages every asset');
            self::assertTrue($this->pickerOffers($owner, 'qa-picker-owned'), 'The editor whose role manages it is offered it');
            self::assertFalse($this->pickerOffers($other, 'qa-picker-owned'), 'Still no grant, whoever read the section in between');
        } finally {
            // The file lives on disk outside the database transaction of the test.
            $this->client->request('DELETE', '/cms-api/v1/admin/assets/' . $assetId, [], [], $owner);
        }
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Whether the image picker of the section lists an asset whose label
     * contains `$label`, as the caller sees it.
     *
     * @param array<string, string> $headers
     */
    private function pickerOffers(array $headers, string $label): bool
    {
        $this->client->request('GET', sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $this->pageId, $this->sectionId), [], [], $headers);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $fields = $this->data()['fields'] ?? null;
        self::assertIsArray($fields);
        foreach ($fields as $field) {
            if (!is_array($field) || ($field['name'] ?? null) !== 'img_src') {
                continue;
            }
            $config = $field['config'] ?? null;
            self::assertIsArray($config, 'The picker always comes with its config, options included');
            $options = $config['options'] ?? null;
            self::assertIsArray($options);
            foreach ($options as $option) {
                if (is_array($option) && is_string($option['text'] ?? null) && str_contains($option['text'], $label)) {
                    return true;
                }
            }

            return false;
        }
        self::fail('The image section has no img_src field');
    }

    /**
     * @return array<mixed>
     */
    private function data(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['data']);

        return $body['data'];
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
