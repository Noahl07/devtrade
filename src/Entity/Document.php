<?php

namespace App\Entity;

use App\Repository\DocumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DocumentRepository::class)]
class Document
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    private ?string $title = null;

    #[ORM\Column(length: 220, unique: true)]
    private ?string $slug = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $content = null;

    #[ORM\Column(length: 400, nullable: true)]
    private ?string $excerpt = null;

    #[ORM\Column(length: 20)]
    private string $accessLevel = 'free';

    #[ORM\Column(nullable: true)]
    private ?int $readingTime = null;

    #[ORM\Column]
    private bool $isPublished = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTime $publishedAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTime $updatedAt = null;

    // ── Nouveau champ position ──
    #[ORM\Column]
    private int $position = 0;

    #[ORM\ManyToOne(targetEntity: DocumentCategory::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(nullable: false)]
    private ?DocumentCategory $category = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $author = null;

    public function __construct()
    {
        $this->createdAt   = new \DateTimeImmutable();
        $this->isPublished = false;
        $this->accessLevel = 'free';
        $this->position    = 0;
    }

    public function getId(): ?int { return $this->id; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }

    public function getSlug(): ?string { return $this->slug; }
    public function setSlug(string $slug): static { $this->slug = $slug; return $this; }

    public function getContent(): ?string { return $this->content; }
    public function setContent(string $content): static { $this->content = $content; return $this; }

    public function getExcerpt(): ?string { return $this->excerpt; }
    public function setExcerpt(?string $excerpt): static { $this->excerpt = $excerpt; return $this; }

    public function getAccessLevel(): string { return $this->accessLevel; }
    public function setAccessLevel(string $accessLevel): static { $this->accessLevel = $accessLevel; return $this; }

    public function isFree(): bool { return $this->accessLevel === 'free'; }
    public function isPremium(): bool { return $this->accessLevel === 'premium'; }

    public function getReadingTime(): ?int { return $this->readingTime; }
    public function setReadingTime(?int $readingTime): static { $this->readingTime = $readingTime; return $this; }

    public function isPublished(): bool { return $this->isPublished; }
    public function setIsPublished(bool $isPublished): static { $this->isPublished = $isPublished; return $this; }

    public function getPublishedAt(): ?\DateTime { return $this->publishedAt; }
    public function setPublishedAt(?\DateTime $publishedAt): static { $this->publishedAt = $publishedAt; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }

    public function getUpdatedAt(): ?\DateTime { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTime $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): static { $this->position = $position; return $this; }

    public function getCategory(): ?DocumentCategory { return $this->category; }
    public function setCategory(?DocumentCategory $category): static { $this->category = $category; return $this; }

    public function getAuthor(): ?User { return $this->author; }
    public function setAuthor(?User $author): static { $this->author = $author; return $this; }
}