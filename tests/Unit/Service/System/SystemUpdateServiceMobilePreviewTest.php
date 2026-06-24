<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\System;

use App\Entity\System\SystemUpdateOperation;
use App\Exception\ServiceException;
use App\Plugin\Registry\Unified\MobilePreviewRelease;
use App\Plugin\Registry\Unified\SignatureBlock;
use App\Repository\Plugin\PluginRepository;
use App\Repository\System\SystemUpdateOperationRepository;
use App\Service\Auth\UserContextService;
use App\Service\System\SystemInstanceService;
use App\Service\System\SystemRegistryReader;
use App\Service\System\SystemUpdateService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mobile-preview-only update path of the CMS update service. The optional
 * `selfhelp-mobile-preview` web image ships independently of the core, so an
 * instance already on the newest core can still update — or, when it has none
 * yet (current version 'unknown'), ENABLE/bootstrap — a compatible preview.
 *
 * These mirror {@see SystemUpdateServiceFrontendTest}: pure service logic with
 * stubbed persistence (no database). They pin the version picker, the stateless
 * preflight (no migration/backup), the preview ⇄ core compatibility gate that
 * keeps the CMS preflight consistent with the SelfHelp Manager, the enable
 * (bootstrap) path, the instance-scoped request, and the claim DTO the manager
 * reads.
 */
final class SystemUpdateServiceMobilePreviewTest extends TestCase
{
    private const INSTANCE = 'inst-mp';

    private function makeService(
        SystemRegistryReader $registry,
        ?SystemUpdateOperationRepository $operations = null,
        ?EntityManagerInterface $em = null,
        string $mobilePreviewVersion = '0.1.0',
        string $coreVersion = '0.1.4',
    ): SystemUpdateService {
        $instance = $this->createStub(SystemInstanceService::class);
        $instance->method('getInstanceId')->willReturn(self::INSTANCE);
        $instance->method('getCmsVersion')->willReturn($coreVersion);
        $instance->method('getMobilePreviewVersion')->willReturn($mobilePreviewVersion);

        $plugins = $this->createStub(PluginRepository::class);
        $plugins->method('findAllOrderedByName')->willReturn([]);

        $userContext = $this->createStub(UserContextService::class);
        $userContext->method('getActualUserId')->willReturn(0);

        return new SystemUpdateService(
            $instance,
            $operations ?? $this->createStub(SystemUpdateOperationRepository::class),
            $plugins,
            $registry,
            $userContext,
            $em ?? $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            new ArrayAdapter(),
            'qa-manager-token',
        );
    }

    /**
     * @param list<array{version: string, channel: string, blocked: bool}>|null $previewReleases
     */
    private function registry(?array $previewReleases, ?MobilePreviewRelease $previewDoc = null): SystemRegistryReader
    {
        $registry = $this->createStub(SystemRegistryReader::class);
        $registry->method('listMobilePreviewReleases')->willReturn($previewReleases);
        $registry->method('getMobilePreviewRelease')->willReturn($previewDoc);

        return $registry;
    }

    /** A signed mobile-preview release doc as the reader returns it, with a chosen core range. */
    private function previewReleaseDoc(string $version, string $requiredCoreRange): MobilePreviewRelease
    {
        return new MobilePreviewRelease(
            id: 'selfhelp-mobile-preview-' . $version,
            version: $version,
            channel: 'stable',
            image: 'ghcr.io/humdek-unibe-ch/selfhelp-mobile-preview:' . $version,
            digest: 'sha256:' . str_repeat('7', 64),
            requiredCoreRange: $requiredCoreRange,
            mobileRendererVersion: '0.1.0',
            reactNativeVersion: '0.83.6',
            expoSdkVersion: '55.0.0',
            security: new SignatureBlock('c2ln', 'selfhelp-dev-fixture'),
            blocked: false,
            raw: [],
        );
    }

