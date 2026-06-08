<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Tests\Service\Action;

use App\Entity\Action;
use App\Service\Action\ActionConfig;
use App\Service\Action\ActionConfigRuntimeService;
use App\Service\Core\InterpolationService;
use App\Tests\Support\NarrowsJson;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Covers overwrite-variable gating in the runtime action config builder.
 */
class ActionConfigRuntimeServiceTest extends TestCase
{
    use NarrowsJson;

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

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $service = new ActionConfigRuntimeService($interpolationService, $entityManager);

        $action = (new Action())->setConfig((string) json_encode([
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

        $this->assertSame(5, $this->jsonGet(
            $runtimeConfig,
            ActionConfig::BLOCKS,
            0,
            ActionConfig::JOBS,
            0,
            ActionConfig::SCHEDULE_TIME,
            'send_after'
        ));
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

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $service = new ActionConfigRuntimeService($interpolationService, $entityManager);

        $action = (new Action())->setConfig((string) json_encode([
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

        $this->assertSame(99, $this->jsonGet(
            $runtimeConfig,
            ActionConfig::BLOCKS,
            0,
            ActionConfig::JOBS,
            0,
            ActionConfig::SCHEDULE_TIME,
            'send_after'
        ));
    }

    /**
     * Regression for the reported bug where a recipient template like
     * "{{recipient.email}};extra@x.org" collapsed to ";extra@x.org" during the
     * early submitted-value interpolation (Mustache blanks unknown variables).
     *
     * With a REAL interpolation service, the per-recipient scopes
     * ({{recipient.*}}, {{mail.*}}) must survive verbatim so the ActionScheduler
     * can render them once the concrete recipient is known, while ordinary
     * submitted-value placeholders are still resolved.
     */
    public function testBuildRuntimeConfigPreservesPerRecipientPlaceholders(): void
    {
        $service = new ActionConfigRuntimeService(
            new InterpolationService(),
            $this->createStub(EntityManagerInterface::class)
        );

        $action = (new Action())->setConfig((string) json_encode([
            ActionConfig::BLOCKS => [[
                ActionConfig::JOBS => [[
                    ActionConfig::NOTIFICATION => [
                        ActionConfig::RECIPIENT => '{{recipient.email}};qa.stefan@selfhelp.test',
                        ActionConfig::SUBJECT => '{{greeting}} from the clinic',
                        ActionConfig::BODY => 'Open {{mail.link}} to continue.',
                    ],
                ]],
            ]],
        ]));

        $runtimeConfig = $service->buildRuntimeConfig($action, ['greeting' => 'Hello World']);

        $recipient = self::asString($this->jsonGet(
            $runtimeConfig,
            ActionConfig::BLOCKS,
            0,
            ActionConfig::JOBS,
            0,
            ActionConfig::NOTIFICATION,
            ActionConfig::RECIPIENT
        ));
        $subject = self::asString($this->jsonGet(
            $runtimeConfig,
            ActionConfig::BLOCKS,
            0,
            ActionConfig::JOBS,
            0,
            ActionConfig::NOTIFICATION,
            ActionConfig::SUBJECT
        ));
        $body = self::asString($this->jsonGet(
            $runtimeConfig,
            ActionConfig::BLOCKS,
            0,
            ActionConfig::JOBS,
            0,
            ActionConfig::NOTIFICATION,
            ActionConfig::BODY
        ));

        // Per-recipient placeholders survive (NOT blanked) and the literal
        // hardcoded address is preserved alongside them.
        self::assertSame('{{recipient.email}};qa.stefan@selfhelp.test', $recipient);
        self::assertStringContainsString('{{mail.link}}', $body);

        // Ordinary submitted-value placeholders are still interpolated.
        self::assertSame('Hello World from the clinic', $subject);
    }
}
