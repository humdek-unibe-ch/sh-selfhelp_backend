<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Plugin\Plugin;
use App\Entity\Plugin\PluginSetting;
use App\Plugin\Cache\PluginCacheInvalidator;
use App\Plugin\Event\Lifecycle\PluginPurgedEvent;
use App\Plugin\Event\Lifecycle\PluginUninstalledEvent;
use App\Plugin\Lifecycle\PluginCatalogueSynchronizer;
use App\Plugin\Registry\PluginRegistryService;
use App\Plugin\Settings\PluginSettingsCrypto;
use App\Plugin\Settings\PluginSettingsInterface;
use App\Plugin\Settings\PluginSettingsService;
use App\Repository\Plugin\PluginSettingRepository;
use App\Service\Auth\UserContextService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\TransactionService;
use App\Tests\Support\Plugin\QaItemPluginFixture;
use App\Tests\Support\Plugin\QaPlatformPluginFixture;
use App\Tests\Support\Plugin\ReloadsApiRoutes;
use App\Tests\Support\QaWebTestCase;
use App\Tests\Support\Security\PermissionMatrixProvider;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * C14: the settings a plugin declares in its manifest, as the administrator edits them.
 *
 *  - `GET /cms-api/v1/admin/plugins/{pluginId}/settings` lists every declared setting with `has_value`; a secret
 *    never shows its `value` (null) and only a `hint` (its last four characters when it has twelve or more);
 *  - `PUT` of the same path takes `{ "values": { "<key>": <value>|null } }`: a value sets (a secret is stored as
 *    `enc:v1:`, checked here by SQL), `null` clears, a key that is left out stays, one bad entry writes nothing
 *    (400 `invalid_plugin_setting`);
 *  - `PluginSettingsInterface::get()` hands the plugin the clear value: the environment variable named by
 *    `envOverride` first, then the stored value, then the manifest default;
 *  - a value encrypted under another `APP_SECRET` reads as not set;
 *  - an uninstall keeps the rows (a reinstall finds them), the purge deletes them;
 *  - the transaction log names the changed keys and never a value.
 *
 * Both routes need `admin.plugins.manage`. The QA platform plugin ({@see QaPlatformPluginFixture}) declares the
 * settings `api_url` (url, env `QA_PLATFORM_API_URL`), `api_key` (secret, required), `batch_size` (number,
 * default 10), `verbose` (boolean, default false) and `label` (string).
 */
#[Group('security')]
final class PluginSettingsControllerTest extends QaWebTestCase
{
    use PermissionMatrixProvider;
    use ReloadsApiRoutes;

    private const PLUGIN = QaPlatformPluginFixture::PLUGIN_ID;
    private const SECRET = 'sk-live-1234567890';
    private const URL = 'https://qa.example.org/v1';
    private const ENV_URL = 'https://from-the-environment.example.org/';

