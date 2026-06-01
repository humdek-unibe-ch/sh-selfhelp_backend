<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Controller\Api\V1\Admin;

use App\Service\Cache\Core\CacheService;
use App\Service\Cache\Core\CacheStatsService;
use App\Service\Core\ApiResponseFormatter;
use App\Controller\Trait\RequestValidatorTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin Cache Management Controller
 * 
 * Provides endpoints for cache monitoring and management:
 * - Get cache statistics and usage
 * - Clear specific cache categories
 * - Clear all caches
 * - Monitor cache effectiveness
 */
class AdminCacheController extends AbstractController
{
    use RequestValidatorTrait;

    public function __construct(
        private CacheService $cacheService,
        private CacheStatsService $statsService,
        private ApiResponseFormatter $responseFormatter,
    ) {
    }

    /**
     * Get cache statistics and monitoring data
     */
    public function getCacheStats(Request $request): Response
    {
        try {
            $stats = $this->statsService->getStats();

            // The getStats() method now returns the full schema format
            // Add additional monitoring data
            $monitoringData = $stats;
            $monitoringData['top_performing_categories'] = $this->statsService->getTopPerformingCategories(5);


            return $this->responseFormatter->formatSuccess(
                $monitoringData,
                'responses/admin/cache/cache_stats',
                Response::HTTP_OK
            );

        } catch (\Exception $e) {

            return $this->responseFormatter->formatError(
                'Failed to retrieve cache statistics',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Clear all caches
     */
    public function clearAllCaches(Request $request): Response
    {
        try {

            foreach (CacheService::ALL_CATEGORIES as $category) {
                $this->cacheService->withCategory($category)->invalidateCategory();
            }
            $this->statsService->resetStats();

            $user = $this->getUser();
            $userId = $user && method_exists($user, 'getId') ? $user->getId() : null;

            return $this->responseFormatter->formatSuccess(
                ['cleared' => true, 'timestamp' => date('c')],
                null,
                Response::HTTP_OK
            );

        } catch (\Exception $e) {

            return $this->responseFormatter->formatError(
                'Failed to clear all caches',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Clear specific cache category
     */
    public function clearCacheCategory(Request $request): Response
    {
        try {
            // Get request data
            $requestData = json_decode($request->getContent(), true);
            if (!is_array($requestData) || !isset($requestData['category'])) {
                return $this->responseFormatter->formatError(
                    'Validation failed: category is required',
                    Response::HTTP_BAD_REQUEST
                );
            }

            $category = $requestData['category'];

            // Validate category exists
            if (!is_string($category) || !in_array($category, CacheService::ALL_CATEGORIES)) {
                return $this->responseFormatter->formatError(
                    'Invalid cache category',
                    Response::HTTP_BAD_REQUEST
                );
            }

            $this->cacheService->withCategory($category)->invalidateCategory();

            $user = $this->getUser();
            $userId = $user && method_exists($user, 'getId') ? $user->getId() : null;

            return $this->responseFormatter->formatSuccess(
                [
                    'category' => $category,
                    'cleared' => true,
                    'timestamp' => date('c')
                ],
                null,
                Response::HTTP_OK
            );

        } catch (\Exception $e) {

            return $this->responseFormatter->formatError(
                'Failed to clear cache category',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Clear cache for specific user
     */
    public function clearUserCache(Request $request): Response
    {
        try {
            // Get request data
            $requestData = json_decode($request->getContent(), true);
            if (!is_array($requestData) || !isset($requestData['user_id']) || !is_int($requestData['user_id']) || $requestData['user_id'] < 1) {
                return $this->responseFormatter->formatError(
                    'Validation failed: user_id must be a positive integer',
                    Response::HTTP_BAD_REQUEST
                );
            }

            $userId = $requestData['user_id'];

            // foreach (ReworkedCacheService::ALL_CATEGORIES as $category) {
            //     $this->cacheService->withCategory($category)->withUser($userId)->invalidateUserGlobally();
            // }
            $this->cacheService
            ->withCategory(CacheService::CATEGORY_USERS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->invalidateEntityScope(CacheService::ENTITY_SCOPE_USER, $userId);

            return $this->responseFormatter->formatSuccess(
                [
                    'user_id' => $userId,
                    'cleared' => true,
                    'timestamp' => date('c')
                ],
                null,
                Response::HTTP_OK
            );

        } catch (\Exception $e) {

            return $this->responseFormatter->formatError(
                'Failed to clear user cache',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Clear API routes cache specifically
     * This is useful when new routes are added to the database
     */
    public function clearApiRoutesCache(Request $request): Response
    {
        try {
            $this->cacheService->withCategory(CacheService::CATEGORY_API_ROUTES)->invalidateCategory();

            return $this->responseFormatter->formatSuccess(
                [
                    'cleared' => true,
                    'cache_type' => 'api_routes',
                    'message' => 'API routes cache cleared successfully',
                    'timestamp' => date('c')
                ],
                null,
                Response::HTTP_OK
            );

        } catch (\Exception $e) {

            return $this->responseFormatter->formatError(
                'Failed to clear API routes cache',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get statistics for a specific cache category
     */
    public function getCategoryStats(Request $request, string $category): Response
    {
        try {
            $categoryStats = $this->statsService->getCategoryStatistics($category);

            return $this->responseFormatter->formatSuccess(
                $categoryStats,
                null,
                Response::HTTP_OK
            );

        } catch (\InvalidArgumentException $e) {
            return $this->responseFormatter->formatError(
                $e->getMessage(),
                Response::HTTP_BAD_REQUEST
            );
        } catch (\Exception $e) {

            return $this->responseFormatter->formatError(
                'Failed to retrieve category cache statistics',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Reset cache statistics
     */
    public function resetCacheStats(Request $request): Response
    {
        try {
            $this->statsService->resetStats();

            $user = $this->getUser();
            $userId = $user && method_exists($user, 'getId') ? $user->getId() : null;

            return $this->responseFormatter->formatSuccess(
                ['reset' => true, 'timestamp' => date('c')],
                null,
                Response::HTTP_OK
            );

        } catch (\Exception $e) {

            return $this->responseFormatter->formatError(
                'Failed to reset cache statistics',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get cache health status
     */
    public function getCacheHealth(Request $request): Response
    {
        try {
            $health = $this->statsService->getCacheHealth();

            return $this->responseFormatter->formatSuccess(
                $health,
                null,
                Response::HTTP_OK
            );

        } catch (\Exception $e) {

            return $this->responseFormatter->formatError(
                'Failed to retrieve cache health status',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

}
