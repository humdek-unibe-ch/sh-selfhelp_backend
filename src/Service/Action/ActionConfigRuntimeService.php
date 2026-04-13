<?php

namespace App\Service\Action;

use App\Entity\Action;
use App\Service\Core\InterpolationService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds runtime-ready action configuration from persisted admin JSON.
 *
 * This includes config decoding, overwrite-variable application, interpolation,
 * repeat counting, randomized block selection, and persistence of randomization
 * counters for even-presentation behavior.
 */
class ActionConfigRuntimeService
{
    public function __construct(
        private readonly InterpolationService $interpolationService,
        private readonly EntityManagerInterface $entityManager
    ) {
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
        return $this->interpolationService->interpolateArray($config, $submittedValues);
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
            return max(1, (int) (($config[ActionConfig::REPEATER][ActionConfig::OCCURRENCES] ?? 1)));
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
        $blocks = array_values($runtimeConfig[ActionConfig::BLOCKS] ?? []);
        if ($blocks === []) {
            return [];
        }

        foreach ($blocks as $index => &$block) {
            $block['index'] = $index;
        }
        unset($block);

        if (($runtimeConfig[ActionConfig::RANDOMIZE] ?? false) !== true) {
            return $blocks;
        }

        $selectedBlocks = $blocks;
        if (($runtimeConfig[ActionConfig::RANDOMIZER][ActionConfig::RANDOMIZER_EVEN_PRESENTATION] ?? false) === true) {
            $selectedBlocks = $this->filterBlocksWithLowestRandomizationCount($selectedBlocks);
        }

        shuffle($selectedBlocks);

        $limit = max(1, (int) (($runtimeConfig[ActionConfig::RANDOMIZER][ActionConfig::RANDOMIZER_RANDOM_ELEMENTS] ?? 1)));
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

        return is_array($decoded) ? $decoded : [];
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

        foreach (($config[ActionConfig::BLOCKS] ?? []) as $blockIndex => $block) {
            foreach (($block[ActionConfig::JOBS] ?? []) as $jobIndex => $job) {
                foreach ($selectedVariables as $variable) {
                    if (!is_string($variable) || !array_key_exists($variable, $submittedValues)) {
                        continue;
                    }

                    $config[ActionConfig::BLOCKS][$blockIndex][ActionConfig::JOBS][$jobIndex][ActionConfig::SCHEDULE_TIME][$variable] = $submittedValues[$variable];
                }
            }
        }

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
            $count = (int) ($block[ActionConfig::RANDOMIZATION_COUNT] ?? 0);
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
        if (!isset($config[ActionConfig::BLOCKS]) || !is_array($config[ActionConfig::BLOCKS])) {
            return;
        }

        foreach ($selectedBlocks as $block) {
            $index = (int) ($block['index'] ?? -1);
            if ($index < 0 || !isset($config[ActionConfig::BLOCKS][$index])) {
                continue;
            }

            $config[ActionConfig::BLOCKS][$index][ActionConfig::RANDOMIZATION_COUNT] =
                (int) ($config[ActionConfig::BLOCKS][$index][ActionConfig::RANDOMIZATION_COUNT] ?? 0) + 1;
        }

        $action->setConfig(json_encode($config, JSON_UNESCAPED_UNICODE));
        $this->entityManager->flush();
    }
}
