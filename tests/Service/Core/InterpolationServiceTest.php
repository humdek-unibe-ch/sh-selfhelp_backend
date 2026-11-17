<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Tests\Service\Core;

use App\Service\Core\InterpolationService;
use App\Service\Core\ServerBuiltString;
use App\Tests\Support\NarrowsJson;
use PHPUnit\Framework\TestCase;

/**
 * Test suite for InterpolationService
 *
 * Verifies that Mustache interpolation works correctly with nested objects,
 * arrays, and various data structures.
 */
class InterpolationServiceTest extends TestCase
{
    use NarrowsJson;

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
                'email' => 'qa.user@selfhelp.test'
            ]
        ];

        $result = $this->interpolationService->interpolate($content, $data);

        $this->assertEquals('User: John Doe, Email: qa.user@selfhelp.test', $result);
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
                'user_name' => 'qa.user@selfhelp.test',
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

        $this->assertEquals('My var: english, Test: 2, Parent: 2, User: qa.user@selfhelp.test', $result);
    }

    /**
     * Guards the exact token seeded into the maintenance page's alert section
     * (`Version20260615150000`): the operator's live note, exposed by
     * VariableResolverService under the `system` namespace, must replace
     * `{{system.maintenance_message}}` at render time.
     */
    public function testMaintenanceMessageTokenResolvesToOperatorNote(): void
    {
        $content = '{{system.maintenance_message}}';
        $data = ['system' => ['maintenance_message' => 'Back online at 14:00 UTC.']];

        $result = $this->interpolationService->interpolate($content, $data);

        $this->assertSame('Back online at 14:00 UTC.', $result);
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
        $this->assertEquals('Age: 30', $this->asArray($result['nested'])['info'] ?? null);
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

        $header = $this->asArray($result['header']);
        $this->assertEquals('My Site', $header['title'] ?? null);
        $this->assertEquals('Welcome!', $header['subtitle'] ?? null);
        $content = $this->asArray($result['content']);
        $this->assertEquals('Hello John', $content['welcome'] ?? null);
        $stats = $this->asArray($content['stats'] ?? null);
        $this->assertEquals('1000 visits', $stats['visits'] ?? null);
        $this->assertEquals('50 users', $stats['users'] ?? null);
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
        $this->assertStringContainsString('&amp;', $result);
    }

    /**
     * Section-scoped file URLs keep a raw `&` so an img_src / href query string
     * still works after interpolation (HTML-escaping would turn it into `&amp;`).
     * Only a server-built marker skips escaping — a plain string does not.
     */
    public function testListFileUrlsAreNotHtmlEscaped(): void
    {
        $url = '/lists/385/records/24/files/cover?page_id=107&index=0';
        $result = $this->interpolationService->interpolate(
            '{{cover__url}}',
            ['cover__url' => new ServerBuiltString($url)],
        );

        $this->assertSame($url, $result);
        $this->assertStringNotContainsString('&amp;', $result);
    }

    /**
     * Ready HTML from {{field__links}} keeps its `<a>` tags so the `text` style
     * can draw clickable downloads (escaping would show entities).
     */
    public function testFileLinksHtmlIsNotHtmlEscaped(): void
    {
        $links = '<a href="/lists/385/records/24/files/doc?page_id=107&amp;index=0">one.pdf</a>'
            . '<br><a href="/lists/385/records/24/files/doc?page_id=107&amp;index=1">two.pdf</a>';
        $result = $this->interpolationService->interpolate(
            '{{doc__links}}',
            ['doc__links' => new ServerBuiltString($links)],
        );

        $this->assertSame($links, $result);
        $this->assertStringContainsString('<a href="/lists/', $result);
        $this->assertStringNotContainsString('&lt;a', $result);
    }

    /**
     * F177: a visitor value that imitates ready download HTML must be escaped.
     * Content sniffing used to leave it raw.
     */
    public function testVisitorHtmlThatLooksLikeFileLinksIsEscaped(): void
    {
        $payload = '<a href="/lists/1/files/">x</a><img src=https://evil.example/t.png>'
            . '<a href="https://evil.example/login">Reset password</a>';
        $result = $this->interpolationService->interpolate('{{c}}', ['c' => $payload]);

        $this->assertStringContainsString('&lt;a href=', $result);
        $this->assertStringNotContainsString('<img src=https://evil.example/t.png>', $result);
        $this->assertSame(htmlspecialchars($payload, ENT_COMPAT, 'UTF-8'), $result);
    }

    /**
     * F177: a visitor value that imitates a list-file URL must not open an attribute.
     */
    public function testVisitorUrlThatBreaksImgSrcIsEscaped(): void
    {
        $payload = '/lists/1/files/" onerror="alert(1)';
        $result = $this->interpolationService->interpolate('<img src="{{c}}">', ['c' => $payload]);

        $this->assertStringContainsString('&quot;', $result);
        $this->assertStringNotContainsString('onerror="alert(1)"', $result);
        $this->assertSame(
            '<img src="' . htmlspecialchars($payload, ENT_COMPAT, 'UTF-8') . '">',
            $result,
        );
    }

    /**
     * A plain string that looks like a list-file URL is escaped (origin, not content).
     */
    public function testPlainListFileUrlStringIsEscaped(): void
    {
        $url = '/lists/385/records/24/files/cover?page_id=107&index=0';
        $result = $this->interpolationService->interpolate('{{cover__url}}', ['cover__url' => $url]);

        $this->assertStringContainsString('&amp;', $result);
        $this->assertSame(htmlspecialchars($url, ENT_COMPAT, 'UTF-8'), $result);
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
     * Regression (issue #56 v2): a content field can reference a token that
     * resolves to a non-scalar value — most commonly `{{system.user_group}}`,
     * because the current user's groups are exposed as a list. The default
     * Mustache escaper handed that array straight to htmlspecialchars(), which
     * on PHP 8 throws a TypeError. Because that is an \Error (not an
     * \Exception) it escaped the catch and 500-ed the whole page render. The
     * guarded escaper must render the array token as an empty string while
     * still interpolating the surrounding scalar tokens.
     */
    public function testNonScalarTokenRendersEmptyInsteadOfCrashing(): void
    {
        $content = '<p>Hi {{system.user_name}}, groups: {{system.user_group}}.</p>';
        $data = [
            'system' => [
                'user_name' => 'Jane',
                'user_group' => ['admin', 'editor'],
            ],
        ];

        $result = $this->interpolationService->interpolate($content, $data);

        $this->assertSame('<p>Hi Jane, groups: .</p>', $result);
    }

    /**
     * The array value that is neutralised for scalar `{{ }}` output must still
     * be iterable as a Mustache section, so authors can list group names with
     * `{{#system.user_group}}…{{/system.user_group}}`.
     */
    public function testNonScalarTokenStillIterableAsSection(): void
    {
        $content = '{{#system.user_group}}[{{.}}]{{/system.user_group}}';
        $data = ['system' => ['user_group' => ['admin', 'editor']]];

        $result = $this->interpolationService->interpolate($content, $data);

        $this->assertSame('[admin][editor]', $result);
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

    // -- conditions of a record template -----------------------------------

    /**
     * A condition without a token is handed back untouched, so every condition that never used a token
     * evaluates exactly as it did before.
     */
    public function testConditionWithoutTokenIsReturnedAsItIs(): void
    {
        $text = '{"==":[{"var":"language"},3]}';
        $this->assertSame($text, $this->interpolationService->interpolateCondition($text, ['language' => 'x']));

        $array = ['==' => [['var' => 'language'], 3]];
        $this->assertSame($array, $this->interpolationService->interpolateCondition($array, ['language' => 'x']));
    }

    public function testConditionTokensAreResolvedInTheStructureAndNotInTheText(): void
    {
        $data = ['city' => 'Basel', 'seats' => 15, 'row' => ['title' => 'Event 03']];

        $this->assertSame(
            ['==' => ['Basel', 'Basel']],
            $this->interpolationService->interpolateCondition('{"==":["{{city}}","Basel"]}', $data),
            'A token inside a string is replaced by its value.',
        );
        $this->assertSame(
            ['>' => ['15', 10]],
            $this->interpolationService->interpolateCondition('{">":[{"var":"{{seats}}"},10]}', $data),
            'A var whose name is a token becomes the value, never a lookup of that name.',
        );
        $this->assertSame(
            ['==' => ['Event 03', 'Event 03']],
            $this->interpolationService->interpolateCondition(['==' => [['var' => ['{{row.title}}', 'x']], 'Event 03']], $data),
            'The default of a var and a nested path work the same way, on a condition that is already decoded.',
        );
        $this->assertSame(
            ['and' => [['!=' => ['Basel', 'Bern']], ['var' => 'language']]],
            $this->interpolationService->interpolateCondition('{"and":[{"!=":["{{city}}","Bern"]},{"var":"language"}]}', $data),
            'A var without a token stays a variable.',
        );
    }

    public function testConditionStoredAsAJsonStringInsideAJsonStringIsReadToo(): void
    {
        $condition = (string) json_encode('{"==":["{{city}}","Basel"]}');

        $this->assertSame(
            ['==' => ['Basel', 'Basel']],
            $this->interpolationService->interpolateCondition($condition, ['city' => 'Basel']),
        );
    }

    /**
     * The value of a record is data, not part of the condition: whatever it holds, the shape of the condition
     * stays, and the name of a system variable in a value never makes the condition read that variable.
     */
    public function testAValueCanNeverChangeTheShapeOfAConditionOrPickAVariable(): void
    {
        $condition = '{"==":[{"var":"{{city}}"},"{{city}}"]}';

        $quote = $this->interpolationService->interpolateCondition($condition, ['city' => 'Bern"},{"==":[1,1']);
        $this->assertSame(['==' => ['Bern"},{"==":[1,1', 'Bern"},{"==":[1,1']], $quote);

        $variable = $this->interpolationService->interpolateCondition($condition, ['city' => 'language']);
        $this->assertSame(['==' => ['language', 'language']], $variable, 'A literal text, not {"var":"language"}.');

        $html = $this->interpolationService->interpolateCondition('{"==":["{{city}}","<b>&"]}', ['city' => '<b>&']);
        $this->assertSame(['==' => ['<b>&', '<b>&']], $html, 'The values are compared as they are: no HTML escaping.');
    }

    public function testAConditionValueThatIsNotATextBecomesAnEmptyText(): void
    {
        $data = ['list' => ['a', 'b'], 'flag' => true, 'none' => null];

        $this->assertSame(
            ['==' => ['', '1']],
            $this->interpolationService->interpolateCondition('{"==":["{{list}}","{{flag}}"]}', $data),
        );
        $this->assertSame(
            ['==' => ['', 'x']],
            $this->interpolationService->interpolateCondition('{"==":["{{none}}","x"]}', $data),
            'A value that does not exist is an empty text.',
        );
    }

    public function testAConditionThatIsOnlyATokenVarIsStillAStructure(): void
    {
        $this->assertSame(
            ['!!' => ['yes']],
            $this->interpolationService->interpolateCondition('{"var":"{{flag}}"}', ['flag' => 'yes']),
        );
    }

    public function testATextThatIsNotAConditionIsHandedBack(): void
    {
        $this->assertSame('not json {{x}}', $this->interpolationService->interpolateCondition('not json {{x}}', ['x' => 1]));
    }
}

