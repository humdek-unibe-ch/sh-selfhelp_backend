<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\System;

use App\Entity\System\SystemUpdateOperation;
use App\Exception\ServiceException;
use App\Plugin\Registry\Unified\CoreImageRef;
use App\Plugin\Registry\Unified\CoreRelease;
use App\Plugin\Registry\Unified\FrontendRelease;
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
 * Frontend-only update path of the CMS update service (the new half of the
 * distribution plan: the frontend ships independently of the core, so an
 * instance already on the newest core can still update to a newer compatible
 * frontend). These exercise the pure service logic with stubbed persistence (no
 * database): the version picker, the stateless preflight (no migration/backup),
 * the instance-scoped request, and the claim DTO the SelfHelp Manager reads.
 */
final class SystemUpdateServiceFrontendTest extends TestCase
{
    private const INSTANCE = 'inst-fe';

    private function makeService(
        SystemRegistryReader $registry,
        ?SystemUpdateOperationRepository $operations = null,
        ?EntityManagerInterface $em = null,
        string $frontendVersion = '0.1.5',
    ): SystemUpdateService {
        $instance = $this->createStub(SystemInstanceService::class);
        $instance->method('getInstanceId')->willReturn(self::INSTANCE);
        $instance->method('getCmsVersion')->willReturn('0.1.4');
        $instance->method('getFrontendVersion')->willReturn($frontendVersion);

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
     * @param list<array{version: string, channel: string, blocked: bool}>|null $frontendReleases
     */
    private function registry(?array $frontendReleases): SystemRegistryReader
    {
        $registry = $this->createStub(SystemRegistryReader::class);
        $registry->method('listFrontendReleases')->willReturn($frontendReleases);

        return $registry;
    }

    /**
     * A registry stub that ALSO answers the signed release fetches the
     * bidirectional frontend ⇄ core preflight reads. `getCoreRelease` /
     * `getFrontendRelease` return null by default (offline / unpublished /
     * signature failure), exactly as the fail-soft reader would.
     *
     * @param list<array{version: string, channel: string, blocked: bool}>|null $frontendReleases
     */
    private function registryWith(
        ?array $frontendReleases,
        ?CoreRelease $core = null,
        ?FrontendRelease $frontend = null,
    ): SystemRegistryReader {
        $registry = $this->createStub(SystemRegistryReader::class);
        $registry->method('listFrontendReleases')->willReturn($frontendReleases);
        $registry->method('getCoreRelease')->willReturn($core);
        $registry->method('getFrontendRelease')->willReturn($frontend);

        return $registry;
    }

    /** A signed core release as the reader returns it, with a chosen frontend range. */
    private function coreReleaseDoc(string $requiredFrontendRange, string $version = '0.1.4'): CoreRelease
    {
        $digest = 'sha256:' . str_repeat('a', 64);

        return new CoreRelease(
            id: 'selfhelp-core',
            version: $version,
            channel: 'stable',
            minimumDirectUpgradeFrom: '0.1.0',
            pluginApiVersion: '0.1.0',
            backend: new CoreImageRef('ghcr.io/selfhelp/backend', $digest),
            worker: new CoreImageRef('ghcr.io/selfhelp/worker', $digest),
            scheduler: new CoreImageRef('ghcr.io/selfhelp/scheduler', $digest),
            requiredFrontendRange: $requiredFrontendRange,
            migrationRange: '>=0.1.0 <0.2.0',
            destructive: false,
            requiresBackup: true,
            manualConfirmationRequired: false,
            security: new SignatureBlock('c2ln', 'selfhelp-dev-fixture'),
            blocked: false,
            raw: [],
        );
    }

    /** A signed frontend release as the reader returns it, with a chosen core range. */
    private function frontendReleaseDoc(string $version, string $requiredCoreRange): FrontendRelease
    {
        return new FrontendRelease(
            id: 'selfhelp-frontend-' . $version,
            version: $version,
            channel: 'stable',
            image: 'ghcr.io/humdek-unibe-ch/selfhelp-frontend:' . $version,
            digest: 'sha256:' . str_repeat('d', 64),
            requiredCoreRange: $requiredCoreRange,
            requiredApiVersion: '0.1.0',
            security: new SignatureBlock('c2ln', 'selfhelp-dev-fixture'),
            blocked: false,
            raw: [],
        );
    }

    public function testAvailableFrontendReleasesListsRegistryVersionsForThePicker(): void
    {
        $service = $this->makeService($this->registry([
            ['version' => '0.1.7', 'channel' => 'stable', 'blocked' => false],
            ['version' => '0.1.5', 'channel' => 'stable', 'blocked' => false],
        ]));

        $releases = $service->getAvailableFrontendReleases();

        self::assertTrue($releases['available']);
        self::assertSame('0.1.5', $releases['current_version'], 'The picker reports the CURRENT frontend version.');
        self::assertSame(['0.1.7', '0.1.5'], array_column($releases['releases'], 'version'));
    }

    public function testAvailableFrontendReleasesDegradesWhenRegistryOffline(): void
    {
        $releases = $this->makeService($this->registry(null))->getAvailableFrontendReleases();

        self::assertFalse($releases['available'], 'An offline registry degrades the picker, never blocks.');
        self::assertSame([], $releases['releases']);
    }

    public function testFrontendPreflightForNewerVersionIsOkAndStateless(): void
    {
        $preflight = $this->makeService($this->registry([
            ['version' => '0.1.7', 'channel' => 'stable', 'blocked' => false],
        ]))->getFrontendPreflight('0.1.7');

        self::assertSame(SystemUpdateService::STATUS_OK, $preflight['status']);
        self::assertSame('0.1.5', $preflight['current_version']);
        self::assertSame('0.1.7', $preflight['target_version']);
        self::assertIsArray($preflight['database']);
        // Frontend is stateless: no destructive migration, no backup required.
        self::assertFalse($preflight['database']['destructive']);
        self::assertFalse($preflight['database']['requires_backup']);
        self::assertIsArray($preflight['options']);
        $firstOption = $preflight['options'][0] ?? null;
        self::assertIsArray($firstOption);
        self::assertSame('frontend', $firstOption['type'] ?? null);
    }

    public function testFrontendPreflightBlocksADowngrade(): void
    {
        $preflight = $this->makeService($this->registry([
            ['version' => '0.1.7', 'channel' => 'stable', 'blocked' => false],
        ]))->getFrontendPreflight('0.1.3');

        self::assertSame(SystemUpdateService::STATUS_BLOCKED, $preflight['status']);
        self::assertContains(SystemUpdateService::CHECK_DOWNGRADE, $this->checkCodes($preflight));
    }

    public function testFrontendPreflightBlocksAnInvalidVersion(): void
    {
        $preflight = $this->makeService($this->registry([]))->getFrontendPreflight('not-a-version');

        self::assertSame(SystemUpdateService::STATUS_BLOCKED, $preflight['status']);
        self::assertContains(SystemUpdateService::CHECK_VERSION_INVALID, $this->checkCodes($preflight));
    }

    public function testFrontendPreflightWarnsButDoesNotBlockWhenVersionNotPublished(): void
    {
        // Newer than the current frontend (not a downgrade) but absent from the
        // registry list: the manager re-resolves it, so this is a warning.
        $preflight = $this->makeService($this->registry([
            ['version' => '0.1.6', 'channel' => 'stable', 'blocked' => false],
        ]))->getFrontendPreflight('0.1.7');

        self::assertNotSame(SystemUpdateService::STATUS_BLOCKED, $preflight['status']);
        self::assertContains(SystemUpdateService::CHECK_REGISTRY_UNREACHABLE, $this->checkCodes($preflight));
    }

    public function testFrontendPreflightBlocksWhenRunningCoreForbidsTheTargetFrontend(): void
    {
        // The reported bug: the instance runs core 0.1.4 whose registry release
        // only supports frontend ">=0.1.0 <0.1.18", but the operator asks for
        // frontend 0.1.19. The manager would reject it at execution, so the CMS
        // preflight must BLOCK here too (not return "OK") to stay consistent.
        $registry = $this->registryWith(
            [['version' => '0.1.19', 'channel' => 'stable', 'blocked' => false]],
            core: $this->coreReleaseDoc('>=0.1.0 <0.1.18'),
            frontend: $this->frontendReleaseDoc('0.1.19', '>=0.1.0 <0.2.0'),
        );

        $preflight = $this->makeService($registry)->getFrontendPreflight('0.1.19');

        self::assertSame(SystemUpdateService::STATUS_BLOCKED, $preflight['status']);
        $compat = $this->firstCheck($preflight, SystemUpdateService::CHECK_FRONTEND_COMPATIBILITY);
        self::assertNotNull($compat, 'A frontend the running core forbids must raise a frontend_compatibility check.');
        self::assertSame('error', $compat['severity'] ?? null);
        self::assertSame('frontend', $compat['component'] ?? null);
        self::assertSame('selfhelp-frontend', $compat['component_id'] ?? null);
        self::assertSame('0.1.5', $compat['current_version'] ?? null);
        self::assertSame('0.1.19', $compat['target_version'] ?? null);
        self::assertSame('>=0.1.0 <0.1.18', $compat['required_range'] ?? null);
        self::assertTrue($compat['blocking'] ?? null);
        self::assertIsString($compat['message'] ?? null);
        self::assertStringContainsString('0.1.19', (string) $compat['message']);
    }

    public function testFrontendPreflightBlocksWhenTargetFrontendRequiresANewerCore(): void
    {
        // The other direction: the running core admits the frontend pick, but the
        // frontend itself requires core >=0.2.0 while the instance runs 0.1.4 →
        // block with "update the core first".
        $registry = $this->registryWith(
            [['version' => '0.1.7', 'channel' => 'stable', 'blocked' => false]],
            core: $this->coreReleaseDoc('>=0.1.0 <0.2.0'),
            frontend: $this->frontendReleaseDoc('0.1.7', '>=0.2.0 <0.3.0'),
        );

        $preflight = $this->makeService($registry)->getFrontendPreflight('0.1.7');

        self::assertSame(SystemUpdateService::STATUS_BLOCKED, $preflight['status']);
        $compat = $this->firstCheck($preflight, SystemUpdateService::CHECK_FRONTEND_COMPATIBILITY);
        self::assertNotNull($compat, 'A frontend that needs a newer core must raise a frontend_compatibility check.');
        self::assertSame('>=0.2.0 <0.3.0', $compat['required_range'] ?? null);
        $message = $compat['message'] ?? null;
        self::assertIsString($message);
        self::assertStringContainsString('core', strtolower($message));
    }

    public function testFrontendPreflightIsOkWhenBothFrontendAndCoreRangesAreSatisfied(): void
    {
        $registry = $this->registryWith(
            [['version' => '0.1.7', 'channel' => 'stable', 'blocked' => false]],
            core: $this->coreReleaseDoc('>=0.1.0 <0.2.0'),
            frontend: $this->frontendReleaseDoc('0.1.7', '>=0.1.0 <0.2.0'),
        );

        $preflight = $this->makeService($registry)->getFrontendPreflight('0.1.7');

        self::assertSame(SystemUpdateService::STATUS_OK, $preflight['status']);
        self::assertNotContains(
            SystemUpdateService::CHECK_FRONTEND_COMPATIBILITY,
            $this->checkCodes($preflight),
            'A frontend both sides accept must not raise a compatibility check.',
        );
    }

    public function testFrontendPreflightDoesNotFabricateACompatBlockWhenReleasesAreAbsentFromRegistry(): void
    {
        // getCoreRelease + getFrontendRelease both null (offline / unpublished /
        // tampered-signature). The CMS cannot see either range, so it must NOT
        // invent a compatibility block — the SelfHelp Manager re-resolves and
        // enforces the running core's range from the instance LOCK (even when the
        // core release has left the registry) as the final authority.
        $registry = $this->registryWith(
            [['version' => '0.1.7', 'channel' => 'stable', 'blocked' => false]],
            core: null,
            frontend: null,
        );

        $preflight = $this->makeService($registry)->getFrontendPreflight('0.1.7');

        self::assertNotSame(SystemUpdateService::STATUS_BLOCKED, $preflight['status']);
        self::assertNotContains(SystemUpdateService::CHECK_FRONTEND_COMPATIBILITY, $this->checkCodes($preflight));
    }

    public function testFrontendPreflightStillBlocksFromTheCoreRangeWhenTheFrontendDocIsUnavailable(): void
    {
        // Only the core release is readable (the frontend release doc 404s / fails
        // verification). The core-direction check alone must still block an
        // out-of-range frontend — a missing frontend doc must not weaken the gate.
        $registry = $this->registryWith(
            [['version' => '0.1.19', 'channel' => 'stable', 'blocked' => false]],
            core: $this->coreReleaseDoc('>=0.1.0 <0.1.18'),
            frontend: null,
        );

        $preflight = $this->makeService($registry)->getFrontendPreflight('0.1.19');

        self::assertSame(SystemUpdateService::STATUS_BLOCKED, $preflight['status']);
        self::assertContains(SystemUpdateService::CHECK_FRONTEND_COMPATIBILITY, $this->checkCodes($preflight));
    }

    public function testRequestFrontendUpdateRejectsAFrontendTheRunningCoreForbids(): void
    {
        $registry = $this->registryWith(
            [['version' => '0.1.19', 'channel' => 'stable', 'blocked' => false]],
            core: $this->coreReleaseDoc('>=0.1.0 <0.1.18'),
            frontend: $this->frontendReleaseDoc('0.1.19', '>=0.1.0 <0.2.0'),
        );

        try {
            $this->makeService($registry)->requestFrontendUpdate(['target_version' => '0.1.19', 'preflight_id' => 'pff_x']);
            self::fail('Expected a 422 for a frontend the running core forbids.');
        } catch (ServiceException $e) {
            self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $e->getCode());
        }
    }

