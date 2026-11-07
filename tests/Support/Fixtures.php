<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Task;
use App\Entity\TaskType;
use App\Entity\User;
use App\Enum\RecurrenceKind;
use App\Enum\TaskPriority;
use Doctrine\ORM\EntityManagerInterface;

/** Tiny builders for tests (each test runs in a rolled-back transaction thanks to DAMA). */
trait Fixtures
{
    private static int $seq = 0;

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface */
        return static::getContainer()->get('doctrine')->getManager();
    }

    private function makeType(string $slug = 'cleaning'): TaskType
    {
        $existing = $this->em()->getRepository(TaskType::class)->findOneBy(['slug' => $slug]);
        if ($existing instanceof TaskType) {
            return $existing;
        }
        $type = (new TaskType())->setName(ucfirst($slug))->setSlug($slug);
        $this->em()->persist($type);
        $this->em()->flush();

        return $type;
    }

    /** @param list<string> $roles */
    private function makeUser(string $tz = 'UTC', array $roles = [], ?string $email = null): User
    {
        ++self::$seq;
        $user = (new User())->setEmail($email ?? 'user'.self::$seq.'@example.test')->setName('User '.self::$seq)->setTimezone($tz)->setRoles($roles);
        $user->setPassword('x'); // login tests use makeLoginUser()
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function makeLoginUser(string $password = 'password123', string $tz = 'UTC', array $roles = []): User
    {
        $user = $this->makeUser($tz, $roles);
        $hasher = static::getContainer()->get('security.user_password_hasher');
        $user->setPassword($hasher->hashPassword($user, $password));
        $this->em()->flush();

        return $user;
    }

    private function makeTask(
        ?User $creator = null,
        TaskPriority $priority = TaskPriority::Medium,
        ?int $reward = 10,
        RecurrenceKind $kind = RecurrenceKind::Reusable,
        string $title = 'Wash dishes',
    ): Task {
        $task = (new Task())->setTitle($title)->setType($this->makeType())->setPriority($priority)
            ->setRewardValue($reward)->setRecurrenceKind($kind)->setCreatedBy($creator ?? $this->makeUser());
        $this->em()->persist($task);
        $this->em()->flush();

        return $task;
    }
}
