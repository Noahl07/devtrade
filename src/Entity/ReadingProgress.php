<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ReadingProgressRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Progression d'un membre sur un article : ouvert, terminé, meilleur score au quiz.
 * Supprimée avec le compte ou l'article (clés étrangères en cascade).
 */
#[ORM\Entity(repositoryClass: ReadingProgressRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_progress_user_document', columns: ['user_id', 'document_id'])]
class ReadingProgress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $openedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $quizScore = null;

    #[ORM\Column(nullable: true)]
    private ?int $quizTotal = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Document $document,
        ?\DateTimeImmutable $openedAt = null,
    ) {
        $this->openedAt = $openedAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getDocument(): Document { return $this->document; }
    public function getOpenedAt(): \DateTimeImmutable { return $this->openedAt; }
    public function setOpenedAt(\DateTimeImmutable $v): static { $this->openedAt = $v; return $this; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function setCompletedAt(?\DateTimeImmutable $v): static { $this->completedAt = $v; return $this; }
    public function isCompleted(): bool { return $this->completedAt !== null; }
    public function getQuizScore(): ?int { return $this->quizScore; }
    public function getQuizTotal(): ?int { return $this->quizTotal; }

    /** Garde le meilleur score obtenu. */
    public function recordQuiz(int $score, int $total): static
    {
        if ($this->quizScore === null || $this->quizTotal !== $total || $score > $this->quizScore) {
            $this->quizScore = $score;
            $this->quizTotal = $total;
        }

        return $this;
    }
}
