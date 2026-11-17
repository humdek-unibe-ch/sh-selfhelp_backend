<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\CMS\Effective;

use App\Exception\ServiceException;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\Effective\PublicSectionAccessService;
use App\Service\Core\InterpolationService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\QaKernelTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * H4 / H1 of the SurveyJS upgrade: a plugin endpoint decides like a core
 * form (rule 7.7, F109) and reads the variables its section sees.
 */
final class PublicSectionAccessServiceTest extends QaKernelTestCase
{
    private PublicSectionAccessService $service;
    private PageSectionFactory $pages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->service(PublicSectionAccessService::class);
        $this->pages = new PageSectionFactory(
            $this->em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );
    }

    public function testAGuestMayUseASectionOnAnOpenPageAndGetsItsStoredFields(): void
    {
        $page = $this->pages->createPage('qa_psa_open', openAccess: true);
        $section = $this->pages->createSection('qa_psa_text', 'text');
        $this->pages->linkSectionToPage($page, $section);
        $this->pages->setSectionField($section, 'text', 'Stored text');

        $scope = $this->service->forSection((int) $page->getId(), (int) $section->getId(), 'text');

        self::assertSame((int) $section->getId(), $scope->sectionId);
        self::assertSame('Stored text', $scope->field('text'));
        self::assertFalse($scope->effective->isPublished(), 'a never-published page is served its draft');
    }

    public function testAGuestIsRefusedOnAPageThatIsNotOpen(): void
    {
        $page = $this->pages->createPage('qa_psa_closed', openAccess: false);
        $section = $this->pages->createSection('qa_psa_closed_text', 'text');
        $this->pages->linkSectionToPage($page, $section);

        $this->assertRefused(Response::HTTP_FORBIDDEN, (int) $page->getId(), (int) $section->getId(), 'text');
    }

    public function testASectionThatIsNotOnThePageIsRefused(): void
    {
        $page = $this->pages->createPage('qa_psa_page', openAccess: true);
        $elsewhere = $this->pages->createSection('qa_psa_elsewhere', 'text');

        $this->assertRefused(Response::HTTP_FORBIDDEN, (int) $page->getId(), (int) $elsewhere->getId(), 'text');
    }

    public function testASectionOfAnotherStyleIsRefused(): void
    {
        $page = $this->pages->createPage('qa_psa_style', openAccess: true);
        $section = $this->pages->createSection('qa_psa_style_text', 'text');
        $this->pages->linkSectionToPage($page, $section);

        $this->assertRefused(Response::HTTP_BAD_REQUEST, (int) $page->getId(), (int) $section->getId(), 'surveyjs');
    }

    public function testASectionHiddenByAnAncestorsConditionIsRefused(): void
    {
        $page = $this->pages->createPage('qa_psa_hidden', openAccess: true);
        $container = $this->pages->createSection('qa_psa_hidden_box', 'container');
        $section = $this->pages->createSection('qa_psa_hidden_text', 'text');
        $this->pages->linkSectionToPage($page, $container);
        $this->pages->linkSectionToParent($container, $section);
        $container->setCondition('{"==":[1,2]}');
        $this->em->flush();

        $this->assertRefused(Response::HTTP_FORBIDDEN, (int) $page->getId(), (int) $section->getId(), 'text');
    }

    public function testVariablesAreThoseTheSectionSeesOnItsPage(): void
    {
        $page = $this->pages->createPage('qa_psa_vars', openAccess: true);
        $container = $this->pages->createSection('qa_psa_vars_box', 'container');
        $section = $this->pages->createSection('qa_psa_vars_text', 'text');
        $this->pages->linkSectionToPage($page, $container);
        $this->pages->linkSectionToParent($container, $section);

        $scope = $this->service->forSection((int) $page->getId(), (int) $section->getId(), 'text');
        $variables = $this->service->variables($scope, 1, ['qa_param' => 'from-route']);

        $route = $variables['route'] ?? null;
        self::assertIsArray($route);
        self::assertSame('from-route', $route['qa_param'] ?? null, 'route parameters reach a nested section');

        $interpolation = $this->service(InterpolationService::class);
        self::assertSame(
            ['title' => 'Hello from-route', 'choices' => ['A ']],
            $interpolation->interpolateArray(['title' => 'Hello {{route.qa_param}}', 'choices' => ['A {{unknown}}']], $variables),
            'a plugin replaces variables in the text values of its content; an unknown one is empty, as on the page'
        );
    }

    private function assertRefused(int $status, int $pageId, int $sectionId, string $style): void
    {
        try {
            $this->service->forSection($pageId, $sectionId, $style);
            self::fail('The section must be refused.');
        } catch (ServiceException $e) {
            self::assertSame($status, $e->getCode());
        }
    }
}
