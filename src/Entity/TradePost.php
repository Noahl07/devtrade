<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TradePostRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Publication de la « Salle des trades » : 1 à 3 captures, contexte du trade et leçon retenue.
 * Visible des membres connectés une fois validée par un administrateur.
 */
#[ORM\Entity(repositoryClass: TradePostRepository::class)]
#[ORM\Index(name: 'idx_trade_post_status_created', columns: ['status', 'created_at'])]
class TradePost
{
    public const EN_ATTENTE = 'en_attente';
    public const PUBLIE = 'publie';
    public const REFUSE = 'refuse';

    /** Thèmes : relient une publication aux modules de la documentation (slugs de catégorie). */
    public const THEMES = [
        'ict' => ['ICT et smart money', ['ict-inner-circle-trader', 'ict-avance-concepts-experts']],
        'orderflow' => ['Orderflow et footprint', ['orderflow-footprint']],
        'price-action' => ['Price action, Wyckoff, analyse technique', ['price-action-wyckoff', 'analyse-technique-classique']],
        'options' => ['Options et flux d\'options', ['options-flow']],
        'setups' => ['Setups et stratégies', ['strategies-setups-complets']],
        'risque' => ['Gestion du risque et psychologie', ['gestion-risque-avancee', 'psychologie-mindset', 'debuter-en-trading']],
        'algo' => ['Quantitatif et algorithmique', ['quantitatif-statistiques', 'trading-algorithmique']],
        'macro' => ['Macro et marchés', ['macroeconomie-marches-financiers', 'marches-financiers-expliques']],
        'autre' => ['Autre', []],
    ];

    public const UNITES = ['1m', '5m', '15m', '1h', '4h', 'Jour', 'Semaine', 'Autre'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 20)]
    private string $status = self::EN_ATTENTE;

    #[ORM\Column(length: 40)]
    private string $instrument = '';

    #[ORM\Column(length: 10)]
    private string $timeframe = '';

    #[ORM\Column(length: 6)]
    private string $direction = 'achat';

    /** Résultat en multiples du risque (R) ; null = trade en cours ou non communiqué. */
    #[ORM\Column(nullable: true)]
    private ?float $resultR = null;

    #[ORM\Column(length: 20)]
    private string $theme = 'autre';

    #[ORM\Column(type: Types::TEXT)]
    private string $lesson = '';

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $moderatedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $rejectReason = null;

    /** @var Collection<int, TradeImage> */
    #[ORM\OneToMany(targetEntity: TradeImage::class, mappedBy: 'post', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $images;

    /** @var Collection<int, TradeReaction> */
    #[ORM\OneToMany(targetEntity: TradeReaction::class, mappedBy: 'post')]
    private Collection $reactions;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $author,
    ) {
        $this->createdAt = new \DateTimeImmutable();
        $this->images = new ArrayCollection();
        $this->reactions = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getAuthor(): User { return $this->author; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getStatus(): string { return $this->status; }
    public function isPublished(): bool { return $this->status === self::PUBLIE; }
    public function getInstrument(): string { return $this->instrument; }
    public function setInstrument(string $v): static { $this->instrument = $v; return $this; }
    public function getTimeframe(): string { return $this->timeframe; }
    public function setTimeframe(string $v): static { $this->timeframe = $v; return $this; }
    public function getDirection(): string { return $this->direction; }
    public function setDirection(string $v): static { $this->direction = $v; return $this; }
    public function getResultR(): ?float { return $this->resultR; }
    public function setResultR(?float $v): static { $this->resultR = $v; return $this; }
    public function isLoss(): bool { return $this->resultR !== null && $this->resultR < 0; }
    public function getTheme(): string { return $this->theme; }
    public function setTheme(string $v): static { $this->theme = $v; return $this; }
    public function getThemeLabel(): string { return self::THEMES[$this->theme][0] ?? 'Autre'; }
    public function getLesson(): string { return $this->lesson; }
    public function setLesson(string $v): static { $this->lesson = $v; return $this; }
    public function getModeratedAt(): ?\DateTimeImmutable { return $this->moderatedAt; }
    public function getRejectReason(): ?string { return $this->rejectReason; }

    /** @return Collection<int, TradeImage> */
    public function getImages(): Collection { return $this->images; }

    public function addImage(TradeImage $image): static
    {
        if (!$this->images->contains($image)) {
            $this->images->add($image);
        }

        return $this;
    }

    /** @return Collection<int, TradeReaction> */
    public function getReactions(): Collection { return $this->reactions; }

    public function moderate(string $status, ?string $reason = null): static
    {
        $this->status = $status;
        $this->rejectReason = $reason;
        $this->moderatedAt = new \DateTimeImmutable();

        return $this;
    }

    /** Remise en file de modération (signalements). */
    public function backToQueue(): static
    {
        $this->status = self::EN_ATTENTE;

        return $this;
    }
}
