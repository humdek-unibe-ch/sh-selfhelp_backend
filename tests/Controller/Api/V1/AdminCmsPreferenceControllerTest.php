<?php

namespace App\Tests\Controller\Api\V1;

use Symfony\Component\HttpFoundation\Response;

class AdminCmsPreferenceControllerTest extends BaseControllerTest
{
    /**
     * @group cms-preferences
     */
    public function testGetCmsPreferencesSuccess(): void
    {
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/cms-preferences',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        
        // Validate response structure
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('id', $data['data']);
        $this->assertArrayHasKey('default_language_id', $data['data']);
        $this->assertArrayHasKey('default_language', $data['data']);
        $this->assertArrayHasKey('anonymous_users', $data['data']);
        $this->assertArrayHasKey('firebase_config', $data['data']);
        
        // Validate data types
        $this->assertIsInt($data['data']['id']);
        $this->assertIsInt($data['data']['anonymous_users']);
        
        // Default language can be null or object
        if ($data['data']['default_language'] !== null) {
            $this->assertIsArray($data['data']['default_language']);
            $this->assertArrayHasKey('id', $data['data']['default_language']);
            $this->assertArrayHasKey('locale', $data['data']['default_language']);
            $this->assertArrayHasKey('language', $data['data']['default_language']);
        }
    }




    /**
     * @group cms-preferences
     */
    public function testGetCmsPreferencesUnauthorized(): void
    {
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/cms-preferences',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

} 