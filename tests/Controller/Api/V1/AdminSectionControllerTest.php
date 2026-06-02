<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Tests\Controller\Api\V1;

use Symfony\Component\HttpFoundation\Response;
use App\Tests\Controller\Api\V1\Traits\ManagesTestPagesTrait; // Add the trait

/**
 * The admin section API is mounted under the numeric page id
 * ( /admin/pages/{page_id}/sections... ), so these tests resolve the page
 * keyword to its id before issuing requests. Section mutations against the
 * seeded 'home' page are reverted by the DAMA per-test transaction rollback;
 * the lifecycle test uses its own qa_-prefixed throwaway page.
 */
class AdminSectionControllerTest extends BaseControllerTest
{
    use ManagesTestPagesTrait; // Use the trait

    private const TEST_PAGE_KEYWORD = "home"; // Using an existing page for testing
    private const LIFECYCLE_TEST_PAGE_KEYWORD = 'qa_sections_lifecycle_test_page';
    // Baseline style/field ids (seeded by the Mantine style migrations).
    private const DEFAULT_STYLE_ID_1 = 112; // alert style (has content + property fields)
    private const DEFAULT_STYLE_ID_2 = 134; // card style
    private const TITLE_FIELD_ID = 287; // content field (display=1, belongs to alert)
    private const CSS_FIELD_ID = 265; // mantine_radius property field (display=0, belongs to alert)
    private const IS_EXPANDED_FIELD_ID = 284; // use_mantine_style property field (display=0, belongs to alert)
    private const DEFAULT_LANGUAGE_ID = 2; // de-CH (valid seeded language)

    private $testSectionId = null; // Will store the ID of a test section for child section tests

    private ?int $homePageIdCache = null;

    /**
     * Resolve and memoize the numeric id of the seeded 'home' page.
     */
    private function homePageId(): int
    {
        if ($this->homePageIdCache === null) {
            $resolved = $this->resolvePageIdByKeyword(self::TEST_PAGE_KEYWORD);
            $this->assertNotNull($resolved, "Seeded '" . self::TEST_PAGE_KEYWORD . "' page not found. Run: composer test:reset-db");
            $this->homePageIdCache = $resolved;
        }

        return $this->homePageIdCache;
    }

    /**
     * @group admin
     * @group section-lifecycle
     */
    public function testSectionLifecycleOnPage(): void
    {
        $token = $this->getAdminAccessToken();
        $pageKeyword = self::LIFECYCLE_TEST_PAGE_KEYWORD;
        $section1Id = null;
        $section2Id = null;
        $section3Id = null;

        // Ensure clean state and cleanup
        $this->deleteTestPageIfExistsWithKeyword($pageKeyword); // Use trait method for robust cleanup

        try {
            // Use trait method to create the page. Default access type is PUBLIC_PAGE.
            $this->createTestPageWithKeyword($pageKeyword);

            // Section routes are addressed by numeric page id.
            $pageId = $this->resolvePageIdByKeyword($pageKeyword);
            $this->assertNotNull($pageId, 'Lifecycle test page could not be resolved to an id');

            // 1. Add Section 1 (S1)
            $this->client->request(
                'POST',
                sprintf('/cms-api/v1/admin/pages/%d/sections/create', $pageId),
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
                json_encode(['styleId' => self::DEFAULT_STYLE_ID_1, 'position' => 0])
            );
            $response = $this->client->getResponse();
            $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode(), 'Failed to create section 1');
            $responseContentS1 = $response->getContent();
            $decodedResponseS1 = json_decode($responseContentS1);
            $validationErrors = $this->jsonSchemaValidationService->validate($decodedResponseS1, 'responses/section/section_created_minimal');
            $this->assertEmpty($validationErrors, 'S1 creation response does not match schema: ' . implode(', ', $validationErrors));
            $section1Data = $decodedResponseS1->data;
            $section1Id = $section1Data->id;
            $this->assertNotNull($section1Id, 'Section 1 ID is null');

            // 2. Add Section 2 (S2)
            $this->client->request(
                'POST',
                sprintf('/cms-api/v1/admin/pages/%d/sections/create', $pageId),
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
                json_encode(['styleId' => self::DEFAULT_STYLE_ID_2, 'position' => 1])
            );
            $response = $this->client->getResponse();
            $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode(), 'Failed to create section 2');
            $responseContentS2 = $response->getContent();
            $decodedResponseS2 = json_decode($responseContentS2);
            $validationErrors = $this->jsonSchemaValidationService->validate($decodedResponseS2, 'responses/section/section_created_minimal');
            $this->assertEmpty($validationErrors, 'S2 creation response does not match schema: ' . implode(', ', $validationErrors));
            $section2Data = $decodedResponseS2->data;
            $section2Id = $section2Data->id;
            $this->assertNotNull($section2Id, 'Section 2 ID is null');

