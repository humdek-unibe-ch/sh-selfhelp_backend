<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\Core;

use App\Service\Core\ConditionService;
use App\Tests\Support\QaKernelTestCase;

/**
 * Behavioural coverage for {@see ConditionService::evaluateCondition()} — the
 * JSON-Logic gate behind section visibility (plan Phase 8: JSON-logic edge
 * cases). Driven anonymously with an explicit language id so the system-variable
 * branches stay deterministic.
 */
final class ConditionServiceTest extends QaKernelTestCase
{
    private ConditionService $conditions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conditions = $this->service(ConditionService::class);
    }

    public function testNullConditionPasses(): void
    {
        self::assertTrue($this->conditions->evaluateCondition(null)['result']);
    }

    public function testBooleanTrueAndFalseConditions(): void
    {
        self::assertTrue($this->conditions->evaluateCondition('true')['result']);
        self::assertFalse($this->conditions->evaluateCondition('false')['result']);
    }

    public function testInvalidJsonConditionFailsWithError(): void
    {
        $result = $this->conditions->evaluateCondition('{ not valid json', null, 'qa_section');

        self::assertFalse($result['result']);
        self::assertArrayHasKey('fields', $result);
        self::assertStringContainsString('qa_section', $this->coerceString($result['fields']));
    }

    public function testLiteralComparisonIsEvaluated(): void
    {
        self::assertTrue($this->conditions->evaluateCondition(['==' => [1, 1]])['result']);
        self::assertFalse($this->conditions->evaluateCondition(['==' => [1, 2]])['result']);
    }

    public function testLanguageSystemVariableMatchesRequestLanguage(): void
    {
        $condition = ['==' => [['var' => 'language'], 1]];

        self::assertTrue(
            $this->conditions->evaluateCondition($condition, null, 'qa_section', 1)['result'],
            'language var must equal the supplied request language id.',
        );
        self::assertFalse(
            $this->conditions->evaluateCondition($condition, null, 'qa_section', 2)['result'],
            'language var must not match a different language id.',
        );
    }

    public function testAnonymousUserHasNoGroupMembership(): void
    {
        // Anonymous visitors resolve to an empty user_group, so a group-gated
        // condition must evaluate to false rather than crash.
        $condition = ['in' => [['var' => 'user_group'], ['admin']]];

        self::assertFalse($this->conditions->evaluateCondition($condition, null, 'qa_section', 1)['result']);
    }
}
