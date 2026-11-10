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

    // Tous les articles publiés d'une catégorie — triés par position puis date
    public function findByCategory(DocumentCategory $category): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.category = :cat')
            ->andWhere('d.isPublished = true')
            ->setParameter('cat', $category)
            ->orderBy('d.position', 'ASC')
            ->addOrderBy('d.createdAt', 'ASC')
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
            ->addOrderBy('d.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // Articles liés (même catégorie, sauf l'article courant)
    public function findRelated(Document $document, int $limit = 3): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.category = :cat')
            ->andWhere('d.id != :id')
            ->andWhere('d.isPublished = true')
            ->setParameter('cat', $document->getCategory())
            ->setParameter('id', $document->getId())
            ->orderBy('d.position', 'ASC')
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

    /**
     * Recherche plein texte simple (titre, résumé, contenu), titres d'abord.
     * Sans accès Premium, le contenu des articles Premium n'est pas fouillé : seuls leur titre et leur résumé,
     * déjà publics dans les listes, peuvent correspondre.
     *
     * @return list<Document>
     */
    public function search(string $terms, bool $premium, int $limit = 40): array
    {
        $like = '%' . addcslashes(mb_strtolower($terms), '%_\\') . '%';

        return $this->createQueryBuilder('d')
            ->addSelect('CASE WHEN LOWER(d.title) LIKE :q THEN 0 WHEN LOWER(d.excerpt) LIKE :q THEN 1 ELSE 2 END AS HIDDEN pertinence')
            ->leftJoin('d.category', 'c')
            ->where('d.isPublished = true')
            ->andWhere('LOWER(d.title) LIKE :q OR LOWER(d.excerpt) LIKE :q OR (LOWER(d.content) LIKE :q AND (d.accessLevel = :free OR :premium = true))')
            ->setParameter('q', $like)
            ->setParameter('free', 'free')
            ->setParameter('premium', $premium)
            ->orderBy('pertinence', 'ASC')
            ->addOrderBy('c.position', 'ASC')
            ->addOrderBy('d.position', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    // Position max dans une catégorie (pour auto-incrémenter)
    public function getMaxPositionInCategory(DocumentCategory $category): int
    {
        $result = $this->createQueryBuilder('d')
            ->select('MAX(d.position)')
            ->where('d.category = :cat')
            ->setParameter('cat', $category)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }
}