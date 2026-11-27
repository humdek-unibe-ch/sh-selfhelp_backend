<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Plugin;

use App\Entity\Plugin\Plugin;
use App\Exception\ServiceException;
use App\Plugin\Lifecycle\PluginDependencyGuard;
use App\Plugin\Lifecycle\PluginEnabler;
use App\Plugin\Lifecycle\PluginInstaller;
use App\Plugin\Lifecycle\PluginPurger;
use App\Plugin\Lifecycle\PluginUninstaller;
use App\Plugin\Lifecycle\PluginUpdater;
use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Manifest\ResolvedSource;
use App\Tests\Support\QaKernelTestCase;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Symfony\Component\HttpFoundation\Response;

/**
 * C21: the `dependencies` and `conflicts` of a plugin manifest are enforced.
 *
 * Install, update and enable need every required dependency installed, enabled and in its
 * version range (409 `plugin_dependency_missing`) and no enabled conflict (409
 * `plugin_conflict`, in both directions) and no name another enabled plugin holds for itself
 * (an `envOverride`, a JSON Logic namespace; 409 `plugin_manifest_collision`, contract C2).
 * Disable, uninstall and purge are refused while an enabled plugin requires the plugin
 * (409 `plugin_dependents_enabled`). A dependency with `required: false` is a hint and never blocks.
 *
 * The rules are checked on {@see PluginDependencyGuard} with real plugin rows. The refusals of
 * the lifecycle classes are checked too, because they must fire before anything is recorded or
 * changed; their success paths are not driven here: they write the plugin lock file and the
 * bundle list of the working tree (rule 7.10, the CLI worker covers them).
 */
#[PhpUnitGroup('security')]
final class PluginDependencyTest extends QaKernelTestCase
{
    private const BASE = 'qa-dep-base';
    private const DEPENDENT = 'qa-dep-dependent';
    private const OTHER = 'qa-dep-other';

