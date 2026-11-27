<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Plugin;

use App\Entity\Plugin\Plugin;
use App\Plugin\Cache\PluginCacheInvalidator;
use App\Service\JSON\JsonSchemaValidationService;
use App\Tests\Support\Plugin\QaDoubleJsonLogicOperation;
use App\Tests\Support\Plugin\QaFailingJsonLogicOperation;
use App\Tests\Support\Plugin\QaUsersPluginFixture;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;

/**
 * C16, the web side: `GET /cms-api/v1/plugins/manifest` is the data the runtime already loads, and the condition
 * builder of the admin UI lists the JSON Logic operations of enabled plugins from it. The endpoint is public and
 * projects fields one by one (no raw manifest), so each plugin entry carries `jsonLogicOperations`: the name and the
 * optional description of every declared operation, and nothing else. A disabled plugin is not in the list at all.
 */
#[PhpUnitGroup('security')]
final class PluginManifestOperationsTest extends QaWebTestCase
{
    private const ROUTE = '/cms-api/v1/plugins/manifest';
    private const SCHEMA = 'responses/frontend/plugin_manifest';

    private EntityManagerInterface $em;
    private PluginCacheInvalidator $invalidator;
    private JsonSchemaValidationService $schema;

    protected function setUp(): void
    {
        parent::setUp();
        // One container for the test and the requests, so the plugin installed here is the one they read.
        $this->client->disableReboot();
        $this->em = $this->service(EntityManagerInterface::class);
        $this->invalidator = $this->service(PluginCacheInvalidator::class);
        $this->schema = $this->service(JsonSchemaValidationService::class);
        QaUsersPluginFixture::install($this->em, $this->invalidator, [], []);
    }

    protected function tearDown(): void
    {
        // The cache pool outlives the database rollback: the next test must not find the QA plugin.
        QaUsersPluginFixture::forget($this->invalidator);
        parent::tearDown();
    }

    public function testTheOperationsOfAnEnabledPluginAreListedForAnAnonymousCaller(): void
    {
        $this->declareOperations([
            ['name' => QaDoubleJsonLogicOperation::NAME, 'description' => 'Doubles its argument'],
            ['name' => QaFailingJsonLogicOperation::NAME],
        ]);

        $envelope = $this->fetch();
        $this->assertEnvelopeSuccess($envelope);
        self::assertFalse($envelope['logged_in'], 'the endpoint is public: nobody is signed in');

        self::assertSame(
            [
                ['name' => 'qa.double', 'description' => 'Doubles its argument'],
                ['name' => 'qa.fail'],
            ],
            $this->entryOf($envelope)['jsonLogicOperations'] ?? null,
            'name and description of each declared operation, the description only when there is one',
        );
    }

    public function testAPluginThatDeclaresNoOperationsListsAnEmptyList(): void
    {
        $this->declareOperations([]);
        self::assertSame([], $this->entryOf($this->fetch())['jsonLogicOperations'] ?? null, 'an empty declaration');

        $manifest = $this->manifestWithOperations([]);
        unset($manifest['jsonLogicOperations']);
        $this->storeManifest($manifest);

        self::assertSame([], $this->entryOf($this->fetch())['jsonLogicOperations'] ?? null, 'no key in the manifest');
    }

    public function testADisabledPluginIsNotListedSoItsOperationsAreNotOffered(): void
    {
        $this->declareOperations([['name' => QaDoubleJsonLogicOperation::NAME]]);
        self::assertNotNull($this->findEntry($this->fetch()), 'enabled: listed');

        QaUsersPluginFixture::setEnabled($this->loadPlugin(), false, $this->em, $this->invalidator);

        self::assertNull($this->findEntry($this->fetch()), 'disabled: not in the manifest at all');

        QaUsersPluginFixture::setEnabled($this->loadPlugin(), true, $this->em, $this->invalidator);

        self::assertSame(
            [['name' => 'qa.double']],
            $this->entryOf($this->fetch())['jsonLogicOperations'] ?? null,
            'enabled again: listed again',
        );
    }

    public function testEntriesTheManifestValidatorWouldRefuseAreLeftOut(): void
    {
        $this->declareOperations([
            'not an object',
            ['description' => 'no name'],
            ['name' => 42],
            ['name' => ''],
            ['name' => 'qa.ok', 'description' => 7],
            ['name' => 'qa.fine', 'description' => ''],
        ]);

        self::assertSame(
            [['name' => 'qa.ok'], ['name' => 'qa.fine']],
            $this->entryOf($this->fetch())['jsonLogicOperations'] ?? null,
            'only names that are text survive; a description that is not text, or is empty, is dropped',
        );
    }

    public function testTheAnswerMatchesTheResponseSchema(): void
    {
        $this->declareOperations([
            ['name' => QaDoubleJsonLogicOperation::NAME, 'description' => 'Doubles its argument'],
            ['name' => QaFailingJsonLogicOperation::NAME],
        ]);
        $this->fetch();

        $errors = $this->schema->validate($this->responseObject(), self::SCHEMA);

        self::assertSame([], $errors, "The manifest answer must match its schema:\n" . implode("\n", $errors));
    }

