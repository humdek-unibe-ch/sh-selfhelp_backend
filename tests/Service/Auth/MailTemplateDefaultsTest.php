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
        // Issue #56 v2: seeded templates render the home link via the namespaced
        // `{{system.special.platform_link}}` token (not the legacy {{platform_url}}).
        self::assertStringContainsString('{{system.special.platform_link}}', MailTemplateDefaults::getBody(MailTemplateDefaults::TYPE_PASSWORD_CHANGED, 'en-GB'));
    }
}
