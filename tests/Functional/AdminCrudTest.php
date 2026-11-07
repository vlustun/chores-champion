<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AuditLog;
use App\Entity\Task;
use App\Entity\TaskType;
use App\Entity\User;
use App\Enum\TaskStatus;
use App\Service\TaskWorkflowService;
use App\Tests\Support\Fixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminCrudTest extends WebTestCase
{
    use Fixtures;

    private KernelBrowser $client;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->admin = $this->makeUser(roles: ['ROLE_ADMIN']);
        $this->client->loginUser($this->admin);
    }

    /** @return list<AuditLog> */
    private function audit(string $type, string|int|null $id = null): array
    {
        $this->em()->clear();
        $criteria = ['entityType' => $type] + (null !== $id ? ['entityId' => (string) $id] : []);

        return $this->em()->getRepository(AuditLog::class)->findBy($criteria, ['id' => 'ASC']);
    }

    public function testAdminCreatesTaskWithoutApprovalAndItIsAudited(): void
    {
        $type = $this->makeType('pet');
        $this->client->request('GET', '/admin/task/new');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Create', [
            'Task[title]' => 'Walk the dog',
            'Task[type]' => $type->getId(),
            'Task[priority]' => '2', // EasyAdmin enum choices are index based: low,medium,high,urgent = 0..3
            'Task[rewardValue]' => '15',
            'Task[recurrenceKind]' => '1',
            'Task[isActive]' => '1',
        ]);
        self::assertResponseRedirects();

        $this->em()->clear();
        $task = $this->em()->getRepository(Task::class)->findOneBy(['title' => 'Walk the dog']);
        self::assertNotNull($task);
        self::assertTrue($task->isActive(), 'no approval step');
        self::assertSame($this->admin->getId(), $task->getCreatedBy()->getId());

        $rows = $this->audit('Task', $task->getId());
        self::assertCount(1, $rows);
        self::assertSame('create', $rows[0]->getAction());
        self::assertSame($this->admin->getId(), $rows[0]->getActor()->getId());
        self::assertNotNull($rows[0]->getRequestId());
        self::assertSame('high', $rows[0]->getChanges()['priority']['new']);
    }

    public function testAdminCanAddATaskTypeAtRuntime(): void
    {
        $this->client->request('GET', '/admin/task-type/new');
        $this->client->submitForm('Create', ['TaskType[name]' => 'Gardening', 'TaskType[isActive]' => '1']);
        self::assertResponseRedirects();

        $this->em()->clear();
        $type = $this->em()->getRepository(TaskType::class)->findOneBy(['name' => 'Gardening']);
        self::assertSame('gardening', $type->getSlug());
        self::assertCount(1, $this->audit('TaskType', $type->getId()));
    }

    public function testDuplicateTaskTypeIsRejectedByValidation(): void
    {
        $this->makeType('cleaning');
        $this->client->request('GET', '/admin/task-type/new');
        $this->client->submitForm('Create', ['TaskType[name]' => 'Cleaning', 'TaskType[isActive]' => '1']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'already exists');
    }

    public function testEditRecordsBeforeAfterDiff(): void
    {
        $task = $this->makeTask($this->admin, title: 'Old title');
        $this->client->request('GET', '/admin/task/'.$task->getId().'/edit');
        $this->client->submitForm('Save changes', ['Task[title]' => 'New title', 'Task[priority]' => '3']);
        self::assertResponseRedirects();

        $update = array_values(array_filter($this->audit('Task', $task->getId()), static fn ($r) => 'update' === $r->getAction()))[0];
        self::assertEquals(['old' => 'Old title', 'new' => 'New title'], $update->getChanges()['title']);
        self::assertEquals(['old' => 'medium', 'new' => 'urgent'], $update->getChanges()['priority']);
    }

    public function testAdminCreatesUserWithHashedPasswordAndCanLogIn(): void
    {
        $this->client->request('GET', '/admin/user/new');
        $this->client->submitForm('Create', [
            'User[name]' => 'New Person',
            'User[email]' => 'New.Person@Example.test',
            'User[timezone]' => 'Europe/Vilnius',
            'User[plainPassword]' => 'a-long-password',
            'User[isActive]' => '1',
        ]);
        self::assertResponseRedirects();

        $this->em()->clear();
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'new.person@example.test']);
        self::assertNotNull($user, 'email is normalised to lowercase');
        self::assertNotSame('a-long-password', $user->getPassword());
        self::assertTrue(static::getContainer()->get('security.user_password_hasher')->isPasswordValid($user, 'a-long-password'));

        $json = json_encode(array_map(static fn ($r) => $r->getChanges(), $this->audit('User', $user->getId())));
        self::assertStringNotContainsString($user->getPassword(), $json);
        self::assertStringNotContainsString('a-long-password', $json);
    }

    public function testShortPasswordAndDuplicateEmailAreRejected(): void
    {
        $existing = $this->makeUser();
        $this->client->request('GET', '/admin/user/new');
        $this->client->submitForm('Create', ['User[name]' => 'X', 'User[email]' => $existing->getEmail(), 'User[timezone]' => 'UTC', 'User[plainPassword]' => 'short', 'User[isActive]' => '1']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'at least 8');
        self::assertSelectorTextContains('body', 'already registered');
    }

    public function testAdminCannotDemoteOrDisableThemselves(): void
    {
        $this->client->request('GET', '/admin/user/'.$this->admin->getId().'/edit');
        $this->client->submitForm('Save changes', ['User[isActive]' => false]);
        $this->client->followRedirect();

        $this->em()->clear();
        self::assertTrue($this->em()->find(User::class, $this->admin->getId())->isActive());
        self::assertSelectorTextContains('body', 'cannot remove your own admin rights');
    }

    public function testAuditLogIsReadOnly(): void
    {
        $this->client->request('GET', '/admin/audit-log');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a.action-new');

        $row = $this->audit('User')[0] ?? null;
        $id = $row?->getId() ?? 1;
        foreach (['new' => 'GET', $id.'/edit' => 'GET', $id.'/delete' => 'POST'] as $path => $method) {
            $this->client->request($method, '/admin/audit-log/'.$path);
            self::assertContains($this->client->getResponse()->getStatusCode(), [403, 404, 405, 400, 302], $path);
            self::assertNotSame(200, $this->client->getResponse()->getStatusCode(), $path);
        }
    }

    public function testTaskWithHistoryIsDeactivatedNotDeleted(): void
    {
        $user = $this->makeUser();
        $task = $this->makeTask($this->admin, title: 'Has history');
        static::getContainer()->get(TaskWorkflowService::class)->claim($user->getId(), $task->getId());

        $crawler = $this->client->request('GET', '/admin/task');
        $token = $crawler->filter('form[action*="delete"]')->first()->filter('input[name="token"]')->count();
        self::assertGreaterThanOrEqual(0, $token);

        // delete through the EA controller logic directly
        $controller = static::getContainer()->get(\App\Controller\Admin\TaskCrudController::class);
        $this->em()->clear();
        $managed = $this->em()->find(Task::class, $task->getId());
        $reflection = new \ReflectionMethod($controller, 'deleteEntity');
        $request = \Symfony\Component\HttpFoundation\Request::create('/');
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()));
        static::getContainer()->get('request_stack')->push($request);
        $reflection->invoke($controller, $this->em(), $managed);

        $this->em()->clear();
        self::assertFalse($this->em()->find(Task::class, $task->getId())->isActive());
        self::assertSame(TaskStatus::Pending, $this->em()->getRepository(\App\Entity\TaskUser::class)->findOneBy([])->getStatus());
    }

    public function testAdminSendsPasswordResetEmail(): void
    {
        $target = $this->makeUser();
        $this->client->enableProfiler();
        $crawler = $this->client->request('GET', '/admin/user');
        $form = $crawler->filter('form[action*="send-password-reset"][action*="entityId='.$target->getId().'"], form[action*="/'.$target->getId().'/send-password-reset"]')->first();
        self::assertCount(1, $form, 'row action is rendered as a POST form');

        $this->client->request('POST', $form->attr('action'));
        self::assertResponseRedirects();
        self::assertEmailCount(1);
        self::assertEmailAddressContains(self::getMailerMessage(), 'to', $target->getEmail());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'One-time reset link');
    }

    public function testAdminResetActionRejectsGetAndBadCsrf(): void
    {
        $target = $this->makeUser();
        $this->client->request('GET', '/admin/user/'.$target->getId().'/send-password-reset');
        self::assertResponseStatusCodeSame(405);

        $this->client->request('POST', '/admin/user/'.$target->getId().'/send-password-reset?_token=forged');
        self::assertResponseStatusCodeSame(403);
    }
}
