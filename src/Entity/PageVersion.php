<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\PageVersionRepository;

/**
 * PageVersion Entity
 *
 * Stores complete published page JSON structures as versions.
 * This entity supports the hybrid versioning approach where:
 * - Page structure, translations, and configurations are stored in JSON
 * - Dynamic elements (data retrieval, conditions) are re-run when serving
 *
 * @package App\Entity
 */

// Define this as a Doctrine entity with custom repository class for advanced queries
#[ORM\Entity(repositoryClass: PageVersionRepository::class)]
// Map to 'page_versions' table in database
#[ORM\Table(name: 'page_versions')]
// Index on id_pages for efficient foreign key lookups and joins with pages table
#[ORM\Index(name: 'idx_id_pages', columns: ['id_pages'])]
// Index on created_by for efficient user-based queries and joins with users table
#[ORM\Index(name: 'idx_created_by', columns: ['created_by'])]
// Index on created_at for efficient time-based queries and ordering
#[ORM\Index(name: 'idx_created_at', columns: ['created_at'])]
// Index on published_at for efficient queries on published versions
#[ORM\Index(name: 'idx_published_at', columns: ['published_at'])]
// Ensure version numbers are unique per page (composite unique constraint)
#[ORM\UniqueConstraint(name: 'uniq_page_version_number', columns: ['id_pages', 'version_number'])]
class PageVersion
{
    /**
     * Unique identifier for each page version record
     *
     * This is the primary key that uniquely identifies each version in the system.
     * Used for database relationships and API endpoints that reference specific versions.
     */
    // Primary key - auto-generated integer ID for the page version
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    /**
     * Reference to the parent Page entity
     *
     * Links this version to its originating page. Every version must belong to exactly one page.
     * When a page is deleted, all its versions are automatically removed (CASCADE delete).
     * This relationship enables querying all versions for a specific page.
     */
    // Many-to-one relationship with Page entity - each version belongs to one page
    // Foreign key column 'id_pages' references pages.id, cannot be null
    // CASCADE delete ensures versions are removed when parent page is deleted
    #[ORM\ManyToOne(targetEntity: Page::class)]
    #[ORM\JoinColumn(name: 'id_pages', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Page $page = null;

    /**
     * Sequential version number within each page
     *
     * Represents the version sequence (1, 2, 3...) for each individual page.
     * Combined with page ID creates a unique constraint ensuring no duplicate version numbers per page.
     * Used for ordering versions chronologically and for user-facing version identification.
     */
    // Incremental version number per page (1, 2, 3...) - part of composite unique constraint
    // Stored as integer in 'version_number' column with database comment
    #[ORM\Column(name: 'version_number', type: 'integer', nullable: false, options: ['comment' => 'Incremental version number per page'])]
    private int $versionNumber;

    /**
     * Optional human-readable name for the version
     *
     * Allows users to assign meaningful names to versions (e.g., "v1.0", "Summer Release", "Pre-launch").
     * Optional field that complements the numeric version number for better user experience.
     * Useful for identifying significant milestones or releases in version history.
     */
    // Optional user-defined name for the version (e.g., "v1.0", "Summer Release")
    // Stored as nullable VARCHAR(255) in 'version_name' column
    #[ORM\Column(name: 'version_name', type: 'string', length: 255, nullable: true, options: ['comment' => 'Optional user-defined name for the version'])]
    private ?string $versionName = null;

    /**
     * Frozen snapshot of complete page structure at publication time
     *
     * Contains the entire page configuration as JSON, including all content, translations, and settings.
     * This is a "frozen" snapshot - the exact state of the page when this version was created/published.
     * Used to serve published content and restore previous versions. Never modified after creation.
     */
    // Complete frozen JSON structure of the published page - stored as JSON type in 'page_json' column
    /**
     * Complete JSON structure from getPage() including:
     * - Page metadata (id, keyword, url, etc.)
     * - Section structure and hierarchy
     * - Field configurations and properties
     * - Translation content for all languages
     * - Data table configurations
     * - Condition definitions
     * - Style configurations
     */
    #[ORM\Column(name: 'page_json', type: 'json', nullable: false, options: ['comment' => 'Complete JSON structure from getPage() including all languages, conditions, data table configs'])]
    private array $pageJson;

