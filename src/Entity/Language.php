<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'languages')]
#[ORM\UniqueConstraint(name: 'uq_languages_locale', columns: ['locale'])]
class Language
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'locale', type: 'string', length: 5)]
    private string $locale = '';

    #[ORM\Column(name: 'language', type: 'string', length: 100)]
    private string $language = '';

    #[ORM\Column(name: 'csv_separator', type: 'string', length: 1)]
    private string $csvSeparator = ',';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(string $language): static
    {
        $this->language = $language;

        return $this;
    }

    public function getCsvSeparator(): ?string
    {
        return $this->csvSeparator;
    }

    public function setCsvSeparator(string $csvSeparator): static
    {
        $this->csvSeparator = $csvSeparator;

        return $this;
    }
}
// ENTITY RULE
