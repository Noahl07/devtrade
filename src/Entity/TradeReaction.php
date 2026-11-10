<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Réaction d'un membre à une publication : un émoji d'une liste fermée, une fois par membre et par émoji.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_trade_reaction', columns: ['post_id', 'user_id', 'emoji'])]
class TradeReaction
{
    /** Liste fermée : clé stockée => [émoji, libellé accessible]. */
    public const EMOJIS = [
        'pouce' => ['👍', 'J\'aime'],
        'feu' => ['🔥', 'Impressionnant'],
        'cible' => ['🎯', 'Bien exécuté'],
        'cerveau' => ['🧠', 'Bonne analyse'],
        'grimace' => ['😬', 'Aïe'],
        'livre' => ['📚', 'Leçon utile'],
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'reactions')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private TradePost $post,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 10)]
        private string $emoji,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getPost(): TradePost { return $this->post; }
    public function getUser(): User { return $this->user; }
    public function getEmoji(): string { return $this->emoji; }
}
