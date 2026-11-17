<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\Effective;

use App\Exception\ServiceException;
use App\Repository\PageRepository;
use App\Service\CMS\FormValidationService;
use App\Service\CMS\Frontend\PageService;
use App\Service\Core\BaseService;
use App\Service\Core\UserContextAwareService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides whether a public request may use a section of a page, the way a
 * core form decides (rule 7.7, F109). Plugin endpoints that serve or accept
 * data for a section on a page (a survey, a map) call this first and read
 * every setting from the returned scope, never from the request.
 *
 * The checks, in order: the page exists; the caller may see it (page ACL
 * `select` for a signed-in user, an open page for a guest; groups see); the
 * effective configuration is chosen (published for a visitor, the draft only
 * for a signed-in preview by someone who may edit the page); the section is
 * on the page and not hidden by its condition or an ancestor's; it has the
 * expected style.
 */
class PublicSectionAccessService extends BaseService
{
    public function __construct(
        private readonly PageRepository $pageRepository,
        private readonly PageService $pageService,
        private readonly EffectivePageResolver $effectivePages,
        private readonly FormValidationService $formValidation,
        private readonly UserContextAwareService $userContext,
    ) {
    }

    /**
     * @throws ServiceException 404 page unknown, 403 page or section not visible to the caller,
     *                                         400 the section has another style, 401/403 a preview without edit rights
     */
    public function forSection(int $pageId, int $sectionId, string $styleName, bool $preview = false): PublicSectionScope
    {
        $page = $this->pageRepository->find($pageId);
        if (!$page) {
            $this->throwNotFound('Page not found');
        }

        if ($this->userContext->getCurrentUser() !== null) {
            $this->pageService->assertPageReadable($page, null, 'select');
        } elseif (!$page->isOpenAccess()) {
            $this->throwForbidden('Page is not open access');
        }

        $effective = $this->effectivePages->forPage($page, $preview);
        $this->formValidation->assertSectionVisibleIn($effective, $sectionId);

        if ($effective->styleOf($sectionId) !== $styleName) {
            throw new ServiceException(sprintf('Section is not a "%s" section', $styleName), Response::HTTP_BAD_REQUEST);
        }

        return new PublicSectionScope($page, $effective, $sectionId, $effective->rawFields($sectionId));
    }

    /**
     * The variables the section sees on its page for the current user (H1):
     * what `{{name}}` in the section's content resolves to. Use
     * {@see \App\Service\Core\InterpolationService::interpolateArray()} with
     * them on the text values of plugin content, never on serialized JSON.
     *
     * @param array<string, string> $routeParams public route params ({{route.*}})
     * @return array<array-key, mixed>
     */
    public function variables(PublicSectionScope $scope, int $languageId, array $routeParams = []): array
    {
        return $this->pageService->variablesForSection($scope->effective, $scope->sectionId, $languageId, $routeParams);
    }
}
