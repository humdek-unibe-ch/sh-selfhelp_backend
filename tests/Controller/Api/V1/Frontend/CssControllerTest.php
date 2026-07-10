<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Tests\Controller\Api\V1\Frontend;

use App\Tests\Controller\Api\V1\BaseControllerTest;
use Symfony\Component\HttpFoundation\Response;

class CssControllerTest extends BaseControllerTest
{
    public function testGetCssClassesReturnsValidResponse(): void
    {
        // Make request to the CSS classes endpoint
        $this->client->request('GET', '/cms-api/v1/frontend/css-classes');
        
        $response = $this->client->getResponse();
        
        // Assert response is successful
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        
        // Parse JSON response
        $responseData = $this->decodeArray();
        
        // Assert response structure
        $this->assertArrayHasKey('status', $responseData);
        $this->assertArrayHasKey('message', $responseData);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertArrayHasKey('logged_in', $responseData);
        $this->assertArrayHasKey('meta', $responseData);
        $this->assertArrayHasKey('data', $responseData);
        
        // Assert status is 200
        $this->assertEquals(200, $responseData['status']);
        
        // Assert data contains classes array
        $data = $this->asArray($responseData['data']);
        $this->assertArrayHasKey('classes', $data);
        $classes = $this->asList($data['classes']);
        
        // Assert classes array is not empty (should have fallback classes at minimum)
        $this->assertGreaterThan(0, count($classes));
        
        // Each class is a selectable option object: { value, text }.
        foreach ($classes as $classRaw) {
            $class = $this->asArray($classRaw);
            $this->assertArrayHasKey('value', $class);
            $this->assertArrayHasKey('text', $class);
            $this->assertIsString($class['value']);
            $this->assertIsString($class['text']);
        }
    }

    public function testGetCssClassesHasOpenAccess(): void
    {
        // Test without authentication - should still work
        $this->client->request('GET', '/cms-api/v1/frontend/css-classes');
        
        $response = $this->client->getResponse();
        
        // Should return 200 even without authentication
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        
        $responseData = $this->decodeArray();
        
        // Should indicate not logged in but still return data
        $this->assertFalse($responseData['logged_in']);
        $this->assertArrayHasKey('classes', $this->asArray($responseData['data']));
    }

    public function testGetCssClassesReturnsValidClasses(): void
    {
        $this->client->request('GET', '/cms-api/v1/frontend/css-classes');
        
        $response = $this->client->getResponse();
        $responseData = $this->decodeArray();
        
        $classes = $this->asList($this->jsonGet($responseData, 'data', 'classes'));
        
        // Just verify we get some valid CSS classes
        $this->assertGreaterThan(0, count($classes), "Should return at least one CSS class");
        
        // Check that all classes are valid option objects with a non-empty value
        foreach ($classes as $classRaw) {
            $class = $this->asArray($classRaw, "Each class should be an option object");
            $this->assertArrayHasKey('value', $class, "Each class must expose a value");
            $this->assertNotEmpty($class['value'], "Each class value should not be empty");
        }

        $values = array_map(
            static fn (array $class): string => self::coerceString($class['value'] ?? null),
            array_map(
                fn ($raw) => $this->asArray($raw),
                $classes,
            ),
        );

        foreach (['grid', 'grid-cols-1', 'sm:grid-cols-2', 'xl:grid-cols-3', 'gap-6', 'md:gap-8'] as $required) {
            $this->assertContains(
                $required,
                $values,
                "tailwind-classes.json must include {$required} for the CMS css dropdown"
            );
        }
    }
} 