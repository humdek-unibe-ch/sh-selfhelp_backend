<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Entity\Action;
use App\Entity\Asset;
use App\Entity\DataTable;
use App\Entity\Lookup;
use App\Service\CMS\Admin\AssetStorageService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\GroupFactory;
use App\Tests\Support\Factories\RoleFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The files an action mails out. The action runs later with system rights and
 * mails whatever its config names, so saving it is where this is decided: an
 * attachment must be an uploaded asset (its delivery URL), and the person
 * saving the action must be allowed to view that asset (its groups). A server
 * file path is refused. The admin role may attach any asset.
 */
#[TestGroup('security')]
final class ActionAttachmentAccessTest extends QaWebTestCase
{
    private const AUTHOR_EMAIL = 'qa.att.author@selfhelp.test';
    private const ACTIONS_URI = '/cms-api/v1/admin/actions';

    private int $dataTableId;
    private int $triggerTypeId;
    private string $publicUuid;
    private string $ownGroupUuid;
    private string $otherGroupUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $em = $this->entityManager();
        $roles = new RoleFactory($em);
        $groups = new GroupFactory($em);
        $users = new UserFactory(
            $em,
            $this->service(UserPasswordHasherInterface::class),
            $this->service(LookupService::class)
        );

        $ownGroup = $groups->createGroup('qa_att_own');
        $otherGroup = $groups->createGroup('qa_att_other');
        $authorRole = $roles->createRole('qa_att_author_role');
        foreach (['admin.action.read', 'admin.action.update'] as $permission) {
            $roles->grantPermission($authorRole, $permission);
        }
        $users->createUser(self::AUTHOR_EMAIL, 'QA Attachment Author', groups: [$ownGroup], roles: [$authorRole]);

        $this->publicUuid = $this->createAsset('qa-att-public.pdf');
        $this->ownGroupUuid = $this->createAsset('qa-att-own.pdf', [$ownGroup]);
        $this->otherGroupUuid = $this->createAsset('qa-att-other.pdf', [$otherGroup]);

        $table = new DataTable();
        $table->setName('qa_att_table');
        $table->setDisplayName('QA attachment table');
        $table->setTimestamp(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $em->persist($table);
        $em->flush();
        $this->dataTableId = (int) $table->getId();

        $trigger = $em->getRepository(Lookup::class)->findOneBy([
            'typeCode' => LookupService::ACTION_TRIGGER_TYPES,
            'lookupCode' => LookupService::ACTION_TRIGGER_TYPES_FINISHED,
        ]);
        self::assertInstanceOf(Lookup::class, $trigger);
        $this->triggerTypeId = (int) $trigger->getId();
    }

    public function testAnAuthorCanAttachAssetsTheyMayView(): void
    {
        $token = $this->loginAs(self::AUTHOR_EMAIL);
        $attachments = [
            $this->url($this->publicUuid),
            $this->url($this->ownGroupUuid),
            // The frontend BFF form and an absolute URL name the same assets.
            '/api/assets/' . $this->ownGroupUuid,
            'https://cms.example.test' . $this->url($this->publicUuid),
            '',
        ];

        $created = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', self::ACTIONS_URI, $this->payload('qa_att_ok', $attachments), $token),
            201
        );
        self::assertSame($attachments, $this->attachmentsOf($created));

