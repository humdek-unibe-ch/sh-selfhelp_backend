<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\Action;

use App\Entity\Action;
use App\Service\Core\BaseService;
use App\Service\Core\InterpolationService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds runtime-ready action configuration from persisted admin JSON.
 *
 * This includes config decoding, overwrite-variable application, interpolation,
 * repeat counting, randomized block selection, and persistence of randomization
 * counters for even-presentation behavior.
 */
class ActionConfigRuntimeService extends BaseService
{
    public function __construct(
        private readonly InterpolationService $interpolationService,
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Coerce a mixed JSON-config section into a string-keyed array.
     *
     * @return array<string, mixed>
     */
    private function toConfigArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            $result[(string) $key] = $item;
        }

        return $result;
    }

    /**
     * Decode and prepare an action config for a specific trigger event.
     *
     * @param array<string, mixed> $submittedValues
     *   Submitted values used for overwrite-variable application and interpolation.
     *
     * @return array<string, mixed>
     *   The runtime-ready config, or an empty array when no valid config exists.
     */
    public function buildRuntimeConfig(Action $action, array $submittedValues): array
    {
        $config = $this->decodeConfig($action->getConfig());
        if ($config === []) {
            return [];
        }

        $config = $this->applyOverwriteVariables($config, $submittedValues);
        return $this->toConfigArray($this->interpolationService->interpolateArray($config, $submittedValues));
    }

    /**
     * Determine how many iterations the current action should execute.
     *
     * @param array<string, mixed> $config
     *   The runtime action config.
     * @param int $firstJobDateCount
     *   The number of dates generated for the first job in the action.
     *
     * @return int
     *   The number of block/job iterations to run.
     */
    public function getIterationCount(array $config, int $firstJobDateCount = 1): int
    {
        if (($config[ActionConfig::REPEAT_UNTIL_DATE] ?? false) === true) {
            return max(1, $firstJobDateCount);
        }

        if (($config[ActionConfig::REPEAT] ?? false) === true) {
            $repeater = $this->asArray($config[ActionConfig::REPEATER] ?? null);
            return max(1, $this->asInt($repeater[ActionConfig::OCCURRENCES] ?? 1));
        }

        return 1;
    }

    /**
     * Select the blocks that should execute for the current iteration.
     *
     * @param array<string, mixed> $runtimeConfig
     *   The runtime action config.
     *
     * @return array<int, array<string, mixed>>
     *   The selected block definitions for this iteration.
     */
    public function selectBlocksForIteration(Action $action, array $runtimeConfig): array
    {
        $blocks = array_values($this->asArray($runtimeConfig[ActionConfig::BLOCKS] ?? null));
        if ($blocks === []) {
            return [];
        }

        $normalizedBlocks = [];
        foreach ($blocks as $index => $block) {
            $blockArray = $this->toConfigArray($block);
            $blockArray['index'] = $index;
            $normalizedBlocks[] = $blockArray;
        }

        if (($runtimeConfig[ActionConfig::RANDOMIZE] ?? false) !== true) {
            return $normalizedBlocks;
        }

        $randomizer = $this->asArray($runtimeConfig[ActionConfig::RANDOMIZER] ?? null);
        $selectedBlocks = $normalizedBlocks;
        if (($randomizer[ActionConfig::RANDOMIZER_EVEN_PRESENTATION] ?? false) === true) {
            $selectedBlocks = $this->filterBlocksWithLowestRandomizationCount($selectedBlocks);
        }

        shuffle($selectedBlocks);

        $limit = max(1, $this->asInt($randomizer[ActionConfig::RANDOMIZER_RANDOM_ELEMENTS] ?? 1));
        $selectedBlocks = array_slice($selectedBlocks, 0, $limit);

        $this->incrementRandomizationCounters($action, $selectedBlocks);

        return $selectedBlocks;
    }

    /**
     * Decode a stored action config, including double-encoded JSON payloads.
     *
     * @param string|null $config
     *   The raw JSON config stored on the action entity.
     *
     * @return array<string, mixed>
     *   The decoded config array, or an empty array when decoding fails.
     */
    private function decodeConfig(?string $config): array
    {
        if ($config === null || trim($config) === '') {
            return [];
        }

        $decoded = json_decode($config, true);
        while (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }

        return is_array($decoded) ? $this->toConfigArray($decoded) : [];
    }

    /**
     * Apply configured overwrite variables to job schedule settings.
     *
     * @param array<string, mixed> $config
     *   The decoded action config.
     * @param array<string, mixed> $submittedValues
     *   Submitted values that may replace selected schedule fields.
     *
     * @return array<string, mixed>
     *   The config with overwrite-variable substitutions applied when enabled.
     */
    private function applyOverwriteVariables(array $config, array $submittedValues): array
    {
        if (($config[ActionConfig::OVERWRITE_VARIABLES] ?? false) !== true) {
            return $config;
        }

        $selectedVariables = $config[ActionConfig::SELECTED_OVERWRITE_VARIABLES] ?? [];
        if (!is_array($selectedVariables)) {
            return $config;
        }

        $blocks = $this->asArray($config[ActionConfig::BLOCKS] ?? null);
        foreach ($blocks as $blockIndex => $block) {
            if (!is_array($block)) {
                continue;
            }

            $jobs = $this->asArray($block[ActionConfig::JOBS] ?? null);
            foreach ($jobs as $jobIndex => $job) {
                if (!is_array($job)) {
                    continue;
                }

                foreach ($selectedVariables as $variable) {
                    if (!is_string($variable) || !array_key_exists($variable, $submittedValues)) {
                        continue;
                    }

                    $scheduleTime = $this->toConfigArray($job[ActionConfig::SCHEDULE_TIME] ?? null);
                    $scheduleTime[$variable] = $submittedValues[$variable];
                    $job[ActionConfig::SCHEDULE_TIME] = $scheduleTime;
                }

                $jobs[$jobIndex] = $job;
            }

            $block[ActionConfig::JOBS] = $jobs;
            $blocks[$blockIndex] = $block;
        }

        $config[ActionConfig::BLOCKS] = $blocks;

        return $config;
    }

    /**
     * Keep only the blocks with the lowest randomization counter.
     *
     * @param array<int, array<string, mixed>> $blocks
     *   Candidate blocks for random selection.
     *
     * @return array<int, array<string, mixed>>
     *   The subset of blocks that share the minimum randomization count.
     */
    private function filterBlocksWithLowestRandomizationCount(array $blocks): array
    {
        $minimumCount = null;
        $selectedBlocks = [];

        foreach ($blocks as $block) {
            $count = $this->asInt($block[ActionConfig::RANDOMIZATION_COUNT] ?? 0);
            if ($minimumCount === null || $count < $minimumCount) {
                $minimumCount = $count;
                $selectedBlocks = [$block];
                continue;
            }

            if ($count === $minimumCount) {
                $selectedBlocks[] = $block;
            }
        }

        return $selectedBlocks;
    }

    /**
     * Persist randomization counter increments for the selected blocks.
     *
     * @param array<int, array<string, mixed>> $selectedBlocks
     *   The blocks chosen for the current randomized iteration.
     */
    private function incrementRandomizationCounters(Action $action, array $selectedBlocks): void
    {
        if ($selectedBlocks === []) {
            return;
        }

        $config = $this->decodeConfig($action->getConfig());
        $blocks = $config[ActionConfig::BLOCKS] ?? null;
        if (!is_array($blocks)) {
            return;
        }

        foreach ($selectedBlocks as $block) {
            $index = $this->asInt($block['index'] ?? -1);
            if ($index < 0 || !isset($blocks[$index]) || !is_array($blocks[$index])) {
                continue;
            }

            $blocks[$index][ActionConfig::RANDOMIZATION_COUNT] =
                $this->asInt($blocks[$index][ActionConfig::RANDOMIZATION_COUNT] ?? 0) + 1;
        }

        $config[ActionConfig::BLOCKS] = $blocks;

        $encoded = json_encode($config, JSON_UNESCAPED_UNICODE);
        if ($encoded !== false) {
            $action->setConfig($encoded);
            $this->entityManager->flush();
        }
    }
}
