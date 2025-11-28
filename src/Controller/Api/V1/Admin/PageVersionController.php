<?php

namespace App\Controller\Api\V1\Admin;

use App\Service\CMS\Admin\PageVersionService;
use App\Service\Core\ApiResponseFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API V1 Page Version Controller
 * 
 * Handles page versioning and publishing endpoints for API v1
 */
class PageVersionController extends AbstractController
{
    public function __construct(
        private readonly PageVersionService $pageVersionService,
        private readonly ApiResponseFormatter $responseFormatter
    ) {
    }

    /**
     * Publish a new version (creates version from current page state)
     * 
     * POST /cms-api/v1/admin/pages/{page_id}/versions/publish
     */
    public function publishPage(Request $request, int $page_id): JsonResponse
    {
        try {
            // Get request body
            $data = json_decode($request->getContent(), true) ?? [];
            
            $versionName = $data['version_name'] ?? null;
            $metadata = $data['metadata'] ?? null;
            $languageId = $data['language_id'] ?? null;

            // Create and publish the version
            $version = $this->pageVersionService->createAndPublishVersion(
                $page_id,
                $versionName,
                $metadata,
                $languageId
            );

            return $this->responseFormatter->formatSuccess(
                [
                    'version_id' => $version->getId(),
                    'version_number' => $version->getVersionNumber(),
                    'version_name' => $version->getVersionName(),
                    'published_at' => $version->getPublishedAt(),
                    'message' => 'Page version published successfully'
                ],
                'responses/admin/page_version_published',
                Response::HTTP_CREATED
            );
        } catch (\Throwable $e) {
            $statusCode = (is_int($e->getCode()) && $e->getCode() >= 100 && $e->getCode() <= 599) 
                ? $e->getCode() 
                : Response::HTTP_INTERNAL_SERVER_ERROR;
            return $this->responseFormatter->formatError($e->getMessage(), $statusCode);
        }
    }

    /**
     * Publish a specific existing version
     * 
     * POST /cms-api/v1/admin/pages/{page_id}/versions/{version_id}/publish
     */
    public function publishSpecificVersion(Request $request, int $page_id, int $version_id): JsonResponse
    {
        try {
            $version = $this->pageVersionService->publishVersion($page_id, $version_id);

            return $this->responseFormatter->formatSuccess(
                [
                    'version_id' => $version->getId(),
                    'version_number' => $version->getVersionNumber(),
                    'version_name' => $version->getVersionName(),
                    'published_at' => $version->getPublishedAt(),
                    'message' => 'Version published successfully'
                ],
                'responses/admin/page_version_published',
                Response::HTTP_OK
            );
        } catch (\Throwable $e) {
            $statusCode = (is_int($e->getCode()) && $e->getCode() >= 100 && $e->getCode() <= 599) 
                ? $e->getCode() 
                : Response::HTTP_INTERNAL_SERVER_ERROR;
            return $this->responseFormatter->formatError($e->getMessage(), $statusCode);
        }
    }

    /**
     * Unpublish current version (revert to draft mode)
     * 
     * POST /cms-api/v1/admin/pages/{page_id}/versions/unpublish
     */
    public function unpublishPage(Request $request, int $page_id): JsonResponse
    {
        try {
            $this->pageVersionService->unpublishPage($page_id);

            return $this->responseFormatter->formatSuccess(
                [
                    'page_id' => $page_id,
                    'message' => 'Page unpublished successfully - reverted to draft mode'
                ],
                'responses/admin/page_unpublished',
                Response::HTTP_OK
            );
        } catch (\Throwable $e) {
            $statusCode = (is_int($e->getCode()) && $e->getCode() >= 100 && $e->getCode() <= 599) 
                ? $e->getCode() 
                : Response::HTTP_INTERNAL_SERVER_ERROR;
            return $this->responseFormatter->formatError($e->getMessage(), $statusCode);
        }
    }

