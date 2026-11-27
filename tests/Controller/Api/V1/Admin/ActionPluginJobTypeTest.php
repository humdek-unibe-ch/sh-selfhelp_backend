<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Entity\Action;
use App\Entity\DataTable;
use App\Entity\Lookup;
use App\Plugin\Cache\PluginCacheInvalidator;
use App\Plugin\Lifecycle\PluginCatalogueSynchronizer;
use App\Service\Action\ActionConfig;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use App\Tests\Support\Factories\RoleDataAccessFactory;
use App\Tests\Support\Factories\RoleFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\Plugin\QaItemPluginFixture;
use App\Tests\Support\Plugin\QaPlatformPluginFixture;
use App\Tests\Support\Plugin\ReloadsApiRoutes;
use App\Tests\Support\QaWebTestCase;
use App\Tests\Support\Security\PermissionMatrixProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * C12: the job types an action may plan, and the check of a plugin job when the action is saved.
 *
 *  - `GET /cms-api/v1/admin/actions/plugin-job-types` lists the `actionPlannable` types of the
 *    **enabled** plugins for a person who holds `admin.action.read`, with the schema of their
 *    `plugin_config` and the properties that bind plugin items;
 *  - saving an action checks every job whose type is not a core type: a type no enabled plugin
 *    offers is 400 `unknown_job_type`, a config that breaks the schema is 400
 *    `invalid_plugin_job_config` (the data names the failing path), an item the editor may not
 *    view is 403 `resource_not_bindable` (C8). Nothing is written on a refusal.
 *
 * The QA platform plugin ({@see QaPlatformPluginFixture}) offers `qa_note_job`.
 */
#[Group('security')]
final class ActionPluginJobTypeTest extends QaWebTestCase
{
    use PermissionMatrixProvider;
    use ReloadsApiRoutes;

    private const ROUTE = '/cms-api/v1/admin/actions/plugin-job-types';
    private const VISIBLE_ITEM = 9101;
    private const HIDDEN_ITEM = 9102;

    private EntityManagerInterface $em;
    private UserFactory $users;
    private RoleFactory $roles;
    private RoleDataAccessFactory $grants;
    private int $dataTableId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reloadApiRoutes();

