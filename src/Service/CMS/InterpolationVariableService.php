<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS;

use App\Entity\Page;
use App\Service\Auth\MailTemplateDefaults;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Context-aware entry point for the CMS interpolation `{{ }}` variable picker
 * (issue #56 v2).
 *
 * The whole CMS shares ONE picker contract: a `token => human label` map where
 * the token is the immutable interpolation key inserted as `{{token}}` and the
 * label is what the admin sees. Every editor surface (section content, condition
 * builder, data-config SQL filter, custom CSS, page/config fields, action
 * subject/body, global values) resolves its variables through this one service
 * so the catalog stays consistent and only ever offers variables that actually
 * interpolate at runtime.
 *
 * The catalog itself lives in {@see DataVariableResolver}; this service only
 * maps a request context to the right catalog method (and special-cases the
 * mail-config page, whose fields are rendered by the mail subsystem).
 */
class InterpolationVariableService
{
    public const CONTEXT_SECTION = 'section';
    public const CONTEXT_PAGE = 'page';
    public const CONTEXT_ACTION = 'action';
    public const CONTEXT_GLOBAL = 'global';

    /**
     * @var list<string>
     */
    public const CONTEXTS = [
        self::CONTEXT_SECTION,
        self::CONTEXT_PAGE,
        self::CONTEXT_ACTION,
        self::CONTEXT_GLOBAL,
    ];

    public function __construct(
        private readonly DataVariableResolver $dataVariableResolver,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Resolve the variable picker for a context as a `token => label` map.
     *
     * @param string   $context One of {@see self::CONTEXTS}
     * @param int|null $id      Context target: section id (`section`), page id
     *                          (`page`), or the action's source data-table id
     *                          (`action`). Ignored for `global`.
     * @return array<string, string> token => human label
     */
    public function getVariablesForContext(string $context, ?int $id): array
    {
        return match ($context) {
            self::CONTEXT_SECTION => $id !== null ? $this->dataVariableResolver->getSectionContextVariables($id) : [],
            self::CONTEXT_ACTION => $this->dataVariableResolver->getActionContextVariables($id),
            self::CONTEXT_GLOBAL => $this->dataVariableResolver->getGlobalContextVariables(),
            self::CONTEXT_PAGE => $this->resolvePageContext($id),
            default => [],
        };
    }

    /**
     * Page-context catalog. The mail-config page is special: its fields hold
     * email templates rendered by the mail subsystem, so it gets the mail
     * catalog (`system.*` + `system.special.*` links) instead of the generic
     * page catalog.
     *
     * @return array<string, string> token => human label
     */
    private function resolvePageContext(?int $pageId): array
    {
        if ($pageId !== null && $pageId > 0) {
            $page = $this->entityManager->getRepository(Page::class)->find($pageId);
            if ($page instanceof Page && $page->getPageType()?->getName() === MailTemplateDefaults::PAGE_TYPE) {
                return $this->dataVariableResolver->getMailContextVariables();
            }
        }

        return $this->dataVariableResolver->getPageContextVariables();
    }
}
