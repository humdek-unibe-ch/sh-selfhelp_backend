<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Logic;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * An operation a plugin adds to JSON Logic conditions (contract C16): section
 * conditions, action conditions, reminders and job conditions all run on it.
 * A service that implements it is tagged `selfhelp.plugin.json_logic_operation`
 * automatically.
 *
 * Declaring is a second step: the plugin's manifest names the operation in
 * `jsonLogicOperations` (`<namespace>.<operation>`, the namespace belongs to one
 * plugin). {@see JsonLogicOperationRegistrar} hands an implementation to the
 * condition engine only while an **enabled** plugin declares its {@see name()};
 * an implementation nobody declares, and the operation of a disabled plugin,
 * stay unknown, exactly like any other operator the engine does not know.
 *
 * **An operation must be pure.** It is a function of its arguments and nothing
 * else: no clock, no I/O, no database, no random numbers, no state kept between
 * calls. A condition is a security boundary that the server decides again on
 * every read and write, and its answer may be cached until the next clock
 * boundary; an operation that reads the world breaks both. This is documented
 * and checked in review, nothing in the code enforces it.
 *
 * It sees only the arguments of the call, already evaluated (the condition
 * data is not passed in). A condition that uses the operation fails closed when
 * it throws: the condition counts as not met and the error is reported like any
 * other evaluation error of the condition.
 */
#[AutoconfigureTag(JsonLogicOperationInterface::TAG)]
interface JsonLogicOperationInterface
{
    /** The container tag the registrar collects. */
    public const TAG = 'selfhelp.plugin.json_logic_operation';

    /** The operation as the manifest's `jsonLogicOperations[].name` declares it: `<namespace>.<operation>`. */
    public function name(): string;

    /**
     * @param list<mixed> $args the arguments of the call, already evaluated
     */
    public function apply(array $args): mixed;
}
