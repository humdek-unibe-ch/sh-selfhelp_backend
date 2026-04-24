<?php

namespace App\Controller\Api\V1\Admin;

use App\Controller\Trait\RequestValidatorTrait;
use App\Repository\StyleRepository;
use App\Service\CMS\Admin\StyleSchemaService;
use App\Service\Core\ApiResponseFormatter;
use App\Service\JSON\JsonSchemaValidationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
        private readonly ApiResponseFormatter $apiResponseFormatter,
        private readonly JsonSchemaValidationService $jsonSchemaValidationService,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
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
     * Static file-serve of the generated AI section-generation prompt template markdown.
     * The file is produced by `bin/console app:prompt-template:build`.
     *
     * GET /cms-api/v1/admin/ai/section-prompt-template
     *
     * Permission: admin.page.export (same as export/import). Locked down via api_routes_permissions.
     */
    public function getSectionPromptTemplate(): Response
    {
        $path = $this->projectDir . '/../sh-selfhelp_frontend/docs/AI Prompts/ai_section_generation_prompt.md';

        // Fallback to the repo-local path (if the frontend sibling dir isn't available).
        if (!is_file($path)) {
            $path = $this->projectDir . '/docs/ai/ai_section_generation_prompt.md';
        }

        if (!is_file($path)) {
            return $this->apiResponseFormatter->formatError(
                'AI section prompt template has not been generated yet. Run `bin/console app:prompt-template:build`.',
                Response::HTTP_NOT_FOUND
            );
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'text/markdown; charset=utf-8');
        $response->headers->set('Cache-Control', 'private, max-age=60');
        return $response;
    }
}
