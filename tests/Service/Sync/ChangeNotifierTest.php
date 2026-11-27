<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\Sync;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Asset;
use App\Entity\User;
use App\Repository\AssetRepository;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\Admin\AdminAssetService;
use App\Service\CMS\Admin\AssetAccessService;
use App\Service\CMS\Admin\AssetStorageService;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use App\Service\Sync\ChangeNoticeService;
use App\Service\Sync\ChangeNotifier;
use App\Tests\Support\FailingCommitEntityManager;
use App\Tests\Support\MercureTestRecorder;
use App\Tests\Support\QaKernelTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The change notifier (live-sync contract C1/C3): notices are merged per topic,
 * sent once after the unit of work, never sent for a write that did not commit,
 * and never carried from one unit into the next.
 */
final class ChangeNotifierTest extends QaKernelTestCase
{
    private MercureTestRecorder $hub;
    private ChangeNotifier $notifier;

    /** @var list<string> */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->hub = new MercureTestRecorder();
        $this->notifier = new ChangeNotifier($this->hub, new NullLogger());
    }

    protected function tearDown(): void
    {
        $storage = $this->service(AssetStorageService::class);
        foreach ($this->createdFiles as $uuid) {
            @unlink($storage->absolutePath($uuid));
        }
        parent::tearDown();
    }

    public function testNothingIsSentBeforeTheFlush(): void
    {
        $this->notifier->notify(['t/one'], ['acl']);

        $this->hub->assertNothingPublished('A notice waits for the end of the unit of work.');
        self::assertSame(['t/one' => ['acl']], $this->notifier->pending());
    }

    public function testNoticesAreMergedPerTopicAndSentOnce(): void
    {
        $this->notifier->notify(['t/one', 't/two'], ['navigation']);
        $this->notifier->notify(['t/one'], ['page:7', 'navigation']);
        $this->notifier->notify(['t/one'], ['acl', 'page:7']);

        $this->notifier->flush();

        $sent = $this->sentByTopic();
        self::assertSame(['t/one', 't/two'], array_keys($sent));
        self::assertSame(['acl', 'navigation', 'page:7'], $sent['t/one'], 'One message per topic with the merged, sorted tags.');
        self::assertSame(['navigation'], $sent['t/two']);
        self::assertCount(2, $this->hub->getUpdates());

        $this->notifier->flush();
        self::assertCount(2, $this->hub->getUpdates(), 'A second flush sends nothing again.');
    }

    public function testTheNoticeIsPrivateNamedChangedAndCarriesTagsOnly(): void
    {
        $this->notifier->notify(['t/one'], ['table:5']);
        $this->notifier->flush();

        $update = $this->hub->getUpdates()[0];
        self::assertTrue($update->isPrivate());
        self::assertSame('changed', $update->getType());
        self::assertSame(['t/one'], $update->getTopics());
        self::assertSame('{"v":1,"tags":["table:5"]}', $update->getData());
    }

    public function testAnInvalidTagIsDroppedAndNoTagMeansNoNotice(): void
    {
        $this->notifier->notify(['t/one'], ['page:abc', 'secret content', '', 'page:0x']);
        $this->notifier->notify(['t/one'], ['page:12', 'asset:3', 'page_lock:4', 'page_draft:5', 'section:6', 'global_values']);
        $this->notifier->flush();

        self::assertSame(
            ['asset:3', 'global_values', 'page:12', 'page_draft:5', 'page_lock:4', 'section:6'],
            $this->sentByTopic()['t/one'] ?? [],
        );
    }

    public function testResetBetweenJobsDropsWhatTheLastUnitLeftBehind(): void
    {
        $this->notifier->notify(['t/one'], ['acl']);

        $this->notifier->reset();
        $this->notifier->notify(['t/two'], ['navigation']);
        $this->notifier->flush();

        self::assertSame(['t/two' => ['navigation']], $this->sentByTopic(), 'What the first unit queued is gone.');
    }

    public function testAWriteWhoseCommitFailsQueuesNoNotice(): void
    {
        $notifier = $this->service(ChangeNotifier::class);
        $notifier->reset();

        $failing = new FailingCommitEntityManager($this->em);
        $assets = $this->assetService($failing);
        $created = $assets->createAsset($this->upload('old'), ['name' => 'qa notifier rollback']);
        $this->createdFiles[] = $this->asString($created['uuid']);
        $id = $this->asInt($created['id']);
        $notifier->reset();

        $failing->failNextCommit();
        try {
            $assets->updateAsset($id, ['alt' => 'new alt']);
            self::fail('The failed commit must reach the caller.');
        } catch (\RuntimeException $e) {
            self::assertSame(FailingCommitEntityManager::MESSAGE, $e->getMessage());
        }

        self::assertSame([], $notifier->pending(), 'A rolled-back write sends nothing.');
    }

    public function testAWriteThatCommitsQueuesItsNoticeAfterTheCommit(): void
    {
        $notifier = $this->service(ChangeNotifier::class);
        $notifier->reset();

        $assets = $this->assetService($this->em);
        $created = $assets->createAsset($this->upload('bytes'), ['name' => 'qa notifier commit']);
        $this->createdFiles[] = $this->asString($created['uuid']);
        $id = $this->asInt($created['id']);
        $notifier->reset();

        $assets->updateAsset($id, ['alt' => 'changed alt']);

        $tags = array_unique(array_merge(...array_values($notifier->pending())));
        self::assertSame(['asset:' . $id], $tags, 'The committed write names the asset, and nothing else.');
        self::assertNotSame([], $notifier->pending(), 'At least the superuser roles are told.');
        $notifier->reset();
    }

    /**
     * @return array<string, list<string>> topic => tags of the message sent to it
     */
    private function sentByTopic(): array
    {
        $out = [];
        foreach ($this->hub->getUpdates() as $update) {
            self::assertInstanceOf(Update::class, $update);
            $decoded = json_decode($update->getData(), true);
            self::assertIsArray($decoded);
            $tags = $decoded['tags'] ?? null;
            self::assertIsArray($tags);
            foreach ($update->getTopics() as $topic) {
                self::assertIsString($topic);
                $out[$topic] = array_values(array_map(static fn (mixed $tag): string => is_string($tag) ? $tag : '', $tags));
            }
        }
        ksort($out);

        return $out;
    }

    private function assetService(\Doctrine\ORM\EntityManagerInterface $entityManager): AdminAssetService
    {
        $admin = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_ADMIN_EMAIL]);
        self::assertInstanceOf(User::class, $admin);
        $this->service(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));

        $repository = $this->em->getRepository(Asset::class);
        self::assertInstanceOf(AssetRepository::class, $repository);

        return new AdminAssetService(
            $repository,
            $entityManager,
            $this->service(TransactionService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
            $this->service(AssetAccessService::class),
            $this->service(AssetStorageService::class),
            $this->service(ChangeNoticeService::class),
        );
    }

    private function upload(string $bytes): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'qa_notice');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, 'qa-notice.txt', null, null, true);
    }
}
