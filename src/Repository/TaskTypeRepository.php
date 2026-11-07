<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TaskType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<TaskType> */
class TaskTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskType::class);
    }

    /** @return list<TaskType> */
    public function findActive(): array
    {
        return $this->findBy(['isActive' => true], ['name' => 'ASC']);
    }

    public function findOneBySlug(string $slug): ?TaskType
    {
        return $this->findOneBy(['slug' => $slug]);
    }
}
