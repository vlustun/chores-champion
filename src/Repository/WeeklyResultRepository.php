<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\WeeklyResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WeeklyResult> */
class WeeklyResultRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WeeklyResult::class);
    }

    public function findOneByUserAndWeek(User $user, string $weekKey): ?WeeklyResult
    {
        return $this->findOneBy(['user' => $user, 'weekKey' => $weekKey]);
    }
}