    public function testAPluginWithoutFeatureFlagsAnswersAnEmptyObjectThatMatchesTheSchema(): void
    {
        $manifest = QaUsersPluginFixture::manifestData();
        $manifest['jsonLogicOperations'] = [['name' => QaDoubleJsonLogicOperation::NAME]];
        unset($manifest['featureFlags']);
        $this->storeManifest($manifest);
        $this->fetch();

        // `featureFlags` is `type: object`: an empty map must be `{}`, never `[]`.
        self::assertStringContainsString('"featureFlags":{}', (string) $this->client->getResponse()->getContent());
        $errors = $this->schema->validate($this->responseObject(), self::SCHEMA);
        self::assertSame([], $errors, "A plugin without feature flags must match the schema:\n" . implode("\n", $errors));
    }

    public function testTheSchemaDescribesTheFieldAndDoesNotJustAllowIt(): void
    {
        $this->declareOperations([['name' => QaDoubleJsonLogicOperation::NAME]]);
        $this->fetch();

        $response = $this->responseObject();
        self::assertSame([], $this->schema->validate($response, self::SCHEMA), 'the answer as it is passes');

        $this->putOperationsOfTheQaPlugin($response, [(object) ['name' => 5]]);
        self::assertNotSame([], $this->schema->validate($response, self::SCHEMA), 'a name that is not text is refused');

        $this->putOperationsOfTheQaPlugin($response, [(object) ['name' => 'qa.double', 'secret' => 'x']]);
        self::assertNotSame([], $this->schema->validate($response, self::SCHEMA), 'a field nobody declared is refused');

        $this->putOperationsOfTheQaPlugin($response, [(object) ['description' => 'no name']]);
        self::assertNotSame([], $this->schema->validate($response, self::SCHEMA), 'a name is required');
    }

    // == helpers ============================================================================

    /**
     * Store the manifest of the QA plugin with the given operations (as the installer would, apart from
     * the entries a test puts there on purpose) and reset the plugin caches.
     *
     * @param array<int, mixed> $operations
     */
    private function declareOperations(array $operations): void
    {
        $this->storeManifest($this->manifestWithOperations($operations));
    }

    /**
     * The QA plugin's manifest with the given operations. It also declares one feature flag, so that the entry
     * of the answer has what a real plugin with flags has (a plugin without any is covered by its own test).
     *
     * @param array<int, mixed> $operations
     * @return array<string, mixed>
     */
    private function manifestWithOperations(array $operations): array
    {
        $manifest = QaUsersPluginFixture::manifestData();
        $manifest['jsonLogicOperations'] = $operations;
        $manifest['featureFlags'] = [['key' => 'qa_flag', 'defaultEnabled' => true]];

        return $manifest;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function storeManifest(array $manifest): void
    {
        $this->loadPlugin()->setManifestJson($manifest);
        $this->em->flush();
        $this->invalidator->invalidatePluginSurfaceCaches();
    }

    /**
     * The QA plugin as the database has it now. A plugin entity is never kept across requests: the first
     * request after a change resets the services of the container, the entity manager among them, and flushing
     * an entity the new manager does not know writes nothing.
     */
    private function loadPlugin(): Plugin
    {
        $plugin = $this->em->getRepository(Plugin::class)->findOneBy(['pluginId' => QaUsersPluginFixture::PLUGIN_ID]);
        self::assertInstanceOf(Plugin::class, $plugin, 'the QA plugin is installed');

        return $plugin;
    }

    /**
     * The public manifest, requested without signing in.
     *
     * @return array<string, mixed>
     */
    private function fetch(): array
    {
        $this->client->request('GET', self::ROUTE);

        return $this->decode($this->client->getResponse());
    }

    private function responseObject(): \stdClass
    {
        return self::asObject(json_decode((string) $this->client->getResponse()->getContent()), 'the answer is a JSON object');
    }

    /**
     * Replace `jsonLogicOperations` of the QA plugin in a decoded answer, to see what the schema says to it.
     *
     * @param list<\stdClass> $operations
     */
    private function putOperationsOfTheQaPlugin(\stdClass $response, array $operations): void
    {
        $found = false;
        foreach (self::asList($this->jsonGet($response, 'data', 'plugins'), 'plugins is a list') as $plugin) {
            $plugin = self::asObject($plugin);
            if (($plugin->pluginId ?? null) === QaUsersPluginFixture::PLUGIN_ID) {
                $plugin->jsonLogicOperations = $operations;
                $found = true;
            }
        }
        self::assertTrue($found, 'the QA plugin is in the answer');
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array<string, mixed>|null the entry of the QA plugin, or null when it is not listed
     */
    private function findEntry(array $envelope): ?array
    {
        $data = $this->assertEnvelopeSuccess($envelope);
        foreach (self::asList($data['plugins'] ?? null, 'plugins is a list') as $plugin) {
            $entry = self::asArray($plugin);
            if (($entry['pluginId'] ?? null) === QaUsersPluginFixture::PLUGIN_ID) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array<string, mixed>
     */
    private function entryOf(array $envelope): array
    {
        $entry = $this->findEntry($envelope);
        self::assertNotNull($entry, 'the enabled QA plugin is listed in the public manifest');

        return $entry;
    }
}