    public function testAvailableMobilePreviewReleasesListsRegistryVersionsForThePicker(): void
    {
        $service = $this->makeService($this->registry([
            ['version' => '0.2.3', 'channel' => 'stable', 'blocked' => false],
            ['version' => '0.1.0', 'channel' => 'stable', 'blocked' => false],
        ]));

        $releases = $service->getAvailableMobilePreviewReleases();

        self::assertTrue($releases['available']);
        self::assertSame('0.1.0', $releases['current_version'], 'The picker reports the CURRENT preview version.');
        self::assertSame(['0.2.3', '0.1.0'], array_column($releases['releases'], 'version'));
    }

    public function testAvailableMobilePreviewReleasesDegradesWhenRegistryOffline(): void
    {
        $releases = $this->makeService($this->registry(null))->getAvailableMobilePreviewReleases();

        self::assertFalse($releases['available'], 'An offline registry degrades the picker, never blocks.');
        self::assertSame([], $releases['releases']);
    }

    public function testMobilePreviewPreflightForNewerVersionIsOkAndStateless(): void
    {
        $preflight = $this->makeService($this->registry([
            ['version' => '0.2.3', 'channel' => 'stable', 'blocked' => false],
        ]))->getMobilePreviewPreflight('0.2.3');

        self::assertSame(SystemUpdateService::STATUS_OK, $preflight['status']);
        self::assertSame('0.1.0', $preflight['current_version']);
        self::assertSame('0.2.3', $preflight['target_version']);
        self::assertIsArray($preflight['database']);
        // The preview is stateless: no destructive migration, no backup required.
        self::assertFalse($preflight['database']['destructive']);
        self::assertFalse($preflight['database']['requires_backup']);
        self::assertIsArray($preflight['options']);
        $firstOption = $preflight['options'][0] ?? null;
        self::assertIsArray($firstOption);
        self::assertSame('mobile-preview', $firstOption['type'] ?? null);
    }

    public function testMobilePreviewPreflightBlocksADowngrade(): void
    {
        $preflight = $this->makeService($this->registry([
            ['version' => '0.2.3', 'channel' => 'stable', 'blocked' => false],
        ]), mobilePreviewVersion: '0.2.3')->getMobilePreviewPreflight('0.1.0');

        self::assertSame(SystemUpdateService::STATUS_BLOCKED, $preflight['status']);
        self::assertContains(SystemUpdateService::CHECK_DOWNGRADE, $this->checkCodes($preflight));
    }

    public function testMobilePreviewPreflightBlocksAnInvalidVersion(): void
    {
        $preflight = $this->makeService($this->registry([]))->getMobilePreviewPreflight('not-a-version');

        self::assertSame(SystemUpdateService::STATUS_BLOCKED, $preflight['status']);
        self::assertContains(SystemUpdateService::CHECK_VERSION_INVALID, $this->checkCodes($preflight));
    }

    public function testMobilePreviewPreflightWarnsButDoesNotBlockWhenVersionNotPublished(): void
    {
        // Newer than the current preview (not a downgrade) but absent from the
        // registry list: the manager re-resolves it, so this is a warning.
        $preflight = $this->makeService($this->registry([
            ['version' => '0.1.5', 'channel' => 'stable', 'blocked' => false],
        ]))->getMobilePreviewPreflight('0.2.3');

        self::assertNotSame(SystemUpdateService::STATUS_BLOCKED, $preflight['status']);
        self::assertContains(SystemUpdateService::CHECK_REGISTRY_UNREACHABLE, $this->checkCodes($preflight));
    }

