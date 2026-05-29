<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Auth;

use App\Repository\PageRepository;
use App\Service\CMS\CmsPreferenceService;
use App\Service\Core\InterpolationService;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Resolves and renders email templates configured via the `sh-mail-config`
 * CMS page.
 *
 * Resolution order for every value (last wins):
 *   1. Hardcoded constants in {@see MailTemplateDefaults}.
 *   2. `pages_fields_translation` row for the `sh-mail-config` page
 *      (the admin-editable copy seeded by the install migration).
 *   3. Caller-provided overrides (`buildEmailConfig` $overrides argument).
 *
 * All `{{placeholders}}` in the resolved subject + body are then rendered
 * via {@see InterpolationService} (Mustache).
 *
 * @see MailTemplateDefaults
 */
class MailTemplateService
{
    /** @var array{from_email: string|null, from_name: string|null, reply_to: string|null, is_html: bool|null}|null lazy-cached global sender config (request-scoped) */
    private ?array $globalConfig = null;

    /** @var array<string, array{subject: string|null, body: string|null}> */
    private array $typeCache = [];

    private ?int $pageId = null;
    private bool $pageLoaded = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly PageRepository $pageRepository,
        private readonly CmsPreferenceService $cmsPreferenceService,
        private readonly InterpolationService $interpolationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Build a complete email config array ready to pass to the job scheduler.
     *
     * @param string                $type            One of {@see MailTemplateDefaults::TYPES}.
     * @param array<string, mixed>  $vars            `{{placeholder}}` values for subject + body interpolation.
     * @param array<string, mixed>  $overrides       Caller overrides applied last (e.g. `recipient_emails`).
     * @param string|null           $preferredLocale Recipient locale to prefer before falling back to CMS defaults.
     *
     * @return array<string, mixed> Keys: from_email, from_name, reply_to, is_html, subject, body
     *                              plus any keys passed in $overrides.
     */
    public function buildEmailConfig(string $type, array $vars = [], array $overrides = [], ?string $preferredLocale = null): array
    {
        $global = $this->resolveGlobalConfig();
        $locale = $this->resolveLocale($preferredLocale);
        $isHtml = $global['is_html'] ?? MailTemplateDefaults::IS_HTML;

        $template = $this->resolveTemplate($type, $locale);

        $subject = $template['subject'] ?? MailTemplateDefaults::getSubject($type, $locale);
        $body    = $template['body']    ?? MailTemplateDefaults::getBody($type, $locale);

        $fromEmail = $global['from_email'] ?? MailTemplateDefaults::FROM_EMAIL;
        $fromName  = $global['from_name']  ?? MailTemplateDefaults::FROM_NAME;
        $replyTo   = $global['reply_to']   ?? MailTemplateDefaults::REPLY_TO;

        $config = [
            'from_email' => $fromEmail,
            'from_name'  => $fromName,
            'reply_to'   => $replyTo,
            'is_html'    => $isHtml,
            'subject'    => $this->interpolationService->interpolate((string) $subject, $vars),
            'body'       => $this->interpolationService->interpolate((string) $body, $vars),
        ];

        return array_merge($config, $overrides);
    }

    /**
     * Resolve global sender configuration shared by all email types.
     *
     * @return array{from_email: string|null, from_name: string|null, reply_to: string|null, is_html: bool|null}
     */
    public function resolveGlobalConfig(): array
    {
        if ($this->globalConfig !== null) {
            return $this->globalConfig;
        }

        $pageId = $this->getPageId();
        if ($pageId === null) {
            return $this->globalConfig = [
                'from_email' => null,
                'from_name'  => null,
                'reply_to'   => null,
                'is_html'    => null,
            ];
        }

        // Global sender fields are stored as "props" (non-translatable, language id = 1).
        $propsLanguageId = $this->getLanguageIdByLocale(MailTemplateDefaults::PROPS_LOCALE) ?? 1;

        $isHtmlRaw = $this->fetchField($pageId, 'mail_is_html', $propsLanguageId);

        return $this->globalConfig = [
            'from_email' => $this->fetchField($pageId, 'mail_from_email', $propsLanguageId),
            'from_name'  => $this->fetchField($pageId, 'mail_from_name',  $propsLanguageId),
            'reply_to'   => $this->fetchField($pageId, 'mail_reply_to',   $propsLanguageId),
            'is_html'    => $isHtmlRaw !== null
                ? in_array(strtolower($isHtmlRaw), ['1', 'true', 'yes', 'on'], true)
                : null,
        ];
    }

