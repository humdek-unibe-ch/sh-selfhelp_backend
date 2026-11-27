<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support\Plugin;

use App\Plugin\Logic\JsonLogicOperationInterface;

/**
 * C16: the JSON Logic operation `qa.fail` of the QA users plugin
 * ({@see QaUsersPluginFixture}): it always throws, to prove that a condition
 * with a failing plugin operation counts as not met and reports the error like
 * any other evaluation error, instead of taking the request down.
 */
final class QaFailingJsonLogicOperation implements JsonLogicOperationInterface
{
    public const NAME = 'qa.fail';

    public function name(): string
    {
        return self::NAME;
    }

    public function apply(array $args): mixed
    {
        throw new \RuntimeException('qa.fail always fails');
    }
}
