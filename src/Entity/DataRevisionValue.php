<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One immutable value in the history of one data record (revision storage).
 *
 * The same exact text is stored once per record, however many revisions refer
 * to it ({@see DataRevisionChange}). The digest only finds a candidate; reuse is
 * confirmed by comparing the stored bytes, so a collision can never serve wrong
 * content. The scope is the record: values are never shared between records, so
 * erasing a user's rows erases every value that belongs to them.
 */
#[ORM\Entity]
#[ORM\Table(name: 'data_revision_values')]
#[ORM\Index(name: 'idx_data_revision_values_row_hash', columns: ['id_data_rows', 'content_hash'])]
class DataRevisionValue
{
    /** Version of the encoding of `content` (1 = the cell text exactly as stored, UTF-8). */
    public const ENCODING_VERSION = 1;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DataRow::class)]
    #[ORM\JoinColumn(name: 'id_data_rows', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?DataRow $dataRow = null;

    #[ORM\Column(name: 'content_hash', type: Types::STRING, length: 64, options: ['fixed' => true])]
    private string $contentHash = '';

    #[ORM\Column(name: 'byte_length', type: Types::INTEGER)]
    private int $byteLength = 0;

    #[ORM\Column(name: 'encoding_version', type: Types::SMALLINT, options: ['default' => 1])]
    private int $encodingVersion = self::ENCODING_VERSION;

    #[ORM\Column(name: 'content', type: Types::TEXT)]
    private string $content = '';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDataRow(): ?DataRow
    {
        return $this->dataRow;
    }

    public function setDataRow(?DataRow $dataRow): static
    {
        $this->dataRow = $dataRow;

        return $this;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    public function setContentHash(string $contentHash): static
    {
        $this->contentHash = $contentHash;

        return $this;
    }

    public function getByteLength(): int
    {
        return $this->byteLength;
    }

    public function setByteLength(int $byteLength): static
    {
        $this->byteLength = $byteLength;

        return $this;
    }

    public function getEncodingVersion(): int
    {
        return $this->encodingVersion;
    }

    public function setEncodingVersion(int $encodingVersion): static
    {
        $this->encodingVersion = $encodingVersion;

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }
}
