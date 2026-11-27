<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Controller\Api\V1\Admin\Plugin;

use App\Controller\Trait\RequestValidatorTrait;
use App\Plugin\Settings\PluginSettingsService;
use App\Service\Core\ApiResponseFormatter;
use App\Service\JSON\JsonSchemaValidationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The settings a plugin declares in its manifest, as the administrator edits them (contract C14).
 *
 *   GET /admin/plugins/{pluginId}/settings   admin_plugins_settings_get
 *   PUT /admin/plugins/{pluginId}/settings   admin_plugins_settings_put
 *
 * Both need `admin.plugins.manage`. A secret is never returned; see {@see PluginSettingsService}.
 */
final class AdminPluginSettingsController extends AbstractController
{
    use RequestValidatorTrait;

    public function __construct(
        private readonly PluginSettingsService $settings,
        private readonly ApiResponseFormatter $responseFormatter,
        private readonly JsonSchemaValidationService $jsonSchemaValidationService,
    ) {
    }

    /**
     * List the settings of a plugin with the values in effect (secrets: only `has_value` and `hint`).
     * @route /admin/plugins/{pluginId}/settings
     * @method GET
     */
    public function getSettings(string $pluginId): JsonResponse
    {
        try {
            return $this->responseFormatter->formatSuccess(
                $this->settings->view($pluginId),
                'responses/admin/plugins/plugin_settings'
            );
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Save settings: a value sets, `null` clears, a key left out stays. 400 `invalid_plugin_setting` writes nothing.
     * @route /admin/plugins/{pluginId}/settings
     * @method PUT
     */
    public function putSettings(string $pluginId, Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/plugins/put_plugin_settings', $this->jsonSchemaValidationService);

            return $this->responseFormatter->formatSuccess(
                $this->settings->update($pluginId, $this->toAssocArray($data['values'] ?? [])),
                'responses/admin/plugins/plugin_settings'
            );
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }
}
