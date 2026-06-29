<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\CMS;

use App\Entity\Page;
use App\Entity\PageType;
use App\Service\Auth\MailTemplateDefaults;
use App\Service\CMS\DataVariableResolver;
use App\Service\CMS\InterpolationVariableService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * Guards the context routing of the unified `{{ }}` picker endpoint (issue #56
 * v2). The service maps a request context to the right catalog method and
 * special-cases the mail-config page, while keeping the "honest picker" rule:
 * generic page fields are never interpolated at render, so the page context is
 * intentionally empty for ordinary pages.
 */
final class InterpolationVariableServiceTest extends TestCase
{
    /**
     * @param array<string, string> $sectionCatalog
     * @param array<string, string> $actionCatalog
     * @param array<string, string> $mailCatalog
     * @param array<string, string> $globalCatalog
     */
    private function service(
        array $sectionCatalog = [],
        array $actionCatalog = [],
        array $mailCatalog = [],
        array $globalCatalog = [],
        ?Page $foundPage = null,
    ): InterpolationVariableService {
        $resolver = $this->createStub(DataVariableResolver::class);
        $resolver->method('getSectionContextVariables')->willReturn($sectionCatalog);
        $resolver->method('getActionContextVariables')->willReturn($actionCatalog);
        $resolver->method('getMailContextVariables')->willReturn($mailCatalog);
        $resolver->method('getGlobalContextVariables')->willReturn($globalCatalog);
        // getPageContextVariables must NOT leak into the page context; give it a
        // distinctive value so the test fails loudly if it ever does.
        $resolver->method('getPageContextVariables')->willReturn(['leaked.page' => 'leaked']);

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('find')->willReturn($foundPage);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        return new InterpolationVariableService($resolver, $em);
    }

    private function pageWithType(string $typeName): Page
    {
        $pageType = (new PageType())->setName($typeName);

        return (new Page())->setPageType($pageType);
    }

    public function testSectionContextDelegatesToSectionCatalog(): void
    {
        $service = $this->service(sectionCatalog: ['d.section_230' => 'd.Daily mood']);

        $vars = $service->getVariablesForContext(InterpolationVariableService::CONTEXT_SECTION, 12);

        self::assertSame(['d.section_230' => 'd.Daily mood'], $vars);
    }

    public function testSectionContextWithoutIdIsEmpty(): void
    {
        $service = $this->service(sectionCatalog: ['d.section_230' => 'd.Daily mood']);

        self::assertSame([], $service->getVariablesForContext(InterpolationVariableService::CONTEXT_SECTION, null));
    }

    public function testActionContextDelegatesToActionCatalog(): void
    {
        $service = $this->service(actionCatalog: ['recipient.email' => 'recipient.email']);

        $vars = $service->getVariablesForContext(InterpolationVariableService::CONTEXT_ACTION, 5);

        self::assertArrayHasKey('recipient.email', $vars);
    }

    public function testGlobalContextDelegatesToGlobalCatalog(): void
    {
        $service = $this->service(globalCatalog: ['system.user_name' => 'User name']);

        $vars = $service->getVariablesForContext(InterpolationVariableService::CONTEXT_GLOBAL, null);

        self::assertSame(['system.user_name' => 'User name'], $vars);
    }

    /**
     * Honest-picker rule: an ordinary page renders its metadata verbatim, so the
     * page context offers nothing (never the system/globals catalog).
     */
    public function testOrdinaryPageContextIsEmpty(): void
    {
        $service = $this->service(
            mailCatalog: ['system.user_name' => 'User name'],
            foundPage: $this->pageWithType('default'),
        );

        $vars = $service->getVariablesForContext(InterpolationVariableService::CONTEXT_PAGE, 99);

        self::assertSame([], $vars, 'Ordinary pages do not interpolate metadata fields, so the picker must be empty.');
    }

    public function testPageContextWithoutIdIsEmpty(): void
    {
        $service = $this->service(mailCatalog: ['system.user_name' => 'User name']);

        self::assertSame([], $service->getVariablesForContext(InterpolationVariableService::CONTEXT_PAGE, null));
    }

    /**
     * The mail-config page is the one page whose fields interpolate (its email
     * templates are rendered by the mail subsystem), so it gets the mail catalog.
     */
    public function testMailConfigPageContextReturnsMailCatalog(): void
    {
        $service = $this->service(
            mailCatalog: [
                'system.user_name' => 'User name',
                'system.special.activation_link' => 'Activation link',
            ],
            foundPage: $this->pageWithType(MailTemplateDefaults::PAGE_TYPE),
        );

        $vars = $service->getVariablesForContext(InterpolationVariableService::CONTEXT_PAGE, 7);

        self::assertArrayHasKey('system.user_name', $vars);
        self::assertArrayHasKey('system.special.activation_link', $vars);
        self::assertArrayNotHasKey('leaked.page', $vars);
    }
}