    /**
     * Resolve subject + body for a mail type in the given locale.
     *
     * @return array{subject: string|null, body: string|null}
     */
    private function resolveTemplate(string $type, string $locale): array
    {
        $cacheKey = $type . '|' . $locale;
        if (isset($this->typeCache[$cacheKey])) {
            return $this->typeCache[$cacheKey];
        }

        $pageId = $this->getPageId();
        if ($pageId === null) {
            return $this->typeCache[$cacheKey] = ['subject' => null, 'body' => null];
        }

        $languageId = $this->getLanguageIdByLocale($locale)
            ?? $this->getLanguageIdByLocale('en-GB');

        if ($languageId === null) {
            return $this->typeCache[$cacheKey] = ['subject' => null, 'body' => null];
        }

        return $this->typeCache[$cacheKey] = [
            'subject' => $this->fetchField($pageId, $type . '_subject', $languageId),
            'body'    => $this->fetchField($pageId, $type . '_body',    $languageId),
        ];
    }

    /**
     * Resolve the locale to use for the next email.
     *
     * Prefers the caller-provided locale when supported, then falls back to
     * the CMS default language, and finally to the first shipped mail locale.
     */
    private function resolveLocale(?string $preferredLocale = null): string
    {
        if ($this->isSupportedLocale($preferredLocale)) {
            return $preferredLocale;
        }

        $defaultLanguageId = $this->cmsPreferenceService->getDefaultLanguageId();
        if ($defaultLanguageId !== null) {
            $locale = $this->getLocaleByLanguageId($defaultLanguageId);
            if ($this->isSupportedLocale($locale)) {
                return $locale;
            }
        }

        return MailTemplateDefaults::LOCALES[0];
    }

    /**
     * @phpstan-assert-if-true string $locale
     */
    private function isSupportedLocale(?string $locale): bool
    {
        return $locale !== null && in_array($locale, MailTemplateDefaults::LOCALES, true);
    }

    private function getPageId(): ?int
    {
        if (!$this->pageLoaded) {
            try {
                $page = $this->pageRepository->findOneBy(['keyword' => MailTemplateDefaults::PAGE_KEYWORD]);
                $this->pageId = $page?->getId();
            } catch (\Throwable $e) {
                $this->logger->warning('MailTemplateService: failed to resolve sh-mail-config page id', [
                    'error' => $e->getMessage(),
                ]);
                $this->pageId = null;
            }
            $this->pageLoaded = true;
        }

        return $this->pageId;
    }

    private function getLanguageIdByLocale(string $locale): ?int
    {
        $id = $this->connection->fetchOne(
            'SELECT id FROM languages WHERE locale = :locale LIMIT 1',
            ['locale' => $locale]
        );

        return is_numeric($id) ? (int) $id : null;
    }

    private function getLocaleByLanguageId(int $languageId): ?string
    {
        $locale = $this->connection->fetchOne(
            'SELECT locale FROM languages WHERE id = :id LIMIT 1',
            ['id' => $languageId]
        );

        return is_scalar($locale) ? (string) $locale : null;
    }

    private function fetchField(int $pageId, string $fieldName, int $languageId): ?string
    {
        $content = $this->connection->fetchOne(
            'SELECT pft.content
               FROM pages_fields_translation pft
               INNER JOIN fields f ON pft.id_fields = f.id
              WHERE pft.id_pages     = :pageId
                AND f.name           = :fieldName
                AND pft.id_languages = :languageId
              LIMIT 1',
            ['pageId' => $pageId, 'fieldName' => $fieldName, 'languageId' => $languageId]
        );

        if ($content === false || $content === null || $content === '') {
            return null;
        }

        $contentString = is_scalar($content) ? (string) $content : '';

        // Subjects should never carry HTML markup.
        if (str_ends_with($fieldName, '_subject')) {
            return html_entity_decode(strip_tags($contentString), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $contentString;
    }
}
