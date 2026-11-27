<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Plugin\Manifest;

use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Manifest\PluginManifestValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The manifest keys that plugin API 0.2.0 added (contracts C2, C22, C24 and
 * C25): `styles[].resourceFields`, `cacheCategories`, the new `scheduledJobs`
 * keys, `settings`, `rateLimits`, `jsonLogicOperations`, `listens` and
 * `recurringJobs`. Runs on the real validator and the canonical schema,
 * DB-free.
 */
final class PluginManifestValidatorHooksTest extends TestCase
{
    /** Passed as the value of {@see patch()} to remove the key instead of setting it. */
    private const REMOVE = '__remove__';

    private PluginManifestValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PluginManifestValidator(
            \dirname(__DIR__, 3) . '/docs/plugins/plugin-manifest.schema.json'
        );
    }

    /**
     * A manifest that uses every new key and is valid.
     *
     * @return array<string, mixed>
     */
    private function manifest(string $apiVersion = '0.2.0'): array
    {
        return [
            'id' => 'qa-hooks-probe',
            'name' => 'QA Hooks Probe',
            'version' => '1.0.0',
            'pluginApiVersion' => $apiVersion,
            'compatibility' => ['selfhelp' => '>=0.1.0'],
            'security' => ['trustLevel' => 'untrusted', 'capabilities' => ['frontendStyles', 'scheduledJobs', 'lookupExtend']],
            'lookups' => ['extends' => [[
                'typeCode' => 'resourceTypes',
                'ownership' => 'plugin_extendable',
                'entries' => [
                    ['code' => 'qa_survey', 'value' => 'QA survey'],
                    ['code' => 'qa_script', 'value' => 'QA script'],
                ],
            ]]],
            'styles' => [[
                'name' => 'qa-survey',
                'description' => 'QA survey',
                'canHaveChildren' => false,
                'fields' => [['name' => 'survey_id']],
                'resourceFields' => ['survey_id' => 'qa_survey'],
            ]],
            'cacheCategories' => [['name' => 'surveys', 'ttlSeconds' => 3600, 'description' => 'Survey definitions']],
            'scheduledJobs' => [[
                'type' => 'qa_r_script',
                'description' => 'Run a QA script',
                'capability' => 'scheduledJobs',
                'label' => 'QA script',
                'actionPlannable' => true,
                'timeoutSeconds' => 300,
                'configSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'script_id' => ['type' => 'integer', 'title' => 'Script'],
                        'note' => ['type' => 'string', 'format' => 'variable-text'],
                    ],
                    'required' => ['script_id'],
                ],
                'resourceFields' => ['script_id' => 'qa_script'],
            ]],
            'settings' => [['key' => 'api_key', 'label' => 'API key', 'type' => 'string', 'secret' => true, 'required' => true, 'envOverride' => 'QA_API_KEY']],
            'rateLimits' => [['name' => 'chat', 'policy' => 'sliding_window', 'limit' => 20, 'interval' => '1 minute']],
            'jsonLogicOperations' => [['name' => 'stats.mean', 'description' => 'Mean of a list of numbers']],
            'listens' => ['record.saved', 'page.published'],
            'recurringJobs' => [['name' => 'nightly', 'type' => 'qa_r_script', 'cron' => '0 3 * * *', 'description' => 'Nightly run']],
        ];
    }

    /**
     * Set (or, with {@see REMOVE}, remove) the value at a path of keys.
     *
     * @param array<string, mixed> $manifest
     * @param list<int|string> $path
     * @return array<string, mixed>
     */
    private static function patch(array $manifest, array $path, mixed $value): array
    {
        $first = array_shift($path);
        if (!is_string($first)) {
            throw new \LogicException('A manifest path starts with a key name.');
        }
        if ($path === []) {
            if ($value === self::REMOVE) {
                unset($manifest[$first]);
            } else {
                $manifest[$first] = $value;
            }

            return $manifest;
        }

        $child = $manifest[$first] ?? [];
        if (!is_array($child)) {
            throw new \LogicException(sprintf('"%s" is not a list or a map.', $first));
        }
        $manifest[$first] = self::patchNode($child, $path, $value);

        return $manifest;
    }

    /**
     * @param array<array-key, mixed> $node
     * @param non-empty-list<int|string> $path
     * @return array<array-key, mixed>
     */
    private static function patchNode(array $node, array $path, mixed $value): array
    {
        $key = array_shift($path);
        if ($path === []) {
            if ($value === self::REMOVE) {
                unset($node[$key]);
            } else {
                $node[$key] = $value;
            }

            return $node;
        }

        $child = $node[$key] ?? [];
        if (!is_array($child)) {
            throw new \LogicException(sprintf('"%s" is not a list or a map.', $key));
        }
        $node[$key] = self::patchNode($child, $path, $value);

        return $node;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function assertRefused(array $manifest, string $needle): void
    {
        $errors = $this->validator->validate($manifest);
        self::assertNotSame([], $errors, 'The manifest must be refused.');
        self::assertStringContainsString($needle, implode("\n", $errors));
    }

    public function testManifestUsingEveryNewKeyValidates(): void
    {
        self::assertSame([], $this->validator->validate($this->manifest()));
    }

    /**
     * Each case changes the valid manifest at one path and names the text the refusal must contain.
     *
     * @return iterable<string, array{0: non-empty-list<int|string>, 1: mixed, 2: string}>
     */
    public static function invalidC2Cases(): iterable
    {
        yield 'resource field names an undeclared type' => [
            ['styles', 0, 'resourceFields'],
            ['survey_id' => 'qa_missing'],
            'uses resource type "qa_missing"',
        ];
        yield 'resource field names a field the style does not declare' => [
            ['styles', 0, 'resourceFields'],
            ['other_id' => 'qa_survey'],
            'resourceFields names "other_id"',
        ];
        yield 'job resource property is not in its configSchema' => [
            ['scheduledJobs', 0, 'resourceFields'],
            ['unknown' => 'qa_script'],
            'not a property of its configSchema',
        ];
        yield 'cache category name is bad' => [
            ['cacheCategories', 0, 'name'],
            'Bad-Name',
            'cacheCategories',
        ];
        yield 'cache category lifetime of 30 seconds' => [
            ['cacheCategories', 0, 'ttlSeconds'],
            30,
            'ttlSeconds',
        ];
        yield 'duplicate cache category' => [
            ['cacheCategories', 1],
            ['name' => 'surveys', 'ttlSeconds' => 120],
            '"surveys" is declared twice',
        ];
        yield 'action-plannable job without a label' => [
            ['scheduledJobs', 0, 'label'],
            self::REMOVE,
            'actionPlannable needs a label',
        ];
        yield 'nested configSchema' => [
            ['scheduledJobs', 0, 'configSchema', 'properties', 'nested'],
            ['type' => 'object', 'properties' => ['inner' => ['type' => 'string']]],
            'configSchema',
        ];
        yield 'duplicate envOverride' => [
            ['settings', 1],
            ['key' => 'other_key', 'label' => 'Other', 'type' => 'string', 'envOverride' => 'QA_API_KEY'],
            '"QA_API_KEY" is declared twice',
        ];
        yield 'default on a secret' => [
            ['settings', 0, 'default'],
            'x',
            'is a secret and cannot have a default value',
        ];
        yield 'rate limit of zero' => [
            ['rateLimits', 0, 'limit'],
            0,
            'rateLimits',
        ];
        yield 'rate limit with an unknown policy' => [
            ['rateLimits', 0, 'policy'],
            'token_bucket',
            'rateLimits',
        ];
        yield 'rate limit with a bad interval' => [
            ['rateLimits', 0, 'interval'],
            'soon',
            'rateLimits',
        ];
        yield 'JSON Logic name that is a core operation' => [
            ['jsonLogicOperations', 0, 'name'],
            'var',
            'collides with a core operation',
        ];
        yield 'JSON Logic name without a namespace' => [
            ['jsonLogicOperations', 0, 'name'],
            'mean',
            'jsonLogicOperations',
        ];
    }

    /**
     * @param non-empty-list<int|string> $path
     */
    #[DataProvider('invalidC2Cases')]
    public function testInvalidC2CaseIsRefused(array $path, mixed $value, string $needle): void
    {
        $this->assertRefused(self::patch($this->manifest(), $path, $value), $needle);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function keysThatNeedApi02(): iterable
    {
        yield 'styles[].resourceFields' => ['styles.qa-survey.resourceFields'];
        yield 'cacheCategories' => ['cacheCategories'];
        yield 'settings' => ['settings'];
        yield 'rateLimits' => ['rateLimits'];
        yield 'jsonLogicOperations' => ['jsonLogicOperations'];
        yield 'listens' => ['listens'];
        yield 'recurringJobs' => ['recurringJobs'];
        yield 'scheduledJobs[].label' => ['scheduledJobs.qa_r_script.label'];
        yield 'scheduledJobs[].actionPlannable' => ['scheduledJobs.qa_r_script.actionPlannable'];
        yield 'scheduledJobs[].configSchema' => ['scheduledJobs.qa_r_script.configSchema'];
        yield 'scheduledJobs[].resourceFields' => ['scheduledJobs.qa_r_script.resourceFields'];
        yield 'scheduledJobs[].timeoutSeconds' => ['scheduledJobs.qa_r_script.timeoutSeconds'];
    }

    /**
     * Every new key, with pluginApiVersion 0.1.0, is refused and named.
     */
    #[DataProvider('keysThatNeedApi02')]
    public function testNewKeyWithApi010IsRefused(string $path): void
    {
        $errors = $this->validator->validate($this->manifest('0.1.0'));

        self::assertContains(
            sprintf('[%s] needs pluginApiVersion 0.2.0 or newer (the manifest declares 0.1.0).', $path),
            $errors,
        );
    }

    public function testManifestWithoutNewKeysValidatesWithApi010(): void
    {
        $manifest = [
            'id' => 'qa-old-plugin',
            'name' => 'QA Old Plugin',
            'version' => '1.0.0',
            'pluginApiVersion' => '0.1.0',
            'compatibility' => ['selfhelp' => '>=0.1.0'],
            'security' => ['trustLevel' => 'untrusted', 'capabilities' => ['scheduledJobs']],
            'scheduledJobs' => [['type' => 'qa_old', 'description' => 'Old job', 'capability' => 'scheduledJobs']],
        ];

        self::assertSame([], $this->validator->validate($manifest));
    }

    /**
     * @return iterable<string, array{0: int, 1: bool}>
     */
    public static function timeouts(): iterable
    {
        yield '9 is too short' => [9, false];
        yield '10 is the shortest' => [10, true];
        yield '900 is the longest' => [900, true];
        yield '901 is too long' => [901, false];
    }

    #[DataProvider('timeouts')]
    public function testJobTimeoutRange(int $seconds, bool $valid): void
    {
        $manifest = self::patch($this->manifest(), ['scheduledJobs', 0, 'timeoutSeconds'], $seconds);

        self::assertSame($valid, $this->validator->validate($manifest) === []);
    }

    public function testListensAcceptsTheFiveEventsOnly(): void
    {
        $manifest = self::patch($this->manifest(), ['listens'], ['record.saved', 'record.deleted', 'user.created', 'user.deleted', 'page.published']);
        self::assertSame([], $this->validator->validate($manifest));

        $manifest = self::patch($manifest, ['listens'], ['record.saved', 'record.exploded']);
        $this->assertRefused($manifest, 'listens');

        $manifest = self::patch($manifest, ['listens'], ['record.saved', 'record.saved']);
        $this->assertRefused($manifest, 'listens');
    }

    public function testRecurringJobMustNameADeclaredJobType(): void
    {
        $manifest = self::patch($this->manifest(), ['recurringJobs', 0, 'type'], 'qa_undeclared');

        $this->assertRefused($manifest, 'uses job type "qa_undeclared"');
    }

    public function testRecurringJobNameMustMatchThePattern(): void
    {
        $manifest = self::patch($this->manifest(), ['recurringJobs', 0, 'name'], 'Nightly-Run');

        $this->assertRefused($manifest, 'recurringJobs');
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function crons(): iterable
    {
        yield 'every 5 minutes' => ['*/5 * * * *', true];
        yield 'every night' => ['0 3 * * *', true];
        yield 'weekdays at 9 and 17' => ['0 9,17 * * 1-5', true];
        yield 'every 3 minutes' => ['*/3 * * * *', false];
        yield 'every minute' => ['* * * * *', false];
        yield 'two runs 4 minutes apart' => ['0,4 9 * * *', false];
        yield 'across the hour 57 and 01' => ['57,1 * * * *', false];
        yield 'across midnight 23:59 and 00:00' => ['59 23 * * *', true];
        yield 'four fields' => ['0 3 * *', false];
        yield 'six fields' => ['0 0 3 * * *', false];
        yield 'a macro' => ['@daily', false];
        yield 'minute out of range' => ['60 * * * *', false];
        yield 'a date that never exists' => ['0 0 31 2 *', false];
    }

    #[DataProvider('crons')]
    public function testRecurringJobCronMustBeFiveFieldsAtLeastFiveMinutesApart(string $cron, bool $valid): void
    {
        $manifest = self::patch($this->manifest(), ['recurringJobs', 0, 'cron'], $cron);

        self::assertSame($valid, $this->validator->validate($manifest) === [], $cron);
    }

    public function testGettersExposeTheNewKeys(): void
    {
        $manifest = new PluginManifest($this->manifest());

        self::assertSame(['qa-survey' => ['survey_id' => 'qa_survey']], $manifest->getResourceFields());
        self::assertSame(['qa_r_script' => ['script_id' => 'qa_script']], $manifest->getJobResourceFields());
        self::assertSame(['qa_survey', 'qa_script'], $manifest->getResourceTypeCodes());
        self::assertSame('surveys', $manifest->getCacheCategories()[0]['name'] ?? null);
        self::assertCount(1, $manifest->getActionJobTypes());
        self::assertSame('api_key', $manifest->getSettings()[0]['key'] ?? null);
        self::assertSame('chat', $manifest->getRateLimits()[0]['name'] ?? null);
        self::assertSame('stats.mean', $manifest->getJsonLogicOperations()[0]['name'] ?? null);
        self::assertSame(['record.saved', 'page.published'], $manifest->getListens());
        self::assertSame('nightly', $manifest->getRecurringJobs()[0]['name'] ?? null);
        self::assertSame(300, $manifest->getJobTimeoutSeconds('qa_r_script'));
        self::assertSame(PluginManifest::DEFAULT_JOB_TIMEOUT_SECONDS, $manifest->getJobTimeoutSeconds('qa_other'));
    }

    public function testGettersAreEmptyWhenTheKeysAreAbsent(): void
    {
        $manifest = new PluginManifest(['id' => 'qa-plain']);

        self::assertSame([], $manifest->getResourceFields());
        self::assertSame([], $manifest->getJobResourceFields());
        self::assertSame([], $manifest->getResourceTypeCodes());
        self::assertSame([], $manifest->getCacheCategories());
        self::assertSame([], $manifest->getActionJobTypes());
        self::assertSame([], $manifest->getSettings());
        self::assertSame([], $manifest->getRateLimits());
        self::assertSame([], $manifest->getJsonLogicOperations());
        self::assertSame([], $manifest->getListens());
        self::assertSame([], $manifest->getRecurringJobs());
    }
}
