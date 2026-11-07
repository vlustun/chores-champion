<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AuditLog;
use App\Entity\Task;
use App\Entity\TaskType;
use App\Entity\User;
use App\Enum\TaskPriority;
use App\Tests\Support\Fixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class DoctrineAuditSubscriberTest extends KernelTestCase
{
    use Fixtures;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    private function actAs(User $user, string $requestId = 'req-12345678'): void
    {
        static::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $request = Request::create('/admin', 'POST', server: ['REMOTE_ADDR' => '203.0.113.9']);
        $request->attributes->set('request_id', $requestId);
        static::getContainer()->get('request_stack')->push($request);
    }

    /** @return list<AuditLog> */
    private function rows(string $type, string|int $id): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(AuditLog::class)->findForEntity($type, $id);
    }

    public function testCreateUpdateDeleteAreRecordedWithActorAndRequestContext(): void
    {
        $admin = $this->makeUser(roles: ['ROLE_ADMIN']);
        $this->actAs($admin);
        $type = $this->makeType('cleaning');

        $task = (new Task())->setTitle('Mop')->setType($type)->setCreatedBy($admin)->setPriority(TaskPriority::Low)->setRewardValue(5);
        $this->em()->persist($task);
        $this->em()->flush();
        $id = $task->getId();

        $task->setTitle('Mop the floor')->setPriority(TaskPriority::Urgent);
        $this->em()->flush();

        $this->em()->remove($task);
        $this->em()->flush();

        $rows = $this->rows('Task', $id);
        self::assertSame(['create', 'update', 'delete'], array_map(static fn (AuditLog $r) => $r->getAction(), $rows));

        [$create, $update, $delete] = $rows;
        self::assertSame('Mop', $create->getChanges()['title']['new']);
        self::assertNull($create->getChanges()['title']['old']);
        self::assertEquals(['title' => ['old' => 'Mop', 'new' => 'Mop the floor'], 'priority' => ['old' => 'low', 'new' => 'urgent']], $update->getChanges());
        self::assertSame('Mop the floor', $delete->getChanges()['title']['old']);

        foreach ($rows as $row) {
            self::assertSame($admin->getId(), $row->getActor()?->getId());
            self::assertSame('req-12345678', $row->getRequestId());
            self::assertSame('203.0.113.9', $row->getIp());
        }
    }

    public function testNoRowWhenNothingMeaningfulChanged(): void
    {
        $admin = $this->makeUser(roles: ['ROLE_ADMIN']);
        $this->actAs($admin);
        $task = $this->makeTask($admin);
        $before = \count($this->rows('Task', $task->getId()));

        $task = $this->em()->find(Task::class, $task->getId());
        $task->setTitle($task->getTitle()); // same value
        $this->em()->flush();

        self::assertCount($before, $this->rows('Task', $task->getId()));
    }

    public function testPasswordHashNeverStored(): void
    {
        $admin = $this->makeUser(roles: ['ROLE_ADMIN']);
        $this->actAs($admin);
        $user = $this->makeUser();
        $user->setPassword('$2y$10$secrethashsecrethashsecrethash');
        $this->em()->flush();

        $json = json_encode(array_map(static fn (AuditLog $r) => $r->getChanges(), $this->rows('User', $user->getId())), \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secrethash', $json);
        self::assertStringContainsString('"password"', $json);
    }

    public function testTaskTypeChangesAreAudited(): void
    {
        $this->actAs($this->makeUser(roles: ['ROLE_ADMIN']));
        $type = (new TaskType())->setName('Gardening');
        $this->em()->persist($type);
        $this->em()->flush();

        $rows = $this->rows('TaskType', $type->getId());
        self::assertCount(1, $rows);
        self::assertSame('gardening', $rows[0]->getChanges()['slug']['new']);
    }

    public function testCliChangesHaveNoActor(): void
    {
        $task = $this->makeTask();
        $row = $this->rows('Task', $task->getId())[0];
        self::assertNull($row->getActor());
    }

    public function testAuditRowsAreNotAudited(): void
    {
        $task = $this->makeTask();
        $this->em()->clear();
        self::assertSame([], $this->em()->getRepository(AuditLog::class)->findBy(['entityType' => 'AuditLog']));
        self::assertNotEmpty($this->rows('Task', $task->getId()));
    }
}
