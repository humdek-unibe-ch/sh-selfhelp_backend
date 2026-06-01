<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Controller\Api\V1\Admin;

use App\Controller\Trait\RequestValidatorTrait;
use App\Plugin\Event\StyleRegistryEvent;
use App\Repository\StyleRepository;
use App\Service\CMS\Admin\PromptTemplateService;
use App\Service\CMS\Admin\StyleSchemaService;
use App\Service\Core\ApiResponseFormatter;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for managing styles in the admin API
 */
class AdminStyleController extends AbstractController
{
    use RequestValidatorTrait;

    public function __construct(
        private readonly StyleRepository $styleRepository,
        private readonly StyleSchemaService $styleSchemaService,
        private readonly PromptTemplateService $promptTemplateService,
        private readonly ApiResponseFormatter $apiResponseFormatter,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * Get all styles grouped by style group with relationship information
     *
     * Returns styles with:
     * - Basic style information (id, name, description, type)
     * - canHaveChildren flag
     * - Relationship constraints (allowedChildren, allowedParents)
     */
    public function getStyles(): JsonResponse
    {
        $styles = $this->styleRepository->findAllStylesGroupedByGroup();
        $styles = $this->mergePluginStyles($styles);

        return $this->apiResponseFormatter->formatSuccess(
            $styles,
            'responses/style/styleGroups'
        );
    }

    /**
     * Append plugin-contributed styles to the grouped catalog.
     *
     * Plugins declare styles by subscribing to `StyleRegistryEvent`. The
     * event's `category` field maps to a `style_groups.name` (case-insensitive);
     * unknown categories fall into a synthetic "Plugins" group appended
     * at the end so the admin builder still surfaces the styles.
     *
     * Synthetic entries are tagged with `plugin_id` so the frontend can
     * render a "plugin" badge and link to the plugin detail page. Real
     * DB rows are left untouched.
     *
     * @param array<int, array<string, mixed>> $groupedStyles
     * @return array<int, array<string, mixed>>
     */
    private function mergePluginStyles(array $groupedStyles): array
    {
        $event = new StyleRegistryEvent();
        $this->eventDispatcher->dispatch($event);
        $contributions = $event->getStyles();
        if ($contributions === []) {
            return $groupedStyles;
        }

        $groupIndexByName = [];
        $existingStyleNames = [];
        foreach ($groupedStyles as $i => $group) {
            $name = strtolower($this->asStringField($group, 'name'));
            if ($name !== '') {
                $groupIndexByName[$name] = $i;
            }
            foreach ($this->asListOfArrays($group['styles'] ?? null) as $style) {
                $styleName = strtolower($this->asStringField($style, 'name'));
                if ($styleName !== '') {
                    $existingStyleNames[$styleName] = true;
                }
            }
        }

        $pluginGroupKey = null;
        foreach ($contributions as $contribution) {
            $styleName = strtolower($contribution['name']);
            if ($styleName !== '' && isset($existingStyleNames[$styleName])) {
                continue;
            }

            $entry = [
                'id' => null,
                'name' => $contribution['name'],
                'description' => $contribution['description'],
                'relationships' => [
                    'allowedChildren' => $contribution['canHaveChildren'] ? [] : [],
                    'allowedParents' => [],
                ],
                'plugin_id' => $contribution['pluginId'],
            ];

            $category = strtolower($contribution['category']);
            if ($category !== '' && isset($groupIndexByName[$category])) {
                $targetIdx = $groupIndexByName[$category];
                $targetStyles = $this->asListOfArrays($groupedStyles[$targetIdx]['styles'] ?? null);
                $targetStyles[] = $entry;
                $groupedStyles[$targetIdx]['styles'] = $targetStyles;
                if ($styleName !== '') {
                    $existingStyleNames[$styleName] = true;
                }
                continue;
            }

            if ($pluginGroupKey === null) {
                $pluginGroupKey = count($groupedStyles);
                $groupedStyles[$pluginGroupKey] = [
                    'id' => null,
                    'name' => 'plugins',
                    'description' => 'Plugin-contributed styles.',
                    'position' => PHP_INT_MAX,
                    'styles' => [],
                ];
            }
            $pluginStyles = $this->asListOfArrays($groupedStyles[$pluginGroupKey]['styles'] ?? null);
            $pluginStyles[] = $entry;
            $groupedStyles[$pluginGroupKey]['styles'] = $pluginStyles;
            if ($styleName !== '') {
                $existingStyleNames[$styleName] = true;
            }
        }

        return array_values($groupedStyles);
    }

    /**
     * Return the full style/field schema used by import validation and the
     * frontend codegen script.
     *
     * GET /cms-api/v1/admin/styles/schema
     */
    public function getStylesSchema(): JsonResponse
    {
        return $this->apiResponseFormatter->formatSuccess(
            $this->styleSchemaService->getSchema(),
            'responses/style/stylesSchema'
        );
    }

    /**
     * Render the AI section-generation prompt template markdown on demand.
     *
     * The base markdown lives in this repo (`docs/ai/prompt_template_base.md`)
     * and is combined with the live style/field catalog from
     * {@see StyleSchemaService}. No disk artefact / build step is required —
     * the prompt is always in sync with the database.
     *
     * GET /cms-api/v1/admin/ai/section-prompt-template
     *
     * Permission: admin.page.export (same as export/import). Locked down via rel_api_routes_permissions.
     */
    public function getSectionPromptTemplate(): Response
    {
        try {
            $markdown = $this->promptTemplateService->render();
        } catch (\RuntimeException $e) {
            return $this->apiResponseFormatter->formatError(
                $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        $response = new Response($markdown);
        $response->headers->set('Content-Type', 'text/markdown; charset=utf-8');
        $response->headers->set('Cache-Control', 'private, max-age=60');
        return $response;
    }
}
