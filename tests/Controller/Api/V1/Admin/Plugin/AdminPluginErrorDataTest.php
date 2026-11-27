<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin\Plugin;

use App\Controller\Api\V1\Admin\Plugin\AdminPluginHealthController;
use App\Controller\Api\V1\Admin\Plugin\AdminPluginOperationController;
use App\Exception\ServiceException;
use App\Tests\Support\QaKernelTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * The operation and the health controllers answer a {@see ServiceException} with its `data`, like the
 * other admin plugin controllers: a refusal that names plugins (the dependents of a 409) must reach the
 * client in the envelope's `data`.
 *
 * No route of these two controllers refuses with data today, and the services behind them are final, so
 * the shared error step of each controller is driven directly with the exception a service would throw.
 */
#[Group('security')]
final class AdminPluginErrorDataTest extends QaKernelTestCase
{
    private const DEPENDENTS = [['plugin_id' => 'qa-error-data-dependent', 'version' => '^1.0.0']];

    public function testTheOperationControllerSendsTheDataOfAServiceException(): void
    {
        $envelope = $this->answer(AdminPluginOperationController::class, new ServiceException(
            'plugin_dependents_enabled',
            Response::HTTP_CONFLICT,
            ['dependents' => self::DEPENDENTS],
        ));

        self::assertSame(Response::HTTP_CONFLICT, $envelope['status']);
        self::assertSame(['dependents' => self::DEPENDENTS], $envelope['data']);
    }

    public function testTheHealthControllerSendsTheDataOfAServiceException(): void
    {
        $envelope = $this->answer(AdminPluginHealthController::class, new ServiceException(
            'plugin_dependents_enabled',
            Response::HTTP_CONFLICT,
            ['dependents' => self::DEPENDENTS],
        ));

        self::assertSame(Response::HTTP_CONFLICT, $envelope['status']);
        self::assertSame(['dependents' => self::DEPENDENTS], $envelope['data']);
    }

    public function testAnExceptionWithoutDataAnswersWithoutData(): void
    {
        foreach ([AdminPluginOperationController::class, AdminPluginHealthController::class] as $controller) {
            $envelope = $this->answer($controller, new \RuntimeException('boom', 0));

            self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $envelope['status']);
            self::assertNull($envelope['data']);
        }
    }

    /**
     * @param class-string $controllerClass
     * @return array{status: mixed, data: mixed}
     */
    private function answer(string $controllerClass, \Throwable $e): array
    {
        $controller = self::getContainer()->get($controllerClass);
        $method = new \ReflectionMethod($controller, 'respondWithError');
        $response = $method->invoke($controller, $e);
        self::assertInstanceOf(Response::class, $response);
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);

        return ['status' => $response->getStatusCode(), 'data' => $body['data'] ?? null];
    }
}
