<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'validation_codes')]
class ValidationCode
{

    #[ORM\Id]
    #[ORM\Column(name: 'code', type: 'string', length: 16)]
    private string $code;

    #[ORM\Column(name: 'created', type: 'datetime_immutable')]
    private \DateTimeImmutable $created;

    #[ORM\Column(name: 'consumed', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $consumed = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'validationCodes', cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(name: 'id_users', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Group::class, inversedBy: 'validationCodes', cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(name: 'id_groups', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?Group $group = null;

    public function __construct()
    {
        $this->created = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getCode(): ?string
    {
        return $this->code;
    }
    public function setCode(string $code): self { $this->code = $code; return $this; }

    public function getCreated(): \DateTimeImmutable
    {
        return $this->created;
    }

    public function setCreated(\DateTimeInterface $created): static
    {
        // Ensure UTC storage
        $this->created = $created instanceof \DateTimeImmutable
            ? ($created->getTimezone()->getName() === 'UTC' ? $created : $created->setTimezone(new \DateTimeZone('UTC')))
            : \DateTimeImmutable::createFromMutable(
                $created->getTimezone()->getName() === 'UTC'
                    ? $created
                    : $created->setTimezone(new \DateTimeZone('UTC'))
            );

        return $this;
    }

    public function getConsumed(): ?\DateTimeImmutable
    {
        return $this->consumed;
    }

    public function setConsumed(?\DateTimeInterface $consumed): static
    {
        if ($consumed === null) {
            $this->consumed = null;
        } else {
            // Ensure UTC storage
            $this->consumed = $consumed instanceof \DateTimeImmutable
                ? ($consumed->getTimezone()->getName() === 'UTC' ? $consumed : $consumed->setTimezone(new \DateTimeZone('UTC')))
                : \DateTimeImmutable::createFromMutable(
                    $consumed->getTimezone()->getName() === 'UTC'
                        ? $consumed
                        : $consumed->setTimezone(new \DateTimeZone('UTC'))
                );
        }

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }
    public function getGroup(): ?Group
    {
        return $this->group;
    }

    public function setGroup(?Group $group): static
    {
        $this->group = $group;
        return $this;
    }
}
// ENTITY RULE
