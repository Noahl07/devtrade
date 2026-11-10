<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Capture d'une publication, réencodée côté serveur (métadonnées supprimées) et stockée en base.
 */
#[ORM\Entity]
class TradeImage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var resource|string */
    #[ORM\Column(type: Types::BLOB)]
    private mixed $data;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'images')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private TradePost $post,
        #[ORM\Column]
        private int $position,
        #[ORM\Column(length: 20)]
        private string $mimeType,
        #[ORM\Column]
        private int $width,
        #[ORM\Column]
        private int $height,
        string $data,
    ) {
        $this->data = $data;
    }

    public function getId(): ?int { return $this->id; }
    public function getPost(): TradePost { return $this->post; }
    public function getPosition(): int { return $this->position; }
    public function getMimeType(): string { return $this->mimeType; }
    public function getWidth(): int { return $this->width; }
    public function getHeight(): int { return $this->height; }

    public function getData(): string
    {
        if (is_resource($this->data)) {
            rewind($this->data);

            return (string) stream_get_contents($this->data);
        }

        return (string) $this->data;
    }
}
