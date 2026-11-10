<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Signalement d'une publication par un membre (un seul par membre et par publication).
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_trade_report', columns: ['post_id', 'user_id'])]
class TradeReport
{
    public const MOTIFS = [
        'donnees' => 'Données personnelles visibles (nom, numéro de compte…)',
        'promo' => 'Publicité, signaux payants ou lien d\'affiliation',
        'irrespect' => 'Contenu irrespectueux ou hors sujet',
        'autre' => 'Autre',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private TradePost $post,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 20)]
        private string $reason,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getPost(): TradePost { return $this->post; }
    public function getUser(): User { return $this->user; }
    public function getReason(): string { return $this->reason; }
    public function getReasonLabel(): string { return self::MOTIFS[$this->reason] ?? 'Autre'; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
