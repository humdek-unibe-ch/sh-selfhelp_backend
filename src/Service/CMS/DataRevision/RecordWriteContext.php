<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\DataRevision;

use App\Service\Core\LookupService;

/**
 * How one write to a record of the generic data store was reached, as far as the
 * history and the trust boundary need to know. The server builds it; a client
 * never supplies the source, the mode or a guard.
 *
 * Two kinds of writer exist:
 *
 *  - **Trusted system writers** (jobs, imports, plugins, administration) build
 *    the context with `new RecordWriteContext(...)` or pass none. They take the
 *    record lock and advance the version, but compare no version (they have no
 *    draft to protect) and may set the row metadata (`id_users`, `trigger_type`).
 *  - **Interactive writers** (a person's request: the form endpoints, quick
 *    actions, a restore) build it with {@see self::interactive()}. They must name
 *    the version of the record they based the change on, they never set the row
 *    metadata (the server derives owner and trigger), and they may carry guards
 *    that run under the record lock.
 *
 * `expectedVersion` is the version of the record the caller based its change on.
 * `restoredFromVersion` is set only by a restore.
 */
final readonly class RecordWriteContext
{
    /**
     * @param bool $interactive a person's request: the version is mandatory and the row metadata is not the caller's to set
     * @param (\Closure(LockedRecord): void)|null $targetGuard runs under the record lock, after the version check, on the record about to
     *                                                         change; it throws to refuse (the dataset boundary of a list)
     * @param (\Closure(RestorePlan): void)|null $planGuard runs under the record lock on the plan of a restore before it is applied;
     *                                                      it throws to refuse the whole restore
     */
    public function __construct(
        public string $source = LookupService::DATA_REVISION_SOURCES_FORM,
        public ?int $expectedVersion = null,
        public ?int $restoredFromVersion = null,
        public bool $interactive = false,
        public ?\Closure $targetGuard = null,
        public ?\Closure $planGuard = null,
    ) {
    }

    /**
     * The context of a person's request. `$expectedVersion` is checked for a
     * real version before anything is written; the guards run under the lock.
     *
     * @param (\Closure(LockedRecord): void)|null $targetGuard
     * @param (\Closure(RestorePlan): void)|null $planGuard
     */
    public static function interactive(
        string $source,
        ?int $expectedVersion,
        ?\Closure $targetGuard = null,
        ?\Closure $planGuard = null,
    ): self {
        return new self($source, $expectedVersion, null, true, $targetGuard, $planGuard);
    }

    /** The default door for a caller that did not name one: people save through forms, everything else is the platform. */
    public static function forTransactionBy(string $transactionBy): self
    {
        return new self(
            $transactionBy === LookupService::TRANSACTION_BY_BY_USER
                ? LookupService::DATA_REVISION_SOURCES_FORM
                : LookupService::DATA_REVISION_SOURCES_SYSTEM,
        );
    }

    public function withSource(string $source): self
    {
        return new self($source, $this->expectedVersion, $this->restoredFromVersion, $this->interactive, $this->targetGuard, $this->planGuard);
    }

    /** The same context for a restore from `$version`. */
    public function restoringFrom(int $version): self
    {
        return new self($this->source, $this->expectedVersion, $version, $this->interactive, $this->targetGuard, $this->planGuard);
    }
}
