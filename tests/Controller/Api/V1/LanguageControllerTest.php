<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Tests\Controller\Api\V1;

use App\Entity\Language;
use Doctrine\ORM\EntityManagerInterface;

class LanguageControllerTest extends BaseControllerTest
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->entityManager = $em;
    }

    public function testGetAllLanguages(): void
    {
        // Get a user token
        $token = $this->getAdminAccessToken();

        // Make the API request
        $this->client->request(
            'GET',
            '/cms-api/v1/languages',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json']
        );

        $response = $this->client->getResponse();
        $this->assertResponseIsSuccessful();
        
        // Validate response structure
        $content = $this->decodeArray();
        $this->assertArrayHasKey('data', $content);
        $languages = $this->asList($content['data']);
        
        // Validate schema
        $validationErrors = $this->jsonSchemaValidationService->validate(
            $this->decodeObject(),
            'responses/languages/get_languages'
        );
        $this->assertEmpty($validationErrors);
        
        // Check that we only get languages with ID > 1
        foreach ($languages as $languageRaw) {
            $language = $this->asArray($languageRaw);
            $this->assertGreaterThan(1, $language['id']);
        }
    }

    public function testAdminGetAllLanguages(): void
    {
        // Get an admin token
        $token = $this->getAdminAccessToken();

        // Make the API request
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/languages',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json']
        );

        $response = $this->client->getResponse();
        $this->assertResponseIsSuccessful();
        
        // Validate response structure
        $content = $this->decodeArray();
        $this->assertArrayHasKey('data', $content);
        $this->assertIsArray($content['data']);
        
        // Validate schema
        $validationErrors = $this->jsonSchemaValidationService->validate(
            $this->decodeObject(),
            'responses/languages/get_languages'
        );
        $this->assertEmpty($validationErrors);    
    }

    public function testAdminCreateUpdateDeleteLanguage(): void
    {
        // Get an admin token
        $token = $this->getAdminAccessToken();

        // 1. Create a new language
        $createData = [
            'locale' => 'es-test',
            'language' => 'Spanish Test',
            'csv_separator' => ';',
        ];

        $this->client->request(
            'POST',
            '/cms-api/v1/admin/languages',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            (string) json_encode($createData)
        );

        $response = $this->client->getResponse();
        $this->assertResponseIsSuccessful();
        
        $content = $this->decodeArray();
        $this->assertArrayHasKey('data', $content);
        
        // Validate schema
        $validationErrors = $this->jsonSchemaValidationService->validate(
            $this->decodeObject(),
            'responses/languages/language'
        );
        $this->assertEmpty($validationErrors);
        
        // Get the created language ID
        $languageId = $this->asInt($this->jsonGet($content, 'data', 'id'));
        
        // 2. Update the language
        $updateData = [
            'locale' => 'es-test-updated',
            'language' => 'Spanish Test Updated',
            'csv_separator' => ';',
        ];

        $this->client->request(
            'PUT',
            '/cms-api/v1/admin/languages/' . $languageId,
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            (string) json_encode($updateData)
        );

        $response = $this->client->getResponse();
        $this->assertResponseIsSuccessful();
        
        $content = $this->decodeArray();
        $this->assertArrayHasKey('data', $content);
        $payload = $this->asArray($content['data']);
        $this->assertEquals('es-test-updated', $payload['locale']);
        $this->assertEquals('Spanish Test Updated', $payload['language']);
        
        // 3. Delete the language
        $this->client->request(
            'DELETE',
            '/cms-api/v1/admin/languages/' . $languageId,
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json']
        );

        $response = $this->client->getResponse();
        $this->assertResponseIsSuccessful();
        
        $content = $this->decodeArray();
        $this->assertArrayHasKey('data', $content);
        $this->assertEquals($languageId, $this->jsonGet($content, 'data', 'id'));
        
        // Validate schema
        $validationErrors = $this->jsonSchemaValidationService->validate(
            $this->decodeObject(),
            'responses/languages/language'
        );
        $this->assertEmpty($validationErrors);
        
        // Verify the language is deleted
        $language = $this->entityManager->getRepository(Language::class)->find($languageId);
        $this->assertNull($language);
    }

    public function testCannotDeleteDefaultLanguage(): void
    {
        // Get an admin token
        $token = $this->getAdminAccessToken();

        // Try to delete the default language (ID = 1)
        $this->client->request(
            'DELETE',
            '/cms-api/v1/admin/languages/1',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json']
        );

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());
        
        $content = $this->decodeArray();
        $this->assertArrayHasKey('error', $content);
        $this->assertEquals('Cannot delete the default language', $content['error']);
    }

    public function testCannotUpdateDefaultLanguage(): void
    {
        // Get an admin token
        $token = $this->getAdminAccessToken();

        // Try to update the default language (ID = 1)
        $updateData = [
            'locale' => 'en-US',
            'language' => 'English (US)'
        ];

        $this->client->request(
            'PUT',
            '/cms-api/v1/admin/languages/1',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            (string) json_encode($updateData)
        );

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());
        
        $content = $this->decodeArray();
        $this->assertArrayHasKey('error', $content);
        $this->assertEquals('Cannot update the default language', $content['error']);
    }

    public function testNonAdminCannotAccessAdminEndpoints(): void
    {
        // Get a regular user token
        $token = 'no-token';

        // Try to access admin endpoint
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/languages',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json']
        );

        $response = $this->client->getResponse();
        $this->assertEquals(401, $response->getStatusCode());
    }
}