    public function testMobilePreviewPreflightBlocksWhenTargetPreviewRequiresANewerCore(): void
    {
        // The instance runs core 0.1.4, but the requested preview 0.2.3 only
        // admits core >=0.2.0 <0.3.0. The manager would reject it at execution,
        // so the CMS preflight must BLOCK here too (not return "OK").
        $registry = $this->registry(
            [['version' => '0.2.3', 'channel' => 'stable', 'blocked' => false]],
            $this->previewReleaseDoc('0.2.3', '>=0.2.0 <0.3.0'),
        );

        $preflight = $this->makeService($registry)->getMobilePreviewPreflight('0.2.3');

        self::assertSame(SystemUpdateService::STATUS_BLOCKED, $preflight['status']);
        $compat = $this->firstCheck($preflight, SystemUpdateService::CHECK_MOBILE_PREVIEW_COMPATIBILITY);
        self::assertNotNull($compat, 'A preview the running core cannot satisfy must raise a mobile_preview_compatibility check.');
        self::assertSame('error', $compat['severity'] ?? null);
        self::assertSame('mobile-preview', $compat['component'] ?? null);
        self::assertSame('selfhelp-mobile-preview', $compat['component_id'] ?? null);
        self::assertSame('0.1.0', $compat['current_version'] ?? null);
        self::assertSame('0.2.3', $compat['target_version'] ?? null);
        self::assertSame('>=0.2.0 <0.3.0', $compat['required_range'] ?? null);
        self::assertTrue($compat['blocking'] ?? null);
        $message = $compat['message'] ?? null;
        self::assertIsString($message);
        self::assertStringContainsString('core', strtolower($message));
    }

    public function testMobilePreviewPreflightIsOkWhenPreviewCoreRangeAdmitsRunningCore(): void
    {
        $registry = $this->registry(
            [['version' => '0.2.3', 'channel' => 'stable', 'blocked' => false]],
            $this->previewReleaseDoc('0.2.3', '>=0.1.0 <0.2.0'),
        );

        $preflight = $this->makeService($registry)->getMobilePreviewPreflight('0.2.3');

        self::assertSame(SystemUpdateService::STATUS_OK, $preflight['status']);
        self::assertNotContains(
            SystemUpdateService::CHECK_MOBILE_PREVIEW_COMPATIBILITY,
            $this->checkCodes($preflight),
            'A preview the running core accepts must not raise a compatibility check.',
        );
    }

    public function testMobilePreviewPreflightDoesNotFabricateACompatBlockWhenPreviewDocAbsent(): void
    {
        // getMobilePreviewRelease null (offline / unpublished / tampered signature):
        // the CMS cannot read the requiredCoreRange, so it must NOT invent a
        // compatibility block — the manager re-resolves + enforces at execution.
        $registry = $this->registry(
            [['version' => '0.2.3', 'channel' => 'stable', 'blocked' => false]],
            null,
        );

        $preflight = $this->makeService($registry)->getMobilePreviewPreflight('0.2.3');

        self::assertNotSame(SystemUpdateService::STATUS_BLOCKED, $preflight['status']);
        self::assertNotContains(SystemUpdateService::CHECK_MOBILE_PREVIEW_COMPATIBILITY, $this->checkCodes($preflight));
    }

    public function testMobilePreviewPreflightEnablesBootstrapWhenInstanceHasNoPreviewYet(): void
    {
        // An instance that predates default provisioning reports 'unknown'. That
        // must NOT masquerade as a downgrade: the preflight is the ENABLE path and
        // stays OK so the manager can provision the container.
        $registry = $this->registry(
            [['version' => '0.1.0', 'channel' => 'stable', 'blocked' => false]],
            $this->previewReleaseDoc('0.1.0', '>=0.1.0 <0.2.0'),
        );

        $preflight = $this->makeService($registry, mobilePreviewVersion: 'unknown')->getMobilePreviewPreflight('0.1.0');

        self::assertSame(SystemUpdateService::STATUS_OK, $preflight['status']);
        self::assertSame('unknown', $preflight['current_version']);
        self::assertNotContains(SystemUpdateService::CHECK_DOWNGRADE, $this->checkCodes($preflight));
    }