        // Updating an existing action goes through the same check.
        $updated = $this->assertEnvelopeSuccess($this->jsonRequest(
            'PUT',
            self::ACTIONS_URI . '/' . $this->asInt($created['id']),
            ['config' => $this->config([$this->url($this->ownGroupUuid)])],
            $token
        ));
        self::assertSame([$this->url($this->ownGroupUuid)], $this->attachmentsOf($updated));
    }

    public function testAServerFilePathIsRefused(): void
    {
        $token = $this->loginAs(self::AUTHOR_EMAIL);

        foreach (['/etc/passwd', 'C:\\Windows\\win.ini', 'var/assets/' . $this->publicUuid, '../.env', $this->publicUuid] as $path) {
            $envelope = $this->jsonRequest('POST', self::ACTIONS_URI, $this->payload('qa_att_path', [$path]), $token);
            $this->assertEnvelope400($envelope);
            self::assertStringContainsString('not an uploaded asset', $this->asString($envelope['error'] ?? $envelope['message'] ?? ''), $path);
        }

        self::assertNull($this->findAction('qa_att_path'), 'A refused save stores nothing');
    }

    public function testAnAttachmentThatIsNotAStringIsRefused(): void
    {
        $this->assertEnvelope400($this->jsonRequest(
            'POST',
            self::ACTIONS_URI,
            $this->payload('qa_att_object', [['path' => '/etc/passwd']]),
            $this->loginAs(self::AUTHOR_EMAIL)
        ));
        self::assertNull($this->findAction('qa_att_object'));
    }

    public function testAnUnknownAssetIsRefused(): void
    {
        $storage = $this->service(AssetStorageService::class);

        $this->assertEnvelope400($this->jsonRequest(
            'POST',
            self::ACTIONS_URI,
            $this->payload('qa_att_unknown', [$this->url($storage->generateUuid())]),
            $this->loginAs(self::AUTHOR_EMAIL)
        ));
        self::assertNull($this->findAction('qa_att_unknown'));
    }

    public function testAnAssetTheAuthorMayNotViewIsRefused(): void
    {
        $token = $this->loginAs(self::AUTHOR_EMAIL);

        // On create, among others that would be fine.
        $this->assertEnvelope403($this->jsonRequest(
            'POST',
            self::ACTIONS_URI,
            $this->payload('qa_att_denied', [$this->url($this->publicUuid), $this->url($this->otherGroupUuid)]),
            $token
        ));
        self::assertNull($this->findAction('qa_att_denied'), 'A refused save stores nothing');

        // On update, also in the reminder of a job; the saved config stays as it was.
        $created = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', self::ACTIONS_URI, $this->payload('qa_att_keep', [$this->url($this->publicUuid)]), $token),
            201
        );
        $reminderConfig = [
            'blocks' => [[
                'block_name' => 'qa block',
                'jobs' => [[
                    'job_name' => 'qa job',
                    'job_type' => 'notification_with_reminder',
                    'notification' => ['notification_types' => 'email', 'attachments' => [$this->url($this->publicUuid)]],
                    'reminders' => [[
                        'notification' => ['notification_types' => 'email', 'attachments' => [$this->url($this->otherGroupUuid)]],
                    ]],
                ]],
            ]],
        ];
        $this->assertEnvelope403($this->jsonRequest(
            'PUT',
            self::ACTIONS_URI . '/' . $this->asInt($created['id']),
            ['config' => $reminderConfig],
            $token
        ));

        $stored = $this->assertEnvelopeSuccess($this->jsonRequest(
            'GET',
            self::ACTIONS_URI . '/' . $this->asInt($created['id']),
            null,
            $token
        ));
        self::assertSame([$this->url($this->publicUuid)], $this->attachmentsOf($stored));
    }

    public function testAdminMayAttachAnyAsset(): void
    {
        $created = $this->assertEnvelopeSuccess($this->jsonRequest(
            'POST',
            self::ACTIONS_URI,
            $this->payload('qa_att_admin', [$this->url($this->otherGroupUuid)]),
            $this->loginAsQaAdmin()
        ), 201);

        self::assertSame([$this->url($this->otherGroupUuid)], $this->attachmentsOf($created));
    }

    // -- helpers -------------------------------------------------------------

    private function entityManager(): EntityManagerInterface
    {
        return $this->service(EntityManagerInterface::class);
    }

    private function url(string $uuid): string
    {
        return AssetStorageService::URL_PREFIX . $uuid;
    }

    /**
     * An asset row, public when it has no groups. The file is not needed:
     * saving an action only resolves and checks the asset.
     *
     * @param list<\App\Entity\Group> $groups
     */
    private function createAsset(string $name, array $groups = []): string
    {
        $em = $this->entityManager();
        $type = $em->getRepository(Lookup::class)->findOneBy([
            'typeCode' => LookupService::ASSET_TYPES,
            'lookupCode' => LookupService::ASSET_TYPES_ASSET,
        ]);
        self::assertInstanceOf(Lookup::class, $type);

        $uuid = $this->service(AssetStorageService::class)->generateUuid();
        $asset = new Asset();
        $asset->setUuid($uuid);
        $asset->setAssetType($type);
        $asset->setName($name);
        $asset->setMimeType('application/pdf');
        foreach ($groups as $group) {
            $asset->addGroup($group);
        }
        $em->persist($asset);
        $em->flush();

        return $uuid;
    }

    /**
     * @param list<mixed> $attachments
     * @return array<string, mixed>
     */
    private function config(array $attachments): array
    {
        return ['blocks' => [[
            'block_name' => 'qa block',
            'jobs' => [[
                'job_name' => 'qa job',
                'job_type' => 'notification',
                'notification' => ['notification_types' => 'email', 'attachments' => $attachments],
            ]],
        ]]];
    }

    /**
     * @param list<mixed> $attachments
     * @return array<string, mixed>
     */
    private function payload(string $name, array $attachments): array
    {
        return [
            'name' => $name,
            'id_action_trigger_types' => $this->triggerTypeId,
            'id_data_tables' => $this->dataTableId,
            'config' => $this->config($attachments),
        ];
    }

    /**
     * The attachments of the first job's notification in an action payload.
     *
     * @param array<string, mixed> $action
     * @return list<mixed>
     */
    private function attachmentsOf(array $action): array
    {
        $blocks = array_values($this->asArray($this->asArray($action['config'] ?? null)['blocks'] ?? null));
        $jobs = array_values($this->asArray($this->asArray($blocks[0] ?? null)['jobs'] ?? null));
        $notification = $this->asArray($this->asArray($jobs[0] ?? null)['notification'] ?? null);

        return array_values($this->asArray($notification['attachments'] ?? null));
    }

    private function findAction(string $name): ?Action
    {
        $em = $this->entityManager();
        $em->clear();
        $action = $em->getRepository(Action::class)->findOneBy(['name' => $name]);

        return $action instanceof Action ? $action : null;
    }
}
