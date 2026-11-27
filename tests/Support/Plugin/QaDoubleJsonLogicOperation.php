<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support\Plugin;

use App\Plugin\Logic\JsonLogicOperationInterface;

/**
 * C16: the JSON Logic operation `qa.double` of the QA users plugin
 * ({@see QaUsersPluginFixture}): it answers twice its first argument. A pure
 * function of its arguments, as every operation must be.
 *
 * `autoconfigure` tags it `selfhelp.plugin.json_logic_operation` (declared on the
 * interface), which puts it into the real
 * {@see \App\Plugin\Logic\JsonLogicOperationRegistrar}. Whether the condition
 * engine knows it depends only on the manifest of an enabled plugin declaring it.
 */
final class QaDoubleJsonLogicOperation implements JsonLogicOperationInterface
{
    public const NAME = 'qa.double';

    public function name(): string
    {
        return self::NAME;
    }

    public function apply(array $args): mixed
    {
        $value = $args[0] ?? 0;

        return is_numeric($value) ? $value * 2 : 0;
    }
}
