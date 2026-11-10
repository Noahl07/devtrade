<?php

declare(strict_types=1);

namespace App\Service\Community;

use App\Entity\TradeImage;
use App\Entity\TradePost;
use App\Entity\TradeReaction;
use App\Entity\TradeReport;
use App\Entity\User;
use App\Service\Admin\AdminLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Logique de la « Salle des trades » : publication, réactions, signalements, modération.
 */
final class TradeWall
{
    /** Au-delà de ce nombre de signalements, une publication repasse en modération. */
    public const SEUIL_SIGNALEMENTS = 3;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TradeImageProcessor $images,
        private readonly AdminLogger $journal,
        private readonly bool $premoderation = true,
    ) {
    }

    public function premoderation(): bool
    {
        return $this->premoderation;
    }

    /**
     * Enregistre une publication et ses captures (toutes traitées avant tout enregistrement).
     *
     * @param list<UploadedFile> $files
     *
     * @throws \InvalidArgumentException
     */
    public function publish(TradePost $post, array $files): void
    {
        if ($files === [] || count($files) > 3) {
            throw new \InvalidArgumentException('Ajoutez entre une et trois captures.');
        }
        $traitees = array_map(fn (UploadedFile $f): array => $this->images->process($f), $files);
        foreach ($traitees as $k => $img) {
            $post->addImage(new TradeImage($post, $k, $img['mime'], $img['width'], $img['height'], $img['data']));
        }
        if (!$this->premoderation) {
            $post->moderate(TradePost::PUBLIE);
        }
        $this->em->persist($post);
        $this->em->flush();
    }

    /** Ajoute ou retire une réaction ; renvoie true si elle est maintenant active. */
    public function toggleReaction(TradePost $post, User $user, string $emoji): bool
    {
        if (!isset(TradeReaction::EMOJIS[$emoji])) {
            throw new \InvalidArgumentException('Réaction inconnue.');
        }
        $existante = $this->em->getRepository(TradeReaction::class)->findOneBy(['post' => $post, 'user' => $user, 'emoji' => $emoji]);
        if ($existante !== null) {
            $this->em->remove($existante);
            $this->em->flush();

            return false;
        }
        $this->em->persist(new TradeReaction($post, $user, $emoji));
        $this->em->flush();

        return true;
    }

    /** Signale une publication (une fois par membre) ; renvoie false si déjà signalée par ce membre. */
    public function report(TradePost $post, User $user, string $reason): bool
    {
        $reports = $this->em->getRepository(TradeReport::class);
        if ($reports->findOneBy(['post' => $post, 'user' => $user]) !== null) {
            return false;
        }
        $this->em->persist(new TradeReport($post, $user, isset(TradeReport::MOTIFS[$reason]) ? $reason : 'autre'));
        $this->em->flush();
        if ($post->isPublished() && $reports->count(['post' => $post]) >= self::SEUIL_SIGNALEMENTS) {
            $post->backToQueue();
            $this->em->flush();
            $this->journal->log('Publication masquée (signalements)', '#' . $post->getId(), null, 'système');
        }

        return true;
    }

    public function approve(TradePost $post): void
    {
        $post->moderate(TradePost::PUBLIE);
        // Une validation efface les signalements traités.
        $this->em->createQuery('DELETE FROM ' . TradeReport::class . ' r WHERE r.post = :post')->setParameter('post', $post)->execute();
        $this->em->flush();
        $this->journal->log('Publication validée', '#' . $post->getId(), $post->getInstrument());
    }

    public function reject(TradePost $post, string $reason): void
    {
        $post->moderate(TradePost::REFUSE, mb_substr(trim($reason), 0, 255) ?: 'Ne respecte pas les règles de la communauté.');
        $this->em->flush();
        $this->journal->log('Publication refusée', '#' . $post->getId(), $post->getRejectReason());
    }

    public function delete(TradePost $post, bool $parAdmin): void
    {
        $id = $post->getId();
        $this->em->remove($post);
        $this->em->flush();
        if ($parAdmin) {
            $this->journal->log('Publication supprimée', '#' . $id);
        }
    }
}
