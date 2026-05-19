<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\Auth;

use App\Repository\PageRepository;
use App\Service\CMS\CmsPreferenceService;
use App\Util\TranslationContentHelper;
use Doctrine\DBAL\Connection;

/**
 * Resolves email configuration from the sh-mail-templates CMS page.
 *
 * Global sender fields (from_email, from_name, reply_to, is_html) apply to
 * every email type. Per-type subject and body override the hardcoded fallbacks
 * in callers when set.
 *
 * All values come from `pages_fields_translation` keyed by field name and
 * the CMS default language. Null means "not configured — use the fallback".
 */
class MailTemplateService
{
    private const PAGE_KEYWORD = 'sh-mail-config';

    /** @var array<string, string|null>|null resolved once per request */
    private ?array $globalConfig = null;

    /** @var array<string, array{subject: string|null, body: string|null}> */
    private array $typeCache = [];

    private ?int $pageId = null;
    private bool $pageLoaded = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly PageRepository $pageRepository,
        private readonly CmsPreferenceService $cmsPreferenceService
    ) {
    }

    /**
     * Resolve subject and body for a mail type.
     *
     * @param string $type  'mail_2fa' | 'mail_confirm' | 'mail_welcome' | 'mail_recovery'
     * @return array{subject: string|null, body: string|null}
     */
    public function resolve(string $type, bool $isHtml = true): array
    {
        if (isset($this->typeCache[$type])) {
            return $this->typeCache[$type];
        }

        $pageId = $this->getPageId();
        if ($pageId === null) {
            return $this->typeCache[$type] = ['subject' => null, 'body' => null];
        }

        $languageId = $this->cmsPreferenceService->getDefaultLanguageId() ?? 1;

        return $this->typeCache[$type] = [
            'subject' => $this->fetchField($pageId, $type . '_subject', $languageId),
            'body'    => $this->fetchField($pageId, $type . '_body', $languageId, $isHtml),
        ];
    }

    /**
     * Build a complete email config array ready to pass to the job scheduler.
     *
     * Merge order (last wins): $fallback → global CMS config → per-type template.
     * Keys not present in the CMS are taken from $fallback unchanged.
     *
     * @param string               $type     e.g. 'mail_2fa'
     * @param array<string, mixed> $fallback Hardcoded defaults from the caller
     * @return array<string, mixed>
     */
    public function buildEmailConfig(string $type, array $fallback): array
    {
        $global   = $this->resolveGlobalConfig();
        $template = $this->resolve($type, $global['is_html'] ?? true);

        $cmsGlobal = array_filter([
            'from_email' => $global['from_email'],
            'from_name'  => $global['from_name'],
            'reply_to'   => $global['reply_to'],
            'is_html'    => $global['is_html'],
        ], fn($v) => $v !== null);

        $cmsType = array_filter([
            'subject' => $template['subject'],
            'body'    => $template['body'],
        ], fn($v) => $v !== null);
    
        return array_merge($fallback, $cmsGlobal, $cmsType);
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

        // Always 1 because is a props
        $languageId = 1;

        $isHtmlRaw = $this->fetchField($pageId, 'mail_is_html', $languageId);
        return $this->globalConfig = [
            'from_email' => $this->fetchField($pageId, 'mail_from_email', $languageId),
            'from_name'  => $this->fetchField($pageId, 'mail_from_name', $languageId),
            'reply_to'   => $this->fetchField($pageId, 'mail_reply_to', $languageId),
            'is_html' => $isHtmlRaw !== null ? $isHtmlRaw === '1' : null,
        ];
    }

    private function getPageId(): ?int
    {
        if (!$this->pageLoaded) {
            $page = $this->pageRepository->findOneBy(['keyword' => self::PAGE_KEYWORD]);
            $this->pageId = $page?->getId();
            $this->pageLoaded = true;
        }

        return $this->pageId;
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

        // Strip HTML for subjects always
        if (str_ends_with($fieldName, '_subject')) {
            return html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $content;
    }
}
