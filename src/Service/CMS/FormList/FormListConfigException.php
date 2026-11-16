<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\FormList;

use App\Exception\ServiceException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A list's configuration is not valid. Author-time validation (saving a
 * section, importing a page or a template) answers 400 with the errors so the
 * author can fix them. A stored configuration that turns out broken when the
 * list is read is a configuration fault, not a visitor mistake: it answers 500
 * without the details.
 */
final class FormListConfigException extends ServiceException
{
    /**
     * @param list<array{field: string, message: string}> $errors
     */
    public function __construct(
        private readonly array $errors,
        int $code = Response::HTTP_BAD_REQUEST,
    ) {
        $authorFacing = $code === Response::HTTP_BAD_REQUEST;
        parent::__construct(
            $authorFacing ? 'The list configuration is not valid.' : 'This list is not configured correctly.',
            $code,
            $authorFacing ? ['reason' => 'invalid_list_config', 'errors' => $errors] : ['reason' => 'invalid_list_config'],
        );
    }

    /**
     * @return list<array{field: string, message: string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
