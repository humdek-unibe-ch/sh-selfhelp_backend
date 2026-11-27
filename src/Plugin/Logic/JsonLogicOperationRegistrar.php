<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Logic;

use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Registry\PluginRegistryService;
use JWadhams\JsonLogic;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Hands the JSON Logic operations of enabled plugins to the condition engine
 * (contract C16), right before a condition is evaluated.
 *
 * An operation is active while an **enabled** plugin declares its name in the
 * manifest (`jsonLogicOperations`) and a {@see JsonLogicOperationInterface}
 * service implements it. An implementation nobody declares is not registered.
 *
 * The engine (`JWadhams\JsonLogic`) keeps its custom operations in a static
 * map of the whole process and has no way to remove one. Registering once
 * would therefore leave the operation of a plugin that was disabled or removed
 * in the map, and a long-running process (a job runner, a worker, a test run)
 * would keep evaluating it. So every run of {@see register()}
 *
 *   - registers the active operations again with the implementation that is
 *     current now, and
 *   - replaces each operation this process handed over earlier and that is no
 *     longer active with a function that refuses it with the engine's own
 *     "Unrecognized operator" error, so the condition behaves as for any
 *     operator the engine does not know.
 *
 * Only names of the form `<namespace>.<operation>` are ever registered. A core
 * operator has no dot, so a plugin can never shadow one (the engine would let a
 * custom operation take precedence over a built-in one).
 *
 * Cost: {@see registerFor()} looks at the condition first and does nothing
 * unless it uses a namespaced operator, so a page full of ordinary conditions
 * never reads the plugin list.
 */
final class JsonLogicOperationRegistrar
{
    /** The manifest schema's shape of an operation name; also what keeps a plugin away from the core operators. */
    private const NAME_PATTERN = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/';

    /** @var array<string, true> the operations this process gave to the engine, which cannot take any back */
    private static array $handedOver = [];

    /**
     * @param iterable<JsonLogicOperationInterface> $operations every implementation of every plugin
     */
    public function __construct(
        private readonly PluginRegistryService $registry,
        #[AutowireIterator(JsonLogicOperationInterface::TAG)]
        private readonly iterable $operations,
    ) {
    }

    /**
     * Prepare the engine for this condition: a no-op unless the condition uses a namespaced operator.
     *
     * @param mixed $logic the condition about to be evaluated (a JSON Logic array, or anything else, which uses none)
     */
    public function registerFor(mixed $logic): void
    {
        if (is_array($logic) && self::usesNamespacedOperator($logic)) {
            $this->register();
        }
    }

    /**
     * Make the engine know exactly the operations that are active now, and refuse every other one this process handed over before.
     */
    public function register(): void
    {
        $declared = $this->declaredNames();

        $active = [];
        foreach ($this->operations as $operation) {
            $name = $operation->name();
            if (isset($declared[$name]) && !isset($active[$name])) {
                $active[$name] = $operation;
            }
        }

        foreach ($active as $name => $operation) {
            JsonLogic::add_operation($name, self::run($operation, $name));
            self::$handedOver[$name] = true;
        }

        foreach (array_keys(self::$handedOver) as $name) {
            if (!isset($active[$name])) {
                JsonLogic::add_operation($name, self::refuse($name));
            }
        }
    }

    /**
     * The names the manifests of enabled plugins declare.
     *
     * @return array<string, true>
     */
    private function declaredNames(): array
    {
        $names = [];
        foreach ($this->registry->getEnabled() as $plugin) {
            foreach ((new PluginManifest($plugin->getManifestJson()))->getJsonLogicOperations() as $declaration) {
                $name = $declaration['name'] ?? null;
                if (is_string($name) && preg_match(self::NAME_PATTERN, $name) === 1) {
                    $names[$name] = true;
                }
            }
        }

        return $names;
    }

    /**
     * The function the engine calls with the evaluated arguments. A plugin
     * that fails, or answers with something a condition cannot hold, fails the
     * condition (which the condition service reports and treats as not met)
     * instead of taking the page down.
     */
    private static function run(JsonLogicOperationInterface $operation, string $name): \Closure
    {
        return static function (mixed ...$args) use ($operation, $name): mixed {
            try {
                $result = $operation->apply(array_values($args));
            } catch (\Throwable $e) {
                throw new \Exception(sprintf('Plugin operation "%s" failed: %s', $name, $e->getMessage()), 0, $e);
            }

            if ($result !== null && !is_scalar($result) && !is_array($result)) {
                throw new \Exception(sprintf('Plugin operation "%s" answered with a value a condition cannot hold.', $name));
            }

            return $result;
        };
    }

    /** What the engine does for an operator it does not know. */
    private static function refuse(string $name): \Closure
    {
        return static function () use ($name): never {
            throw new \Exception("Unrecognized operator $name");
        };
    }

    /**
     * Whether the condition has an operator with a dot in its name, anywhere in the tree.
     *
     * @param array<array-key, mixed> $logic
     */
    private static function usesNamespacedOperator(array $logic): bool
    {
        foreach ($logic as $key => $value) {
            if (is_string($key) && str_contains($key, '.')) {
                return true;
            }
            if (is_array($value) && self::usesNamespacedOperator($value)) {
                return true;
            }
        }

        return false;
    }
}