        $this->em = $this->service(EntityManagerInterface::class);
        $this->users = new UserFactory(
            $this->em,
            $this->service(UserPasswordHasherInterface::class),
            $this->service(LookupService::class),
        );
        $this->roles = new RoleFactory($this->em);
        $this->grants = new RoleDataAccessFactory(
            $this->em,
            $this->service(LookupService::class),
            $this->service(DataAccessSecurityService::class),
        );
        $this->dataTableId = $this->createDataTable();
    }

    protected function tearDown(): void
    {
        // The cache pool outlives the database rollback: the next test must not find the QA plugin.
        QaPlatformPluginFixture::forgetCaches($this->service(CacheService::class), $this->service(PluginCacheInvalidator::class));
        parent::tearDown();
    }

    // -- the list of types ---------------------------------------------------------------

    public function testAnActionReaderSeesTheActionPlannableTypesOfEnabledPlugins(): void
    {
        $this->installPlugin();

        $data = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::ROUTE, null, $this->loginAsQaAdmin()));

        $types = $this->typesByCode($data);
        self::assertSame([QaPlatformPluginFixture::NOTE_JOB], array_keys($types), 'Only a type with actionPlannable is offered to actions.');

        $note = $types[QaPlatformPluginFixture::NOTE_JOB];
        self::assertSame(QaPlatformPluginFixture::PLUGIN_ID, $note['plugin_id']);
        self::assertSame('QA note job', $note['label']);
        self::assertSame(['item_id' => QaItemPluginFixture::TYPE_CODE], $note['resource_fields']);
        $schema = $this->asArray($note['config_schema']);
        self::assertSame(['item_id'], $schema['required'] ?? null);
        self::assertSame('variable-text', $this->asArray($this->asArray($schema['properties'] ?? null)['note'] ?? null)['format'] ?? null);
    }

    public function testATypeOfADisabledPluginIsNotListed(): void
    {
        $this->installPlugin(enabled: false);

        $data = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::ROUTE, null, $this->loginAsQaAdmin()));

        self::assertSame([], $this->typesByCode($data));
    }

    public function testNothingIsListedWhenNoPluginOffersAType(): void
    {
        $data = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::ROUTE, null, $this->loginAsQaAdmin()));

        self::assertSame([], $this->typesByCode($data));
    }

    public function testTheTypeListEnforcesThePermissionMatrix(): void
    {
        $this->installPlugin();

        $this->assertAdminOnlyMatrix('GET', self::ROUTE);
    }

    public function testAReaderOfActionsWithoutAnyOtherRightMaySeeTheTypes(): void
    {
        $this->installPlugin();
        $reader = $this->editor('qa_c12_reader', ['admin.action.read']);

        $data = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::ROUTE, null, $reader));

        self::assertArrayHasKey(QaPlatformPluginFixture::NOTE_JOB, $this->typesByCode($data));
    }

    // -- saving an action ----------------------------------------------------------------

    public function testAnActionWithAValidPluginConfigIsSaved(): void
    {
        $this->installPlugin();
        $config = $this->actionConfig(['item_id' => self::VISIBLE_ITEM, 'note' => 'Hello {{recipient.name}}', 'priority' => 2]);

        $envelope = $this->createAction($config, $this->loginAsQaAdmin());

        $data = $this->assertEnvelopeSuccess($envelope, Response::HTTP_CREATED);
        $stored = $this->storedConfig($this->asInt($data['id']));
        self::assertSame(
            ['item_id' => self::VISIBLE_ITEM, 'note' => 'Hello {{recipient.name}}', 'priority' => 2],
            $this->firstJob($stored)[ActionConfig::PLUGIN_CONFIG] ?? null,
        );
    }

    public function testAnActionWithAWrongTypeInThePluginConfigIsRefused(): void
    {
        $this->installPlugin();
        $before = $this->actionCount();

        $envelope = $this->createAction($this->actionConfig(['item_id' => self::VISIBLE_ITEM, 'priority' => 'high']), $this->loginAsQaAdmin());

        $this->assertEnvelope400($envelope);
        self::assertSame('invalid_plugin_job_config', $envelope['error'] ?? null);
        $data = $this->asArray($envelope['data'] ?? null);
        self::assertSame(QaPlatformPluginFixture::NOTE_JOB, $data['job_type'] ?? null);
        self::assertSame('blocks.0.jobs.0.plugin_config.priority', $data['path'] ?? null);
        self::assertSame($before, $this->actionCount(), 'A refused save writes nothing.');
    }

    public function testAnActionWithAMissingRequiredPropertyIsRefused(): void
    {
        $this->installPlugin();

        $envelope = $this->createAction($this->actionConfig(['note' => 'no item']), $this->loginAsQaAdmin());

        $this->assertEnvelope400($envelope);
        self::assertSame('invalid_plugin_job_config', $envelope['error'] ?? null);
        self::assertSame('blocks.0.jobs.0.plugin_config.item_id', $this->asArray($envelope['data'] ?? null)['path'] ?? null);
    }

    public function testAnActionWithAPropertyTheTypeDoesNotDeclareIsRefused(): void
    {
        $this->installPlugin();

        $envelope = $this->createAction($this->actionConfig(['item_id' => self::VISIBLE_ITEM, 'undeclared' => 'x']), $this->loginAsQaAdmin());

        $this->assertEnvelope400($envelope);
        self::assertSame('invalid_plugin_job_config', $envelope['error'] ?? null);
    }

    public function testAnUnknownJobTypeIsRefused(): void
    {
        $this->installPlugin();

        $envelope = $this->createAction($this->actionConfig(['item_id' => self::VISIBLE_ITEM], 'qa_no_such_job'), $this->loginAsQaAdmin());

        $this->assertEnvelope400($envelope);
        self::assertSame('unknown_job_type', $envelope['error'] ?? null);
        self::assertSame('blocks.0.jobs.0.job_type', $this->asArray($envelope['data'] ?? null)['path'] ?? null);
    }

    public function testATypeOfADisabledPluginIsUnknownWhenSaving(): void
    {
        $this->installPlugin(enabled: false);

        $envelope = $this->createAction($this->actionConfig(['item_id' => self::VISIBLE_ITEM]), $this->loginAsQaAdmin());

        $this->assertEnvelope400($envelope);
        self::assertSame('unknown_job_type', $envelope['error'] ?? null);
    }

    public function testACoreJobTypeIsNotCheckedAgainstPlugins(): void
    {
        $this->installPlugin();
        $config = [ActionConfig::BLOCKS => [[ActionConfig::JOBS => [[
            ActionConfig::JOB_NAME => 'qa_core_job',
            ActionConfig::JOB_TYPE => ActionConfig::JOB_TYPE_ADD_GROUP,
            ActionConfig::SCHEDULE_TIME => [ActionConfig::JOB_SCHEDULE_TYPES => LookupService::ACTION_SCHEDULE_TYPES_IMMEDIATELY],
        ]]]]];

        $this->assertEnvelopeSuccess($this->createAction($config, $this->loginAsQaAdmin()), Response::HTTP_CREATED);
    }

    public function testAnEditorMayBindAnItemTheyMayView(): void
    {
        $this->installPlugin();
        $editor = $this->editor('qa_c12_allow', ['admin.action.read', 'admin.action.update'], viewItem: self::VISIBLE_ITEM);

        $envelope = $this->createAction($this->actionConfig(['item_id' => self::VISIBLE_ITEM]), $editor);

        $this->assertEnvelopeSuccess($envelope, Response::HTTP_CREATED);
    }

    public function testAnEditorWhoMayNotViewTheItemIsRefusedAndNothingIsWritten(): void
    {
        $this->installPlugin();
        $editor = $this->editor('qa_c12_deny', ['admin.action.read', 'admin.action.update'], viewItem: self::VISIBLE_ITEM);
        $before = $this->actionCount();

        $envelope = $this->createAction($this->actionConfig(['item_id' => self::HIDDEN_ITEM]), $editor);

        $this->assertEnvelope403($envelope);
        self::assertSame('resource_not_bindable', $envelope['error'] ?? null);
        self::assertSame($before, $this->actionCount(), 'A refused save writes nothing.');
    }

    public function testAnEditorWhoMayNotViewTheItemCannotChangeAnActionToBindIt(): void
    {
        $this->installPlugin();
        $admin = $this->loginAsQaAdmin();
        $created = $this->assertEnvelopeSuccess(
            $this->createAction($this->actionConfig(['item_id' => self::VISIBLE_ITEM]), $admin),
            Response::HTTP_CREATED,
        );
        $actionId = $this->asInt($created['id']);
        $editor = $this->editor('qa_c12_update', ['admin.action.read', 'admin.action.update'], viewItem: self::VISIBLE_ITEM);

        $envelope = $this->jsonRequest(
            'PUT',
            '/cms-api/v1/admin/actions/' . $actionId,
            ['config' => $this->actionConfig(['item_id' => self::HIDDEN_ITEM])],
            $editor,
        );

        $this->assertEnvelope403($envelope);
        self::assertSame('resource_not_bindable', $envelope['error'] ?? null);
        self::assertSame(
            self::VISIBLE_ITEM,
            $this->asArray($this->firstJob($this->storedConfig($actionId))[ActionConfig::PLUGIN_CONFIG] ?? null)['item_id'] ?? null,
            'The refused update leaves the action as it was.',
        );
    }

    public function testTheSuperuserMayBindAnyItem(): void
    {
        $this->installPlugin();

        $envelope = $this->createAction($this->actionConfig(['item_id' => self::HIDDEN_ITEM]), $this->loginAsQaAdmin());

        $this->assertEnvelopeSuccess($envelope, Response::HTTP_CREATED);
    }

    // -- helpers -------------------------------------------------------------------------

    private function installPlugin(bool $enabled = true): void
    {
        QaPlatformPluginFixture::install(
            $this->em,
            $this->service(PluginCatalogueSynchronizer::class),
            $this->service(PluginCacheInvalidator::class),
            $enabled,
        );
    }

    /**
     * A person with the given route permissions and, when `$viewItem` is set, the view grant on that
     * plugin item (and on no other). Returns the login token.
     *
     * @param list<string> $permissions
     */
    private function editor(string $slug, array $permissions, ?int $viewItem = null): string
    {
        $email = $slug . '@selfhelp.test';
        $user = $this->users->createUser($email, 'QA ' . $slug);
        $role = $this->roles->createRole($slug . '_role');
        foreach ($permissions as $permission) {
            $this->roles->grantPermission($role, $permission);
        }
        $this->grants->assignRoleToUser($user, $role);
        if ($viewItem !== null) {
            $this->grants->grantResourceAccess($role, QaItemPluginFixture::TYPE_CODE, $viewItem, DataAccessSecurityService::PERMISSION_READ);
        }

        return $this->loginAs($email);
    }

    /**
     * @param array<string, mixed> $pluginConfig
     *
     * @return array<string, mixed>
     */
    private function actionConfig(array $pluginConfig, string $jobType = QaPlatformPluginFixture::NOTE_JOB): array
    {
        return [ActionConfig::BLOCKS => [[ActionConfig::JOBS => [[
            ActionConfig::JOB_NAME => 'qa_plugin_job',
            ActionConfig::JOB_TYPE => $jobType,
            ActionConfig::PLUGIN_CONFIG => $pluginConfig,
            ActionConfig::SCHEDULE_TIME => [ActionConfig::JOB_SCHEDULE_TYPES => LookupService::ACTION_SCHEDULE_TYPES_IMMEDIATELY],
        ]]]]];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed> the response envelope
     */
    private function createAction(array $config, string $token): array
    {
        return $this->jsonRequest('POST', '/cms-api/v1/admin/actions', [
            'name' => 'qa_c12_action_' . bin2hex(random_bytes(4)),
            'id_action_trigger_types' => $this->triggerTypeId(),
            'id_data_tables' => $this->dataTableId,
            'config' => $config,
        ], $token);
    }

    private function createDataTable(): int
    {
        $table = new DataTable();
        $table->setName('qa_c12_action_table');
        $table->setDisplayName('QA C12 action table');
        $table->setTimestamp(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->em->persist($table);
        $this->em->flush();

        return (int) $table->getId();
    }

    private function triggerTypeId(): int
    {
        $lookup = $this->em->getRepository(Lookup::class)->findOneBy([
            'typeCode' => LookupService::ACTION_TRIGGER_TYPES,
            'lookupCode' => LookupService::ACTION_TRIGGER_TYPES_FINISHED,
        ]);
        self::assertInstanceOf(Lookup::class, $lookup);

        return (int) $lookup->getId();
    }

    private function actionCount(): int
    {
        return self::coerceInt($this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM actions'));
    }

    /**
     * @return array<string, mixed> the decoded config of the action as stored
     */
    private function storedConfig(int $actionId): array
    {
        $this->em->clear();
        $action = $this->em->getRepository(Action::class)->find($actionId);
        self::assertInstanceOf(Action::class, $action);
        $decoded = json_decode((string) $action->getConfig(), true);

        return $this->asArray($decoded);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function firstJob(array $config): array
    {
        $block = $this->asArray($this->asList($config[ActionConfig::BLOCKS] ?? null)[0] ?? null);

        return $this->asArray($this->asList($block[ActionConfig::JOBS] ?? null)[0] ?? null);
    }

    /**
     * The `data` of the list route as `type => entry`.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, array<string, mixed>>
     */
    private function typesByCode(array $data): array
    {
        $types = [];
        foreach ($data as $entry) {
            $entry = $this->asArray($entry);
            $types[$this->asString($entry['type'] ?? '')] = $entry;
        }

        return $types;
    }
}
