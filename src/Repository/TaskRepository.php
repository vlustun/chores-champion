<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Task;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Task> */
class TaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Task::class);
    }

    /**
     * Active tasks, most urgent first, then newest.
     *
     * @return list<Task>
     */
    public function findAvailable(?int $typeId = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->addSelect('ty')
            ->join('t.type', 'ty')
            ->andWhere('t.isActive = true')
            ->andWhere('ty.isActive = true')
            ->addSelect("CASE t.priority WHEN 'urgent' THEN 4 WHEN 'high' THEN 3 WHEN 'medium' THEN 2 ELSE 1 END AS HIDDEN weight")
            ->orderBy('weight', 'DESC')
            ->addOrderBy('t.createdAt', 'DESC')
            ->addOrderBy('t.id', 'DESC');
        if (null !== $typeId) {
            $qb->andWhere('ty.id = :type')->setParameter('type', $typeId);
        }

        /** @var list<Task> */
        return $qb->getQuery()->getResult();
    }

    /** Row lock (SELECT ... FOR UPDATE); must be called inside a transaction. */
    public function findForUpdate(int $id): ?Task
    {
        $task = $this->getEntityManager()->find(Task::class, $id, LockMode::PESSIMISTIC_WRITE);

        return $task instanceof Task ? $task : null;
    }
}