    /**
     * List all versions for a page
     * 
     * GET /cms-api/v1/admin/pages/{page_id}/versions
     * 
     * Includes:
     * - List of versions with pagination
     * - Current published version ID
     * - Fast check for unpublished changes (hash-based comparison)
     */
    public function listVersions(Request $request, int $page_id): JsonResponse
    {
        try {
            $limit = $request->query->getInt('limit', 10);
            $offset = $request->query->getInt('offset', 0);

            $result = $this->pageVersionService->getVersionHistory($page_id, $limit, $offset);

            // Get the currently published version ID
            $publishedVersion = $this->pageVersionService->getPublishedVersion($page_id);
            $currentPublishedVersionId = $publishedVersion ? $publishedVersion->getId() : null;

            // Versions are already formatted in the service, just use them directly
            $formattedVersions = $result['versions'];

            return $this->responseFormatter->formatSuccess(
                [
                    'versions' => $formattedVersions,
                    'pagination' => [
                        'total_count' => $result['total_count'],
                        'limit' => $result['limit'],
                        'offset' => $result['offset']
                    ],
                    'current_published_version_id' => $currentPublishedVersionId,
                    'has_unpublished_changes' => $result['has_unpublished_changes']
                ],
                'responses/admin/page_versions_list',
                Response::HTTP_OK
            );
        } catch (\Throwable $e) {
            $statusCode = (is_int($e->getCode()) && $e->getCode() >= 100 && $e->getCode() <= 599) 
                ? $e->getCode() 
                : Response::HTTP_INTERNAL_SERVER_ERROR;
            return $this->responseFormatter->formatError($e->getMessage(), $statusCode);
        }
    }

    /**
     * Get specific version details
     * 
     * GET /cms-api/v1/admin/pages/{page_id}/versions/{version_id}
     */
    public function getVersion(Request $request, int $page_id, int $version_id): JsonResponse
    {
        try {
            $version = $this->pageVersionService->getVersionById($version_id);

            // Verify the version belongs to this page
            if ($version->getPage()->getId() !== $page_id) {
                return $this->responseFormatter->formatError(
                    "Version {$version_id} does not belong to page {$page_id}",
                    Response::HTTP_BAD_REQUEST
                );
            }

            $includePageJson = $request->query->getBoolean('include_page_json', false);

            $versionData = [
                'id' => $version->getId(),
                'page_id' => $version->getPage()->getId(),
                'version_number' => $version->getVersionNumber(),
                'version_name' => $version->getVersionName(),
                'created_by' => $version->getCreatedBy() ? [
                    'id' => $version->getCreatedBy()->getId(),
                    'name' => $version->getCreatedBy()->getName() ?? $version->getCreatedBy()->getEmail()
                ] : null,
                'created_at' => $version->getCreatedAt(),
                'published_at' => $version->getPublishedAt(),
                'is_published' => $version->isPublished(),
                'metadata' => $version->getMetadata()
            ];

            // Include full page JSON if requested
            if ($includePageJson) {
                $versionData['page_json'] = $version->getPageJson();
            }

            return $this->responseFormatter->formatSuccess(
                $versionData,
                'responses/admin/page_version_details',
                Response::HTTP_OK
            );
        } catch (\Throwable $e) {
            $statusCode = (is_int($e->getCode()) && $e->getCode() >= 100 && $e->getCode() <= 599) 
                ? $e->getCode() 
                : Response::HTTP_INTERNAL_SERVER_ERROR;
            return $this->responseFormatter->formatError($e->getMessage(), $statusCode);
        }
    }

    /**
     * Compare two versions
     * 
     * GET /cms-api/v1/admin/pages/{page_id}/versions/compare/{version1_id}/{version2_id}
     */
    public function compareVersions(
        Request $request, 
        int $page_id, 
        int $version1_id, 
        int $version2_id
    ): JsonResponse {
        try {
            $format = $request->query->get('format', 'unified');
            
            // Validate format
            $validFormats = ['unified', 'side_by_side', 'json_patch', 'summary'];
            if (!in_array($format, $validFormats)) {
                return $this->responseFormatter->formatError(
                    "Invalid format. Must be one of: " . implode(', ', $validFormats),
                    Response::HTTP_BAD_REQUEST
                );
            }

            $comparison = $this->pageVersionService->compareVersions($version1_id, $version2_id, $format);

            return $this->responseFormatter->formatSuccess(
                $comparison,
                'responses/admin/page_versions_comparison',
                Response::HTTP_OK
            );
        } catch (\Throwable $e) {
            $statusCode = (is_int($e->getCode()) && $e->getCode() >= 100 && $e->getCode() <= 599) 
                ? $e->getCode() 
                : Response::HTTP_INTERNAL_SERVER_ERROR;
            return $this->responseFormatter->formatError($e->getMessage(), $statusCode);
        }
    }

