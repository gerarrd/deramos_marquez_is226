<?php

namespace App\Repository;

use App\Entity\Loan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Loan|null find($id, $lockMode = null, $lockVersion = null)
 * @method Loan|null findOneBy(array $criteria, array $orderBy = null)
 * @method Loan[]    findAll()
 * @method Loan[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class LoanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Loan::class);
    }

    /**
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function add(Loan $entity, bool $flush = true): void
    {
        $this->_em->persist($entity);
        if ($flush) {
            $this->_em->flush();
        }
    }

    /**
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function remove(Loan $entity, bool $flush = true): void
    {
        $this->_em->remove($entity);
        if ($flush) {
            $this->_em->flush();
        }
    }

    // /**
    //  * @return Loan[] Returns an array of Loan objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('l.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    public function findByBorrowerAndLender($user, $peer): ?array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.lender = :user and l.borrower = :peer')
            ->orWhere('l.borrower = :user and l.lender = :peer')
            ->orderBy('l.date', 'DESC')
            ->setParameter('user', $user)
            ->setParameter('peer', $peer)
            ->getQuery()
            ->getResult();
    }

    public function findByBorrower($borrower): ?array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.borrower = :borrower')
            ->andWhere('l.amount > 0')
            ->orderBy('l.date', 'DESC')
            ->setParameter('borrower', $borrower)
            ->getQuery()
            ->getResult();
    }
}
