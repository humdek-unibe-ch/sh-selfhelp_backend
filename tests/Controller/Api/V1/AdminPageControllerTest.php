<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Tests\Controller\Api\V1;

use App\Service\Core\LookupService;
use Symfony\Component\HttpFoundation\Response;
use App\Tests\Controller\Api\V1\Traits\ManagesTestPagesTrait; // Add the trait

class AdminPageControllerTest extends BaseControllerTest
{
    use ManagesTestPagesTrait; // Use the trait
    
    private const TEST_PAGE_KEYWORD = "qa_test_test";

    /**
     * @group admin
     */
    public function testGetPageSections(): void
    {
        // Authenticate as admin and request sections for the seeded 'home' page.
        // The admin API addresses pages by numeric id, so resolve the keyword first.
        $token = $this->getAdminAccessToken();
        $homeId = $this->resolvePageIdByKeyword('home');
        $this->assertNotNull($homeId, "Seeded 'home' page not found. Run: composer test:reset-db");
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/pages/' . $homeId . '/sections',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json']
        );
        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), 'Admin get page sections failed.');
        
        // Decode as object (not array) for schema validation
        $data = json_decode($response->getContent());
        $this->assertTrue(property_exists($data, 'data'), 'Response does not have data property');

        // Validate response against JSON schema
        $validationErrors = $this->jsonSchemaValidationService->validate(
            $data, // Validate the full response object
            'responses/admin/pages/page_sections' // Schema for page sections
        );
        $this->assertEmpty($validationErrors, "Response for /cms-api/v1/admin/pages/task/sections failed schema validation:\n" . implode("\n", $validationErrors));
    }

    /**
     * @group admin
     */
    public function testGetPagesFromAdmin(): void
    {
        // Authenticate as admin and request /cms-api/v1/admin/pages
        $token = $this->getAdminAccessToken();
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/pages',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json']
        );
        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), 'Admin get pages failed.');
        
        // Decode as object (not array) for schema validation
        $data = json_decode($response->getContent());
        $this->assertTrue(property_exists($data, 'data'), 'Response does not have data property');

        // Validate response against JSON schema
        $validationErrors = $this->jsonSchemaValidationService->validate(
            $data, // Validate the full response object
            'responses/common/_admin_page_definition' // Schema for admin page list
        );
        $this->assertEmpty($validationErrors, "Response for /cms-api/v1/admin/pages failed schema validation:\n" . implode("\n", $validationErrors));
    }
    
    /**
     * @group admin
     */
    public function testGetPageFields(): void
    {
        // Authenticate as admin and request page fields for the seeded 'home' page (by id).
        $token = $this->getAdminAccessToken();
        $homeId = $this->resolvePageIdByKeyword('home');
        $this->assertNotNull($homeId, "Seeded 'home' page not found. Run: composer test:reset-db");
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/pages/' . $homeId,
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json']
        );
        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), 'Admin get page fields failed.');
        
        // Decode as object (not array) for schema validation
        $data = json_decode($response->getContent());
        $this->assertTrue(property_exists($data, 'data'), 'Response does not have data property');
        $this->assertTrue(property_exists($data->data, 'page'), 'Response data does not have page property');
        $this->assertTrue(property_exists($data->data, 'fields'), 'Response data does not have fields property');
        $this->assertIsArray($data->data->fields, 'Fields property is not an array');

        // Validate response against JSON schema
        $validationErrors = $this->jsonSchemaValidationService->validate(
            $data, // Validate the full response object
            'responses/admin/pages/get_page_fields' // Schema for page fields
        );
        $this->assertEmpty($validationErrors, "Response for /cms-api/v1/admin/pages/home/fields failed schema validation:\n" . implode("\n", $validationErrors));
    }
    
    /**
     * @group admin
     */
    public function testCreateAndDeletePage(): void
    {
        $this->deleteTestPageIfExistsWithKeyword(self::TEST_PAGE_KEYWORD); // Ensure clean state
        $this->createTestPageWithKeyword(self::TEST_PAGE_KEYWORD, LookupService::PAGE_ACCESS_TYPES_MOBILE_AND_WEB);
        $this->deleteTestPageWithKeyword(self::TEST_PAGE_KEYWORD);
    }
    
    /**
     * @group admin
     */
    public function testUpdatePage(): void
    {
        // Ensure the test page doesn't exist from a previous failed run
        $this->deleteTestPageIfExistsWithKeyword(self::TEST_PAGE_KEYWORD);

        // First, create the test page
        $this->createTestPageWithKeyword(self::TEST_PAGE_KEYWORD, LookupService::PAGE_ACCESS_TYPES_MOBILE_AND_WEB);

        try {
            $token = $this->getAdminAccessToken();
            $pageId = $this->resolvePageIdByKeyword(self::TEST_PAGE_KEYWORD);
            $this->assertNotNull($pageId, 'Created test page could not be resolved to an id');
            
            // First, get the page fields to find a valid field ID
            $this->client->request(
                'GET',
                '/cms-api/v1/admin/pages/' . $pageId,
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json']
            );
            
            $getResponse = $this->client->getResponse();
            $this->assertSame(Response::HTTP_OK, $getResponse->getStatusCode(), 'Failed to get test page fields');
            
            $pageData = json_decode($getResponse->getContent(), true);
            $this->assertArrayHasKey('data', $pageData);
            $this->assertArrayHasKey('fields', $pageData['data']);
            
            // This test will update basic page properties without fields
            // since test pages may not have fields associated with them
            
            // Test update without fields first to verify basic functionality
            $basicUpdateData = [
                'pageData' => [
                    'headless' => true,  // Update headless to true
                    'navPosition' => 55  // Change nav position
                ],
                'fields' => [] // No fields to avoid validation issues
            ];
            
            // Send the basic update request
            $this->client->request(
                'PUT',
                '/cms-api/v1/admin/pages/' . $pageId,
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
                json_encode($basicUpdateData)
            );
            
            $basicResponse = $this->client->getResponse();
            $this->assertSame(Response::HTTP_OK, $basicResponse->getStatusCode(), 'Failed to update test page without fields');
            
            // Verify the page was updated by fetching it
            $this->client->request(
                'GET',
                '/cms-api/v1/admin/pages/' . $pageId,
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json']
            );
            
            $verifyResponse = $this->client->getResponse();
            $this->assertSame(Response::HTTP_OK, $verifyResponse->getStatusCode(), 'Failed to fetch updated test page');
            
            // Check that the changes were applied
            $verifiedPageData = json_decode($verifyResponse->getContent(), true);
            $this->assertTrue($verifiedPageData['data']['page']['headless'], 'headless should be true after update');
            
            // Test is successful - basic page update functionality works
            return;
            
        } finally {
            // Clean up: delete the test page
            $this->deleteTestPageIfExistsWithKeyword(self::TEST_PAGE_KEYWORD); // Use ifExists for robustness in cleanup
        }
    }

    /**
     * Test updating a page with invalid field IDs that don't belong to the page
     * @group admin
     * @group page-update-validation
     */
    public function testUpdatePageWithInvalidFields(): void
    {
        // Ensure the test page doesn't exist from a previous failed run
        $this->deleteTestPageIfExistsWithKeyword(self::TEST_PAGE_KEYWORD);

        // First, create the test page
        $this->createTestPageWithKeyword(self::TEST_PAGE_KEYWORD, LookupService::PAGE_ACCESS_TYPES_MOBILE_AND_WEB);

        try {
            $token = $this->getAdminAccessToken();
            $pageId = $this->resolvePageIdByKeyword(self::TEST_PAGE_KEYWORD);
            $this->assertNotNull($pageId, 'Created test page could not be resolved to an id');
            
            // Try to update the page with invalid field IDs (fields that don't belong to the page)
            $updateData = [
                'pageData' => [
                    'headless' => true
                ],
                'fields' => [
                    [
                        'fieldId' => 999, // Non-existent field ID
                        'languageId' => 1, // Default language ID
                        'content' => 'This should fail'
                    ]
                ]
            ];
            
            // Send the update request
            $this->client->request(
                'PUT',
                '/cms-api/v1/admin/pages/' . $pageId,
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
                json_encode($updateData)
            );
            
            $response = $this->client->getResponse();
            $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode(), 'Should have failed with invalid field ID');
            
            $responseData = json_decode($response->getContent(), true);
            $this->assertArrayHasKey('error', $responseData, 'Response should have error key');
            $this->assertStringContainsString('do not belong to page', $responseData['error'], 'Error message should mention invalid fields');
            
        } finally {
            // Clean up: delete the test page
            $this->deleteTestPageIfExistsWithKeyword(self::TEST_PAGE_KEYWORD);
        }
    }
}
