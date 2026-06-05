<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\Action;

use App\Entity\User;
use App\Service\Action\ActionTemplateContextBuilder;
use App\Service\Core\InterpolationService;
use PHPUnit\Framework\TestCase;

/**
 * Slice 0: canonical `{{...}}` interpolation for action templates. The builder
 * renders the namespaced recipient/record/system scopes and flags the legacy
 * `@user`-style placeholders that admin validation must reject.
 */
final class ActionTemplateContextBuilderTest extends TestCase
{
    private function builder(): ActionTemplateContextBuilder
    {
        return new ActionTemplateContextBuilder(new InterpolationService());
    }

    public function testRendersRecipientRecordAndSystemScopes(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getEmail')->willReturn('qa.user@selfhelp.test');
        $user->method('getName')->willReturn('QA User');
        $user->method('getUserName')->willReturn('qa_user');

        $builder = $this->builder();
        $context = $builder->buildContext(
            $user,
            'CODE-123',
            ['score' => '42'],
            ['project_name' => 'SelfHelp'],
        );

        self::assertSame('qa.user@selfhelp.test', $builder->render('{{recipient.email}}', $context));
        self::assertSame('QA User', $builder->render('{{recipient.name}}', $context));
        self::assertSame('qa_user', $builder->render('{{recipient.user_name}}', $context));
        self::assertSame('CODE-123', $builder->render('{{recipient.code}}', $context));
        self::assertSame('Your score is 42', $builder->render('Your score is {{record.score}}', $context));
        self::assertSame('Welcome to SelfHelp', $builder->render('Welcome to {{system.project_name}}', $context));
    }

    public function testEmptyTemplateRendersEmpty(): void
    {
        $user = $this->createStub(User::class);
        $context = $this->builder()->buildContext($user);

        self::assertSame('', $this->builder()->render('', $context));
    }

    public function testHasLegacyPlaceholdersDetectsDeprecatedTokens(): void
    {
        $builder = $this->builder();

        self::assertTrue($builder->hasLegacyPlaceholders('Hello @user'));
        self::assertTrue($builder->hasLegacyPlaceholders('Name: @user_name'));
        self::assertTrue($builder->hasLegacyPlaceholders('Code: @user_code'));
        self::assertTrue($builder->hasLegacyPlaceholders('Project @project link @link'));
    }

    public function testCanonicalPlaceholdersAreNotFlaggedAsLegacy(): void
    {
        $builder = $this->builder();

        self::assertFalse($builder->hasLegacyPlaceholders('{{recipient.email}}'));
        self::assertFalse($builder->hasLegacyPlaceholders('Contact us at support@example.org'));
    }
}
