<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AdminLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal des actions d'administration (qui a fait quoi, quand).
 * L'auteur et la cible sont copiés en texte : l'entrée reste lisible après suppression d'un compte.
 */
#[ORM\Entity(repositoryClass: AdminLogRepository::class)]
#[ORM\Index(name: 'idx_admin_log_created', columns: ['created_at'])]
class AdminLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Column(length: 180)]
        private string $actor,
        #[ORM\Column(length: 60)]
        private string $action,
        #[ORM\Column(length: 255)]
        private string $target,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $details = null,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getActor(): string { return $this->actor; }
    public function getAction(): string { return $this->action; }
    public function getTarget(): string { return $this->target; }
    public function getDetails(): ?string { return $this->details; }
}
