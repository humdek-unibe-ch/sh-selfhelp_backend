<?php

namespace App\Tests\Service\Core;

use App\Service\Core\InterpolationService;
use PHPUnit\Framework\TestCase;

/**
 * Test suite for InterpolationService
 *
 * Verifies that Mustache interpolation works correctly with nested objects,
 * arrays, and various data structures.
 */
class InterpolationServiceTest extends TestCase
{
    private InterpolationService $interpolationService;

    protected function setUp(): void
    {
        $this->interpolationService = new InterpolationService();
    }

    /**
     * Test basic variable interpolation
     */
    public function testBasicVariableInterpolation(): void
    {
        $content = 'Hello {{name}}!';
        $data = ['name' => 'World'];

        $result = $this->interpolationService->interpolate($content, $data);

        $this->assertEquals('Hello World!', $result);
    }

    /**
     * Test nested object interpolation (dot notation)
     */
    public function testNestedObjectInterpolation(): void
    {
        $content = 'User: {{user.name}}, Email: {{user.email}}';
        $data = [
            'user' => [
                'name' => 'John Doe',
                'email' => 'john@example.com'
            ]
        ];

        $result = $this->interpolationService->interpolate($content, $data);

        $this->assertEquals('User: John Doe, Email: john@example.com', $result);
    }

    /**
     * Test deep nested object interpolation
     */
    public function testDeepNestedObjectInterpolation(): void
    {
        $content = 'Record: {{parent.text}}, Type: {{test.triggerType}}';
        $data = [
            'parent' => [
                'record_id' => 56,
                'text' => '2'
            ],
            'test' => [
                'triggerType' => 'finished',
                'text' => '2'
            ]
        ];

        $result = $this->interpolationService->interpolate($content, $data);

        $this->assertEquals('Record: 2, Type: finished', $result);
    }

    /**
     * Test interpolation with namespaced structure (system and globals)
     */
    public function testNamespacedStructureInterpolation(): void
    {
        $content = 'My var: {{globals.my_var}}, Test: {{test.text}}, Parent: {{parent.text}}, User: {{system.user_name}}';
        $data = [
            'parent' => [
                'record_id' => 56,
                'text' => '2'
            ],
            'system' => [
                'user_name' => 'stefan.kodzhabashev@gmail.com',
                'language' => 3,
                'current_date' => '2025-10-24'
            ],
            'globals' => [
                'my_var' => 'english'
            ],
            'test' => [
                'text' => '2'
            ]
        ];

        $result = $this->interpolationService->interpolate($content, $data);

        $this->assertEquals('My var: english, Test: 2, Parent: 2, User: stefan.kodzhabashev@gmail.com', $result);
    }

    /**
     * Test interpolation with multiple data arrays (merging behavior)
     */
    public function testMultipleDataArraysInterpolation(): void
    {
        $content = 'Name: {{name}}, Age: {{age}}, City: {{city}}';
        $data1 = ['name' => 'John', 'age' => 30];
        $data2 = ['city' => 'New York'];

        $result = $this->interpolationService->interpolate($content, $data1, $data2);

        $this->assertEquals('Name: John, Age: 30, City: New York', $result);
    }

    /**
     * Test that later data arrays override earlier ones
     */
    public function testDataArrayPrecedence(): void
    {
        $content = 'Value: {{key}}';
        $data1 = ['key' => 'first'];
        $data2 = ['key' => 'second'];

        $result = $this->interpolationService->interpolate($content, $data1, $data2);

        $this->assertEquals('Value: second', $result);
    }

    /**
     * Test interpolation with missing variables (should remain unchanged)
     */
    public function testMissingVariableInterpolation(): void
    {
        $content = 'Hello {{name}}, you are {{age}} years old';
        $data = ['name' => 'John'];

        $result = $this->interpolationService->interpolate($content, $data);

        // Mustache removes missing variables by default
        $this->assertEquals('Hello John, you are  years old', $result);
    }

