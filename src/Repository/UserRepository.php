<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Enregistre l'activité d'un compte, au plus une fois par minute (requête directe, sans flush global).
     */
    public function touchLastSeen(int $id, \DateTimeImmutable $now): void
    {
        $this->createQueryBuilder('u')
            ->update()
            ->set('u.lastSeenAt', ':now')
            ->where('u.id = :id')
            ->andWhere('u.lastSeenAt IS NULL OR u.lastSeenAt < :seuil')
            ->setParameter('now', $now)
            ->setParameter('id', $id)
            ->setParameter('seuil', $now->modify('-1 minute'))
            ->getQuery()
            ->execute();
    }

    /** Membres actifs depuis $since, administrateurs exclus. */
    public function countOnlineMembers(\DateTimeImmutable $since): int
    {
        $actifs = $this->createQueryBuilder('u')
            ->where('u.lastSeenAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getResult();

        return count(array_filter($actifs, static fn (User $u): bool => !in_array('ROLE_ADMIN', $u->getRoles(), true)));
    }

    //    /**
    //     * @return User[] Returns an array of User objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('u.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?User
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
