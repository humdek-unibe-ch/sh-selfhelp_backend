<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace Humdek\QaLiveBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Humdek\QaLiveBundle\Repository\QaLiveItemRepository;

#[ORM\Entity(repositoryClass: QaLiveItemRepository::class)]
#[ORM\Table(name: 'qa_live_items')]
class QaLiveItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id = 0;

    #[ORM\Column(length: 255)]
    private string $title = '';

    #[ORM\Column(name: 'published_version', options: ['default' => 0])]
    private int $publishedVersion = 0;

    /** JSON map of version number → title at publish time. */
    #[ORM\Column(name: 'versions_json', type: 'text')]
    private string $versionsJson = '{}';

    public function getId(): ?int
    {
        return $this->id > 0 ? $this->id : null;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getPublishedVersion(): int
    {
        return $this->publishedVersion;
    }

    public function setPublishedVersion(int $publishedVersion): void
    {
        $this->publishedVersion = $publishedVersion;
    }

    /** @return array<int, string> */
    public function getVersionsMap(): array
    {
        $decoded = json_decode($this->versionsJson, true);
        if (!is_array($decoded)) {
            return [];
        }

        $map = [];
        foreach ($decoded as $version => $label) {
            if (is_int($version)) {
                $versionKey = $version;
            } elseif (ctype_digit((string) $version)) {
                $versionKey = (int) $version;
            } else {
                continue;
            }
            $map[$versionKey] = is_string($label) ? $label : (is_scalar($label) ? (string) $label : '');
        }

        return $map;
    }

    /** @param array<int, string> $map */
    public function setVersionsMap(array $map): void
    {
        $this->versionsJson = json_encode($map, JSON_THROW_ON_ERROR);
    }

    public function titleAtVersion(int $version): ?string
    {
        $map = $this->getVersionsMap();

        return $map[$version] ?? null;
    }
}
