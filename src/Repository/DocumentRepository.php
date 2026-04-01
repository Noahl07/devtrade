<?php

namespace App\Repository;

use App\Entity\Document;
use App\Entity\DocumentCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
    }

    // Tous les articles publiés d'une catégorie
    public function findByCategory(DocumentCategory $category): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.category = :cat')
            ->andWhere('d.isPublished = true')
            ->setParameter('cat', $category)
            ->orderBy('d.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // Articles publiés par niveau d'accès
    public function findPublishedByAccess(string $accessLevel): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.accessLevel = :level')
            ->andWhere('d.isPublished = true')
            ->setParameter('level', $accessLevel)
            ->orderBy('d.category', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // Recherche fulltext dans titre + extrait
    public function search(string $query): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.isPublished = true')
            ->andWhere('LOWER(d.title) LIKE :q OR LOWER(d.excerpt) LIKE :q')
            ->setParameter('q', '%' . strtolower($query) . '%')
            ->orderBy('d.title', 'ASC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();
    }

    // Récupère les articles liés (même catégorie, sauf l'article courant)
    public function findRelated(Document $document, int $limit = 3): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.category = :cat')
            ->andWhere('d.id != :id')
            ->andWhere('d.isPublished = true')
            ->setParameter('cat', $document->getCategory())
            ->setParameter('id', $document->getId())
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    // Compte les articles publiés par catégorie
    public function countByCategory(DocumentCategory $category): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.category = :cat')
            ->andWhere('d.isPublished = true')
            ->setParameter('cat', $category)
            ->getQuery()
            ->getSingleScalarResult();
    }

    // Articles récents pour le dashboard admin
    public function findRecent(int $limit = 10): array
    {
        return $this->createQueryBuilder('d')
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}