<?php

namespace App\EventSubscriber;

use App\Entity\Document;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Symfony\Component\String\Slugger\SluggerInterface;

#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
class DoctrineEventSubscriber
{
    public function __construct(private SluggerInterface $slugger) {}

    public function prePersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof Document) {
            if (!$entity->getSlug() && $entity->getTitle()) {
                $entity->setSlug($this->generateSlug($entity->getTitle()));
            }
            if (!$entity->getCreatedAt()) {
                $entity->setCreatedAt(new \DateTimeImmutable());
            }
        }

        if ($entity instanceof Project) {
            if (!$entity->getSlug() && $entity->getTitle()) {
                $entity->setSlug($this->generateSlug($entity->getTitle()));
            }
            if (!$entity->getCreatedAt()) {
                $entity->setCreatedAt(new \DateTimeImmutable());
            }
        }
    }

    public function preUpdate(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof Document) {
            $entity->setUpdatedAt(new \DateTime());
        }
    }

    private function generateSlug(string $title): string
    {
        return strtolower($this->slugger->slug($title));
    }
}