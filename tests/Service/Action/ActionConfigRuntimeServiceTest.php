<?php

namespace App\Tests\Service\Action;

use App\Entity\Action;
use App\Service\Action\ActionConfig;
use App\Service\Action\ActionConfigRuntimeService;
use App\Service\Core\InterpolationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Covers overwrite-variable gating in the runtime action config builder.
 */
class ActionConfigRuntimeServiceTest extends TestCase
{
    /**
     * Ensure schedule values are left untouched when overwrite variables are disabled.
     */
    public function testBuildRuntimeConfigDoesNotApplyOverwriteVariablesWhenFlagDisabled(): void
    {
        $interpolationService = $this->createMock(InterpolationService::class);
        $interpolationService
            ->expects($this->once())
            ->method('interpolateArray')
            ->willReturnCallback(static fn(array $config): array => $config);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new ActionConfigRuntimeService($interpolationService, $entityManager);

        $action = (new Action())->setConfig(json_encode([
            ActionConfig::OVERWRITE_VARIABLES => false,
            ActionConfig::SELECTED_OVERWRITE_VARIABLES => ['send_after'],
            ActionConfig::BLOCKS => [[
                ActionConfig::JOBS => [[
                    ActionConfig::SCHEDULE_TIME => [
                        'send_after' => 5,
                    ],
                ]],
            ]],
        ]));

        $runtimeConfig = $service->buildRuntimeConfig($action, ['send_after' => 99]);

        $this->assertSame(5, $runtimeConfig[ActionConfig::BLOCKS][0][ActionConfig::JOBS][0][ActionConfig::SCHEDULE_TIME]['send_after']);
    }

    /**
     * Ensure schedule values are overwritten only when the feature flag is enabled.
     */
    public function testBuildRuntimeConfigAppliesOverwriteVariablesWhenFlagEnabled(): void
    {
        $interpolationService = $this->createMock(InterpolationService::class);
        $interpolationService
            ->expects($this->once())
            ->method('interpolateArray')
            ->willReturnCallback(static fn(array $config): array => $config);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new ActionConfigRuntimeService($interpolationService, $entityManager);

        $action = (new Action())->setConfig(json_encode([
            ActionConfig::OVERWRITE_VARIABLES => true,
            ActionConfig::SELECTED_OVERWRITE_VARIABLES => ['send_after'],
            ActionConfig::BLOCKS => [[
                ActionConfig::JOBS => [[
                    ActionConfig::SCHEDULE_TIME => [
                        'send_after' => 5,
                    ],
                ]],
            ]],
        ]));

        $runtimeConfig = $service->buildRuntimeConfig($action, ['send_after' => 99]);

        $this->assertSame(99, $runtimeConfig[ActionConfig::BLOCKS][0][ActionConfig::JOBS][0][ActionConfig::SCHEDULE_TIME]['send_after']);
    }
}
