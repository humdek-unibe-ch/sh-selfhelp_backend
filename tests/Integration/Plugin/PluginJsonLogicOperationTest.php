<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Plugin;

use App\Entity\Plugin\Plugin;
use App\Plugin\Cache\PluginCacheInvalidator;
use App\Plugin\Logic\JsonLogicOperationInterface;
use App\Plugin\Logic\JsonLogicOperationRegistrar;
use App\Plugin\Registry\PluginRegistryService;
use App\Service\Action\ActionConditionEvaluatorService;
use App\Service\Action\ActionConfig;
use App\Service\Core\ConditionService;
use App\Tests\Support\Plugin\QaDoubleJsonLogicOperation;
use App\Tests\Support\Plugin\QaFailingJsonLogicOperation;
use App\Tests\Support\Plugin\QaUsersPluginFixture;
use App\Tests\Support\QaKernelTestCase;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;

/**
 * C16: a plugin adds pure JSON Logic operations to conditions. Section conditions, action conditions, reminders
 * and job conditions all run on `ConditionService`, which hands the operations of enabled plugins to the engine
 * right before it evaluates a condition that uses one. The QA users plugin ({@see QaUsersPluginFixture}) declares
 * `qa.double` in its manifest and {@see QaDoubleJsonLogicOperation} implements it.
 *
 * The engine (`JWadhams\JsonLogic`) keeps custom operations in a static map of the whole process and cannot
 * remove one. The tests below therefore turn the plugin off and on again inside one test and look at what the
 * very next condition does, instead of trusting a fresh process.
 *
 * Not declared, or declared by a plugin that is not enabled: the operation is unknown, exactly like any other
 * operator the engine does not know (the condition is not met and the error is reported in `fields`).
 */
#[PhpUnitGroup('security')]
final class PluginJsonLogicOperationTest extends QaKernelTestCase
{
    /** `qa.double` of 21 is 42. */
    private const DOUBLES_TO_42 = ['==' => [['qa.double' => [21]], 42]];

