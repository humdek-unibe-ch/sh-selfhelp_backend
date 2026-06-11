<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\Auth;

use App\Service\Auth\MailTemplateDefaults;
use PHPUnit\Framework\TestCase;

final class MailTemplateDefaultsTest extends TestCase
{
    public function testPasswordChangedTemplateDefaultsExist(): void
    {
        self::assertContains(MailTemplateDefaults::TYPE_PASSWORD_CHANGED, MailTemplateDefaults::TYPES);
        self::assertSame('Your password was changed', MailTemplateDefaults::getSubject(MailTemplateDefaults::TYPE_PASSWORD_CHANGED, 'en-GB'));
        self::assertStringContainsString('{{platform_url}}', MailTemplateDefaults::getBody(MailTemplateDefaults::TYPE_PASSWORD_CHANGED, 'en-GB'));
    }
}