    private PluginDependencyGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = $this->service(PluginDependencyGuard::class);
    }

    // -- install / update / enable: assertCanActivate --------------------------------

    public function testAllRequiredDependenciesInstalledEnabledAndInRangeLetThePluginActivate(): void
    {
        $this->plugin(self::BASE, '1.2.0', true);
        $this->plugin('qa-dep-second', '3.0.1', true);

        $this->guard->assertCanActivate($this->manifest(self::DEPENDENT, [
            'dependencies' => [
                ['pluginId' => self::BASE, 'version' => '^1.0.0'],
                ['pluginId' => 'qa-dep-second'],
            ],
        ]));

        $this->addToAssertionCount(1);
    }

    public function testAMissingRequiredDependencyBlocksActivation(): void
    {
        $refusal = $this->refusal(fn () => $this->guard->assertCanActivate($this->manifest(self::DEPENDENT, [
            'dependencies' => [['pluginId' => self::BASE, 'version' => '^1.0.0']],
        ])));

        self::assertSame(PluginDependencyGuard::ERROR_DEPENDENCY_MISSING, $refusal->getMessage());
        self::assertSame(Response::HTTP_CONFLICT, $refusal->getCode());
        self::assertSame(
            [['plugin_id' => self::BASE, 'version' => '^1.0.0', 'reason' => 'missing', 'installed_version' => null]],
            $refusal->getData()['unmet'] ?? null,
        );
    }

    public function testADisabledRequiredDependencyBlocksActivation(): void
    {
        $this->plugin(self::BASE, '1.2.0', false);

        $refusal = $this->refusal(fn () => $this->guard->assertCanActivate($this->manifest(self::DEPENDENT, [
            'dependencies' => [['pluginId' => self::BASE, 'version' => '^1.0.0']],
        ])));

        self::assertSame(PluginDependencyGuard::ERROR_DEPENDENCY_MISSING, $refusal->getMessage());
        self::assertSame(
            [['plugin_id' => self::BASE, 'version' => '^1.0.0', 'reason' => 'disabled', 'installed_version' => '1.2.0']],
            $refusal->getData()['unmet'] ?? null,
        );
    }

    public function testARequiredDependencyOutOfRangeBlocksActivation(): void
    {
        $this->plugin(self::BASE, '2.0.0', true);

        $refusal = $this->refusal(fn () => $this->guard->assertCanActivate($this->manifest(self::DEPENDENT, [
            'dependencies' => [['pluginId' => self::BASE, 'version' => '^1.0.0']],
        ])));

        self::assertSame(PluginDependencyGuard::ERROR_DEPENDENCY_MISSING, $refusal->getMessage());
        self::assertSame(
            [['plugin_id' => self::BASE, 'version' => '^1.0.0', 'reason' => 'out_of_range', 'installed_version' => '2.0.0']],
            $refusal->getData()['unmet'] ?? null,
        );
    }

    public function testEveryUnmetDependencyIsReportedNotJustTheFirst(): void
    {
        $this->plugin(self::BASE, '2.0.0', true);

        $refusal = $this->refusal(fn () => $this->guard->assertCanActivate($this->manifest(self::DEPENDENT, [
            'dependencies' => [
                ['pluginId' => self::BASE, 'version' => '^1.0.0'],
                ['pluginId' => self::OTHER],
            ],
        ])));

        $unmet = $refusal->getData()['unmet'] ?? [];
        self::assertIsArray($unmet);
        self::assertSame([self::BASE, self::OTHER], array_column($unmet, 'plugin_id'));
    }

    public function testAnOptionalDependencyNeverBlocks(): void
    {
        $this->guard->assertCanActivate($this->manifest(self::DEPENDENT, [
            'dependencies' => [['pluginId' => self::BASE, 'version' => '^9.0.0', 'required' => false]],
        ]));

        $this->addToAssertionCount(1);
    }

    public function testAnEnabledPluginTheManifestConflictsWithBlocksActivation(): void
    {
        $this->plugin(self::OTHER, '1.4.0', true);

        $refusal = $this->refusal(fn () => $this->guard->assertCanActivate($this->manifest(self::DEPENDENT, [
            'conflicts' => [['pluginId' => self::OTHER, 'version' => '<2.0.0', 'reason' => 'both draw the same page']],
        ])));

        self::assertSame(PluginDependencyGuard::ERROR_CONFLICT, $refusal->getMessage());
        self::assertSame(Response::HTTP_CONFLICT, $refusal->getCode());
        self::assertSame(
            [['plugin_id' => self::OTHER, 'version' => '<2.0.0', 'reason' => 'both draw the same page']],
            $refusal->getData()['conflicts'] ?? null,
        );
    }

    public function testAnEnabledPluginThatConflictsWithThisOneBlocksActivationToo(): void
    {
        $this->plugin(self::OTHER, '1.0.0', true, [
            'conflicts' => [['pluginId' => self::DEPENDENT, 'version' => '>=1.0.0']],
        ]);

        $refusal = $this->refusal(fn () => $this->guard->assertCanActivate($this->manifest(self::DEPENDENT)));

        self::assertSame(PluginDependencyGuard::ERROR_CONFLICT, $refusal->getMessage());
        self::assertSame([self::OTHER], array_column($this->listOf($refusal, 'conflicts'), 'plugin_id'));
    }

    public function testAConflictThatIsDisabledOrOutOfRangeDoesNotBlock(): void
    {
        $this->plugin(self::OTHER, '1.4.0', false);
        $this->plugin('qa-dep-newer', '3.0.0', true);

        $this->guard->assertCanActivate($this->manifest(self::DEPENDENT, [
            'conflicts' => [
                ['pluginId' => self::OTHER],
                ['pluginId' => 'qa-dep-newer', 'version' => '<2.0.0'],
            ],
        ]));

        $this->addToAssertionCount(1);
    }

    // -- names that are unique across enabled plugins (C2) -------------------------------

    public function testAnEnvironmentVariableAnEnabledPluginAlreadyUsesBlocksActivation(): void
    {
        $this->plugin(self::OTHER, '1.0.0', true, [
            'settings' => [['key' => 'api_key', 'label' => 'API key', 'type' => 'string', 'envOverride' => 'QA_DEP_API_KEY']],
        ]);

        $refusal = $this->refusal(fn () => $this->guard->assertCanActivate($this->manifest(self::DEPENDENT, [
            'settings' => [['key' => 'token', 'label' => 'Token', 'type' => 'string', 'envOverride' => 'QA_DEP_API_KEY']],
        ])));

        self::assertSame(PluginDependencyGuard::ERROR_MANIFEST_COLLISION, $refusal->getMessage());
        self::assertSame(Response::HTTP_CONFLICT, $refusal->getCode());
        self::assertSame(
            [['kind' => 'env_override', 'value' => 'QA_DEP_API_KEY', 'plugin_id' => self::OTHER]],
            $refusal->getData()['collisions'] ?? null,
        );
    }

    public function testAJsonLogicNamespaceAnEnabledPluginAlreadyOwnsBlocksActivation(): void
    {
        $this->plugin(self::OTHER, '1.0.0', true, [
            'jsonLogicOperations' => [['name' => 'qastats.mean', 'description' => 'Mean']],
        ]);

        $refusal = $this->refusal(fn () => $this->guard->assertCanActivate($this->manifest(self::DEPENDENT, [
            'jsonLogicOperations' => [['name' => 'qastats.median', 'description' => 'Median']],
        ])));

        self::assertSame(PluginDependencyGuard::ERROR_MANIFEST_COLLISION, $refusal->getMessage());
        self::assertSame(
            [['kind' => 'json_logic_namespace', 'value' => 'qastats', 'plugin_id' => self::OTHER]],
            $refusal->getData()['collisions'] ?? null,
        );
    }

    public function testTheSameNamesAreFineInADisabledPluginInOtherNamespacesAndInThePluginsOwnEarlierVersion(): void
    {
        $this->plugin(self::OTHER, '1.0.0', false, [
            'settings' => [['key' => 'api_key', 'label' => 'API key', 'type' => 'string', 'envOverride' => 'QA_DEP_API_KEY']],
            'jsonLogicOperations' => [['name' => 'qastats.mean', 'description' => 'Mean']],
        ]);
        $this->plugin(self::BASE, '1.0.0', true, [
            'settings' => [['key' => 'other_key', 'label' => 'Other key', 'type' => 'string', 'envOverride' => 'QA_DEP_OTHER_KEY']],
            'jsonLogicOperations' => [['name' => 'qaother.mean', 'description' => 'Mean']],
        ]);
        // The enabled plugin that is being updated holds the names itself.
        $this->plugin(self::DEPENDENT, '1.0.0', true, [
            'settings' => [['key' => 'api_key', 'label' => 'API key', 'type' => 'string', 'envOverride' => 'QA_DEP_API_KEY']],
            'jsonLogicOperations' => [['name' => 'qastats.mean', 'description' => 'Mean']],
        ]);

        $this->guard->assertCanActivate($this->manifest(self::DEPENDENT, [
            'settings' => [['key' => 'api_key', 'label' => 'API key', 'type' => 'string', 'envOverride' => 'QA_DEP_API_KEY']],
            'jsonLogicOperations' => [
                ['name' => 'qastats.mean', 'description' => 'Mean'],
                ['name' => 'qastats.median', 'description' => 'Median'],
            ],
        ]));

        $this->addToAssertionCount(1);
    }

    // -- disable / uninstall / purge: assertNoEnabledDependents -------------------------

    public function testAPluginThatAnEnabledPluginRequiresCannotLeave(): void
    {
        $this->plugin(self::BASE, '1.2.0', true);
        $this->plugin(self::DEPENDENT, '1.0.0', true, [
            'dependencies' => [['pluginId' => self::BASE, 'version' => '^1.0.0']],
        ]);

        $refusal = $this->refusal(fn () => $this->guard->assertNoEnabledDependents(self::BASE));

        self::assertSame(PluginDependencyGuard::ERROR_DEPENDENTS_ENABLED, $refusal->getMessage());
        self::assertSame(Response::HTTP_CONFLICT, $refusal->getCode());
        self::assertSame([['plugin_id' => self::DEPENDENT, 'version' => '^1.0.0']], $refusal->getData()['dependents'] ?? null);
    }

    public function testADisabledOrOptionalDependentDoesNotKeepAPluginInPlace(): void
    {
        $this->plugin(self::BASE, '1.2.0', true);
        $this->plugin(self::DEPENDENT, '1.0.0', false, [
            'dependencies' => [['pluginId' => self::BASE]],
        ]);
        $this->plugin(self::OTHER, '1.0.0', true, [
            'dependencies' => [['pluginId' => self::BASE, 'required' => false]],
        ]);

        $this->guard->assertNoEnabledDependents(self::BASE);

        $this->addToAssertionCount(1);
    }

    // -- the lifecycle refuses before it records or changes anything ---------------------

    public function testEnablingIsRefusedWhileARequiredDependencyIsDisabled(): void
    {
        $this->plugin(self::BASE, '1.2.0', false);
        $this->plugin(self::DEPENDENT, '1.0.0', false, [
            'dependencies' => [['pluginId' => self::BASE, 'version' => '^1.0.0']],
        ]);

        $refusal = $this->refusal(fn () => $this->service(PluginEnabler::class)->enable(self::DEPENDENT));

        self::assertSame(PluginDependencyGuard::ERROR_DEPENDENCY_MISSING, $refusal->getMessage());
        self::assertFalse($this->isEnabled(self::DEPENDENT), 'a refused enable changes nothing');
        self::assertSame(0, $this->operationCount(self::DEPENDENT), 'and records no operation');
    }

    public function testEnablingWorksOnceTheDependencyIsEnabled(): void
    {
        $this->plugin(self::BASE, '1.2.0', true);
        $dependent = $this->plugin(self::DEPENDENT, '1.0.0', false, [
            'dependencies' => [['pluginId' => self::BASE, 'version' => '^1.0.0']],
        ]);

        // The same call the enabler makes: it passes (the enabler itself also rewrites the lock file, so it is not run here).
        $this->guard->assertCanActivate(new PluginManifest($dependent->getManifestJson()));

        $this->addToAssertionCount(1);
    }

    public function testDisablingIsRefusedWhileAnEnabledPluginRequiresIt(): void
    {
        $this->plugin(self::BASE, '1.2.0', true);
        $this->plugin(self::DEPENDENT, '1.0.0', true, [
            'dependencies' => [['pluginId' => self::BASE]],
        ]);

        $refusal = $this->refusal(fn () => $this->service(PluginEnabler::class)->disable(self::BASE));

        self::assertSame(PluginDependencyGuard::ERROR_DEPENDENTS_ENABLED, $refusal->getMessage());
        self::assertTrue($this->isEnabled(self::BASE), 'a refused disable changes nothing');
        self::assertSame(0, $this->operationCount(self::BASE));
    }

    public function testUninstallingIsRefusedWhileAnEnabledPluginRequiresIt(): void
    {
        $this->plugin(self::BASE, '1.2.0', true);
        $this->plugin(self::DEPENDENT, '1.0.0', true, [
            'dependencies' => [['pluginId' => self::BASE]],
        ]);

        $refusal = $this->refusal(fn () => $this->service(PluginUninstaller::class)->request(self::BASE));

        self::assertSame(PluginDependencyGuard::ERROR_DEPENDENTS_ENABLED, $refusal->getMessage());
        self::assertSame(0, $this->operationCount(self::BASE), 'no uninstall operation is queued');
    }

    public function testPurgingIsRefusedWhileAnEnabledPluginRequiresIt(): void
    {
        $this->plugin(self::BASE, '1.2.0', true);
        $this->plugin(self::DEPENDENT, '1.0.0', true, [
            'dependencies' => [['pluginId' => self::BASE]],
        ]);

        $refusal = $this->refusal(fn () => $this->service(PluginPurger::class)->request(self::BASE, self::BASE));

        self::assertSame(PluginDependencyGuard::ERROR_DEPENDENTS_ENABLED, $refusal->getMessage());
        self::assertSame(Response::HTTP_CONFLICT, $refusal->getCode());
        self::assertSame(0, $this->operationCount(self::BASE), 'no purge operation is queued');
    }

    public function testUpdatingIsRefusedWhileARequiredDependencyOfTheNewVersionIsMissing(): void
    {
        $this->plugin(self::DEPENDENT, '1.0.0', true);
        $newVersion = $this->manifest(self::DEPENDENT, [
            'version' => '1.1.0',
            'dependencies' => [['pluginId' => self::BASE, 'version' => '^1.0.0']],
        ]);

        $refusal = $this->refusal(fn () => $this->service(PluginUpdater::class)->request(
            $newVersion,
            new ResolvedSource(ResolvedSource::KIND_PASTE, null, null, '{}', 'qa-signature', 'qa-key', [], [], []),
        ));

        self::assertSame(PluginDependencyGuard::ERROR_DEPENDENCY_MISSING, $refusal->getMessage());
        self::assertSame(Response::HTTP_CONFLICT, $refusal->getCode());
        self::assertSame(0, $this->operationCount(self::DEPENDENT), 'no update operation is queued');
    }

    public function testAnUpdateThatLeavesTheRangeAnEnabledDependentRequiresIsRefused(): void
    {
        $this->plugin(self::BASE, '1.2.0', true);
        $this->plugin(self::DEPENDENT, '1.0.0', true, [
            'dependencies' => [['pluginId' => self::BASE, 'version' => '^1.0.0']],
        ]);
        $newVersion = $this->manifestData(self::BASE, '2.0.0', []);

        $refusal = $this->refusal(fn () => $this->service(PluginUpdater::class)->request(
            new PluginManifest($newVersion),
            new ResolvedSource(ResolvedSource::KIND_PASTE, null, null, '{}', 'qa-signature', 'qa-key', [], [], []),
            true,
        ));

        self::assertSame(PluginDependencyGuard::ERROR_DEPENDENTS_ENABLED, $refusal->getMessage());
        self::assertSame(Response::HTTP_CONFLICT, $refusal->getCode());
        self::assertSame([['plugin_id' => self::DEPENDENT, 'version' => '^1.0.0']], $refusal->getData()['dependents'] ?? null);
        self::assertSame(0, $this->operationCount(self::BASE), 'no update operation is queued');
    }

    public function testAnUpdateWithinTheRangeOfEveryEnabledDependentIsAcceptedByTheGuard(): void
    {
        $this->plugin(self::BASE, '1.2.0', true);
        $this->plugin(self::DEPENDENT, '1.0.0', true, [
            'dependencies' => [['pluginId' => self::BASE, 'version' => '^1.0.0']],
        ]);
        // A disabled dependent and an optional dependency never hold a version back.
        $this->plugin(self::OTHER, '1.0.0', false, [
            'dependencies' => [['pluginId' => self::BASE, 'version' => '^1.0.0']],
        ]);
        $this->plugin('qa-dep-optional', '1.0.0', true, [
            'dependencies' => [['pluginId' => self::BASE, 'version' => '^1.0.0', 'required' => false]],
        ]);

        // The same call PluginUpdater::request() makes (its success path also dispatches the operation, which
        // writes the plugin lock file of the working tree and is not run from a test).
        $this->guard->assertUpdateKeepsDependents(self::BASE, '1.3.0');

        $refusal = $this->refusal(fn () => $this->guard->assertUpdateKeepsDependents(self::BASE, '2.0.0'));
        self::assertSame([self::DEPENDENT], array_column($this->listOf($refusal, 'dependents'), 'plugin_id'));
    }

    public function testInstallingIsRefusedWhileARequiredDependencyIsMissing(): void
    {
        $manifest = $this->manifest(self::DEPENDENT, [
            'dependencies' => [['pluginId' => self::BASE, 'version' => '^1.0.0']],
        ]);

        $refusal = $this->refusal(fn () => $this->service(PluginInstaller::class)->request(
            $manifest,
            new ResolvedSource(ResolvedSource::KIND_PASTE, null, null, '{}', 'qa-signature', 'qa-key', [], [], []),
        ));

        self::assertSame(PluginDependencyGuard::ERROR_DEPENDENCY_MISSING, $refusal->getMessage());
        self::assertSame(Response::HTTP_CONFLICT, $refusal->getCode());
        self::assertSame(0, $this->operationCount(self::DEPENDENT), 'no install operation is queued');
    }

    // -- helpers --------------------------------------------------------------------------

    /**
     * A plugin row as an install leaves it: version, enabled flag and the manifest it carries.
     *
     * @param array<string, mixed> $manifestExtra further manifest keys (`dependencies`, `conflicts`, `settings`, `jsonLogicOperations`)
     */
    private function plugin(string $id, string $version, bool $enabled, array $manifestExtra = []): Plugin
    {
        $plugin = new Plugin($id, 'QA ' . $id, $version, $this->hostSdkVersion());
        $plugin->setEnabled($enabled);
        $plugin->setManifestJson($this->manifestData($id, $version, $manifestExtra));
        $this->em->persist($plugin);
        $this->em->flush();

        return $plugin;
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function manifest(string $id, array $extra = []): PluginManifest
    {
        return new PluginManifest($this->manifestData($id, '1.0.0', $extra));
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function manifestData(string $id, string $version, array $extra): array
    {
        return array_merge([
            'id' => $id,
            'name' => 'QA ' . $id,
            'version' => $version,
            'pluginApiVersion' => $this->hostSdkVersion(),
        ], $extra);
    }

    /**
     * The plugin API version this host ships. The fixture manifests declare it, so the install and
     * update refusals under test are the dependency rules and not the SDK compatibility check that
     * runs before them (a hard-coded version went stale with every SDK bump).
     */
    private function hostSdkVersion(): string
    {
        $version = self::getContainer()->getParameter('selfhelp.plugin_api_version');
        self::assertIsString($version);

        return $version;
    }

    private function refusal(\Closure $call): ServiceException
    {
        try {
            $call();
        } catch (ServiceException $e) {
            return $e;
        }

        self::fail('The call should have been refused with a ServiceException.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listOf(ServiceException $refusal, string $key): array
    {
        $list = $refusal->getData()[$key] ?? [];
        self::assertIsArray($list);

        return array_map(static fn (mixed $item): array => self::asArray($item), array_values($list));
    }

    private function isEnabled(string $pluginId): bool
    {
        return self::coerceInt($this->em->getConnection()->fetchOne('SELECT enabled FROM plugins WHERE plugin_id = ?', [$pluginId])) === 1;
    }

    private function operationCount(string $pluginId): int
    {
        return self::coerceInt($this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM plugin_operations WHERE plugin_id = ?', [$pluginId]));
    }
}
