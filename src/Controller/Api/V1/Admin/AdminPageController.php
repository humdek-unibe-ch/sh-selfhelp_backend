<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Controller\Api\V1\Admin;

use App\Controller\Trait\RequestValidatorTrait;
use App\Exception\ServiceException;
use App\Service\Auth\UserContextService;
use App\Service\CMS\Admin\AdminPageService;
use App\Service\Core\ApiResponseFormatter;
use App\Service\Core\LookupService;
use App\Service\JSON\JsonSchemaValidationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API V1 Admin Controller
 * 
 * Handles admin-related endpoints for API v1
 */
class AdminPageController extends AbstractController
{
    use RequestValidatorTrait;

    /**
     * Constructor
     */
    public function __construct(
        private readonly AdminPageService $adminPageService,
        private readonly ApiResponseFormatter $responseFormatter,
        private readonly JsonSchemaValidationService $jsonSchemaValidationService,
        private readonly UserContextService $userContextService,
        private readonly \App\Service\CMS\Admin\PageExportImportService $pageExportImportService,
        private readonly \App\Service\CMS\Admin\CmsAppWizardService $cmsAppWizardService,
    ) {
    }

    /**
     * Get all pages for admin
     * Filtered by page access permissions
     *
     * @route /admin/pages
     * @route /admin/pages/{language_id}
     * @method GET
     */
    public function getPages(): JsonResponse
    {
        try {
            $userId = $this->userContextService->getCurrentUser()?->getId();

            if ($userId === null) {
                return $this->responseFormatter->formatError(
                    'User not authenticated',
                    Response::HTTP_UNAUTHORIZED
                );
            }

            // Use SQL-based filtering for pages (no dataFetcher needed)
            $pages = $this->adminPageService->getFilteredPages($userId);

            return $this->responseFormatter->formatSuccess(
                $pages,
                'responses/common/_admin_page_definition',
                Response::HTTP_OK // Explicitly pass the status code
            );
        } catch (\Throwable $e) {
            // Attempt to get a valid HTTP status code from the exception, default to 500
            $statusCode = (is_int($e->getCode()) && $e->getCode() >= 100 && $e->getCode() <= 599) ? $e->getCode() : Response::HTTP_INTERNAL_SERVER_ERROR;
            return $this->responseFormatter->formatError(
                $e->getMessage(),
                $statusCode
            );
        }
    }

