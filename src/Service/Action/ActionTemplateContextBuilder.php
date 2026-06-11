<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Action;

use App\Entity\User;
use App\Service\Core\InterpolationService;

/**
 * Builds the structured Mustache context used to render action email and
 * notification templates, and renders them via {@see InterpolationService}.
 *
 * This is the canonical replacement for the legacy `@user`, `@user_name` and
 * `@user_code` `str_replace()` placeholders. New action templates must use the
 * namespaced double-curly scopes:
 *   - `{{recipient.email}}`, `{{recipient.name}}`, `{{recipient.user_name}}`,
 *     `{{recipient.code}}`, `{{recipient.timezone}}`
 *   - `{{record.<field>}}` for submitted/source-row values
 *   - `{{system.project_name}}`, `{{system.platform_url}}`
 */
class ActionTemplateContextBuilder
{
    /**
     * Known legacy placeholders that must be migrated to `{{...}}` scopes.
     *
     * @var list<string>
     */
    private const LEGACY_PLACEHOLDERS = ['@user_name', '@user_code', '@user', '@project', '@link'];

    public function __construct(
        private readonly InterpolationService $interpolationService,
    ) {
    }

    /**
     * Build the per-recipient template context.
     *
     * @param array<string, mixed> $record
     *   Submitted/source-row values exposed as `{{record.<field>}}`.
     * @param array<string, mixed> $system
     *   System values exposed as `{{system.<key>}}` (e.g. project_name).
     *
     * @return array<string, mixed>
     *   The structured context keyed by scope (`recipient`, `record`, `system`).
     */
    public function buildContext(User $recipient, ?string $code = null, array $record = [], array $system = []): array
    {
        return [
            'recipient' => [
                'email' => (string) ($recipient->getEmail() ?? ''),
                'name' => (string) ($recipient->getName() ?? ''),
                'user_name' => (string) ($recipient->getUserName() ?? ''),
                'code' => (string) ($code ?? ''),
                'timezone' => (string) ($recipient->getTimezone()?->getLookupCode()
                    ?? $recipient->getTimezone()?->getLookupValue()
                    ?? ''),
            ],
            'record' => $record,
            'system' => $system,
        ];
    }

    /**
     * Render a template string against a previously built context.
     *
     * @param array<string, mixed> $context
     *   The structured context produced by {@see buildContext()}.
     */
    public function render(string $template, array $context): string
    {
        if ($template === '') {
            return $template;
        }

        return $this->interpolationService->interpolate($template, $context);
    }

    /**
     * Detect whether a template still contains a deprecated legacy `@...`
     * placeholder ({@see LEGACY_PLACEHOLDERS}).
     *
     * Wired into admin action-translation validation
     * ({@see \App\Service\CMS\Admin\AdminActionTranslationService::bulkCreateTranslations()})
     * so saving template content that reintroduces the deprecated syntax is
     * rejected. Matching is word-boundary aware so a legacy token like `@user`
     * is not falsely flagged inside an unrelated literal such as an email
     * domain (e.g. `noreply@userville.test`).
     */
    public function hasLegacyPlaceholders(string $text): bool
    {
        foreach (self::LEGACY_PLACEHOLDERS as $placeholder) {
            if (preg_match('/' . preg_quote($placeholder, '/') . '\b/', $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
