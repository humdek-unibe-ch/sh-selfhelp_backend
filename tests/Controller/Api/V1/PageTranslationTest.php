<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Tests\Controller\Api\V1;

use App\Tests\Controller\Api\V1\BaseControllerTest;

/**
 * Test page translation functionality
 */
class PageTranslationTest extends BaseControllerTest
{
    /**
     * Test getting pages without language_id (default behavior)
     */
    public function testGetPagesWithoutLanguageId(): void
    {
        // Make request to get pages without language_id
        $this->client->request('GET', '/cms-api/v1/pages');
        
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $responseData = $this->decodeArray();
        
        $this->assertArrayHasKey('data', $responseData);
        $pages = $this->asList($responseData['data']);
        
        // Check if pages have title field (if any pages exist)
        if (!empty($pages)) {
            $firstPage = $this->asArray($pages[0]);
            $this->assertArrayHasKey('title', $firstPage);
        }
    }

    /**
     * Test getting pages with language_id parameter
     */
    public function testGetPagesWithLanguageId(): void
    {
        // English is language id 3 (en-GB). Pages filtered by language use the
        // dedicated /pages/language/{language_id} route (single page content is
        // resolved by keyword via /pages/by-keyword/{keyword}).
        $this->client->request('GET', '/cms-api/v1/pages/language/3');
        
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $responseData = $this->decodeArray();
        
        $this->assertArrayHasKey('data', $responseData);
        $pages = $this->asList($responseData['data']);
        
        // Check if pages have title field (if any pages exist)
        if (!empty($pages)) {
            $firstPage = $this->asArray($pages[0]);
            $this->assertArrayHasKey('title', $firstPage);
        }
    }

    /**
     * Test getting pages with German language_id
     */
    public function testGetPagesWithGermanLanguageId(): void
    {
        // German is language id 2 (de-CH).
        $this->client->request('GET', '/cms-api/v1/pages/language/2');
        
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $responseData = $this->decodeArray();
        
        $this->assertArrayHasKey('data', $responseData);
        $pages = $this->asList($responseData['data']);
        
        // Check if pages have title field (if any pages exist)
        if (!empty($pages)) {
            $firstPage = $this->asArray($pages[0]);
            $this->assertArrayHasKey('title', $firstPage);
        }
    }

    /**
     * Test admin pages endpoint with language_id parameter
     */
    public function testAdminGetPagesWithLanguageId(): void
    {
        // First login as admin to get access token
        $accessToken = $this->getAdminAccessToken();
        
        // Admin pages filtered by language use /admin/pages/language/{language_id}.
        $this->client->request('GET', '/cms-api/v1/admin/pages/language/2', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $accessToken,
            'CONTENT_TYPE' => 'application/json'
        ]);
        
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $responseData = $this->decodeArray();
        
        $this->assertArrayHasKey('data', $responseData);
        $pages = $this->asList($responseData['data']);
        
        // Admin page list items follow the _admin_page_definition shape
        // (keyword/id_pages/crud), not the public-rendered "title" shape.
        if (!empty($pages)) {
            $firstPage = $this->asArray($pages[0]);
            $this->assertArrayHasKey('keyword', $firstPage);
        }
    }

    /**
     * Test getting single page with language_id query parameter
     */
    public function testGetSinglePageWithLanguageId(): void
    {
        // First get all pages to find a valid page keyword
        $this->client->request('GET', '/cms-api/v1/pages');
        $response = $this->client->getResponse();
        $responseData = $this->decodeArray();
        $pages = $this->asList($responseData['data']);
        
        if (!empty($pages)) {
            $firstPage = $this->asArray($pages[0]);
            $pageKeyword = $this->asString($firstPage['keyword']);
            
            // Now get the specific page with language_id parameter (keyword lookup)
            $this->client->request('GET', '/cms-api/v1/pages/by-keyword/' . $pageKeyword . '?language_id=2');
            
            $pageResponse = $this->client->getResponse();
            $this->assertSame(200, $pageResponse->getStatusCode());
            $pageResponseData = $this->decodeArray();
            
            $this->assertArrayHasKey('data', $pageResponseData);
            $pageData = $this->asArray($pageResponseData['data']);
            $this->assertArrayHasKey('page', $pageData);
            $pageObj = $this->asArray($pageData['page']);
            $this->assertArrayHasKey('keyword', $pageObj);
            $this->assertEquals($pageKeyword, $pageObj['keyword']);
        } else {
            $this->markTestSkipped('No pages available for testing');
        }
    }

    /**
     * Test invalid language_id format
     */
    public function testGetPagesWithInvalidLanguageId(): void
    {
        // Make request with invalid language_id format (should be numeric)
        $this->client->request('GET', '/cms-api/v1/pages/invalid');
        
        // Should return 404 as the route pattern doesn't match
        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }
} 