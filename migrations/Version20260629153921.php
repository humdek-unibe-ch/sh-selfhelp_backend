<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Promote the free-form display-copy fields `text` and `blockquote_content` from
 * `markdown-inline` (single-line editor) to `textarea` (full rich-text editor:
 * Enter, headings, lists, links, alignment) so admins can author nicely styled
 * content with interpolation (issue #56 rich content fields).
 *
 * Data-only change: only `fields.id_field_types` is updated, no content is
 * touched, so it is fully reversible. The `text` field is shared by the `text`
 * and `highlight` styles; `highlight` renders its content as plain text (it
 * strips tags), so the richer editor degrades gracefully there. The matching web
 * renderers (`TextStyle`, `BlockquoteStyle`) render the block structure instead
 * of flattening it.
 */
final class Version20260629153921 extends AbstractMigration
{
    /** @var list<string> */
    private const FIELDS = ['text', 'blockquote_content'];

    public function getDescription(): string
    {
        return 'Retype free-form fields text + blockquote_content from markdown-inline to textarea (rich content).';
    }

    public function up(Schema $schema): void
    {
        $this->retype('markdown-inline', 'textarea');
    }

    public function down(Schema $schema): void
    {
        $this->retype('textarea', 'markdown-inline');
    }

    private function retype(string $from, string $to): void
    {
        $names = implode(', ', array_map(static fn (string $n): string => "'" . $n . "'", self::FIELDS));

        $this->addSql(sprintf(
            'UPDATE fields f '
            . 'JOIN field_types target ON target.name = %s '
            . 'JOIN field_types current ON current.id = f.id_field_types AND current.name = %s '
            . 'SET f.id_field_types = target.id '
            . 'WHERE f.name IN (%s)',
            $this->connection->quote($to),
            $this->connection->quote($from),
            $names
        ));
    }
}
