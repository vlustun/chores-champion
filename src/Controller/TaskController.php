<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Task;
use App\Entity\User;
use App\Exception\AppException;
use App\Exception\InvalidCsrfToken;
use App\Exception\InvalidParentTask;
use App\Form\UserTaskType;
use App\Repository\TaskRepository;
use App\Repository\TaskTypeRepository;
use App\Repository\TaskUserRepository;
use App\Service\TaskWorkflowService;
use App\Service\Time\UserClock;
use App\Service\WeeklySummaryService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Thin controller: parsing + CSRF + rendering. All rules live in the services. */
#[IsGranted('ROLE_USER')]
final class TaskController extends AbstractController
{
    #[Route('/tasks', name: 'app_tasks', methods: ['GET'])]
    public function index(Request $request, TaskRepository $tasks, TaskTypeRepository $types, TaskUserRepository $taskUsers, #[CurrentUser] User $user): Response
    {
        $typeId = $this->idOrNull($request->query->get('type'));

        return $this->render('task/index.html.twig', [
            'tasks' => $tasks->findAvailable($typeId),
            'types' => $types->findActive(),
            'selected_type' => $typeId,
            'open_claims' => $taskUsers->findOpenForUser($user),
        ]);
    }

    #[Route('/tasks/new', name: 'app_task_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, #[CurrentUser] User $user, LoggerInterface $logger): Response
    {
        $task = (new Task())->setCreatedBy($user);
        $form = $this->createForm(UserTaskType::class, $task, ['is_admin' => $user->isAdmin()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($task);
            $em->flush();
            $logger->info('Task created by user', ['task_id' => $task->getId(), 'user_id' => $user->getId()]);
            $this->addFlash('success', 'Task added.');

            return $this->redirectToRoute('app_tasks');
        }

        return $this->render('task/new.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/tasks/{id}/claim', name: 'app_task_claim', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function claim(int $id, Request $request, TaskWorkflowService $workflow, #[CurrentUser] User $user): Response
    {
        $this->checkCsrf($request, 'claim'.$id);
        $rawDeadline = $request->request->get('deadline');
        $deadline = \is_string($rawDeadline) && '' !== trim($rawDeadline) ? $rawDeadline : null;
        $parent = $this->idOrNull($request->request->get('parent'));

        return $this->act(static fn () => $workflow->claim((int) $user->getId(), $id, $deadline, $parent), 'Task claimed. Good luck!', 'app_my_tasks', 'app_tasks');
    }

    #[Route('/my', name: 'app_my_tasks', methods: ['GET'])]
    public function mine(TaskUserRepository $taskUsers, #[CurrentUser] User $user): Response
    {
        return $this->render('task/mine.html.twig', [
            'open' => $taskUsers->findOpenForUser($user),
            'recent' => $taskUsers->findRecentForUser($user, 15),
        ]);
    }

    #[Route('/my/{id}/start', name: 'app_task_start', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function start(int $id, Request $request, TaskWorkflowService $workflow, #[CurrentUser] User $user): Response
    {
        $this->checkCsrf($request, 'tu'.$id);

        return $this->act(static fn () => $workflow->start((int) $user->getId(), $id), 'Started.', 'app_my_tasks', 'app_my_tasks');
    }

    #[Route('/my/{id}/complete', name: 'app_task_complete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function complete(int $id, Request $request, TaskWorkflowService $workflow, #[CurrentUser] User $user): Response
    {
        $this->checkCsrf($request, 'tu'.$id);

        return $this->act(function () use ($workflow, $user, $id): void {
            $tu = $workflow->complete((int) $user->getId(), $id);
            $this->addFlash('success', \sprintf('Done! You earned %d points.', $tu->getPointsGained()));
        }, null, 'app_my_tasks', 'app_my_tasks');
    }

    #[Route('/my/{id}/cancel', name: 'app_task_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(int $id, Request $request, TaskWorkflowService $workflow, #[CurrentUser] User $user): Response
    {
        $this->checkCsrf($request, 'tu'.$id);

        return $this->act(static fn () => $workflow->cancel((int) $user->getId(), $id), 'Task cancelled.', 'app_my_tasks', 'app_my_tasks');
    }

    #[Route('/week', name: 'app_week', methods: ['GET'])]
    public function week(Request $request, WeeklySummaryService $summary, UserClock $clock, #[CurrentUser] User $user): Response
    {
        $current = $summary->currentWeek($user);
        $week = $request->query->get('week');
        $key = \is_string($week) && '' !== $week ? $week : $current['weekKey'];
        try {
            $board = $summary->leaderboard($key);
        } catch (AppException) {
            $this->addFlash('warning', 'Unknown week; showing the current one.');

            return $this->redirectToRoute('app_week');
        }

        return $this->render('task/week.html.twig', ['current' => $current, 'board' => $board, 'week_key' => $key]);
    }

    /** Empty/absent => null; digits => int; anything else is a client error (never a 500). */
    private function idOrNull(mixed $raw): ?int
    {
        if (null === $raw || '' === $raw) {
            return null;
        }
        if (!\is_string($raw) || 1 !== preg_match('/^[1-9]\d{0,9}$/', $raw)) {
            throw new InvalidParentTask('Invalid selection.');
        }

        return (int) $raw;
    }

    private function checkCsrf(Request $request, string $id): void
    {
        $rawToken = $request->request->get('_token');
        $token = \is_string($rawToken) ? $rawToken : '';
        if (!$this->isCsrfTokenValid($id, $token)) {
            throw new InvalidCsrfToken(context: ['id' => $id]);
        }
    }

    /** Run a workflow action; domain errors become flash messages instead of error pages. */
    private function act(callable $action, ?string $success, string $okRoute, string $failRoute): Response
    {
        try {
            $action();
        } catch (AppException $e) {
            if ($e->getHttpStatus() >= 500) {
                throw $e;
            }
            // 404/403 for foreign resources must stay real status codes
            if (\in_array($e->getHttpStatus(), [403, 404], true)) {
                throw $e;
            }
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute($failRoute);
        }
        if (null !== $success) {
            $this->addFlash('success', $success);
        }

        return $this->redirectToRoute($okRoute);
    }
}