            // 3. Verify S1, S2 are on the page
            $this->client->request('GET', sprintf('/cms-api/v1/admin/pages/%d/sections', $pageId), [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
            $response = $this->client->getResponse();
            $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
            $responseContentGet1 = $response->getContent();
            $decodedResponseGet1 = json_decode($responseContentGet1);
            $validationErrors = $this->jsonSchemaValidationService->validate($decodedResponseGet1, 'responses/admin/pages/page_sections');
            $this->assertEmpty($validationErrors, 'Get sections (S1,S2) response does not match schema: ' . implode(', ', $validationErrors));
            $pageSectionsData = $decodedResponseGet1->data;
            $this->assertCount(2, $pageSectionsData->sections, 'Incorrect number of sections after adding S1, S2');
            $this->assertSame($section1Id, $pageSectionsData->sections[0]->id, 'S1 not at position 0');
            $this->assertSame(0, $pageSectionsData->sections[0]->position, 'S1 position incorrect');
            $this->assertSame($section2Id, $pageSectionsData->sections[1]->id, 'S2 not at position 1');
            $this->assertSame(10, $pageSectionsData->sections[1]->position, 'S2 position incorrect');

            // 4. Add S3 as child of S1 (first section)
            $this->client->request(
                'POST',
                sprintf('/cms-api/v1/admin/pages/%d/sections/%d/sections/create', $pageId, $section1Id),
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
                json_encode(['styleId' => self::DEFAULT_STYLE_ID_1, 'position' => 0])
            );
            $responseS3 = $this->client->getResponse();
            
            // Debug: Log the actual response if it's not what we expect
            if ($responseS3->getStatusCode() !== Response::HTTP_CREATED) {
                $responseContent = $responseS3->getContent();
                $this->fail(sprintf(
                    'Failed to create child section S3. Expected status %d but got %d. Response: %s', 
                    Response::HTTP_CREATED, 
                    $responseS3->getStatusCode(), 
                    $responseContent
                ));
            }
            
            $this->assertSame(Response::HTTP_CREATED, $responseS3->getStatusCode(), 'Failed to create child section S3');
            $responseContentS3 = $responseS3->getContent();
            $decodedResponseS3 = json_decode($responseContentS3);
            $validationErrors = $this->jsonSchemaValidationService->validate($decodedResponseS3, 'responses/section/section_created_minimal');
            $this->assertEmpty($validationErrors, 'S3 creation response does not match schema: ' . implode(', ', $validationErrors));
            $section3Data = $decodedResponseS3->data;
            $section3Id = $section3Data->id;
            $this->assertNotNull($section3Id, 'Section 3 ID is null');

            // 5. Verify Nested Structure (S1[S3], S2)
            $this->client->request('GET', sprintf('/cms-api/v1/admin/pages/%d/sections', $pageId), [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
            $responseGet3 = $this->client->getResponse();
            $this->assertSame(Response::HTTP_OK, $responseGet3->getStatusCode(), 'Failed to get sections after adding S3');
            $responseContentGet3 = $responseGet3->getContent();
            $decodedResponseGet3 = json_decode($responseContentGet3);
            $validationErrors = $this->jsonSchemaValidationService->validate($decodedResponseGet3, 'responses/admin/pages/page_sections');
            $this->assertEmpty($validationErrors, 'Get sections (S1[S3],S2) response does not match schema: ' . implode(', ', $validationErrors));
            $pageSectionsData = $decodedResponseGet3->data;
            $this->assertCount(2, $pageSectionsData->sections, 'Incorrect number of top-level sections after adding S3');
            $this->assertSame($section1Id, $pageSectionsData->sections[0]->id, 'S1 is not the first section after S3 add');
            $this->assertCount(1, $pageSectionsData->sections[0]->children, 'S1 does not have one child (S3) after S3 add');
            $this->assertSame($section3Id, $pageSectionsData->sections[0]->children[0]->id, 'S3 is not the child of S1 after S3 add');
            $this->assertSame($section2Id, $pageSectionsData->sections[1]->id, 'S2 is not the second section after S3 add');
            $this->assertEmpty($pageSectionsData->sections[1]->children, 'S2 should not have children after S3 add');

            // 6. Remove Child Section S3 from S1
            $this->client->request(
                'DELETE',
                sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $pageId, $section3Id),
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
            );
            $deleteResponse = $this->client->getResponse();
            
            // Debug: Log the actual response if it's not what we expect
            if ($deleteResponse->getStatusCode() !== Response::HTTP_NO_CONTENT) {
                $responseContent = $deleteResponse->getContent();
                $this->fail(sprintf(
                    'Failed to remove child section S3. Expected status %d but got %d. Response: %s', 
                    Response::HTTP_NO_CONTENT, 
                    $deleteResponse->getStatusCode(), 
                    $responseContent
                ));
            }
            
            $this->assertSame(Response::HTTP_NO_CONTENT, $deleteResponse->getStatusCode(), 'Failed to remove child section S3');

            // 7. Verify S3 Removal (S1, S2)
            $this->client->request('GET', sprintf('/cms-api/v1/admin/pages/%d/sections', $pageId), [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
            $responseGet4 = $this->client->getResponse();
            $this->assertSame(Response::HTTP_OK, $responseGet4->getStatusCode(), 'Failed to get sections after S3 removal');
            $responseContentGet4 = $responseGet4->getContent();
            $decodedResponseGet4 = json_decode($responseContentGet4);
            $validationErrors = $this->jsonSchemaValidationService->validate($decodedResponseGet4, 'responses/admin/pages/page_sections');
            $this->assertEmpty($validationErrors, 'Get sections (after S3 removal) response does not match schema: ' . implode(', ', $validationErrors));
            $pageSectionsData = $decodedResponseGet4->data;
            $this->assertCount(2, $pageSectionsData->sections, 'Incorrect number of top-level sections after removing S3');
            $this->assertSame($section1Id, $pageSectionsData->sections[0]->id, 'S1 not first after S3 removal');
            $this->assertEmpty($pageSectionsData->sections[0]->children, 'S1 should have no children after S3 removal');
            $this->assertSame($section2Id, $pageSectionsData->sections[1]->id, 'S2 not second after S3 removal');

            // 8. Remove S2 from page
            $this->client->request(
                'DELETE',
                sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $pageId, $section2Id),
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
            );
            $this->assertSame(Response::HTTP_NO_CONTENT, $this->client->getResponse()->getStatusCode(), 'Failed to remove section S2');

            // 9. Remove S1 from page
            $this->client->request(
                'DELETE',
                sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $pageId, $section1Id),
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
            );
            $this->assertSame(Response::HTTP_NO_CONTENT, $this->client->getResponse()->getStatusCode(), 'Failed to remove section S1');

            // 10. Verify All Sections Gone
            $this->client->request('GET', sprintf('/cms-api/v1/admin/pages/%d/sections', $pageId), [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
            $responseGet5 = $this->client->getResponse();
            $this->assertSame(Response::HTTP_OK, $responseGet5->getStatusCode(), 'Failed to get sections after all deletes');
            $responseContentGet5 = $responseGet5->getContent();
            $decodedResponseGet5 = json_decode($responseContentGet5);
            $validationErrors = $this->jsonSchemaValidationService->validate($decodedResponseGet5, 'responses/admin/pages/page_sections');
            $this->assertEmpty($validationErrors, 'Get sections (all gone) response does not match schema: ' . implode(', ', $validationErrors));
            $pageSectionsData = $decodedResponseGet5->data;
            $this->assertEmpty($pageSectionsData->sections, 'Sections array not empty after deleting all sections');

        } finally {
            // Cleanup: Delete the test page using the trait method for robustness
            $this->deleteTestPageIfExistsWithKeyword($pageKeyword);
        }
    }

    // ... existing methods follow ...

    /**
     * Move an existing child section from one parent container to another (the
     * "drag a section into a different container" workflow) and reject a move
     * of a non-existent section. Complements the lifecycle test, which only
     * covers create/nest/remove, not relocation between parents.
     *
     * @group admin
     * @group section-lifecycle
     */
    public function testMoveSectionBetweenParentsAndRejectInvalidMove(): void
    {
        $token = $this->getAdminAccessToken();
        $pageKeyword = 'qa_sections_move_test_page';

        $this->deleteTestPageIfExistsWithKeyword($pageKeyword);

        try {
            $this->createTestPageWithKeyword($pageKeyword);
            $pageId = $this->resolvePageIdByKeyword($pageKeyword);
            $this->assertNotNull($pageId, 'Move test page could not be resolved to an id');

            // Two top-level container sections P1, P2 (alert style allows children).
            $p1 = $this->createTopLevelSection($pageId, $token, 0);
            $p2 = $this->createTopLevelSection($pageId, $token, 1);

            // Child C under P1.
            $this->client->request(
                'POST',
                sprintf('/cms-api/v1/admin/pages/%d/sections/%d/sections/create', $pageId, $p1),
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
                json_encode(['styleId' => self::DEFAULT_STYLE_ID_1, 'position' => 0])
            );
            $this->assertSame(
                Response::HTTP_CREATED,
                $this->client->getResponse()->getStatusCode(),
                'Failed to create child C: ' . $this->client->getResponse()->getContent()
            );
            $childId = (int) json_decode($this->client->getResponse()->getContent())->data->id;

            // Move C from P1 to P2 (oldParentSectionId detaches the old link).
            // The "add existing section to a parent" route is PUT (the DB-backed
            // route table is authoritative; the controller @method doc is stale).
            $this->client->request(
                'PUT',
                sprintf('/cms-api/v1/admin/pages/%d/sections/%d/sections', $pageId, $p2),
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
                json_encode(['childSectionId' => $childId, 'oldParentSectionId' => $p1, 'position' => 0])
            );
            $this->assertSame(
                Response::HTTP_OK,
                $this->client->getResponse()->getStatusCode(),
                'Move between parents failed: ' . $this->client->getResponse()->getContent()
            );

            // Verify C is now under P2 and no longer under P1.
            $this->client->request(
                'GET',
                sprintf('/cms-api/v1/admin/pages/%d/sections', $pageId),
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
            );
            $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
            $sections = json_decode($this->client->getResponse()->getContent(), true)['data']['sections'];
            $byId = [];
            foreach ($sections as $section) {
                $byId[$section['id']] = $section;
            }
            $this->assertArrayHasKey($p1, $byId, 'P1 missing from page sections');
            $this->assertArrayHasKey($p2, $byId, 'P2 missing from page sections');
            $p1ChildIds = array_column($byId[$p1]['children'] ?? [], 'id');
            $p2ChildIds = array_column($byId[$p2]['children'] ?? [], 'id');
            $this->assertNotContains($childId, $p1ChildIds, 'Child still under old parent P1 after move');
            $this->assertContains($childId, $p2ChildIds, 'Child not under new parent P2 after move');

            // Invalid move: relocating a non-existent section must be rejected.
            $this->client->request(
                'PUT',
                sprintf('/cms-api/v1/admin/pages/%d/sections/%d/sections', $pageId, $p2),
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
                json_encode(['childSectionId' => 999999])
            );
            $this->assertSame(
                Response::HTTP_NOT_FOUND,
                $this->client->getResponse()->getStatusCode(),
                'Move of a non-existent section should be rejected with 404'
            );
        } finally {
            $this->deleteTestPageIfExistsWithKeyword($pageKeyword);
        }
    }

    /**
     * Create a top-level section (alert style, which allows children) on a page
     * and return its id.
     */
    private function createTopLevelSection(int $pageId, string $token, int $position): int
    {
        $this->client->request(
            'POST',
            sprintf('/cms-api/v1/admin/pages/%d/sections/create', $pageId),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['styleId' => self::DEFAULT_STYLE_ID_1, 'position' => $position])
        );
        $this->assertSame(
            Response::HTTP_CREATED,
            $this->client->getResponse()->getStatusCode(),
            'Failed to create top-level section: ' . $this->client->getResponse()->getContent()
        );

        return (int) json_decode($this->client->getResponse()->getContent())->data->id;
    }

    /**
     * Set up before each test
     */
    protected function setUp(): void
    {
        parent::setUp();
        // Create a test section if needed for child section tests (for testCreateChildSection)
        $testName = $this->name ?? null;
        if ($testName === 'testCreateChildSection' && !$this->testSectionId) {
             // Only create if testCreateChildSection is running and it's not already created
            $this->createTestSectionForChildTest(); 
        }
    }

    /**
     * Test creating a page section
     */
    public function testCreatePageSection(): void
    {
        // Get JWT token for authentication
        $token = $this->getAdminAccessToken();
        
        // Create request data
        $requestData = [
            'styleId' => self::DEFAULT_STYLE_ID_1, 
            'position' => 0 // Add at the beginning
        ];
        
        // Send request to create a page section
        $this->client->request(
            'POST',
            sprintf('/cms-api/v1/admin/pages/%d/sections/create', $this->homePageId()),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            json_encode($requestData)
        );
        
        // Check response
        $this->assertEquals(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
        
        // Parse response data
        $responseContent = $this->client->getResponse()->getContent();
        $data = json_decode($responseContent);
        
        // Validate response structure
        $this->assertNotNull($data);
        $this->assertTrue(property_exists($data, 'data'), 'Response does not have data property');
        $this->assertTrue(property_exists($data->data, 'id'), 'Response does not have section ID');
        $this->assertTrue(property_exists($data->data, 'position'), 'Response does not have position property');
        
        // Validate response against JSON schema
        $validationErrors = $this->jsonSchemaValidationService->validate(
            $data,
            'responses/page/section_added'
        );
        $this->assertEmpty($validationErrors, 'Response does not match schema: ' . implode(', ', $validationErrors));
    }
    
    /**
     * Test creating a child section
     */
    public function testCreateChildSection(): void
    {
        // Get JWT token for authentication
        $token = $this->getAdminAccessToken();
        $homePageId = $this->homePageId();
        
        // First, create a parent section to add a child to
        $parentRequestData = [
            'styleId' => self::DEFAULT_STYLE_ID_1, 
            'position' => 0
        ];
        
        // Create parent section
        $this->client->request(
            'POST',
            sprintf('/cms-api/v1/admin/pages/%d/sections/create', $homePageId),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            json_encode($parentRequestData)
        );
        
        $parentResponse = $this->client->getResponse();
        $this->assertEquals(Response::HTTP_CREATED, $parentResponse->getStatusCode(), 'Failed to create parent section: ' . $parentResponse->getContent());
        
        $parentData = json_decode($parentResponse->getContent());
        $parentSectionId = $parentData->data->id;
        
        // Now create request data for child section
        $requestData = [
            'styleId' => self::DEFAULT_STYLE_ID_2, 
            'position' => 0 // Add at the beginning
        ];
        
        // Send request to create a child section
        $this->client->request(
            'POST',
            sprintf('/cms-api/v1/admin/pages/%d/sections/%d/sections/create', $homePageId, $parentSectionId),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            json_encode($requestData)
        );

        $response = $this->client->getResponse();
        
        // Debug: Log the actual response if it's not what we expect
        if ($response->getStatusCode() !== Response::HTTP_CREATED) {
            $responseContent = $response->getContent();
            $this->fail(sprintf(
                'Expected status %d but got %d. Response: %s', 
                Response::HTTP_CREATED, 
                $response->getStatusCode(), 
                $responseContent
            ));
        }

        // Check response
        $this->assertEquals(Response::HTTP_CREATED, $response->getStatusCode());
        
        // Parse response data
        $responseContent = $response->getContent();
        $data = json_decode($responseContent);
        
        // Validate response structure
        $this->assertNotNull($data);
        $this->assertTrue(property_exists($data, 'data'), 'Response does not have data property');
        $this->assertTrue(property_exists($data->data, 'id'), 'Response does not have section ID');
        $this->assertTrue(property_exists($data->data, 'position'), 'Response does not have position property');
        
        // Validate response against JSON schema
        $validationErrors = $this->jsonSchemaValidationService->validate(
            $data,
            'responses/section/child_section_added'
        );
        $this->assertEmpty($validationErrors, 'Response does not match schema: ' . implode(', ', $validationErrors));
    }
    
    /**
     * Test getting a section with fields and translations
     * @group admin
     * @group section-get
     */
    public function testGetSectionWithFields(): void
    {
        // Get JWT token for authentication
        $token = $this->getAdminAccessToken();
        
        // First, create a test section to retrieve
        $this->createTestSectionForChildTest();
        $this->assertNotNull($this->testSectionId, 'Failed to create test section');
        
        // Send request to get the section
        $this->client->request(
            'GET',
            sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $this->homePageId(), $this->testSectionId),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );
        
        // Check response (should be 200 OK)
        $response = $this->client->getResponse();
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode(), 'Failed to get section: ' . $response->getContent());
        
        // Parse response data
        $responseContent = $response->getContent();
        $data = json_decode($responseContent);
        
        // Validate response structure
        $this->assertNotNull($data);
        $this->assertTrue(property_exists($data, 'data'), 'Response does not have data property');
        $this->assertTrue(property_exists($data->data, 'section'), 'Response data does not have section property');
        $this->assertTrue(property_exists($data->data, 'fields'), 'Response data does not have fields property');
        $this->assertTrue(property_exists($data->data, 'languages'), 'Response data does not have languages property');
        
        // Validate section data
        $section = $data->data->section;
        $this->assertEquals($this->testSectionId, $section->id, 'Section ID mismatch');
        $this->assertTrue(property_exists($section, 'name'), 'Section does not have name property');
        $this->assertNotNull($section->style, 'Section style is null');
        $this->assertEquals(self::DEFAULT_STYLE_ID_1, $section->style->id, 'Section style ID mismatch');
        
        // Validate fields array (may be empty depending on the style)
        $this->assertIsArray($data->data->fields, 'Fields is not an array');
        
        // Validate languages array (may be empty if no translations exist)
        $this->assertIsArray($data->data->languages, 'Languages is not an array');
        
        // Validate against JSON schema
        $validationErrors = $this->jsonSchemaValidationService->validate($data, 'responses/admin/sections/section');
        $this->assertEmpty($validationErrors, 'Response does not match schema: ' . implode(', ', $validationErrors));
    }
    
    /**
     * Test validation errors when creating a page section
     */
    public function testCreatePageSectionValidationErrors(): void
    {
        // Get JWT token for authentication
        $token = $this->getAdminAccessToken();
        
        // Create invalid request data (missing required fields)
        $requestData = [
            // Missing styleId and position
        ];
        
        // Send request to create a page section
        $this->client->request(
            'POST',
            sprintf('/cms-api/v1/admin/pages/%d/sections/create', $this->homePageId()),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            json_encode($requestData)
        );

        $response = $this->client->getResponse();
        $responseContent = $response->getContent();
        
        // Debug: Log the actual response to understand the structure
        if ($response->getStatusCode() !== Response::HTTP_BAD_REQUEST) {
            $this->fail(sprintf(
                'Expected validation error (status %d) but got %d. Response: %s', 
                Response::HTTP_BAD_REQUEST, 
                $response->getStatusCode(), 
                $responseContent
            ));
        }

        // Check response (should be 400 Bad Request)
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        // Parse response data
        $data = json_decode($responseContent);
        
        // Debug: Log the response structure
        if (!$data) {
            $this->fail('Response is not valid JSON: ' . $responseContent);
        }
        
        // The validation errors are in the 'validation.errors' field, not 'errors'
        if (!property_exists($data, 'validation') || !property_exists($data->validation, 'errors')) {
            $this->fail('Response does not have validation.errors property. Actual response structure: ' . json_encode($data, JSON_PRETTY_PRINT));
        }

        // Validate error response structure
        $this->assertNotNull($data);
        $this->assertTrue(property_exists($data, 'validation'), 'Response does not have validation property');
        $this->assertTrue(property_exists($data->validation, 'errors'), 'Response does not have validation.errors property');
        $this->assertNotEmpty($data->validation->errors, 'No validation errors returned');
    }
    
    /**
     * Helper method to create a test section for child section tests (testCreateChildSection).
     * This is kept separate from the lifecycle page to avoid interference.
     */
    private function createTestSectionForChildTest(): void
    {
        if ($this->testSectionId) return; // Already created

        // Get JWT token for authentication
        $token = $this->getAdminAccessToken();
        
        // Create request data
        $requestData = [
            'styleId' => self::DEFAULT_STYLE_ID_1, 
            'position' => 999 // Add at the end to minimize disruption on 'home' page
        ];
        
        // Send request to create a page section on the 'home' page
        $this->client->request(
            'POST',
            sprintf('/cms-api/v1/admin/pages/%d/sections/create', $this->homePageId()), // Uses 'home'
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            json_encode($requestData)
        );
        
        $response = $this->client->getResponse();
        if ($response->getStatusCode() === Response::HTTP_CREATED) {
            $data = json_decode($response->getContent());
            if ($data && property_exists($data, 'data') && property_exists($data->data, 'id')) {
                 $this->testSectionId = $data->data->id;
            } else {
                $this->fail('Failed to create test section for child test: ID missing in response.');
            }
        } else {
            $this->fail('Failed to create test section for child test: ' . $response->getContent());
        }
    }
    
    /**
     * Clean up after tests
     */
    protected function tearDown(): void
    {
        // Clean up the section created by createTestSectionForChildTest if it exists
        if ($this->testSectionId) {
            $token = $this->getAdminAccessToken();
            // Use the correct delete route for sections
            $this->client->request(
                'DELETE',
                sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $this->homePageId(), $this->testSectionId),
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
            );
            // We don't strictly check the response here, as it's just a cleanup attempt.
            $this->testSectionId = null;
        }
        parent::tearDown();
    }

