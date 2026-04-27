<?php

namespace App\Controller\Api\V1\Admin;

use App\Controller\Trait\RequestValidatorTrait;
use App\Repository\StyleRepository;
use App\Service\CMS\Admin\PromptTemplateService;
use App\Service\CMS\Admin\StyleSchemaService;
use App\Service\Core\ApiResponseFormatter;
use App\Service\JSON\JsonSchemaValidationService;
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
        private readonly JsonSchemaValidationService $jsonSchemaValidationService,
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

        return $this->apiResponseFormatter->formatSuccess(
            $styles,
            'responses/style/styleGroups'
        );
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
     * Permission: admin.page.export (same as export/import). Locked down via api_routes_permissions.
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