    private ConditionService $conditions;
    private ActionConditionEvaluatorService $actionConditions;
    private PluginCacheInvalidator $invalidator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conditions = $this->service(ConditionService::class);
        $this->actionConditions = $this->service(ActionConditionEvaluatorService::class);
        $this->invalidator = $this->service(PluginCacheInvalidator::class);
    }

    protected function tearDown(): void
    {
        // The cache pool outlives the database rollback: the next test must not find the QA plugin.
        QaUsersPluginFixture::forget($this->invalidator);
        parent::tearDown();
    }

    // == it works when declared and enabled =================================================

    public function testASectionConditionUsesTheOperationOfAnEnabledPluginThatDeclaresIt(): void
    {
        $this->install();

        $met = $this->conditions->evaluateCondition(self::DOUBLES_TO_42, null, 'qa_p12_section');
        $this->assertMet($met, 'qa.double of 21 is 42');
        self::assertSame([], $met['fields'], 'and nothing went wrong');

        $notMet = $this->conditions->evaluateCondition(['==' => [['qa.double' => [21]], 43]], null, 'qa_p12_section');
        self::assertFalse($notMet['result']);
        self::assertSame([], $notMet['fields'], 'a condition that is simply not met reports no error');
    }

    public function testTheOperationSeesItsArgumentsAlreadyEvaluated(): void
    {
        $this->install();

        $result = $this->conditions->evaluateCondition(['==' => [['qa.double' => [['+' => [10, 11]]]], 42]], null, 'qa_p12_section');

        $this->assertMet($result, 'qa.double received 21, the evaluated "10 + 11"');
    }

    public function testAnActionConditionUsesItToo(): void
    {
        $this->install();

        self::assertTrue($this->actionConditions->passes(self::DOUBLES_TO_42, null, 'qa_p12_action'), 'a bare JSON Logic array');
        self::assertTrue($this->actionConditions->passes([ActionConfig::JSON_LOGIC => self::DOUBLES_TO_42], null, 'qa_p12_action'), 'the wrapped form of the action config');
        self::assertTrue($this->actionConditions->passes(json_encode(self::DOUBLES_TO_42, JSON_THROW_ON_ERROR), null, 'qa_p12_action'), 'a JSON string, as stored in a job');
        self::assertFalse($this->actionConditions->passes(['==' => [['qa.double' => [21]], 43]], null, 'qa_p12_action'));
    }

    // == unknown when not declared, or not enabled ==========================================

    public function testAnOperationNoManifestDeclaresIsUnknown(): void
    {
        $this->install(operations: []);

        $this->assertUnknown($this->conditions->evaluateCondition(self::DOUBLES_TO_42, null, 'qa_p12_section'), 'qa.double');
        self::assertFalse($this->actionConditions->passes(self::DOUBLES_TO_42, null, 'qa_p12_action'));
    }

    public function testADeclaredOperationNobodyImplementsIsUnknown(): void
    {
        $this->install(operations: ['qa.missing']);

        $this->assertUnknown($this->conditions->evaluateCondition(['qa.missing' => [1]], null, 'qa_p12_section'), 'qa.missing');
    }

    public function testAnImplementationThatAnotherOperationOfTheManifestDoesNotCoverIsUnknown(): void
    {
        $this->install(operations: [QaFailingJsonLogicOperation::NAME]);

        $this->assertUnknown($this->conditions->evaluateCondition(self::DOUBLES_TO_42, null, 'qa_p12_section'), 'qa.double');
    }

    public function testADisabledPluginsOperationIsUnknownAndStaysSoUntilItIsEnabledAgain(): void
    {
        $plugin = $this->install();
        $this->assertMet($this->conditions->evaluateCondition(self::DOUBLES_TO_42, null, 'qa_p12_section'), 'enabled: known');

        QaUsersPluginFixture::setEnabled($plugin, false, $this->em, $this->invalidator);
        // The engine's map is static and still holds the closure of the first call: it must have been replaced.
        $this->assertUnknown($this->conditions->evaluateCondition(self::DOUBLES_TO_42, null, 'qa_p12_section'), 'qa.double');
        self::assertFalse($this->actionConditions->passes(self::DOUBLES_TO_42, null, 'qa_p12_action'));

        QaUsersPluginFixture::setEnabled($plugin, true, $this->em, $this->invalidator);
        $this->assertMet($this->conditions->evaluateCondition(self::DOUBLES_TO_42, null, 'qa_p12_section'), 'enabled again: known again');
    }

    public function testAnUninstalledPluginsOperationDoesNotStayRegistered(): void
    {
        $plugin = $this->install();
        $this->assertMet($this->conditions->evaluateCondition(self::DOUBLES_TO_42, null, 'qa_p12_section'), 'installed: known');

        $this->em->remove($plugin);
        $this->em->flush();
        $this->invalidator->invalidatePluginSurfaceCaches();

        $this->assertUnknown($this->conditions->evaluateCondition(self::DOUBLES_TO_42, null, 'qa_p12_section'), 'qa.double');
    }

    // == a failing operation, a core operator ===============================================

    public function testAnOperationThatFailsFailsTheConditionClosedAndReportsIt(): void
    {
        $this->install(operations: [QaFailingJsonLogicOperation::NAME]);

        $result = $this->conditions->evaluateCondition(['qa.fail' => [1]], null, 'qa_p12_section');

        self::assertFalse($result['result'], 'a condition that cannot be evaluated is not met');
        self::assertIsString($result['fields']);
        self::assertStringContainsString("section 'qa_p12_section'", $result['fields']);
        self::assertStringContainsString('qa.fail always fails', $result['fields']);
        self::assertFalse($this->actionConditions->passes(['qa.fail' => [1]], null, 'qa_p12_action'));
    }

    public function testAPluginCanNeverTakeOverACoreOperator(): void
    {
        // A manifest that tried to declare `max` (the schema refuses it; here it is stored as it came) and an implementation for it.
        $this->install(operations: ['max']);
        $impostor = new class implements JsonLogicOperationInterface {
            public function name(): string
            {
                return 'max';
            }

            public function apply(array $args): mixed
            {
                return -1;
            }
        };

        (new JsonLogicOperationRegistrar($this->service(PluginRegistryService::class), [$impostor]))->register();

        $result = $this->conditions->evaluateCondition(['==' => [['max' => [1, 5]], 5]], null, 'qa_p12_section');
        $this->assertMet($result, 'max is still the core operator');
        self::assertSame([], $result['fields']);
    }

    public function testAConditionWithoutAPluginOperationIsNotAffected(): void
    {
        $this->install();

        self::assertTrue($this->conditions->evaluateCondition(['==' => [1, 1]], null, 'qa_p12_section')['result']);
        self::assertFalse($this->conditions->evaluateCondition(['==' => [1, 2]], null, 'qa_p12_section')['result']);
        self::assertTrue($this->conditions->evaluateCondition(['and' => [true, ['>' => [3, 2]]]], null, 'qa_p12_section')['result']);
    }

    // == helpers ============================================================================

    /**
     * The enabled QA users plugin, declaring the given operations.
     *
     * @param list<string> $operations
     */
    private function install(array $operations = [QaDoubleJsonLogicOperation::NAME]): Plugin
    {
        return QaUsersPluginFixture::install($this->em, $this->invalidator, [], $operations);
    }

    /**
     * The condition was met; when it was not, the failure says what the engine reported in `fields`.
     *
     * @param array<string, mixed> $outcome
     */
    private function assertMet(array $outcome, string $why): void
    {
        self::assertTrue($outcome['result'], $why . ' (the engine said: ' . json_encode($outcome['fields']) . ')');
    }

    /**
     * The condition was not met and the engine said what it says for any operator it does not know.
     *
     * @param array<string, mixed> $result
     */
    private function assertUnknown(array $result, string $operation): void
    {
        self::assertFalse($result['result'], "$operation is unknown, so the condition is not met");
        self::assertIsString($result['fields']);
        self::assertStringContainsString("Unrecognized operator $operation", $result['fields']);
    }
}
