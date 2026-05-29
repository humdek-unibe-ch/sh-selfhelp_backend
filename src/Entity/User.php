<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'uq_users_email', columns: ['email'])]
#[ORM\UniqueConstraint(name: 'uq_users_user_name', columns: ['user_name'])]
#[ORM\Index(name: 'idx_users_id_languages', columns: ['id_languages'])]
#[ORM\Index(name: 'idx_users_id_status', columns: ['id_status'])]
#[ORM\Index(name: 'idx_users_id_user_types', columns: ['id_user_types'])]
#[ORM\Index(name: 'idx_users_id_timezones', columns: ['id_timezones'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    /** @var Collection<int, UsersGroup> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: UsersGroup::class, orphanRemoval: true)]
    private Collection $usersGroups;

    // --- RELATIONSHIPS ---

    /**
     * @return Collection<int, Group>
     */
    public function getGroups(): Collection
    {
        // Every UsersGroup still in the collection has a non-null group
        // (removeUsersGroup() detaches it before nulling the back-reference);
        // array_filter drops the never-occurring null case to satisfy types.
        return new ArrayCollection(
            array_values(array_filter(
                array_map(fn(UsersGroup $ug) => $ug->getGroup(), $this->usersGroups->toArray())
            ))
        );
    }

    public function addUsersGroup(UsersGroup $usersGroup): self
    {
        if (!$this->usersGroups->contains($usersGroup)) {
            $this->usersGroups[] = $usersGroup;
            $usersGroup->setUser($this);
        }
        return $this;
    }

    public function removeUsersGroup(UsersGroup $usersGroup): self
    {
        if ($this->usersGroups->removeElement($usersGroup)) {
            if ($usersGroup->getUser() === $this) {
                $usersGroup->setUser(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, UsersGroup>
     */
    public function getUsersGroups(): Collection
    {
        return $this->usersGroups;
    }

    /** @var Collection<int, Transaction> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Transaction::class, orphanRemoval: true, cascade: ['persist', 'remove'])]
    private Collection $transactions;

    /** @var Collection<int, RefreshToken> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: RefreshToken::class, orphanRemoval: true, cascade: ['persist', 'remove'])]
    private Collection $refreshTokens;

    /** @var Collection<int, ValidationCode> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: ValidationCode::class, orphanRemoval: true, cascade: ['persist', 'remove'])]
    private Collection $validationCodes;

    /** @var Collection<int, Role> */
    #[ORM\ManyToMany(targetEntity: Role::class, inversedBy: 'users', fetch: 'EAGER')]
    #[ORM\JoinTable(
        name: 'rel_roles_users',
        joinColumns: [new ORM\JoinColumn(name: 'id_users', referencedColumnName: 'id', onDelete: 'CASCADE')],
        inverseJoinColumns: [new ORM\JoinColumn(name: 'id_roles', referencedColumnName: 'id', onDelete: 'CASCADE')]
    )]
    private Collection $roles;

    public function __construct()
    {
        $this->usersGroups = new ArrayCollection();
        $this->transactions = new ArrayCollection();
        $this->refreshTokens = new ArrayCollection();
        $this->validationCodes = new ArrayCollection();
        $this->roles = new ArrayCollection();

        // Set default userType in service layer or controller when creating new users
        // The default value should be the 'user' type from lookups table
        // This cannot be set directly in the entity as it requires database access
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    private ?string $email = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $password = null;


    #[ORM\Column(type: 'boolean', options: ['default' => 0])]
    private bool $blocked = false;

    #[ORM\Column(type: 'integer', nullable: true, options: ['default' => 1])]
    private ?int $id_status = 1;

    #[ORM\Column(type: 'boolean', options: ['default' => 0])]
    private bool $intern = false;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $token = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $id_languages = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $last_login = null;

    #[ORM\Column(type: 'string', length: 200, nullable: true)]
    private ?string $device_token = null;

    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_user_types', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE', options: ['default' => 72])] //TODO: set default value to user type dynamically
    private ?Lookup $userType = null;


    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_status', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Lookup $status = null;

    #[ORM\ManyToOne(targetEntity: Language::class)]
    #[ORM\JoinColumn(name: 'id_languages', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Language $language = null;

    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_timezones', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Lookup $timezone = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $user_name = null;

    /**
     * Monotonically-bumped token used by the frontend BFF to detect ACL/permission
     * changes so that the navigation cache can be surgically invalidated instead of
     * refetched on every navigation. Bumped inside the same transaction that calls
     * invalidateUserCaches() in AdminUserService and ProfileService.
     */
    #[ORM\Column(name: 'acl_version', type: 'string', length: 36, nullable: true)]
    private ?string $acl_version = null;

    // Not persisted: for 2FA runtime state
    // This property is used for 2FA runtime state and is not stored in the database
    private bool $twoFactorRequired = false;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getUserType(): ?Lookup
    {
        return $this->userType;
    }

    public function setUserType(?Lookup $userType): self
    {
        $this->userType = $userType;
        return $this;
    }

    public function isBlocked(): ?bool
    {
        return $this->blocked;
    }

    public function setBlocked(bool $blocked): static
    {
        $this->blocked = $blocked;

        return $this;
    }

    public function getIdStatus(): ?int
    {
        return $this->id_status;
    }



    public function isIntern(): ?bool
    {
        return $this->intern;
    }

    public function setIntern(bool $intern): static
    {
        $this->intern = $intern;

        return $this;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function setToken(?string $token): static
    {
        $this->token = $token;

        return $this;
    }

    public function getDeviceToken(): ?string
    {
        return $this->device_token;
    }

    public function setDeviceToken(?string $deviceToken): static
    {
        $this->device_token = $deviceToken;

        return $this;
    }

    public function getIdLanguages(): ?int
    {
        return $this->id_languages;
    }



    public function getLastLogin(): ?\DateTimeImmutable
    {
        return $this->last_login;
    }

    public function setLastLogin(?\DateTimeImmutable $last_login): static
    {
        $this->last_login = $last_login;

        return $this;
    }

    public function getUserName(): ?string
    {
        return $this->user_name;
    }

    public function setUserName(?string $user_name): static
    {
        $this->user_name = $user_name;

        return $this;
    }

    /**
     * Get the roles granted to the user for Symfony Security
     *
     * @return string[] The user roles
     */
    public function getRoles(): array
    {
        $roleNames = $this->getUserRoles()
            ->map(function (Role $role) {
                return 'ROLE_' . strtoupper($role->getName());
            })
            ->toArray();

        return array_unique($roleNames);
    }

    /**
     * Get the role entities associated with this user
     * 
     * @return Collection<int, Role>
     */
    public function getUserRoles(): Collection
    {
        return $this->roles;
    }

    /**
     * Add a role to this user
     */
    public function addRole(Role $role): self
    {
        if (!$this->roles->contains($role)) {
            $this->roles->add($role);
        }

        return $this;
    }

    /**
     * Remove a role from this user
     */
    public function removeRole(Role $role): self
    {
        $this->roles->removeElement($role);
        return $this;
    }
    public function eraseCredentials(): void {}
    public function getUserIdentifier(): string
    {
        // A persisted/authenticated user always has a non-empty email (the DB
        // column is NOT NULL and login flows require it); assert the invariant
        // so the security contract's non-empty-string return type holds.
        assert($this->email !== null && $this->email !== '');
        return $this->email;
    }

    // --- RELATIONSHIP GETTERS & SETTERS ---

    /**
     * @return Collection<int, Transaction>
     */
    public function getTransactions(): Collection
    {
        return $this->transactions;
    }
    public function addTransaction(Transaction $transaction): self
    {
        if (!$this->transactions->contains($transaction)) {
            $this->transactions[] = $transaction;
            $transaction->setUser($this);
        }
        return $this;
    }
    public function removeTransaction(Transaction $transaction): self
    {
        if ($this->transactions->removeElement($transaction)) {
            if ($transaction->getUser() === $this) {
                $transaction->setUser(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, RefreshToken>
     */
    public function getRefreshTokens(): Collection
    {
        return $this->refreshTokens;
    }
    public function addRefreshToken(RefreshToken $refreshToken): self
    {
        if (!$this->refreshTokens->contains($refreshToken)) {
            $this->refreshTokens[] = $refreshToken;
            $refreshToken->setUser($this);
        }
        return $this;
    }
    public function removeRefreshToken(RefreshToken $refreshToken): self
    {
        if ($this->refreshTokens->removeElement($refreshToken)) {
            if ($refreshToken->getUser() === $this) {
                $refreshToken->setUser(null);
            }
        }
        return $this;
    }


    /**
     * @return Collection<int, ValidationCode>
     */
    public function getValidationCodes(): Collection
    {
        return $this->validationCodes;
    }
    public function addValidationCode(ValidationCode $validationCode): self
    {
        if (!$this->validationCodes->contains($validationCode)) {
            $this->validationCodes[] = $validationCode;
            $validationCode->setUser($this);
        }
        return $this;
    }
    public function removeValidationCode(ValidationCode $validationCode): self
    {
        if ($this->validationCodes->removeElement($validationCode)) {
            if ($validationCode->getUser() === $this) {
                $validationCode->setUser(null);
            }
        }
        return $this;
    }

    /**
     * Check if two-factor authentication is required for this user
     * This is determined by checking if any of the user's groups require 2FA
     * 
     * @return bool True if 2FA is required, false otherwise
     */
    public function isTwoFactorRequired(): bool
    {
        // First check if it's already set (for performance)
        if ($this->twoFactorRequired) {
            return true;
        }

        // Check if any of the user's groups require 2FA
        foreach ($this->usersGroups as $userGroup) {
            $group = $userGroup->getGroup();
            if ($group && $group->isRequires2fa()) {
                $this->twoFactorRequired = true;
                return true;
            }
        }

        return false;
    }

    public function getAclVersion(): ?string
    {
        return $this->acl_version;
    }

    public function setAclVersion(?string $aclVersion): self
    {
        $this->acl_version = $aclVersion;
        return $this;
    }

    /**
     * Generate and assign a new acl_version value. Called by services that
     * invalidate user ACL-related caches so the frontend BFF can detect the
     * change by comparing the acl_version field returned by /auth/user-data.
     */
    public function bumpAclVersion(): self
    {
        $this->acl_version = bin2hex(random_bytes(16));
        return $this;
    }

    public function setTwoFactorRequired(bool $required): self
    {
        $this->twoFactorRequired = $required;
        return $this;
    }

    /**
     * @return Collection<int, Permission>
     */
    public function getPermissions(): Collection
    {
        /** @var ArrayCollection<int, Permission> $perms */
        $perms = new ArrayCollection();
        foreach ($this->getUserRoles() as $role) {
            foreach ($role->getPermissions() as $p) {
                if (! $perms->contains($p)) {
                    $perms->add($p);
                }
            }
        }
        return $perms;
    }

    /**
     * Optionally return just the names:
     *
     * @return string[]
     */
    public function getPermissionNames(): array
    {
        return $this->getPermissions()
            ->map(fn($p) => $p->getName())
            ->toArray();
    }

    public function getStatus(): ?Lookup
    {
        return $this->status;
    }

    public function setStatus(?Lookup $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getTimezone(): ?Lookup
    {
        return $this->timezone;
    }

    public function setTimezone(?Lookup $timezone): self
    {
        $this->timezone = $timezone;
        return $this;
    }


    public function getLanguage(): ?Language
    {
        return $this->language;
    }

    public function setLanguage(?Language $language): self
    {
        $this->language = $language;
        return $this;
    }

    /**
     * Returns a string representation of the User for debugging purposes
     * This helps identify User entities in Doctrine error messages
     */
    public function __toString(): string
    {
        return sprintf(
            'User(id=%s, email=%s, name=%s)',
            $this->getId(),
            $this->getEmail(),
            $this->getName() ?? 'null'
        );
    }
}
// ENTITY RULE
