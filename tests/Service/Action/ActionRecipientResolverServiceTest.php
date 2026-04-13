<?php

namespace App\Tests\Service\Action;

use App\Repository\UserRepository;
use App\Service\Action\ActionConfig;
use App\Service\Action\ActionRecipientResolverService;
use PHPUnit\Framework\TestCase;

/**
 * Covers recipient resolution for source-user and overwrite-variable scenarios.
 */
class ActionRecipientResolverServiceTest extends TestCase
{
    /**
     * Ensure the source user remains the recipient when no overrides apply.
     */
    public function testResolveReturnsSourceUserWhenNoOverridesApply(): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        $service = new ActionRecipientResolverService($userRepository);

        $recipients = $service->resolve([], [], 42);

        $this->assertSame([42], $recipients);
    }

    /**
     * Ensure impersonation codes override the source user when overwrite variables are enabled.
     */
    public function testResolveUsesImpersonationCodeWhenConfigured(): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository
            ->expects($this->once())
            ->method('findIdByValidationCode')
            ->with('abc123')
            ->willReturn(55);

        $service = new ActionRecipientResolverService($userRepository);
        $recipients = $service->resolve(
            [
                ActionConfig::OVERWRITE_VARIABLES => true,
                ActionConfig::SELECTED_OVERWRITE_VARIABLES => [ActionConfig::OVERWRITE_IMPERSONATE_USER_CODE],
            ],
            [ActionConfig::OVERWRITE_IMPERSONATE_USER_CODE => 'abc123'],
            42
        );

        $this->assertSame([55], $recipients);
    }

    /**
     * Ensure impersonation is ignored when the overwrite-variables feature flag is disabled.
     */
    public function testResolveIgnoresImpersonationCodeWhenOverwriteVariablesDisabled(): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository
            ->expects($this->never())
            ->method('findIdByValidationCode');

        $service = new ActionRecipientResolverService($userRepository);
        $recipients = $service->resolve(
            [ActionConfig::SELECTED_OVERWRITE_VARIABLES => [ActionConfig::OVERWRITE_IMPERSONATE_USER_CODE]],
            [ActionConfig::OVERWRITE_IMPERSONATE_USER_CODE => 'abc123'],
            42
        );

        $this->assertSame([42], $recipients);
    }
}
