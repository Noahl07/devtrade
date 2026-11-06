<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PageViewRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une page vue, pour les statistiques de l'admin. Aucune donnée personnelle directe :
 * ni adresse IP, ni cookie ; le visiteur n'est identifié que par une empreinte renouvelée chaque jour.
 */
#[ORM\Entity(repositoryClass: PageViewRepository::class)]
#[ORM\Index(name: 'idx_page_view_viewed_at', columns: ['viewed_at'])]
class PageView
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column]
        private \DateTimeImmutable $viewedAt,
        #[ORM\Column(length: 255)]
        private string $path,
        /** Empreinte du visiteur, valable une journée (HMAC de l'IP et du navigateur avec un sel du jour). */
        #[ORM\Column(length: 16)]
        private string $visitor,
        #[ORM\Column(length: 120, nullable: true)]
        private ?string $referrer,
        #[ORM\Column(length: 10)]
        private string $device,
        #[ORM\Column(length: 20)]
        private string $browser,
        #[ORM\Column(length: 8, nullable: true)]
        private ?string $language,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getViewedAt(): \DateTimeImmutable
    {
        return $this->viewedAt;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getVisitor(): string
    {
        return $this->visitor;
    }

    public function getReferrer(): ?string
    {
        return $this->referrer;
    }

    public function getDevice(): string
    {
        return $this->device;
    }

    public function getBrowser(): string
    {
        return $this->browser;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }
}