    /**
     * Test interpolation with empty content
     */
    public function testEmptyContentInterpolation(): void
    {
        $content = '';
        $data = ['name' => 'John'];

        $result = $this->interpolationService->interpolate($content, $data);

        $this->assertEquals('', $result);
    }

    /**
     * Test interpolation with array content
     */
    public function testArrayContentInterpolation(): void
    {
        $contentArray = [
            'title' => 'Hello {{name}}',
            'message' => 'Welcome to {{city}}',
            'nested' => [
                'info' => 'Age: {{age}}'
            ]
        ];
        $data = ['name' => 'John', 'city' => 'New York', 'age' => 30];

        $result = $this->interpolationService->interpolateArray($contentArray, $data);

        $this->assertEquals('Hello John', $result['title']);
        $this->assertEquals('Welcome to New York', $result['message']);
        $this->assertEquals('Age: 30', $result['nested']['info']);
    }

    /**
     * Test interpolation with complex nested array structures
     */
    public function testComplexNestedArrayInterpolation(): void
    {
        $contentArray = [
            'header' => [
                'title' => '{{site.name}}',
                'subtitle' => '{{site.tagline}}'
            ],
            'content' => [
                'welcome' => 'Hello {{user.name}}',
                'stats' => [
                    'visits' => '{{stats.visits}} visits',
                    'users' => '{{stats.users}} users'
                ]
            ]
        ];

        $data = [
            'site' => [
                'name' => 'My Site',
                'tagline' => 'Welcome!'
            ],
            'user' => [
                'name' => 'John'
            ],
            'stats' => [
                'visits' => 1000,
                'users' => 50
            ]
        ];

        $result = $this->interpolationService->interpolateArray($contentArray, $data);

        $this->assertEquals('My Site', $result['header']['title']);
        $this->assertEquals('Welcome!', $result['header']['subtitle']);
        $this->assertEquals('Hello John', $result['content']['welcome']);
        $this->assertEquals('1000 visits', $result['content']['stats']['visits']);
        $this->assertEquals('50 users', $result['content']['stats']['users']);
    }

    /**
     * Test interpolation with special characters
     */
    public function testSpecialCharactersInterpolation(): void
    {
        $content = 'Message: {{message}}';
        $data = ['message' => 'Hello <b>World</b> & "Friends"'];

        $result = $this->interpolationService->interpolate($content, $data);

        // Mustache escapes HTML by default
        $this->assertStringContainsString('Hello', $result);
    }

    /**
     * Test that all namespaced variables work correctly
     */
    public function testAllNamespacedVariablesWork(): void
    {
        $content = 'System: {{system.user_name}}, Global: {{globals.my_var}}, Data: {{test.text}}';
        $data = [
            'system' => [
                'user_name' => 'John Doe',
                'language' => 3
            ],
            'globals' => [
                'my_var' => 'english',
                'site_name' => 'My Site'
            ],
            'test' => [
                'text' => 'Test Value'
            ]
        ];

        $result = $this->interpolationService->interpolate($content, $data);

        $this->assertEquals('System: John Doe, Global: english, Data: Test Value', $result);
    }

    /**
     * Test interpolation with numeric values
     */
    public function testNumericValueInterpolation(): void
    {
        $content = 'ID: {{id}}, Count: {{count}}, Price: {{price}}';
        $data = [
            'id' => 123,
            'count' => 0,
            'price' => 19.99
        ];

        $result = $this->interpolationService->interpolate($content, $data);

        $this->assertEquals('ID: 123, Count: 0, Price: 19.99', $result);
    }

    /**
     * Test interpolation with boolean values
     */
    public function testBooleanValueInterpolation(): void
    {
        $content = 'Active: {{active}}, Disabled: {{disabled}}';
        $data = [
            'active' => true,
            'disabled' => false
        ];

        $result = $this->interpolationService->interpolate($content, $data);

        // Mustache renders true as '1' and false as empty string
        $this->assertEquals('Active: 1, Disabled: ', $result);
    }
}