    public function testRequestMobilePreviewUpdatePersistsAMobilePreviewKindOperation(): void
    {
        $persisted = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->willReturnCallback(function ($op) use (&$persisted): void {
            $persisted = $op;
        });
        $em->expects(self::once())->method('flush');

        $service = $this->makeService(
            $this->registry([['version' => '0.2.3', 'channel' => 'stable', 'blocked' => false]]),
            em: $em,
        );

        $result = $service->requestMobilePreviewUpdate(['target_version' => '0.2.3', 'preflight_id' => 'pfm_x']);

        self::assertSame('mobile-preview', $result['kind']);
        self::assertSame('0.2.3', $result['target_mobile_preview_version']);
        self::assertSame(SystemUpdateOperation::STATUS_REQUESTED, $result['status']);

        self::assertInstanceOf(SystemUpdateOperation::class, $persisted);
        self::assertTrue($persisted->isMobilePreviewUpdate());
        self::assertSame('0.2.3', $persisted->getTargetMobilePreviewVersion());
        // target_version mirrors the preview version to keep the manager's
        // approval verification (keyed on target version) consistent.
        self::assertSame('0.2.3', $persisted->getTargetVersion());
        self::assertFalse($persisted->isAcceptedMigrationRisk(), 'Preview swaps carry no migration risk.');
    }

    public function testRequestMobilePreviewUpdateRejectsAPreviewTheRunningCoreForbids(): void
    {
        $registry = $this->registry(
            [['version' => '0.2.3', 'channel' => 'stable', 'blocked' => false]],
            $this->previewReleaseDoc('0.2.3', '>=0.2.0 <0.3.0'),
        );

        try {
            $this->makeService($registry)->requestMobilePreviewUpdate(['target_version' => '0.2.3', 'preflight_id' => 'pfm_x']);
            self::fail('Expected a 422 for a preview the running core forbids.');
        } catch (ServiceException $e) {
            self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $e->getCode());
        }
    }

    public function testClaimEmitsMobilePreviewKindAndTargetWithoutTouchingTheCoreRegistry(): void
    {
        $operation = new SystemUpdateOperation(self::INSTANCE, 'op_mp', '0.2.3');
        $operation->setKind(SystemUpdateOperation::KIND_MOBILE_PREVIEW)->setTargetMobilePreviewVersion('0.2.3')->setPreflightId('pfm_x');

        $operations = $this->createStub(SystemUpdateOperationRepository::class);
        $operations->method('findLatestClaimableForInstance')->willReturn($operation);

        // A stateless preview swap must NOT recompute core destructive facts.
        $registry = $this->createMock(SystemRegistryReader::class);
        $registry->expects(self::never())->method('getCoreRelease');

        $dto = $this->makeService($registry, $operations)->claimPendingOperation();

        self::assertNotNull($dto);
        self::assertSame('mobile-preview', $dto['kind']);
        self::assertSame('0.2.3', $dto['target_mobile_preview_version']);
        self::assertFalse($dto['destructive_migration']);
        self::assertSame('op_mp', $dto['approval_token']);
    }

    public function testStatusCarriesTheKindForAMobilePreviewOperation(): void
    {
        $operation = new SystemUpdateOperation(self::INSTANCE, 'op_mp', '0.2.3');
        $operation->setKind(SystemUpdateOperation::KIND_MOBILE_PREVIEW)->setTargetMobilePreviewVersion('0.2.3');
        $operations = $this->createStub(SystemUpdateOperationRepository::class);
        $operations->method('findLatestForInstance')->willReturn($operation);

        $status = $this->makeService($this->registry([]), $operations)->getStatus();

        self::assertSame('mobile-preview', $status['kind']);
        self::assertSame('0.2.3', $status['target_mobile_preview_version']);
    }

    /**
     * @param array<string,mixed> $preflight
     * @return list<string>
     */
    private function checkCodes(array $preflight): array
    {
        $codes = [];
        self::assertIsArray($preflight['checks']);
        foreach ($preflight['checks'] as $check) {
            self::assertIsArray($check);
            $code = $check['code'] ?? null;
            if (is_string($code)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * The first preflight check with the given code, or null.
     *
     * @param array<string,mixed> $preflight
     * @return array<array-key,mixed>|null
     */
    private function firstCheck(array $preflight, string $code): ?array
    {
        self::assertIsArray($preflight['checks']);
        foreach ($preflight['checks'] as $check) {
            self::assertIsArray($check);
            if (($check['code'] ?? null) === $code) {
                return $check;
            }
        }

        return null;
    }
}