    public function testRequestFrontendUpdatePersistsAFrontendKindOperation(): void
    {
        $persisted = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->willReturnCallback(function ($op) use (&$persisted): void {
            $persisted = $op;
        });
        $em->expects(self::once())->method('flush');

        $service = $this->makeService(
            $this->registry([['version' => '0.1.7', 'channel' => 'stable', 'blocked' => false]]),
            em: $em,
        );

        $result = $service->requestFrontendUpdate(['target_version' => '0.1.7', 'preflight_id' => 'pff_x']);

        self::assertSame('frontend', $result['kind']);
        self::assertSame('0.1.7', $result['target_frontend_version']);
        self::assertSame(SystemUpdateOperation::STATUS_REQUESTED, $result['status']);

        self::assertInstanceOf(SystemUpdateOperation::class, $persisted);
        self::assertTrue($persisted->isFrontendUpdate());
        self::assertSame('0.1.7', $persisted->getTargetFrontendVersion());
        // target_version mirrors the frontend version to keep the manager's
        // approval verification (keyed on target version) consistent.
        self::assertSame('0.1.7', $persisted->getTargetVersion());
        self::assertFalse($persisted->isAcceptedMigrationRisk(), 'Frontend swaps carry no migration risk.');
    }

    public function testRequestFrontendUpdateRejectsABlockedDowngrade(): void
    {
        $service = $this->makeService($this->registry([
            ['version' => '0.1.7', 'channel' => 'stable', 'blocked' => false],
        ]));

        try {
            $service->requestFrontendUpdate(['target_version' => '0.1.3', 'preflight_id' => 'pff_x']);
            self::fail('Expected a 422 for a blocked frontend downgrade.');
        } catch (ServiceException $e) {
            self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $e->getCode());
        }
    }

    public function testClaimEmitsFrontendKindAndTargetWithoutTouchingTheCoreRegistry(): void
    {
        $operation = new SystemUpdateOperation(self::INSTANCE, 'op_fe', '0.1.7');
        $operation->setKind(SystemUpdateOperation::KIND_FRONTEND)->setTargetFrontendVersion('0.1.7')->setPreflightId('pff_x');

        $operations = $this->createStub(SystemUpdateOperationRepository::class);
        $operations->method('findLatestClaimableForInstance')->willReturn($operation);

        // A stateless frontend swap must NOT recompute core destructive facts.
        $registry = $this->createMock(SystemRegistryReader::class);
        $registry->expects(self::never())->method('getCoreRelease');
        $registry->method('listFrontendReleases')->willReturn([]);

        $dto = $this->makeService($registry, $operations)->claimPendingOperation();

        self::assertNotNull($dto);
        self::assertSame('frontend', $dto['kind']);
        self::assertSame('0.1.7', $dto['target_frontend_version']);
        self::assertFalse($dto['destructive_migration']);
        self::assertSame('op_fe', $dto['approval_token']);
    }

    public function testClaimEmitsCoreKindAndNullFrontendForACoreOperation(): void
    {
        $operation = new SystemUpdateOperation(self::INSTANCE, 'op_core', '0.1.5');
        $operations = $this->createStub(SystemUpdateOperationRepository::class);
        $operations->method('findLatestClaimableForInstance')->willReturn($operation);

        // Offline core registry -> non-destructive, but the kind/target wiring
        // is what we pin here.
        $registry = $this->createStub(SystemRegistryReader::class);
        $registry->method('getCoreRelease')->willReturn(null);

        $dto = $this->makeService($registry, $operations)->claimPendingOperation();

        self::assertNotNull($dto);
        self::assertSame('core', $dto['kind']);
        self::assertNull($dto['target_frontend_version']);
    }

    public function testStatusCarriesTheKindForAFrontendOperation(): void
    {
        $operation = new SystemUpdateOperation(self::INSTANCE, 'op_fe', '0.1.7');
        $operation->setKind(SystemUpdateOperation::KIND_FRONTEND)->setTargetFrontendVersion('0.1.7');
        $operations = $this->createStub(SystemUpdateOperationRepository::class);
        $operations->method('findLatestForInstance')->willReturn($operation);

        $status = $this->makeService($this->registry([]), $operations)->getStatus();

        self::assertSame('frontend', $status['kind']);
        self::assertSame('0.1.7', $status['target_frontend_version']);
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
