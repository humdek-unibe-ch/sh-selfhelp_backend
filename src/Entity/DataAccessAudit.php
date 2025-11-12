<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Data Access Audit Entity
 *
 * Logs all permission checks performed by the DataAccessSecurityService
 * Provides complete audit trail for security monitoring and compliance
 */
#[ORM\Entity]
#[ORM\Table(name: 'dataAccessAudit')]
#[ORM\Index(name: 'IDX_dataAccessAudit_users', columns: ['id_users'])]
#[ORM\Index(name: 'IDX_dataAccessAudit_resource_types', columns: ['id_resourceTypes'])]
#[ORM\Index(name: 'IDX_dataAccessAudit_resource_id', columns: ['resource_id'])]
#[ORM\Index(name: 'IDX_dataAccessAudit_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'IDX_dataAccessAudit_permission_results', columns: ['id_permissionResults'])]
#[ORM\Index(name: 'IDX_dataAccessAudit_http_method', columns: ['http_method'])]
#[ORM\Index(name: 'IDX_dataAccessAudit_request_body_hash', columns: ['request_body_hash'])]
class DataAccessAudit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'id_users', type: Types::INTEGER)]
    private int $idUsers;

    #[ORM\Column(name: 'id_resourceTypes', type: Types::INTEGER)]
    private int $idResourceTypes;

    #[ORM\Column(name: 'resource_id', type: Types::INTEGER)]
    private int $resourceId;

    #[ORM\Column(name: 'id_actions', type: Types::INTEGER)]
    private int $idActions;

    #[ORM\Column(name: 'id_permissionResults', type: Types::INTEGER)]
    private int $idPermissionResults;

    #[ORM\Column(name: 'crud_permission', type: Types::SMALLINT, nullable: true, options: ['unsigned' => true])]
    private ?int $crudPermission = null;

    #[ORM\Column(name: 'http_method', type: Types::STRING, length: 10, nullable: true)]
    private ?string $httpMethod = null;

    #[ORM\Column(name: 'request_body_hash', type: Types::STRING, length: 64, nullable: true)]
    private ?string $requestBodyHash = null;

    #[ORM\Column(name: 'ip_address', type: Types::STRING, length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(name: 'user_agent', type: Types::TEXT, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(name: 'request_uri', type: Types::TEXT, nullable: true)]
    private ?string $requestUri = null;

    #[ORM\Column(name: 'notes', type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeInterface $createdAt;

    // Relationships
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'id_users', referencedColumnName: 'id', nullable: false)]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_resourceTypes', referencedColumnName: 'id', nullable: false)]
    private Lookup $resourceType;

    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_actions', referencedColumnName: 'id', nullable: false)]
    private Lookup $action;

    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_permissionResults', referencedColumnName: 'id', nullable: false)]
    private Lookup $permissionResult;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIdUsers(): int
    {
        return $this->idUsers;
    }

    public function getIdResourceTypes(): int
    {
        return $this->idResourceTypes;
    }


    public function getResourceId(): int
    {
        return $this->resourceId;
    }

    public function setResourceId(int $resourceId): self
    {
        $this->resourceId = $resourceId;
        return $this;
    }

    public function getIdActions(): int
    {
        return $this->idActions;
    }

    public function getIdPermissionResults(): int
    {
        return $this->idPermissionResults;
    }

    public function getCrudPermission(): ?int
    {
        return $this->crudPermission;
    }

    public function setCrudPermission(?int $crudPermission): self
    {
        $this->crudPermission = $crudPermission;
        return $this;
    }

    public function getHttpMethod(): ?string
    {
        return $this->httpMethod;
    }

    public function setHttpMethod(?string $httpMethod): self
    {
        $this->httpMethod = $httpMethod;
        return $this;
    }

    public function getRequestBodyHash(): ?string
    {
        return $this->requestBodyHash;
    }

    public function setRequestBodyHash(?string $requestBodyHash): self
    {
        $this->requestBodyHash = $requestBodyHash;
        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): self
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): self
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    public function getRequestUri(): ?string
    {
        return $this->requestUri;
    }

    public function setRequestUri(?string $requestUri): self
    {
        $this->requestUri = $requestUri;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getResourceType(): Lookup
    {
        return $this->resourceType;
    }

    public function setResourceType(Lookup $resourceType): self
    {
        $this->resourceType = $resourceType;
        return $this;
    }

    public function getAction(): Lookup
    {
        return $this->action;
    }

    public function setAction(Lookup $action): self
    {
        $this->action = $action;
        return $this;
    }

    public function getPermissionResult(): Lookup
    {
        return $this->permissionResult;
    }

    public function setPermissionResult(Lookup $permissionResult): self
    {
        $this->permissionResult = $permissionResult;
        return $this;
    }
}
