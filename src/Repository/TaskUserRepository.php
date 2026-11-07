<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Task;
use App\Entity\TaskUser;
use App\Entity\User;
use App\Enum\TaskStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<TaskUser> */
class TaskUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskUser::class);
    }

    /** @return list<TaskUser> */
    public function findOpenForUser(User $user): array
    {
        /** @var list<TaskUser> */
        return $this->createQueryBuilder('tu')
            ->addSelect('t', 'ty')
            ->join('tu.task', 't')
            ->join('t.type', 'ty')
            ->andWhere('tu.user = :user')
            ->andWhere('tu.status IN (:open)')
            ->setParameter('user', $user)
            ->setParameter('open', TaskStatus::openValues())
            ->orderBy('tu.deadlineAt', 'ASC')
            ->addOrderBy('tu.id', 'DESC')
            ->getQuery()->getResult();
    }

    /** @return list<TaskUser> */
    public function findRecentForUser(User $user, int $limit = 20): array
    {
        /** @var list<TaskUser> */
        return $this->createQueryBuilder('tu')
            ->addSelect('t')
            ->join('tu.task', 't')
            ->andWhere('tu.user = :user')
            ->andWhere('tu.status NOT IN (:open)')
            ->setParameter('user', $user)
            ->setParameter('open', TaskStatus::openValues())
            ->orderBy('tu.id', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()->getResult();
    }

    public function findOpenByTaskAndUser(Task $task, User $user): ?TaskUser
    {
        return $this->createQueryBuilder('tu')
            ->andWhere('tu.task = :task')
            ->andWhere('tu.user = :user')
            ->andWhere('tu.status IN (:open)')
            ->setParameter('task', $task)
            ->setParameter('user', $user)
            ->setParameter('open', TaskStatus::openValues())
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    public function hasOpenClaim(Task $task): bool
    {
        return (int) $this->createQueryBuilder('tu')
            ->select('COUNT(tu.id)')
            ->andWhere('tu.task = :task')
            ->andWhere('tu.status IN (:open)')
            ->setParameter('task', $task)
            ->setParameter('open', TaskStatus::openValues())
            ->getQuery()->getSingleScalarResult() > 0;
    }

    /** Pessimistic row lock; must be called inside a transaction. */
    public function findForUpdate(int $id): ?TaskUser
    {
        $tu = $this->getEntityManager()->find(TaskUser::class, $id, LockMode::PESSIMISTIC_WRITE);

        return $tu instanceof TaskUser ? $tu : null;
    }

    /**
     * Ids of open claims whose deadline has passed (UTC comparison).
     *
     * @return list<int>
     */
    public function findOverdueIds(\DateTimeImmutable $nowUtc, int $limit = 500): array
    {
        /** @var list<array{id: int}> $rows */
        $rows = $this->createQueryBuilder('tu')
            ->select('tu.id')
            ->andWhere('tu.status IN (:open)')
            ->andWhere('tu.deadlineAt IS NOT NULL')
            ->andWhere('tu.deadlineAt < :now')
            ->setParameter('open', TaskStatus::openValues())
            ->setParameter('now', $nowUtc, 'datetime_immutable')
            ->orderBy('tu.id', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()->getArrayResult();

        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    /** @return array{points: int, completed: int} */
    public function weeklyTotals(User $user, string $weekKey): array
    {
        /** @var array{points: string|null, completed: string} $row */
        $row = $this->createQueryBuilder('tu')
            ->select('COALESCE(SUM(tu.pointsGained), 0) AS points', 'COUNT(tu.id) AS completed')
            ->andWhere('tu.user = :user')
            ->andWhere('tu.weekKey = :week')
            ->andWhere('tu.status = :done')
            ->setParameter('user', $user)
            ->setParameter('week', $weekKey)
            ->setParameter('done', TaskStatus::Completed)
            ->getQuery()->getSingleResult();

        return ['points' => (int) $row['points'], 'completed' => (int) $row['completed']];
    }

    /**
     * Completed totals for every user in a week, best first.
     *
     * @return list<array{userId: int, points: int, completed: int}>
     */
    public function weeklyLeaderboard(string $weekKey): array
    {
        /** @var list<array{userId: int|string, points: string, completed: string}> $rows */
        $rows = $this->createQueryBuilder('tu')
            ->select('IDENTITY(tu.user) AS userId', 'SUM(tu.pointsGained) AS points', 'COUNT(tu.id) AS completed')
            ->andWhere('tu.weekKey = :week')
            ->andWhere('tu.status = :done')
            ->setParameter('week', $weekKey)
            ->setParameter('done', TaskStatus::Completed)
            ->groupBy('tu.user')
            ->orderBy('points', 'DESC')
            ->addOrderBy('completed', 'DESC')
            ->addOrderBy('userId', 'ASC')
            ->getQuery()->getArrayResult();

        return array_map(static fn (array $r): array => [
            'userId' => (int) $r['userId'],
            'points' => (int) $r['points'],
            'completed' => (int) $r['completed'],
        ], $rows);
    }
}
