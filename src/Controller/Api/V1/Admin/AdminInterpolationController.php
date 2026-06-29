<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Controller\Api\V1\Admin;

use App\Service\CMS\InterpolationVariableService;
use App\Service\Core\ApiResponseFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Single context-aware endpoint for the CMS interpolation `{{ }}` variable
 * picker (issue #56 v2).
 *
 * Every editor surface (section content, condition builder, data-config SQL
 * filter, custom CSS, page/config fields, action subject/body, global values)
 * fetches its variables here so the catalog is defined once and only ever offers
 * tokens that interpolate at runtime. The heavy lifting lives in
 * {@see InterpolationVariableService} / {@see \App\Service\CMS\DataVariableResolver}.
 */
class AdminInterpolationController extends AbstractController
{
    public function __construct(
        private readonly InterpolationVariableService $interpolationVariableService,
        private readonly ApiResponseFormatter $apiResponseFormatter,
    ) {}

    /**
     * Resolve the variable picker for a context as a `token => label` map.
     *
     * Query params:
     *   - `context` (required): one of section|page|action|global.
     *   - `id` (optional int): section id (`section`), page id (`page`), or the
     *     action's source data-table id (`action`). Ignored for `global`.
     *
     * @route /admin/interpolation/variables
     * @method GET
     */
    public function getVariables(Request $request): Response
    {
        $context = (string) $request->query->get('context', '');
        if (!in_array($context, InterpolationVariableService::CONTEXTS, true)) {
            return $this->apiResponseFormatter->formatError(
                'Invalid or missing "context" query parameter. Expected one of: '
                    . implode(', ', InterpolationVariableService::CONTEXTS) . '.',
                Response::HTTP_BAD_REQUEST
            );
        }

        $idParam = $request->query->get('id');
        $id = ($idParam !== null && $idParam !== '' && is_numeric($idParam)) ? (int) $idParam : null;

        $variables = $this->interpolationVariableService->getVariablesForContext($context, $id);

        return $this->apiResponseFormatter->formatSuccess(
            [
                'context' => $context,
                'data_variables' => $variables,
            ],
            'responses/admin/interpolation/variables',
            Response::HTTP_OK
        );
    }
}