    /**
     * Test updating a section with content and property fields
     * @group admin
     * @group section-update
     */
    public function testUpdateSection(): void
    {
        $token = $this->getAdminAccessToken();
        $pageId = $this->homePageId();
        
        // Create a test section first
        $this->client->request(
            'POST',
            sprintf('/cms-api/v1/admin/pages/%d/sections/create', $pageId),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['styleId' => self::DEFAULT_STYLE_ID_1, 'position' => 0])
        );
        
        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode(), 'Failed to create test section');
        $createData = json_decode($response->getContent(), true);
        $sectionId = $createData['data']['id'];
        
        try {
            // Update the section with field values only (no name change)
            $updateData = [
                'contentFields' => [
                    [
                        'fieldId' => self::TITLE_FIELD_ID,
                        'languageId' => 2,
                        'value' => 'Updated content text'
                    ]
                ],
                'propertyFields' => [
                    [
                        'fieldId' => self::CSS_FIELD_ID,
                        'value' => 'Updated property value'
                    ],
                    [
                        'fieldId' => self::IS_EXPANDED_FIELD_ID,
                        'value' => true
                    ]
                ]
            ];
            
            $this->client->request(
                'PUT',
                sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $pageId, $sectionId),
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
                json_encode($updateData)
            );
            
            $response = $this->client->getResponse();
            $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), 'Failed to update section: ' . $response->getContent());
            
            $responseData = json_decode($response->getContent(), true);
            $this->assertArrayHasKey('data', $responseData);
            $this->assertArrayHasKey('section', $responseData['data']);
            // Name should not have changed since we didn't provide sectionName
            $this->assertNotEquals('updated-test-section', $responseData['data']['section']['name']);
            
            // Verify the section was actually updated by getting it again
            $this->client->request(
                'GET',
                sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $pageId, $sectionId),
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
            );
            
            $getResponse = $this->client->getResponse();
            $this->assertSame(Response::HTTP_OK, $getResponse->getStatusCode(), 'Failed to get updated section');
            
            $getData = json_decode($getResponse->getContent(), true);
            // Verify the name hasn't changed
            $this->assertNotEquals('updated-test-section', $getData['data']['section']['name']);
            
        } finally {
            // Clean up - delete the test section
            $this->client->request(
                'DELETE',
                sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $pageId, $sectionId),
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
            );
                 }
     }

    /**
     * Test updating a section name specifically
     * @group admin
     * @group section-update-name
     */
    public function testUpdateSectionName(): void
    {
        $token = $this->getAdminAccessToken();
        $pageId = $this->homePageId();
        
        // Create a test section first
        $this->client->request(
            'POST',
            sprintf('/cms-api/v1/admin/pages/%d/sections/create', $pageId),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['styleId' => self::DEFAULT_STYLE_ID_1, 'position' => 0])
        );
        
        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode(), 'Failed to create test section');
        $createData = json_decode($response->getContent(), true);
        $sectionId = $createData['data']['id'];
        
        // Get the original section to check the initial name
        $this->client->request(
            'GET',
            sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $pageId, $sectionId),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );
        
        $getResponse = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $getResponse->getStatusCode(), 'Failed to get initial section');
        $initialData = json_decode($getResponse->getContent(), true);
        $originalName = $initialData['data']['section']['name'];
        
        try {
            // Update the section name specifically
            $updateData = [
                'sectionName' => 'qa-updated-test-section-name',
                'contentFields' => [],
                'propertyFields' => []
            ];
            
            $this->client->request(
                'PUT',
                sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $pageId, $sectionId),
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
                json_encode($updateData)
            );
            
            $response = $this->client->getResponse();
            $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), 'Failed to update section name: ' . $response->getContent());
            
            $responseData = json_decode($response->getContent(), true);
            $this->assertArrayHasKey('data', $responseData);
            $this->assertArrayHasKey('section', $responseData['data']);
            $this->assertSame('qa-updated-test-section-name', $responseData['data']['section']['name']);
            
            // Verify the section name was actually updated by getting it again
            $this->client->request(
                'GET',
                sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $pageId, $sectionId),
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
            );
            
            $getResponse = $this->client->getResponse();
            $this->assertSame(Response::HTTP_OK, $getResponse->getStatusCode(), 'Failed to get updated section');
            
            $getData = json_decode($getResponse->getContent(), true);
            $this->assertSame('qa-updated-test-section-name', $getData['data']['section']['name']);
            $this->assertNotEquals($originalName, $getData['data']['section']['name'], 'Section name should have changed from original');
            
        } finally {
            // Clean up - delete the test section
            $this->client->request(
                'DELETE',
                sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $pageId, $sectionId),
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
            );
                 }
     }

    /**
     * Test updating a section with invalid field IDs that don't belong to the style
     * @group admin
     * @group section-update-validation
     */
    public function testUpdateSectionWithInvalidFields(): void
    {
        $token = $this->getAdminAccessToken();
        $pageId = $this->homePageId();
        
        // Create a test section first
        $this->client->request(
            'POST',
            sprintf('/cms-api/v1/admin/pages/%d/sections/create', $pageId),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['styleId' => self::DEFAULT_STYLE_ID_1, 'position' => 0])
        );
        
        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode(), 'Failed to create test section');
        
        $responseData = json_decode($response->getContent(), true);
        $sectionId = $responseData['data']['id'];
        
        try {
            // Try to update the section with invalid field IDs (fields that don't belong to the style)
            $updateData = [
                'contentFields' => [
                    [
                        'fieldId' => 999, // Non-existent field ID
                        'languageId' => 2,
                        'value' => 'This should fail'
                    ]
                ],
                'propertyFields' => [
                    [
                        'fieldId' => 998, // Another non-existent field ID
                        'value' => 'This should also fail'
                    ]
                ]
            ];
            
            $this->client->request(
                'PUT',
                sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $pageId, $sectionId),
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
                json_encode($updateData)
            );
            
            $response = $this->client->getResponse();
            $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode(), 'Should have failed with invalid field IDs');
            
            $responseData = json_decode($response->getContent(), true);
            $this->assertIsArray($responseData, 'Response should be valid JSON');
            $this->assertArrayHasKey('error', $responseData, 'Response should have error key');
            $this->assertStringContainsString('do not belong to style', $responseData['error'], 'Error message should mention invalid fields');
        } finally {
            // Clean up: delete the test section
            $this->client->request(
                'DELETE',
                sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $pageId, $sectionId),
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
            );
        }
    }

    /**
     * Test exporting all sections from a page
     * @group admin
     * @group section-export
     */
    public function testExportPageSections(): void
    {
        $token = $this->getAdminAccessToken();
        $pageId = $this->homePageId();
        
        // Create a test section first
        $this->client->request(
            'POST',
            sprintf('/cms-api/v1/admin/pages/%d/sections/create', $pageId),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['styleId' => self::DEFAULT_STYLE_ID_1, 'position' => 1])
        );
        
        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode(), 'Failed to create test section');
        $createData = json_decode($response->getContent(), true);
        $sectionId = $createData['data']['id'];
        
        try {
            // Export sections from the page
            $this->client->request(
                'GET',
                sprintf('/cms-api/v1/admin/pages/%d/sections/export', $pageId),
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
            );
            
            $response = $this->client->getResponse();
            $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), 'Failed to export page sections: ' . $response->getContent());
            
            $responseData = json_decode($response->getContent(), true);
            $this->assertArrayHasKey('data', $responseData);
            $this->assertArrayHasKey('sectionsData', $responseData['data']);
            
            // The export envelope wraps the section list under data.sectionsData.
            $sectionsData = $responseData['data']['sectionsData'];
            $this->assertIsArray($sectionsData);
            $this->assertNotEmpty($sectionsData, 'Export should contain at least one section');
            
            // Verify the minimized export shape (docs/section-export-import.md):
            // section_name + style_name are always present; fields/children are
            // omitted when empty.
            foreach ($sectionsData as $sectionData) {
                $this->assertArrayHasKey('section_name', $sectionData);
                $this->assertArrayHasKey('style_name', $sectionData);
                if (array_key_exists('fields', $sectionData)) {
                    $this->assertIsArray($sectionData['fields']);
                }
                if (array_key_exists('children', $sectionData)) {
                    $this->assertIsArray($sectionData['children']);
                }
            }
            
        } finally {
            // Clean up - delete the test section
            $this->client->request(
                'DELETE',
                sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $pageId, $sectionId),
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
            );
        }
    }

    /**
     * Test exporting a specific section with its children
     * @group admin
     * @group section-export
     */
    public function testExportSection(): void
    {
        $token = $this->getAdminAccessToken();
        $pageId = $this->homePageId();
        
        // Create a parent section first
        $this->client->request(
            'POST',
            sprintf('/cms-api/v1/admin/pages/%d/sections/create', $pageId),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['styleId' => self::DEFAULT_STYLE_ID_1, 'position' => 1])
        );
        
        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode(), 'Failed to create parent section');
        $createData = json_decode($response->getContent(), true);
        $parentSectionId = $createData['data']['id'];
        
        try {
            // Export the specific section
            $this->client->request(
                'GET',
                sprintf('/cms-api/v1/admin/pages/%d/sections/%d/export', $pageId, $parentSectionId),
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
            );
            
            $response = $this->client->getResponse();
            $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), 'Failed to export section: ' . $response->getContent());
            
            $responseData = json_decode($response->getContent(), true);
            $this->assertArrayHasKey('data', $responseData);
            $this->assertArrayHasKey('sectionsData', $responseData['data']);
            
            $sectionsData = $responseData['data']['sectionsData'];
            $this->assertIsArray($sectionsData);
            $this->assertNotEmpty($sectionsData, 'Export should contain the section');
            
            // Verify the minimized export shape (docs/section-export-import.md).
            $sectionData = $sectionsData[0];
            $this->assertArrayHasKey('section_name', $sectionData);
            $this->assertArrayHasKey('style_name', $sectionData);
            if (array_key_exists('fields', $sectionData)) {
                $this->assertIsArray($sectionData['fields']);
            }
            if (array_key_exists('children', $sectionData)) {
                $this->assertIsArray($sectionData['children']);
            }
            
        } finally {
            // Clean up - delete the test section
            $this->client->request(
                'DELETE',
                sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $pageId, $parentSectionId),
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
            );
        }
    }

    /**
     * Test importing sections to a page
     * @group admin
     * @group section-import
     */
    public function testImportSectionsToPage(): void
    {
        $token = $this->getAdminAccessToken();
        $pageId = $this->homePageId();
        
        // Prepare test sections data for import (simplified format - no gender)
        $sectionsData = [
            [
                'section_name' => 'qa-imported-test-section',
                'style_name' => 'alert', // Seeded style with a translatable 'content' field
                'fields' => [
                    'content' => [
                        'en-GB' => [
                            'content' => 'Test Title',
                            'meta' => null
                        ]
                    ]
                ],
                'children' => []
            ]
        ];
        
        // Import sections to the page
        $this->client->request(
            'POST',
            sprintf('/cms-api/v1/admin/pages/%d/sections/import', $pageId),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['sections' => $sectionsData])
        );
        
        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), 'Failed to import sections to page: ' . $response->getContent());
        
        $responseData = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('data', $responseData);
        $this->assertArrayHasKey('importedSections', $responseData['data']);
        $this->assertIsArray($responseData['data']['importedSections']);
        $this->assertNotEmpty($responseData['data']['importedSections']);
        
        $importedSection = $responseData['data']['importedSections'][0];
        $this->assertArrayHasKey('id', $importedSection);
        $this->assertArrayHasKey('section_name', $importedSection);
        // The importer appends a "-{timestamp}" suffix for uniqueness (see SectionExportImportService::importSections).
        $this->assertStringStartsWith('qa-imported-test-section', $importedSection['section_name']);
        
        // Clean up - delete the imported section
        $sectionId = $importedSection['id'];
        $this->client->request(
            'DELETE',
            sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $pageId, $sectionId),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );
    }
}