    /**
     * Delete a version (hard delete)
     * 
     * DELETE /cms-api/v1/admin/pages/{page_id}/versions/{version_id}
     */
    public function deleteVersion(Request $request, int $page_id, int $version_id): JsonResponse
    {
        try {
            $this->pageVersionService->deleteVersion($version_id);

            return $this->responseFormatter->formatSuccess(
                [
                    'version_id' => $version_id,
                    'message' => 'Version deleted successfully'
                ],
                'responses/admin/page_version_deleted',
                Response::HTTP_OK
            );
        } catch (\Throwable $e) {
            $statusCode = (is_int($e->getCode()) && $e->getCode() >= 100 && $e->getCode() <= 599) 
                ? $e->getCode() 
                : Response::HTTP_INTERNAL_SERVER_ERROR;
            return $this->responseFormatter->formatError($e->getMessage(), $statusCode);
        }
    }

    /**
     * Compare current draft with a specific version
     * 
     * This endpoint allows comparing the current unsaved draft page state with a published version.
     * Useful for showing real-time changes before creating a new version.
     * 
     * GET /cms-api/v1/admin/pages/{page_id}/versions/compare-draft/{version_id}
     * 
     * @param Request $request
     * @param int $page_id The page ID
     * @param int $version_id The version ID to compare the draft against
     * @return JsonResponse
     */
    public function compareDraftWithVersion(Request $request, int $page_id, int $version_id): JsonResponse
    {
        try {
            $format = $request->query->get('format', 'side_by_side');
            
            // Validate format
            $validFormats = ['unified', 'side_by_side', 'json_patch', 'summary'];
            if (!in_array($format, $validFormats)) {
                return $this->responseFormatter->formatError(
                    "Invalid format. Must be one of: " . implode(', ', $validFormats),
                    Response::HTTP_BAD_REQUEST
                );
            }

            $comparison = $this->pageVersionService->compareDraftWithVersion($page_id, $version_id, $format);

            return $this->responseFormatter->formatSuccess(
                $comparison,
                'responses/admin/page_draft_comparison',
                Response::HTTP_OK
            );
        } catch (\Throwable $e) {
            $statusCode = (is_int($e->getCode()) && $e->getCode() >= 100 && $e->getCode() <= 599) 
                ? $e->getCode() 
                : Response::HTTP_INTERNAL_SERVER_ERROR;
            return $this->responseFormatter->formatError($e->getMessage(), $statusCode);
        }
    }

    /**
     * Check if page has unpublished changes (lightweight check)
     * 
     * This is a very fast endpoint (typically < 50ms) that uses hash comparison
     * to detect if the current draft differs from the published version.
     * 
     * Perfect for real-time UI status indicators without the overhead of full comparison.
     * 
     * GET /cms-api/v1/admin/pages/{page_id}/versions/has-changes
     * 
     * @param Request $request
     * @param int $page_id The page ID
     * @return JsonResponse
     */
    public function hasUnpublishedChanges(Request $request, int $page_id): JsonResponse
    {
        try {
            $hasChanges = $this->pageVersionService->hasUnpublishedChanges($page_id);
            $publishedVersion = $this->pageVersionService->getPublishedVersion($page_id);

            return $this->responseFormatter->formatSuccess(
                [
                    'page_id' => $page_id,
                    'has_unpublished_changes' => $hasChanges,
                    'current_published_version_id' => $publishedVersion ? $publishedVersion->getId() : null,
                    'current_published_version_number' => $publishedVersion ? $publishedVersion->getVersionNumber() : null
                ],
                'responses/admin/page_has_changes',
                Response::HTTP_OK
            );
        } catch (\Throwable $e) {
            $statusCode = (is_int($e->getCode()) && $e->getCode() >= 100 && $e->getCode() <= 599) 
                ? $e->getCode() 
                : Response::HTTP_INTERNAL_SERVER_ERROR;
            return $this->responseFormatter->formatError($e->getMessage(), $statusCode);
        }
    }
}

