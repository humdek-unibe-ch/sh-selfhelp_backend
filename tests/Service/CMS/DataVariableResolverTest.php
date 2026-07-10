<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\CMS;

use App\Entity\DataTable;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\Common\SectionAccessibleRouteService;
use App\Service\CMS\DataService;
use App\Service\CMS\DataVariableResolver;
use App\Service\CMS\GlobalVariableService;
use App\Service\Core\UserContextAwareService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Guards the system-variable list the CMS `{{ }}` editor offers as autocomplete
 * suggestions (returned to the SPA as `data_variables`).
 *
 * `getSystemVariables()` is a pure, dependency-free list, so the resolver is
 * built without its constructor to keep this a fast unit test (no DB / cache).
 */
final class DataVariableResolverTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function systemVariables(): array
    {
        $resolver = (new \ReflectionClass(DataVariableResolver::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(DataVariableResolver::class, 'getSystemVariables');
        $method->setAccessible(true);

        /** @var list<string> $vars */
        $vars = $method->invoke($resolver);

        return $vars;
    }

    public function testEditorSuggestsTheMaintenanceMessageVariable(): void
    {
        self::assertContains(
            'system.maintenance_message',
            $this->systemVariables(),
            'The CMS editor must suggest system.maintenance_message so operators can place the '
                . 'maintenance note that the seeded maintenance page renders.',
        );
    }

    public function testEstablishedSystemVariablesStayAvailable(): void
    {
        $vars = $this->systemVariables();

        // Regression guard: adding the maintenance variable must not drop the
        // existing contract the section editor relies on.
        foreach (['system.project_name', 'system.current_datetime', 'system.user_email'] as $expected) {
            self::assertContains($expected, $vars);
        }
    }

    /**
     * Issue #56 v2: the picker token is the immutable `field_key`, never the
     * mutable input name, and the label is the curated `display_name`. This is
     * the rename-safety guarantee — renaming an input only moves the label.
     *
     * @param array<string, string> $columns field_key => display label
     */
    private function resolverWithColumns(string $tableName, int $tableId, array $columns): DataVariableResolver
    {
        // Pure stubs (no call expectations) — they only feed canned return
        // values so the resolver's token-building logic can be asserted.
        $em = $this->createStub(EntityManagerInterface::class);
        $dataService = $this->createStub(DataService::class);
        $cache = $this->createStub(CacheService::class);
        $globals = $this->createStub(GlobalVariableService::class);
        $userContext = $this->createStub(UserContextAwareService::class);
        $routeService = $this->createStub(SectionAccessibleRouteService::class);

        $dataTable = $this->createStub(DataTable::class);
        $dataTable->method('getId')->willReturn($tableId);
        $dataService->method('getDataTableByName')->willReturn($dataTable);

        // Bypass the real cache: return the column map directly for getList,
        // and make the fluent scope builders return the same mock.
        $cache->method('withCategory')->willReturnSelf();
        $cache->method('withEntityScope')->willReturnSelf();
        $cache->method('getList')->willReturn($columns);

        return new DataVariableResolver($em, $dataService, $cache, $globals, $userContext, $routeService);
    }

    public function testTableTokensUseImmutableFieldKeyNotInputName(): void
    {
        // A core form column whose input is currently named "changed" but whose
        // stable key is section_230 and whose curated label is "Daily mood".
        $columns = ['section_230' => 'Daily mood', 'record_id' => 'record_id'];
        $resolver = $this->resolverWithColumns('230', 5, $columns);

        $method = new \ReflectionMethod(DataVariableResolver::class, 'getTableVariablesFromConfig');
        $method->setAccessible(true);
        /** @var array<string, string> $vars */
        $vars = $method->invoke($resolver, ['scope' => 'd', 'table' => '230']);

        self::assertSame(
            'd.Daily mood',
            $vars['d.section_230'] ?? null,
            'Token must be scope.field_key; label must be scope.display_name.',
        );
        self::assertArrayNotHasKey(
            'd.changed',
            $vars,
            'A renamed input must never leak its mutable name into the interpolation token.',
        );
        // Standard projection columns keep their own key as token and label.
        self::assertSame('d.record_id', $vars['d.record_id'] ?? null);
    }

    public function testCustomFieldTokensUseFieldKeyWithCuratedLabel(): void
    {
        $columns = ['section_230' => 'Daily mood'];
        $resolver = $this->resolverWithColumns('230', 5, $columns);

        // data_config custom fields store the immutable field_key in field_name.
        $section = ['global_fields' => ['data_config' => json_encode([
            ['scope' => 'd', 'table' => '230', 'fields' => [['field_name' => 'section_230']]],
        ])]];

        $method = new \ReflectionMethod(DataVariableResolver::class, 'parseDataConfig');
        $method->setAccessible(true);
        /** @var array<string, string> $vars */
        $vars = $method->invoke($resolver, $section);

        self::assertSame('d.Daily mood', $vars['d.section_230'] ?? null);
    }

    public function testTokenLabelFallsBackToFieldKeyForUncuratedColumn(): void
    {
        // SurveyJS-style key with no curated display_name (label == key).
        $columns = ['question.q1' => 'question.q1'];
        $resolver = $this->resolverWithColumns('sh2_surveyjs_9', 9, $columns);

        $method = new \ReflectionMethod(DataVariableResolver::class, 'getTableVariablesFromConfig');
        $method->setAccessible(true);
        /** @var array<string, string> $vars */
        $vars = $method->invoke($resolver, ['scope' => 'e', 'table' => 'sh2_surveyjs_9']);

        self::assertSame('e.question.q1', $vars['e.question.q1'] ?? null);
    }

    /**
     * Issue #56 v2 unified picker: the mail-config context offers the system
     * scope plus the one-time `system.special.*` links, and never data columns.
     */
    public function testMailContextOffersSystemAndSpecialLinkTokens(): void
    {
        $resolver = $this->resolverWithColumns('irrelevant', 1, []);

        $vars = $resolver->getMailContextVariables();

        self::assertArrayHasKey('system.user_name', $vars);
        self::assertArrayHasKey('system.user_code', $vars);
        self::assertArrayHasKey('system.special.activation_link', $vars);
        self::assertArrayHasKey('system.special.reset_link', $vars);
        self::assertArrayHasKey('system.special.platform_link', $vars);
    }

    /**
     * Issue #56 v2: the action context mirrors the three scopes the action
     * template context builder documents — recipient.*, record.<field_key> from
     * the chosen data table, and system.* — and intentionally omits globals.
     */
    public function testActionContextOffersRecipientRecordAndSystemNotGlobals(): void
    {
        $columns = ['section_230' => 'Daily mood'];
        $resolver = $this->resolverWithColumns('230', 5, $columns);

        $vars = $resolver->getActionContextVariables(5);

        self::assertSame('recipient.email', $vars['recipient.email'] ?? null);
        // record token is the immutable field_key; label is the curated display_name.
        self::assertSame('record.Daily mood', $vars['record.section_230'] ?? null);
        self::assertArrayHasKey('system.user_name', $vars);
        self::assertArrayNotHasKey('globals.section_230', $vars);
    }

    /**
     * Issue #56 v2: action context without a chosen data table still offers
     * recipient.* + system.*, so the picker is useful before a table is picked.
     */
    public function testActionContextWithoutDataTableStillOffersRecipientAndSystem(): void
    {
        $resolver = $this->resolverWithColumns('irrelevant', 1, []);

        $vars = $resolver->getActionContextVariables(null);

        self::assertArrayHasKey('recipient.email', $vars);
        self::assertArrayHasKey('system.user_name', $vars);
        // No data table → no record.* tokens.
        self::assertSame([], array_filter(
            array_keys($vars),
            static fn (string $token): bool => str_starts_with($token, 'record.'),
        ));
    }

    /**
     * Issue #56 v2: the page/config context offers system.* (+ globals.*) but
     * never section data columns, since page fields render through the
     * page/global pipeline, not a section's data_config.
     */
    public function testPageContextOffersSystemNotDataColumns(): void
    {
        $resolver = $this->resolverWithColumns('230', 5, ['section_230' => 'Daily mood']);

        $vars = $resolver->getPageContextVariables();

        self::assertArrayHasKey('system.user_name', $vars);
        self::assertArrayNotHasKey('d.section_230', $vars);
    }
}