    /**
     * User who created this version
     *
     * Tracks the user responsible for creating this page version. Can be null for system-generated versions.
     * Used for audit trails, user-specific version filtering, and accountability.
     * Set to NULL if the creating user is deleted to preserve version history.
     */
    // Many-to-one relationship with User entity - tracks who created this version
    // Foreign key column 'created_by' references users.id, can be null
    // SET NULL on delete preserves version history even if user is deleted
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    /**
     * Timestamp when this version was created
     *
     * Automatically set when the version record is instantiated. Represents the exact moment
     * this version was created in the system. Used for chronological ordering of versions,
     * audit trails, and determining version age for retention policies.
     */
    // Timestamp when this version was created - auto-set in constructor
    // Stored as DATETIME in 'created_at' column, never null
    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', nullable: false)]
    private \DateTimeImmutable $createdAt;

    /**
     * Timestamp when this version was published (made live)
     *
     * NULL indicates this version has not been published yet. When set, represents the moment
     * this version became the live/published version of the page. Only one version per page
     * should have a non-NULL published_at at any time (enforced by business logic).
     */
    // Timestamp when this version was published - null means not published yet
    // Stored as nullable DATETIME in 'published_at' column
    #[ORM\Column(name: 'published_at', type: 'datetime_immutable', nullable: true, options: ['comment' => 'When this version was published'])]
    private ?\DateTimeImmutable $publishedAt = null;

    /**
     * Extensible metadata for version management
     *
     * Flexible JSON field for storing additional version-related information such as:
     * - Change summaries or commit messages
     * - Tags or labels (e.g., "major", "bugfix", "breaking-change")
     * - Automated flags (e.g., "auto-generated", "from-migration")
     * - Custom workflow states or approval information
     * - Integration data from external systems
     */
    // Optional additional metadata (change summary, tags, etc.) - stored as nullable JSON
    // Stored as nullable JSON in 'metadata' column for flexible metadata storage
    /**
     * Additional metadata like change summary, tags, etc.
     */
    #[ORM\Column(name: 'metadata', type: 'json', nullable: true, options: ['comment' => 'Additional info like change summary, tags, etc.'])]
    private ?array $metadata = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->versionNumber = 1; // Default to first version
        $this->pageJson = []; // Initialize with empty array
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPage(): ?Page
    {
        return $this->page;
    }

    public function setPage(?Page $page): static
    {
        $this->page = $page;
        return $this;
    }

    public function getVersionNumber(): int
    {
        return $this->versionNumber;
    }

    public function setVersionNumber(int $versionNumber): static
    {
        $this->versionNumber = $versionNumber;
        return $this;
    }

    public function getVersionName(): ?string
    {
        return $this->versionName;
    }

    public function setVersionName(?string $versionName): static
    {
        $this->versionName = $versionName;
        return $this;
    }

    public function getPageJson(): array
    {
        return $this->pageJson;
    }

    public function setPageJson(array $pageJson): static
    {
        $this->pageJson = $pageJson;
        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        // Ensure UTC storage
        $this->createdAt = $createdAt instanceof \DateTimeImmutable
            ? ($createdAt->getTimezone()->getName() === 'UTC' ? $createdAt : $createdAt->setTimezone(new \DateTimeZone('UTC')))
            : \DateTimeImmutable::createFromMutable(
                $createdAt->getTimezone()->getName() === 'UTC'
                    ? $createdAt
                    : $createdAt->setTimezone(new \DateTimeZone('UTC'))
            );
        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeInterface $publishedAt): static
    {
        if ($publishedAt === null) {
            $this->publishedAt = null;
        } else {
            // Ensure UTC storage
            $this->publishedAt = $publishedAt instanceof \DateTimeImmutable
                ? ($publishedAt->getTimezone()->getName() === 'UTC' ? $publishedAt : $publishedAt->setTimezone(new \DateTimeZone('UTC')))
                : \DateTimeImmutable::createFromMutable(
                    $publishedAt->getTimezone()->getName() === 'UTC'
                        ? $publishedAt
                        : $publishedAt->setTimezone(new \DateTimeZone('UTC'))
                );
        }
        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;
        return $this;
    }

    /**
     * Check if this version is currently published
     */
    public function isPublished(): bool
    {
        return $this->publishedAt !== null;
    }
}
// ENTITY RULE