    private EntityManagerInterface $em;
    private ?string $admin = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reloadApiRoutes();
        $this->em = $this->service(EntityManagerInterface::class);
        $this->setEnvironment(QaPlatformPluginFixture::SETTING_URL_ENV, null);
    }

    protected function tearDown(): void
    {
        $this->setEnvironment(QaPlatformPluginFixture::SETTING_URL_ENV, null);
        // The cache pool outlives the database rollback: the next test must not find the QA plugin.
        QaPlatformPluginFixture::forgetCaches($this->service(CacheService::class), $this->service(PluginCacheInvalidator::class));
        parent::tearDown();
    }

    // -- the list ----------------------------------------------------------------------------

    public function testTheListHasOneEntryPerDeclaredSettingInManifestOrder(): void
    {
        $this->installPlugin();

        $data = $this->assertEnvelopeSuccess($this->get());

        self::assertSame(
            ['api_url', 'api_key', 'batch_size', 'verbose', 'label'],
            array_map(fn (mixed $entry): string => $this->asString($this->asArray($entry)['key'] ?? null), array_values($data)),
        );
        $key = $this->entry($data, 'api_key');
        self::assertSame('API key', $key['label']);
        self::assertSame('string', $key['type']);
        self::assertTrue($key['secret']);
        self::assertTrue($key['required']);
        self::assertSame('url', $this->entry($data, 'api_url')['type']);
        self::assertSame('number', $this->entry($data, 'batch_size')['type']);
        self::assertSame('boolean', $this->entry($data, 'verbose')['type']);
        self::assertFalse($this->entry($data, 'label')['required']);
    }

    public function testNothingIsSetAtFirstAndTheDefaultsShowWithoutCountingAsAValue(): void
    {
        $this->installPlugin();

        $data = $this->assertEnvelopeSuccess($this->get());

        foreach ($data as $entry) {
            $entry = $this->asArray($entry);
            self::assertFalse($entry['has_value'], $this->asString($entry['key']) . ': nothing was entered.');
            self::assertFalse($entry['overridden_by_env']);
        }
        self::assertSame(10, $this->entry($data, 'batch_size')['value'], 'The default of a number shows as the value in effect.');
        self::assertFalse($this->entry($data, 'verbose')['value']);
        self::assertNull($this->entry($data, 'label')['value']);
        self::assertNull($this->entry($data, 'api_url')['value']);
        self::assertNull($this->entry($data, 'api_key')['value']);
        self::assertNull($this->entry($data, 'api_key')['hint']);
    }

    public function testAPluginWithoutSettingsHasAnEmptyList(): void
    {
        QaItemPluginFixture::install($this->em, $this->service(PluginCatalogueSynchronizer::class), $this->service(PluginCacheInvalidator::class));

        self::assertSame([], $this->assertEnvelopeSuccess($this->get()));
    }

    public function testAnUnknownPluginIsNotFoundOnBothRoutes(): void
    {
        $this->assertEnvelope404($this->get('qa-no-such-plugin'));
        $this->assertEnvelope404($this->put(['label' => 'x'], 'qa-no-such-plugin'));
    }

    // -- saving ------------------------------------------------------------------------------

    public function testSavedValuesAreReturnedAsTheirDeclaredTypesAndReadBackByThePlugin(): void
    {
        $this->installPlugin();

        $data = $this->assertEnvelopeSuccess($this->put([
            'api_url' => self::URL,
            'api_key' => self::SECRET,
            'batch_size' => 25,
            'verbose' => true,
            'label' => 'Hello',
        ]));

        self::assertSame(self::URL, $this->entry($data, 'api_url')['value']);
        self::assertSame(25, $this->entry($data, 'batch_size')['value']);
        self::assertTrue($this->entry($data, 'verbose')['value']);
        self::assertSame('Hello', $this->entry($data, 'label')['value']);
        foreach ($data as $entry) {
            self::assertTrue($this->asArray($entry)['has_value'], $this->asString($this->asArray($entry)['key']) . ' is set now.');
        }

        // The plugin reads the same through the interface, as the declared types.
        $settings = $this->settings();
        self::assertSame(self::URL, $settings->get(self::PLUGIN, 'api_url'));
        self::assertSame(self::SECRET, $settings->get(self::PLUGIN, 'api_key'), 'The plugin gets the clear secret.');
        self::assertSame(25, $settings->get(self::PLUGIN, 'batch_size'));
        self::assertTrue($settings->get(self::PLUGIN, 'verbose'));
        self::assertSame('Hello', $settings->get(self::PLUGIN, 'label'));
    }

    public function testNumbersKeepTheirKindAndFractions(): void
    {
        $this->installPlugin();

        $data = $this->assertEnvelopeSuccess($this->put(['batch_size' => 2.5]));

        self::assertSame(2.5, $this->entry($data, 'batch_size')['value']);
        self::assertSame(2.5, $this->settings()->get(self::PLUGIN, 'batch_size'));
        self::assertSame('2.5', $this->rows()['batch_size']['value']);
    }

    public function testTwoFirstSavesOfOneSettingLeaveOneRowWithTheLastValueAndNoError(): void
    {
        $this->installPlugin();
        // The second administrator read the table before the first one committed: for them there is still no row.
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $blind = new class($registry) extends PluginSettingRepository {
            public int $blindReads = 0;

            public function findOneByPluginIdAndKey(string $pluginId, string $key): ?PluginSetting
            {
                if ($this->blindReads > 0) {
                    --$this->blindReads;

                    return null;
                }

                return parent::findOneByPluginIdAndKey($pluginId, $key);
            }
        };
        $service = new PluginSettingsService(
            $this->service(PluginRegistryService::class),
            $blind,
            $this->crypto(),
            $this->em,
            $this->service(TransactionService::class),
            $this->service(UserContextService::class),
        );

        $service->update(self::PLUGIN, ['label' => 'first', 'api_key' => self::SECRET]);
        $blind->blindReads = 2;
        $view = $service->update(self::PLUGIN, ['label' => 'second', 'api_key' => 'sk-live-second-0987654321']);

        $rows = $this->rows();
        self::assertSame(['label', 'api_key'], array_keys($rows), 'one row per setting, no violation of the unique key');
        self::assertSame('second', $rows['label']['value']);
        self::assertIsString($rows['api_key']['value']);
        self::assertSame('sk-live-second-0987654321', $this->crypto()->decrypt($rows['api_key']['value']), 'a secret stays encrypted');
        self::assertSame(1, $rows['api_key']['is_secret']);
        self::assertSame('second', $this->entry($view, 'label')['value']);
        self::assertSame('4321', $this->entry($view, 'api_key')['hint']);
    }

    public function testASecretIsStoredEncryptedAndNeverReturned(): void
    {
        $this->installPlugin();

        $envelope = $this->put(['api_key' => self::SECRET]);

        $data = $this->assertEnvelopeSuccess($envelope);
        $key = $this->entry($data, 'api_key');
        self::assertNull($key['value'], 'The value of a secret is never returned.');
        self::assertTrue($key['has_value']);
        self::assertTrue($key['secret']);
        self::assertSame('7890', $key['hint'], 'A secret of twelve or more characters shows its last four.');
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($envelope), 'The clear secret is nowhere in the answer.');
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($this->assertEnvelopeSuccess($this->get())), 'Nor in the list.');

        $row = $this->rows()['api_key'];
        self::assertSame(1, $row['is_secret']);
        self::assertIsString($row['value']);
        self::assertStringStartsWith('enc:v1:', $row['value'], 'The database holds the ciphertext.');
        self::assertStringNotContainsString('sk-live', $row['value']);
        self::assertSame(self::SECRET, $this->crypto()->decrypt($row['value']), 'And it opens with the key of this APP_SECRET.');
    }

    public function testAPlainValueIsStoredAsText(): void
    {
        $this->installPlugin();

        $this->assertEnvelopeSuccess($this->put(['label' => 'Hello', 'batch_size' => 25, 'verbose' => true, 'api_url' => self::URL]));

        $rows = $this->rows();
        self::assertSame(['Hello', 0], [$rows['label']['value'], $rows['label']['is_secret']]);
        self::assertSame('25', $rows['batch_size']['value']);
        self::assertSame('1', $rows['verbose']['value']);
        self::assertSame(self::URL, $rows['api_url']['value']);
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function secretsAndTheirHints(): iterable
    {
        yield 'twelve characters show the last four' => ['abcdefgh1234', '1234'];
        yield 'eleven characters show nothing' => ['abcdefg1234', null];
        yield 'a short secret shows nothing' => ['s3cret', null];
        yield 'a long secret shows the last four' => ['sk-live-abcdefghijklmnopqrstuvwxyz-9876', '9876'];
        yield 'characters, not bytes, are counted' => ['ääääääääääää', 'ääää'];
    }

    #[DataProvider('secretsAndTheirHints')]
    public function testTheHintOfASecret(string $secret, ?string $hint): void
    {
        $this->installPlugin();

        $data = $this->assertEnvelopeSuccess($this->put(['api_key' => $secret]));

        self::assertSame($hint, $this->entry($data, 'api_key')['hint']);
        self::assertNull($this->entry($data, 'api_key')['value']);
    }

    public function testSavingWithoutASecretKeepsTheSecret(): void
    {
        $this->installPlugin();
        $this->assertEnvelopeSuccess($this->put(['api_key' => self::SECRET, 'label' => 'first']));
        $before = $this->rows()['api_key']['value'];

        $data = $this->assertEnvelopeSuccess($this->put(['label' => 'second']));

        self::assertTrue($this->entry($data, 'api_key')['has_value'], 'A form that never shows a secret cannot wipe it by saving.');
        self::assertSame($before, $this->rows()['api_key']['value'], 'The stored ciphertext is untouched.');
        self::assertSame(self::SECRET, $this->settings()->get(self::PLUGIN, 'api_key'));
        self::assertSame('second', $this->settings()->get(self::PLUGIN, 'label'));
    }

    public function testNullClearsASecret(): void
    {
        $this->installPlugin();
        $this->assertEnvelopeSuccess($this->put(['api_key' => self::SECRET]));

        $data = $this->assertEnvelopeSuccess($this->put(['api_key' => null]));

        $key = $this->entry($data, 'api_key');
        self::assertFalse($key['has_value']);
        self::assertNull($key['hint']);
        self::assertArrayNotHasKey('api_key', $this->rows(), 'The row is deleted.');
        self::assertNull($this->settings()->get(self::PLUGIN, 'api_key'));
    }

    public function testNullOnAPlainSettingBringsBackTheDefault(): void
    {
        $this->installPlugin();
        $this->assertEnvelopeSuccess($this->put(['batch_size' => 99, 'label' => 'x']));
        self::assertSame(99, $this->settings()->get(self::PLUGIN, 'batch_size'));

        $data = $this->assertEnvelopeSuccess($this->put(['batch_size' => null, 'label' => null]));

        self::assertSame(10, $this->settings()->get(self::PLUGIN, 'batch_size'), 'The manifest default applies again.');
        self::assertFalse($this->entry($data, 'batch_size')['has_value']);
        self::assertSame(10, $this->entry($data, 'batch_size')['value']);
        self::assertNull($this->settings()->get(self::PLUGIN, 'label'));
        self::assertSame([], $this->rows());
    }

    public function testClearingASettingThatWasNeverSetChangesNothing(): void
    {
        $this->installPlugin();

        $this->assertEnvelopeSuccess($this->put(['api_key' => null, 'label' => null]));

        self::assertSame([], $this->rows());
        self::assertSame([], $this->transactionLines(), 'Nothing changed, so nothing is logged.');
    }

    public function testASettingBelongsToTheAdministratorWhoSavedIt(): void
    {
        $this->installPlugin();

        $this->assertEnvelopeSuccess($this->put(['label' => 'mine']));

        $adminId = $this->em->getConnection()->fetchOne('SELECT id FROM users WHERE email = ?', [QaBaselineFixture::QA_ADMIN_EMAIL]);
        self::assertSame(self::coerceInt($adminId), self::coerceInt($this->rows()['label']['id_users_updated']));
    }

    public function testTheSameValueAgainIsNotAChange(): void
    {
        $this->installPlugin();
        $this->assertEnvelopeSuccess($this->put(['label' => 'same', 'api_key' => self::SECRET]));
        $this->em->getConnection()->executeStatement("UPDATE plugin_settings SET updated_at = '2020-01-01 00:00:00' WHERE plugin_id = ?", [self::PLUGIN]);
        $logged = count($this->transactionLines());

        $this->assertEnvelopeSuccess($this->put(['label' => 'same', 'api_key' => self::SECRET]));

        foreach ($this->rows() as $key => $row) {
            self::assertSame('2020-01-01 00:00:00', $row['updated_at'], $key . ': an unchanged value keeps its row as it was.');
        }
        self::assertCount($logged, $this->transactionLines(), 'An unchanged save is not logged.');
    }

    // -- refusals ----------------------------------------------------------------------------

    /** @return iterable<string, array{string, mixed, string}> setting key, value, reason */
    public static function badValues(): iterable
    {
        yield 'a url with plain http' => ['api_url', 'http://example.org/x', 'url_must_be_https'];
        yield 'a host that only starts like localhost' => ['api_url', 'http://localhost.evil.example/x', 'url_must_be_https'];
        yield 'an address that only starts like 127.0.0.1' => ['api_url', 'http://127.0.0.1.evil.example/', 'url_must_be_https'];
        yield 'a url with another scheme' => ['api_url', 'ftp://example.org/file', 'url_must_be_https'];
        yield 'a url that is no url' => ['api_url', 'not a url', 'invalid_url'];
        yield 'a url without a host' => ['api_url', 'https://', 'invalid_url'];
        yield 'a url that is a number' => ['api_url', 5, 'wrong_type'];
        yield 'a url that is too long' => ['api_url', 'https://example.org/' . str_repeat('a', 2100), 'invalid_url'];
        yield 'a number sent as text' => ['batch_size', '25', 'wrong_type'];
        yield 'a number sent as a boolean' => ['batch_size', true, 'wrong_type'];
        yield 'a boolean sent as text' => ['verbose', 'yes', 'wrong_type'];
        yield 'a boolean sent as a number' => ['verbose', 1, 'wrong_type'];
        yield 'a string sent as a number' => ['label', 123, 'wrong_type'];
        yield 'a string sent as a list' => ['label', ['a'], 'wrong_type'];
        yield 'an empty string' => ['label', '', 'empty'];
        yield 'an empty secret' => ['api_key', '', 'empty'];
        yield 'a string that is too long' => ['label', str_repeat('x', 4001), 'too_long'];
        yield 'a key the manifest does not declare' => ['no_such_setting', 'x', 'unknown_setting'];
    }

    #[DataProvider('badValues')]
    public function testABadValueIsRefusedAndNothingIsWritten(string $key, mixed $value, string $reason): void
    {
        $this->installPlugin();

        $envelope = $this->put([$key => $value]);

        $this->assertEnvelope400($envelope);
        self::assertSame('invalid_plugin_setting', $envelope['error'] ?? null);
        $data = $this->asArray($envelope['data'] ?? null);
        self::assertSame($key, $data['key'] ?? null);
        self::assertSame($reason, $data['reason'] ?? null);
        self::assertArrayHasKey('detail', $data);
        self::assertSame([], $this->rows(), 'A refused save writes nothing.');
    }

    /** @return iterable<string, array{string}> */
    public static function localUrls(): iterable
    {
        yield 'localhost with a port' => ['http://localhost:8080/hook'];
        yield 'localhost, upper case' => ['http://LOCALHOST/hook'];
        yield 'the loopback address' => ['http://127.0.0.1:9000/'];
        yield 'the loopback address of IPv6' => ['http://[::1]:9000/'];
        yield 'https anywhere' => ['https://api.example.org:8443/v2?x=1'];
        yield 'https on localhost' => ['https://localhost/'];
    }

    #[DataProvider('localUrls')]
    public function testHttpsAndLocalHttpUrlsAreAccepted(string $url): void
    {
        $this->installPlugin();

        $data = $this->assertEnvelopeSuccess($this->put(['api_url' => $url]));

        self::assertSame($url, $this->entry($data, 'api_url')['value']);
    }

    public function testOneBadEntryWritesNoneOfTheOthers(): void
    {
        $this->installPlugin();

        $envelope = $this->put(['label' => 'good', 'api_key' => self::SECRET, 'api_url' => 'http://example.org/']);

        $this->assertEnvelope400($envelope);
        self::assertSame([], $this->rows(), 'The good entries of a refused save are not written either.');
        self::assertSame([], $this->transactionLines());
    }

    public function testABodyWithoutValuesIsRefusedBeforeTheService(): void
    {
        $this->installPlugin();

        $envelope = $this->jsonRequest('PUT', $this->url(self::PLUGIN), ['label' => 'x'], $this->adminToken());

        $this->assertEnvelope400($envelope);
        self::assertNotSame('invalid_plugin_setting', $envelope['error'] ?? null);
        self::assertSame([], $this->rows());
    }

    public function testValuesThatAreNotAnObjectAreRefused(): void
    {
        $this->installPlugin();

        $envelope = $this->jsonRequest('PUT', $this->url(self::PLUGIN), ['values' => ['label', 'x']], $this->adminToken());

        $this->assertEnvelope400($envelope);
        self::assertSame([], $this->rows());
    }

    // -- the environment override ------------------------------------------------------------

    public function testAnEnvironmentVariableWinsOverTheStoredValueAndIsReported(): void
    {
        $this->installPlugin();
        $this->assertEnvelopeSuccess($this->put(['api_url' => self::URL]));
        $this->setEnvironment(QaPlatformPluginFixture::SETTING_URL_ENV, self::ENV_URL);

        $entry = $this->entry($this->assertEnvelopeSuccess($this->get()), 'api_url');

        self::assertSame(self::ENV_URL, $entry['value'], 'The environment wins over what is stored.');
        self::assertTrue($entry['overridden_by_env']);
        self::assertTrue($entry['has_value']);
        self::assertSame(self::ENV_URL, $this->settings()->get(self::PLUGIN, 'api_url'));
        self::assertSame(self::URL, $this->rows()['api_url']['value'], 'The stored value stays, ready for when the variable is unset.');
    }

    public function testAnEnvironmentVariableCountsEvenWhenNothingIsStored(): void
    {
        $this->installPlugin();
        $this->setEnvironment(QaPlatformPluginFixture::SETTING_URL_ENV, self::ENV_URL);

        $entry = $this->entry($this->assertEnvelopeSuccess($this->get()), 'api_url');

        self::assertTrue($entry['has_value']);
        self::assertTrue($entry['overridden_by_env']);
        self::assertSame(self::ENV_URL, $this->settings()->get(self::PLUGIN, 'api_url'));
    }

    public function testTheStoredValueIsBackWhenTheVariableIsUnset(): void
    {
        $this->installPlugin();
        $this->assertEnvelopeSuccess($this->put(['api_url' => self::URL]));
        $this->setEnvironment(QaPlatformPluginFixture::SETTING_URL_ENV, self::ENV_URL);
        self::assertSame(self::ENV_URL, $this->settings()->get(self::PLUGIN, 'api_url'));

        $this->setEnvironment(QaPlatformPluginFixture::SETTING_URL_ENV, null);

        $entry = $this->entry($this->assertEnvelopeSuccess($this->get()), 'api_url');
        self::assertSame(self::URL, $entry['value']);
        self::assertFalse($entry['overridden_by_env']);
        self::assertSame(self::URL, $this->settings()->get(self::PLUGIN, 'api_url'));
    }

    public function testAnEmptyEnvironmentVariableDoesNotOverride(): void
    {
        $this->installPlugin();
        $this->assertEnvelopeSuccess($this->put(['api_url' => self::URL]));
        $this->setEnvironment(QaPlatformPluginFixture::SETTING_URL_ENV, '');

        $entry = $this->entry($this->assertEnvelopeSuccess($this->get()), 'api_url');

        self::assertSame(self::URL, $entry['value']);
        self::assertFalse($entry['overridden_by_env']);
    }

    public function testASettingWithoutAnEnvironmentVariableIsNeverOverridden(): void
    {
        $this->installPlugin();
        $this->assertEnvelopeSuccess($this->put(['label' => 'mine']));
        $this->setEnvironment('LABEL', 'from the environment');

        try {
            $entry = $this->entry($this->assertEnvelopeSuccess($this->get()), 'label');
            self::assertSame('mine', $entry['value']);
            self::assertFalse($entry['overridden_by_env']);
        } finally {
            $this->setEnvironment('LABEL', null);
        }
    }

    // -- a value that cannot be decrypted ----------------------------------------------------

    public function testASecretEncryptedUnderAnotherAppSecretReadsAsNotSet(): void
    {
        $this->installPlugin();
        $foreign = (new PluginSettingsCrypto('the-app-secret-of-the-old-installation'))->encrypt(self::SECRET);
        $this->em->getConnection()->executeStatement(
            'INSERT INTO plugin_settings (plugin_id, setting_key, value, is_secret, updated_at) VALUES (?, ?, ?, 1, NOW())',
            [self::PLUGIN, 'api_key', $foreign],
        );

        $key = $this->entry($this->assertEnvelopeSuccess($this->get()), 'api_key');

        self::assertFalse($key['has_value'], 'The administrator is asked to enter it again.');
        self::assertNull($key['hint']);
        self::assertNull($this->settings()->get(self::PLUGIN, 'api_key'));
    }

    public function testASecretThatCannotBeReadCanBeEnteredAgain(): void
    {
        $this->installPlugin();
        $foreign = (new PluginSettingsCrypto('the-app-secret-of-the-old-installation'))->encrypt(self::SECRET);
        $this->em->getConnection()->executeStatement(
            'INSERT INTO plugin_settings (plugin_id, setting_key, value, is_secret, updated_at) VALUES (?, ?, ?, 1, NOW())',
            [self::PLUGIN, 'api_key', $foreign],
        );

        $data = $this->assertEnvelopeSuccess($this->put(['api_key' => self::SECRET]));

        self::assertTrue($this->entry($data, 'api_key')['has_value']);
        self::assertSame(self::SECRET, $this->settings()->get(self::PLUGIN, 'api_key'));
        self::assertNotSame($foreign, $this->rows()['api_key']['value'], 'The unreadable ciphertext was replaced.');
        $count = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM plugin_settings WHERE plugin_id = ? AND setting_key = ?', [self::PLUGIN, 'api_key']);
        self::assertSame(1, self::coerceInt($count), 'One row per setting.');
    }

    // -- uninstall keeps, purge deletes ------------------------------------------------------

    public function testUninstallKeepsTheStoredValuesAndAReinstallFindsThem(): void
    {
        $this->installPlugin();
        $this->assertEnvelopeSuccess($this->put(['api_key' => self::SECRET, 'label' => 'kept']));

        // What the uninstaller does: it deletes the plugin row and tells the world.
        $plugin = $this->deletePluginRow();
        $this->events()->dispatch(new PluginUninstalledEvent($plugin, null));

        self::assertSame(['api_key', 'label'], array_keys($this->rows()), 'The rows stay: the settings table has no foreign key to plugins.');
        $this->assertEnvelope404($this->get());

        $this->insertPluginRow();
        $data = $this->assertEnvelopeSuccess($this->get());
        self::assertTrue($this->entry($data, 'api_key')['has_value'], 'A reinstalled plugin finds its settings again.');
        self::assertSame(self::SECRET, $this->settings()->get(self::PLUGIN, 'api_key'));
        self::assertSame('kept', $this->settings()->get(self::PLUGIN, 'label'));
    }

    public function testThePurgeDeletesTheStoredValuesOfThePluginOnly(): void
    {
        $this->installPlugin();
        $this->assertEnvelopeSuccess($this->put(['api_key' => self::SECRET, 'label' => 'goes']));
        $this->em->getConnection()->executeStatement(
            "INSERT INTO plugin_settings (plugin_id, setting_key, value, is_secret, updated_at) VALUES ('qa-other-plugin', 'label', 'stays', 0, NOW())"
        );

        $plugin = $this->deletePluginRow();
        $this->events()->dispatch(new PluginPurgedEvent($plugin, null));

        self::assertSame([], $this->rows(), 'The purge removes every stored value of the plugin, secrets included.');
        $others = $this->em->getConnection()->fetchFirstColumn("SELECT value FROM plugin_settings WHERE plugin_id = 'qa-other-plugin'");
        self::assertSame(['stays'], $others, 'The values of another plugin are not touched.');
        $lines = $this->transactionLines();
        self::assertNotSame([], $lines);
        self::assertStringContainsString('deleted by the purge', $lines[count($lines) - 1]);
    }

    public function testAPurgeWithoutStoredValuesIsQuiet(): void
    {
        $this->installPlugin();
        $plugin = $this->deletePluginRow();

        $this->events()->dispatch(new PluginPurgedEvent($plugin, null));

        self::assertSame([], $this->rows());
        self::assertSame([], $this->transactionLines());
    }

    // -- the transaction log -----------------------------------------------------------------

    public function testTheTransactionLogNamesTheChangedKeysAndNeverTheValues(): void
    {
        $this->installPlugin();

        $this->assertEnvelopeSuccess($this->put(['api_key' => self::SECRET, 'label' => 'a-label-nobody-may-read-in-the-log', 'batch_size' => 4242]));

        $lines = $this->transactionLines();
        self::assertCount(1, $lines);
        self::assertStringContainsString(self::PLUGIN, $lines[0]);
        foreach (['api_key', 'label', 'batch_size'] as $key) {
            self::assertStringContainsString($key, $lines[0], 'The changed keys are named.');
        }
        foreach ([self::SECRET, '7890', 'a-label-nobody-may-read-in-the-log', '4242'] as $value) {
            self::assertStringNotContainsString($value, $lines[0], 'A value is never logged.');
        }
        $raw = $this->em->getConnection()->fetchOne("SELECT transaction_log FROM transactions WHERE table_name = 'plugin_settings' ORDER BY id DESC LIMIT 1");
        self::assertStringNotContainsString(self::SECRET, self::coerceString($raw), 'Not even in the raw log row.');
    }

    public function testTheLogNamesOnlyTheKeysThatReallyChanged(): void
    {
        $this->installPlugin();
        $this->assertEnvelopeSuccess($this->put(['label' => 'one', 'batch_size' => 5]));

        $this->assertEnvelopeSuccess($this->put(['label' => 'one', 'batch_size' => 6]));

        $lines = $this->transactionLines();
        self::assertCount(2, $lines);
        self::assertStringContainsString('batch_size', $lines[1]);
        self::assertStringNotContainsString('label', $lines[1], 'label was sent again with the same value.');
    }

    // -- permissions -------------------------------------------------------------------------

    public function testTheListEnforcesThePermissionMatrix(): void
    {
        $this->installPlugin();

        $this->assertAdminOnlyMatrix('GET', $this->url(self::PLUGIN));
    }

    public function testTheSaveEnforcesThePermissionMatrix(): void
    {
        $this->installPlugin();

        $this->assertAdminOnlyMatrix('PUT', $this->url(self::PLUGIN), ['values' => ['label' => 'qa_matrix']]);
    }

    public function testARefusedPersonWritesNothing(): void
    {
        $this->installPlugin();

        $this->assertForbiddenForNonAdmins('PUT', $this->url(self::PLUGIN), ['values' => ['label' => 'qa_forbidden', 'api_key' => self::SECRET]]);

        self::assertSame([], $this->rows());
    }

    // -- helpers -----------------------------------------------------------------------------

    private function installPlugin(): Plugin
    {
        $plugin = QaPlatformPluginFixture::install(
            $this->em,
            $this->service(PluginCatalogueSynchronizer::class),
            $this->service(PluginCacheInvalidator::class),
        );

        return $plugin;
    }

    /**
     * Delete the row of the QA plugin, as the uninstaller and the purger do, and drop the plugin caches.
     * The entity comes from a fresh read: the requests of the test have reset the entity manager.
     */
    private function deletePluginRow(): Plugin
    {
        $this->em->clear();
        $plugin = $this->em->getRepository(Plugin::class)->findOneBy(['pluginId' => self::PLUGIN]);
        self::assertInstanceOf(Plugin::class, $plugin);

        $this->em->remove($plugin);
        $this->em->flush();
        $this->service(PluginCacheInvalidator::class)->invalidatePluginSurfaceCaches();

        return $plugin;
    }

    /**
     * Put the plugin row back, as a reinstall does (the catalogue rows are not the subject here).
     */
    private function insertPluginRow(): void
    {
        $plugin = new Plugin(self::PLUGIN, 'QA platform plugin', '1.0.0', '0.2.0');
        $plugin->setEnabled(true);
        $plugin->setManifestJson(QaPlatformPluginFixture::manifestData());
        $this->em->persist($plugin);
        $this->em->flush();
        $this->service(PluginCacheInvalidator::class)->invalidatePluginSurfaceCaches();
    }

    private function adminToken(): string
    {
        return $this->admin ??= $this->loginAsQaAdmin();
    }

    private function url(string $pluginId): string
    {
        return '/cms-api/v1/admin/plugins/' . $pluginId . '/settings';
    }

    /** @return array<string, mixed> the response envelope */
    private function get(string $pluginId = self::PLUGIN): array
    {
        return $this->jsonRequest('GET', $this->url($pluginId), null, $this->adminToken());
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed> the response envelope
     */
    private function put(array $values, string $pluginId = self::PLUGIN): array
    {
        return $this->jsonRequest('PUT', $this->url($pluginId), ['values' => $values], $this->adminToken());
    }

    /**
     * One entry of the list by key.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function entry(array $data, string $key): array
    {
        foreach ($data as $entry) {
            $entry = $this->asArray($entry);
            if (($entry['key'] ?? null) === $key) {
                return $entry;
            }
        }
        self::fail(sprintf('The list has no entry "%s".', $key));
    }

    /**
     * The stored rows of the QA plugin by setting key, as the database holds them.
     *
     * @return array<string, array<string, mixed>>
     */
    private function rows(): array
    {
        $rows = [];
        foreach ($this->connection()->fetchAllAssociative(
            'SELECT setting_key, value, is_secret, updated_at, id_users_updated FROM plugin_settings WHERE plugin_id = ? ORDER BY id',
            [self::PLUGIN],
        ) as $row) {
            $row['is_secret'] = self::coerceInt($row['is_secret']);
            $rows[self::coerceString($row['setting_key'])] = $row;
        }

        return $rows;
    }

    /**
     * The verbal part of the transaction log rows about settings, oldest first.
     *
     * @return list<string>
     */
    private function transactionLines(): array
    {
        $lines = [];
        foreach ($this->connection()->fetchFirstColumn("SELECT transaction_log FROM transactions WHERE table_name = 'plugin_settings' ORDER BY id") as $log) {
            $decoded = json_decode(self::coerceString($log), true);
            $lines[] = self::asString(self::asArray($decoded)['verbal_log'] ?? null);
        }

        return $lines;
    }

    private function connection(): Connection
    {
        return $this->em->getConnection();
    }

    private function settings(): PluginSettingsInterface
    {
        $service = self::getContainer()->get('test.plugin_settings');
        self::assertInstanceOf(PluginSettingsInterface::class, $service);

        return $service;
    }

    /** The crypto of this installation, built the way the container builds it: from `APP_SECRET`. */
    private function crypto(): PluginSettingsCrypto
    {
        return new PluginSettingsCrypto(self::asString(self::getContainer()->getParameter('kernel.secret')));
    }

    private function events(): EventDispatcherInterface
    {
        $events = $this->service(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $events);

        return $events;
    }

    /** Set or unset an environment variable for the code under test (PHP reads it from three places). */
    private function setEnvironment(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            return;
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
