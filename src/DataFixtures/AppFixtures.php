<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Task;
use App\Entity\TaskType;
use App\Entity\User;
use App\Enum\RecurrenceKind;
use App\Enum\TaskPriority;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** DEV ONLY data (known passwords!). Users live in different timezones to exercise week/deadline logic. */
final class AppFixtures extends Fixture
{
    public const PASSWORD = 'password123';

    public function __construct(private readonly UserPasswordHasherInterface $hasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $types = [];
        foreach (['Cleaning', 'Cooking', 'Pet', 'Hygiene', 'Medication', 'Errands', 'Others'] as $name) {
            $type = $manager->getRepository(TaskType::class)->findOneBy(['name' => $name]) ?? (new TaskType())->setName($name);
            $manager->persist($type);
            $types[mb_strtolower($name)] = $type;
        }

        $admin = $this->user($manager, 'admin@example.test', 'Ada Admin', 'Europe/London', ['ROLE_ADMIN']);
        $this->user($manager, 'alice@example.test', 'Alice', 'Europe/Vilnius');
        $this->user($manager, 'bob@example.test', 'Bob', 'America/New_York');
        $this->user($manager, 'carol@example.test', 'Carol', 'Pacific/Auckland');
        $manager->flush();

        $tasks = [
            ['Wash the dishes', 'cleaning', TaskPriority::Medium, 10, RecurrenceKind::Reusable],
            ['Vacuum the living room', 'cleaning', TaskPriority::Low, 15, RecurrenceKind::Reusable],
            ['Cook dinner', 'cooking', TaskPriority::High, 25, RecurrenceKind::Reusable],
            ['Walk the dog', 'pet', TaskPriority::High, 15, RecurrenceKind::Recurring],
            ['Clean the litter box', 'pet', TaskPriority::Medium, 10, RecurrenceKind::Reusable],
            ['Give grandma her pills', 'medication', TaskPriority::Urgent, 20, RecurrenceKind::Recurring],
            ['Take out the trash', 'hygiene', TaskPriority::Medium, 5, RecurrenceKind::Reusable],
            ['Buy groceries', 'errands', TaskPriority::High, 30, RecurrenceKind::OneOff],
            ['Fix the squeaky door', 'others', TaskPriority::Low, null, RecurrenceKind::OneOff],
        ];
        foreach ($tasks as [$title, $type, $priority, $reward, $kind]) {
            $manager->persist(
                (new Task())->setTitle($title)->setType($types[$type])->setPriority($priority)
                    ->setRewardValue($reward)->setRecurrenceKind($kind)->setCreatedBy($admin),
            );
        }
        $manager->flush();
    }

    /** @param list<string> $roles */
    private function user(ObjectManager $manager, string $email, string $name, string $tz, array $roles = []): User
    {
        $user = (new User())->setEmail($email)->setName($name)->setTimezone($tz)->setRoles($roles);
        $user->setPassword($this->hasher->hashPassword($user, self::PASSWORD));
        $manager->persist($user);

        return $user;
    }
}
