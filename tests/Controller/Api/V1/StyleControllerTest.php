<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Tests\Controller\Api\V1;

use Symfony\Component\HttpFoundation\Response;

class StyleControllerTest extends BaseControllerTest
{
    /**
     * @group api
     */
    public function testGetStyles(): void
    {
        // Authenticate as admin and request /api/v1/styles
        $token = $this->getAdminAccessToken();
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/styles',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json']
        );
        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), 'Get styles failed.');
        
        // Decode as object (not array) for schema validation
        $data = $this->decodeObject();
        $this->assertTrue(property_exists($data, 'data'), 'Response does not have data property');
        
        // Validate response against JSON schema
        $validationErrors = $this->jsonSchemaValidationService->validate(
            $data, // Validate the full response object
            'responses/style/styleGroups' // Schema for styles grouped by style groups
        );
        $this->assertEmpty($validationErrors, "Response for GET /api/v1/styles failed schema validation:\n" . implode("\n", $validationErrors));
        
        // Verify the response structure
        $groups = $this->asList($data->data);
        
        // If there are style groups, verify their structure
        if (count($groups) > 0) {
            $firstGroup = $this->asObject($groups[0]);
            $this->assertTrue(property_exists($firstGroup, 'id'), 'Style group does not have id property');
            $this->assertTrue(property_exists($firstGroup, 'name'), 'Style group does not have name property');
            $this->assertTrue(property_exists($firstGroup, 'styles'), 'Style group does not have styles property');
            $styles = $this->asList($firstGroup->styles);
            
            // If there are styles in the first group, verify their structure
            if (count($styles) > 0) {
                $firstStyle = $this->asObject($styles[0]);
                $this->assertTrue(property_exists($firstStyle, 'id'), 'Style does not have id property');
                $this->assertTrue(property_exists($firstStyle, 'name'), 'Style does not have name property');
                $this->assertTrue(property_exists($firstStyle, 'description'), 'Style does not have description property');
            }
        }
    }
}
