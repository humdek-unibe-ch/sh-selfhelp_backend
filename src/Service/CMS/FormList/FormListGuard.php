<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\FormList;

use App\Exception\ServiceException;
use App\Repository\DataTableRepository;
use App\Service\Core\BaseService;
use Symfony\Component\HttpFoundation\Response;

/**
 * The operation rules of a list, enforced where the write happens.
 *
 * A form that sits inside an `entry-form-list` writes through the ordinary
 * form endpoints (create, update, delete). The list decides what that form may
 * do: the operations it allows, whether it works on the user's own records
 * only, and which table it is bound to. The page may show or hide buttons; this
 * is the check that actually refuses.
 *
 * The configuration it checks is the one the effective page holds (published
 * for a visitor, the draft for an authorized preview): the caller reads it with
 * {@see FormListConfigReader::readIn()} and hands it in.
 */
class FormListGuard extends BaseService
{
    public const OP_CREATE = 'create';
    public const OP_UPDATE = 'update';
    public const OP_DELETE = 'delete';

    public function __construct(
        private readonly DataTableRepository $dataTableRepository,
    ) {
    }

    /**
     * Refuse the operation unless the list allows it.
     *
     * @param FormListConfig $config the list the write goes through, as the effective page holds it
     * @param string $operation one of the OP_* constants
     * @param bool $authenticated whether a user is signed in
     * @param int|null $formSectionId the inline form that writes; null when a list action writes (a delete or quick action)
     * @throws ServiceException 401 for a guest on a list of own records, 403 for an operation the list does not offer, 400 for a form bound to another table
     */
    public function assertOperation(FormListConfig $config, string $operation, bool $authenticated, ?int $formSectionId = null): void
    {
        $allowed = match ($operation) {
            self::OP_CREATE => $config->allowCreate,
            self::OP_UPDATE => $config->allowUpdate,
            self::OP_DELETE => $config->allowDelete,
            default => false,
        };
        if (!$allowed) {
            throw new ServiceException(
                sprintf('This list does not allow to %s entries.', $operation),
                Response::HTTP_FORBIDDEN,
                ['reason' => 'operation_disabled', 'operation' => $operation],
            );
        }

        if ($config->ownEntriesOnly && !$authenticated) {
            throw new ServiceException('Sign in to work with your entries.', Response::HTTP_UNAUTHORIZED);
        }

        if ($formSectionId !== null && $config->dataTableId !== null) {
            $table = $this->dataTableRepository->find($config->dataTableId);
            if ($table === null || $table->getName() !== (string) $formSectionId) {
                throw new ServiceException(
                    'The form does not write into the table of this list.',
                    Response::HTTP_BAD_REQUEST,
                    ['reason' => 'table_mismatch'],
                );
            }
        }
    }

    /**
     * The table a list reads and writes: its own `data_table`, otherwise the
     * table of its inline form. Null when neither exists (yet): a list whose
     * form was never submitted has no records.
     *
     * @param list<array<array-key, mixed>> $rows the page tree rows
     */
    public function tableIdOf(FormListConfig $config, array $rows): ?int
    {
        if ($config->dataTableId !== null) {
            return $config->dataTableId;
        }
        $formSectionId = FormListLayout::inlineFormSectionId($rows, $config->sectionId);

        return $formSectionId === null ? null : $this->tableIdOfForm($formSectionId);
    }

    /**
     * The id of the table a form section writes into; null while the form was never submitted.
     */
    public function tableIdOfForm(int $formSectionId): ?int
    {
        $table = $this->dataTableRepository->findOneBy(['name' => (string) $formSectionId]);

        return $table?->getId();
    }
}
