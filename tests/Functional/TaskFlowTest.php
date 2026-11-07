<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Task;
use App\Entity\TaskUser;
use App\Enum\RecurrenceKind;
use App\Enum\TaskPriority;
use App\Enum\TaskStatus;
use App\Tests\Support\Fixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TaskFlowTest extends WebTestCase
{
    use Fixtures;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /** Mint a valid token inside the browser's current session (the client does not expose it otherwise). */
    private function csrfToken(string $id): string
    {
        $request = $this->client->getRequest();
        $stack = static::getContainer()->get('request_stack');
        $stack->push($request);
        $token = static::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
        $request->getSession()->save();
        $stack->pop();

        return $token;
    }

    private function find(string $title): ?TaskUser
    {
        $this->em()->clear();

        return $this->em()->getRepository(TaskUser::class)->createQueryBuilder('tu')->join('tu.task', 't')->where('t.title = :t')->setParameter('t', $title)->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    public function testFullHappyPathThroughTheUi(): void
    {
        $user = $this->makeUser('Europe/Vilnius');
        $this->makeTask(priority: TaskPriority::High, reward: 10, title: 'Cook dinner');
        $this->client->loginUser($user);

        $this->client->request('GET', '/tasks');
        self::assertSelectorTextContains('article h3', 'Cook dinner');
        $this->client->submitForm('Pick up');
        self::assertResponseRedirects('/my');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Task claimed');

        $this->client->submitForm('Start');
        $this->client->followRedirect();
        self::assertSame(TaskStatus::InProgress, $this->find('Cook dinner')->getStatus());

        $this->client->submitForm('Complete');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'You earned 20 points');
        self::assertSame(20, $this->find('Cook dinner')->getPointsGained());

        $this->client->request('GET', '/week');
        self::assertSelectorTextContains('.stat', '20 pts');
    }

    public function testClaimWithLocalDeadlineIsStoredInUtc(): void
    {
        $user = $this->makeUser('Asia/Tokyo');
        $this->makeTask(title: 'Deadline task');
        $this->client->loginUser($user);
        $local = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo')))->modify('+2 days 15:00')->format('Y-m-d\TH:i');

        $this->client->request('GET', '/tasks');
        $this->client->submitForm('Pick up', ['deadline' => $local]);

        $deadline = $this->find('Deadline task')->getDeadlineAt();
        self::assertSame(
            (new \DateTimeImmutable($local, new \DateTimeZone('Asia/Tokyo')))->getTimestamp(),
            $deadline->getTimestamp(),
        );
        self::assertSame('06:00', $deadline->format('H:i'), '15:00 JST = 06:00 UTC');
    }

    public function testDomainErrorsBecomeFlashMessages(): void
    {
        $user = $this->makeUser();
        $this->makeTask(title: 'Only once');
        $this->client->loginUser($user);

        $this->client->request('GET', '/tasks');
        $this->client->submitForm('Pick up');
        $this->client->followRedirect();

        $this->client->request('GET', '/tasks'); // claim the same again
        $this->client->submitForm('Pick up');
        self::assertResponseRedirects('/tasks');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-danger', 'already have this task open');

        $this->client->request('GET', '/tasks');
        $this->client->submitForm('Pick up', ['deadline' => '2001-01-01T10:00']);
        $this->client->followRedirect();
        self::assertSelectorExists('.flash-danger');
    }

    public function testPastDeadlineAndGarbageDeadlineAreRefused(): void
    {
        $this->makeTask(title: 'Dl');
        $this->client->loginUser($this->makeUser());

        foreach (['2001-01-01T10:00', 'not-a-date'] as $bad) {
            $this->client->request('GET', '/tasks');
            $this->client->submitForm('Pick up', ['deadline' => $bad]);
            $this->client->followRedirect();
            self::assertSelectorExists('.flash-danger', $bad);
        }
        self::assertNull($this->find('Dl'));
    }

    public function testMissingOrInvalidCsrfTokenGives403Page(): void
    {
        $task = $this->makeTask(title: 'Csrf');
        $this->client->loginUser($this->makeUser());

        $this->client->request('POST', '/tasks/'.$task->getId().'/claim');
        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextContains('.box', 'session expired');

        $this->client->request('POST', '/tasks/'.$task->getId().'/claim', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->find('Csrf'));
    }

    public function testOtherUsersClaimIsForbiddenAndUnknownIsNotFound(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $task = $this->makeTask(title: 'Mine');
        $this->client->loginUser($owner);
        $this->client->request('GET', '/tasks');
        $this->client->submitForm('Pick up');
        $tuId = $this->find('Mine')->getId();

        $this->client->loginUser($other);
        $crawler = $this->client->request('GET', '/my');
        // forge a form with a valid token for that id: tokens are per-id but not per-user, so ownership must hold on the server
        $token = $this->csrfToken('tu'.$tuId);
        foreach (['start', 'complete', 'cancel'] as $action) {
            $this->client->request('POST', "/my/$tuId/$action", ['_token' => $token]);
            self::assertResponseStatusCodeSame(403, $action);
        }

        $token = $this->csrfToken('tu999999');
        $this->client->request('POST', '/my/999999/start', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/my/abc/start');
        self::assertResponseStatusCodeSame(404);
        self::assertNotNull($task->getId());
        self::assertNotNull($crawler);
    }

    public function testOneOffTaskDisappearsAfterCompletion(): void
    {
        $this->makeTask(kind: RecurrenceKind::OneOff, title: 'Single');
        $this->client->loginUser($this->makeUser());
        $this->client->request('GET', '/tasks');
        $this->client->submitForm('Pick up');
        $this->client->followRedirect();
        $this->client->submitForm('Start');
        $this->client->followRedirect();
        $this->client->submitForm('Complete');

        $this->client->request('GET', '/tasks');
        self::assertSelectorNotExists('article');
    }

    public function testAddingATaskValidatesInput(): void
    {
        $type = $this->makeType('errands');
        $user = $this->makeUser();
        $this->client->loginUser($user);

        $this->client->request('GET', '/tasks/new');
        $this->client->submitForm('Add', ['user_task[title]' => 'x', 'user_task[type]' => $type->getId(), 'user_task[priority]' => 'high', 'user_task[rewardValue]' => '5000', 'user_task[recurrenceKind]' => 'one_off']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.errors, ul li');

        $this->client->submitForm('Add', ['user_task[title]' => 'Buy milk', 'user_task[type]' => $type->getId(), 'user_task[priority]' => 'urgent', 'user_task[rewardValue]' => '40', 'user_task[recurrenceKind]' => 'one_off']);
        self::assertResponseRedirects('/tasks');

        $task = $this->em()->getRepository(Task::class)->findOneBy(['title' => 'Buy milk']);
        self::assertSame($user->getId(), $task->getCreatedBy()->getId());
        self::assertSame(40, $task->getRewardValue());
        self::assertSame(TaskPriority::Urgent, $task->getPriority());
    }

    public function testNegativeAndNonNumericRewardRejected(): void
    {
        $type = $this->makeType('errands');
        $this->client->loginUser($this->makeUser());
        foreach (['-1', 'abc', '101'] as $reward) {
            $this->client->request('GET', '/tasks/new');
            $this->client->submitForm('Add', ['user_task[title]' => 'Valid title', 'user_task[type]' => $type->getId(), 'user_task[priority]' => 'low', 'user_task[rewardValue]' => $reward, 'user_task[recurrenceKind]' => 'reusable']);
            self::assertResponseStatusCodeSame(422, "reward $reward");
        }
    }

    public function testUnknownWeekFallsBackGracefully(): void
    {
        $this->client->loginUser($this->makeUser());
        $this->client->request('GET', '/week?week=garbage');
        self::assertResponseRedirects('/week');
        $this->client->request('GET', '/week?week=2025-W20');
        self::assertResponseIsSuccessful();
    }
}
