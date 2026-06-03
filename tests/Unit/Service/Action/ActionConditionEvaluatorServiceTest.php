<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\Action;

use App\Service\Action\ActionConfig;
use App\Service\Action\ActionConditionEvaluatorService;
use App\Service\Core\ConditionService;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for action condition evaluation. Empty/absent conditions must pass
 * cheaply (no evaluation), real conditions must delegate to ConditionService and
 * honour its boolean result.
 */
final class ActionConditionEvaluatorServiceTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array<string, mixed>|string|null}>
     */
    public static function emptyConditionProvider(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
        yield 'whitespace string' => ['   '];
        yield 'empty array' => [[]];
        yield 'wrapped empty json logic' => [[ActionConfig::JSON_LOGIC => []]];
    }

    /**
     * @param array<string, mixed>|string|null $condition
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('emptyConditionProvider')]
    public function testEmptyConditionsPassWithoutEvaluation(array|string|null $condition): void
    {
        $conditionService = $this->createMock(ConditionService::class);
        $conditionService->expects(self::never())->method('evaluateCondition');

        $service = new ActionConditionEvaluatorService($conditionService);
        self::assertTrue($service->passes($condition, 5, 'action.root'));
    }

    public function testNonJsonStringConditionIsDelegatedAndTruthyResultPasses(): void
    {
        $conditionService = $this->createMock(ConditionService::class);
        $conditionService->expects(self::once())
            ->method('evaluateCondition')
            ->with('age > 18', 5, 'action.job')
            ->willReturn(['result' => true]);

        $service = new ActionConditionEvaluatorService($conditionService);
        self::assertTrue($service->passes('age > 18', 5, 'action.job'));
    }

    public function testFalsyEvaluationResultBlocks(): void
    {
        $conditionService = $this->createStub(ConditionService::class);
        $conditionService->method('evaluateCondition')->willReturn(['result' => false]);

        $service = new ActionConditionEvaluatorService($conditionService);
        self::assertFalse($service->passes('age > 18', 5, 'action.job'));
    }

    public function testWrappedJsonLogicArrayIsUnwrappedBeforeEvaluation(): void
    {
        $jsonLogic = ['>' => [['var' => 'age'], 18]];

        $conditionService = $this->createMock(ConditionService::class);
        $conditionService->expects(self::once())
            ->method('evaluateCondition')
            ->with($jsonLogic, 5, 'action.block')
            ->willReturn(['result' => true]);

        $service = new ActionConditionEvaluatorService($conditionService);
        self::assertTrue($service->passes([ActionConfig::JSON_LOGIC => $jsonLogic], 5, 'action.block'));
    }

    public function testMissingResultKeyDefaultsToBlocked(): void
    {
        $conditionService = $this->createStub(ConditionService::class);
        $conditionService->method('evaluateCondition')->willReturn([]);

        $service = new ActionConditionEvaluatorService($conditionService);
        self::assertFalse($service->passes('age > 18', 5, 'action.job'));
    }
}