    /**
     * Get page with page fields
     * 
     * @route /admin/pages/{page_id}
     * @method GET
     */
    public function getPage(int $page_id): JsonResponse
    {
        try {
            $pageWithFields = $this->adminPageService->getPageWithFields($page_id);
            return $this->responseFormatter->formatSuccess($pageWithFields);
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError(
                $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get page sections
     * 
     * @route /admin/pages/{page_id}/sections
     * @method GET
     */
    public function getPageSections(int $page_id): JsonResponse
    {
        try {
            $sections = $this->adminPageService->getPageSections($page_id);
            return $this->responseFormatter->formatSuccess([
                'pageId' => $page_id,
                'sections' => $sections
            ], 'responses/admin/pages/page_sections');
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError(
                $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Create a new page
     * 
     * @route /admin/page
     * @method POST
     */
    public function createPage(Request $request): JsonResponse
    {
        try {
            // Validate request against JSON schema
            // This will throw an exception if validation fails
            $data = $this->validateRequest($request, 'requests/admin/create_page', $this->jsonSchemaValidationService);

            // Normalize the optional CMS-in-CMS access group ids into list<int>.
            $accessGroups = [];
            $accessGroupsRaw = $data['accessGroups'] ?? [];
            if (is_array($accessGroupsRaw)) {
                foreach ($accessGroupsRaw as $groupId) {
                    if (is_int($groupId)) {
                        $accessGroups[] = $groupId;
                    } elseif (is_numeric($groupId)) {
                        $accessGroups[] = (int) $groupId;
                    }
                }
            }

            $navigationAssignments = null;
            if (isset($data['navigationAssignments']) && is_array($data['navigationAssignments'])) {
                /** @var list<array<string, mixed>> $navigationAssignments */
                $navigationAssignments = array_values(array_filter($data['navigationAssignments'], 'is_array'));
            }

            // Create page using pageAccessTypeCode
            $page = $this->adminPageService->createPage(
                $this->asStringField($data, 'keyword'),
                $this->asStringField($data, 'pageAccessTypeCode'),
                $this->asBoolField($data, 'headless'),
                $this->asBoolField($data, 'openAccess'),
                $this->asStringOrNullField($data, 'url'),
                $this->asIntOrNullField($data, 'parent'),
                $this->asStringField($data, 'surface', \App\Service\Core\LookupService::PAGE_SURFACE_PUBLIC),
                $accessGroups,
                $navigationAssignments,
                null,
                $this->asBoolField($data, 'syncUrlWithParent', false),
                $this->asStringOrNullField($data, 'oldRoutePolicy'),
            );

            // Page cache is automatically invalidated by the service

            // Return success response
            return $this->responseFormatter->formatSuccess(
                $page,
                'responses/admin/pages/page',
                Response::HTTP_CREATED
            );
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError(
                $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Delete page
     * 
     * @route /admin/pages/{page_id}
     * @method DELETE
     */
    public function deletePage(int $page_id): JsonResponse
    {
        try {
            $page = $this->adminPageService->deletePage($page_id);

            return $this->responseFormatter->formatSuccess(
                $page,
                'responses/admin/pages/page'
            );
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError(
                $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Update a page and its field translations
     * 
     * @Route("/page/{page_id}", methods={"PUT"})
     * @param Request $request
     * @param int $page_id Page ID
     * @return JsonResponse
     */
    public function updatePage(Request $request, int $page_id): JsonResponse
    {
        try {
            // Validate request against JSON schema
            // This will throw an exception if validation fails
            $data = $this->validateRequest($request, 'requests/admin/update_page', $this->jsonSchemaValidationService);

            $this->adminPageService->updatePage(
                $page_id,
                $this->asArrayField($data, 'pageData'),
                $this->asListOfArrays($data['fields'] ?? null)
            );

            // Page cache is automatically invalidated by the service

            // Return updated page with fields
            $pageWithFields = $this->adminPageService->getPageWithFields($page_id);

            return $this->responseFormatter->formatSuccess($pageWithFields);
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError(
                $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Add one or more sections to a page in a single atomic operation.
     *
     * Accepts either a single-section payload (legacy shape) or a
     * `{sections: [...]}` batch. Both are normalized into the same array
     * and persisted in one transaction by the service. Returns either a
     * single object (legacy shape) or an array (batch shape) to match the
     * input.
     *
     * @route /admin/pages/{page_id}/sections
     * @method POST
     */
    public function addSectionToPage(Request $request, int $page_id): Response
    {
        $data = $this->validateRequest(
            $request,
            'requests/page/add_section_to_page',
            $this->jsonSchemaValidationService
        );

        $isBulkRequest = isset($data['sections']);
        $sections = $isBulkRequest ? $this->asListOfArrays($data['sections']) : [$this->toAssocArray($data)];

        $results = $this->adminPageService->addSectionToPage($page_id, $sections);

        return $this->responseFormatter->formatSuccess(
            $isBulkRequest ? $results : $results[0],
            null,
            Response::HTTP_OK
        );
    }

    public function removeSectionFromPage(int $page_id, int $section_id): Response
    {
        $this->adminPageService->removeSectionFromPage($page_id, $section_id);

        return $this->responseFormatter->formatSuccess(null, null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Export one or more pages as a portable bundle (issue #30, Phase 5).
     *
     * @route /admin/pages/export
     * @method POST
     */
    public function exportPages(Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/export_pages', $this->jsonSchemaValidationService);
            $bundle = $this->pageExportImportService->exportBundle(
                $this->toIntList($data['pageIds'] ?? null),
                is_array($data['options'] ?? null) ? $this->toAssocArray($data['options']) : []
            );

            return $this->responseFormatter->formatSuccess($bundle, null, Response::HTTP_OK);
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Suggest the related page ids that belong in a bundle with the given page
     * (its list/detail counterpart and `/cms/...` admin pair).
     *
     * @route /admin/pages/{page_id}/export/suggest
     * @method GET
     */
    public function suggestExportBundle(int $page_id): JsonResponse
    {
        try {
            $pageIds = $this->pageExportImportService->suggestRelatedPageIds($page_id);

            return $this->responseFormatter->formatSuccess(['page_ids' => $pageIds], null, Response::HTTP_OK);
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Dry-run validation of a page bundle before import (issue #30, Phase 5).
     *
     * @route /admin/pages/import/validate
     * @method POST
     */
    public function validateImportPages(Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/import_pages', $this->jsonSchemaValidationService);
            $report = $this->pageExportImportService->validateImport(
                $this->asArrayField($data, 'bundle'),
                is_array($data['options'] ?? null) ? $this->toAssocArray($data['options']) : []
            );

            return $this->responseFormatter->formatSuccess($report, null, Response::HTTP_OK);
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Import a validated page bundle (issue #30, Phase 5).
     *
     * @route /admin/pages/import
     * @method POST
     */
    public function importPages(Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/import_pages', $this->jsonSchemaValidationService);
            $result = $this->pageExportImportService->importBundle(
                $this->asArrayField($data, 'bundle'),
                is_array($data['options'] ?? null) ? $this->toAssocArray($data['options']) : []
            );

            return $this->responseFormatter->formatSuccess($result, null, Response::HTTP_CREATED);
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * List the shipped importable example page bundles (issue #30, decision E),
     * so the admin import UI can offer ready-made "Example bundles" that load
     * straight into the existing validate/import flow.
     *
     * @route /admin/pages/examples
     * @method GET
     */
    public function getExampleBundles(): JsonResponse
    {
        try {
            $examples = $this->pageExportImportService->listExampleBundles();

            return $this->responseFormatter->formatSuccess(['examples' => $examples], null, Response::HTTP_OK);
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * "Create list + detail pages" CMS-in-CMS app wizard (issue #30, Phase 6).
     *
     * Atomically scaffolds a public and/or admin list+detail page pair bound to
     * a data table: pages (with the right `page_surface` + ACL), their DB-driven
     * `page_routes`, and `entry-list` / `entry-record` holder sections whose
     * `data_config` binds to the table (detail filters on
     * `record_id = {{route.record_id}}`).
     *
     * @route /admin/pages/cms-app
     * @method POST
     */
    public function createCmsApp(Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/create_cms_app', $this->jsonSchemaValidationService);
            $result = $this->cmsAppWizardService->createCmsApp($this->toAssocArray($data));

            return $this->responseFormatter->formatSuccess($result, null, Response::HTTP_CREATED);
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove multiple sections from a page in one request.
     *
     * Accepts an array of section IDs and delegates to the service for a single
     * bulk delete operation, avoiding the overhead of individual DELETE calls.
     * Returns the counter of relationships actually removed.
     *
     * @route /admin/pages/{page_id}/sections
     * @method DELETE
     *
     * @param Request $request  JSON body validated against bulk_remove_sections schema
     * @param int     $page_id  The page to remove sections from
     * @return Response         200 with {deleted_count} on success, 500 on failure
     */
    public function bulkRemoveSectionsFromPage(Request $request, int $page_id): Response
    {
        $data = $this->validateRequest(
            $request,
            'requests/section/bulk_remove_sections',
            $this->jsonSchemaValidationService
        );

        try {
            $result = $this->adminPageService->bulkRemoveSectionsFromPage(
                $page_id,
                $this->toIntList($data['sectionIds'] ?? null)
            );

            return $this->responseFormatter->formatSuccess($result);

        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError(
                'Bulk delete failed: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
