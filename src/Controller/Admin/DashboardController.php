<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AuditLog;
use App\Entity\Task;
use App\Entity\TaskUser;
use App\Entity\User;
use App\Enum\TaskStatus;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
#[IsGranted('ROLE_ADMIN')]
final class DashboardController extends AbstractDashboardController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function index(): Response
    {
        $open = $this->em->getRepository(TaskUser::class)->count(['status' => TaskStatus::openValues()]);

        return $this->render('admin/dashboard.html.twig', [
            'tasks' => $this->em->getRepository(Task::class)->count(['isActive' => true]),
            'users' => $this->em->getRepository(User::class)->count(['isActive' => true]),
            'open_claims' => $open,
            'audit_rows' => $this->em->getRepository(AuditLog::class)->count([]),
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()->setTitle('Chores Champion · Admin');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');
        yield MenuItem::section('Chores');
        yield MenuItem::linkTo(TaskCrudController::class, 'Tasks', 'fa fa-list-check');
        yield MenuItem::linkTo(TaskTypeCrudController::class, 'Task types', 'fa fa-tags');
        yield MenuItem::section('People');
        yield MenuItem::linkTo(UserCrudController::class, 'Users', 'fa fa-users');
        yield MenuItem::section('Compliance');
        yield MenuItem::linkTo(AuditLogCrudController::class, 'Audit log', 'fa fa-clipboard-list');
        yield MenuItem::section('');
        yield MenuItem::linkToUrl('Metrics', 'fa fa-chart-line', '/metrics');
        yield MenuItem::linkToRoute('Back to app', 'fa fa-arrow-left', 'app_tasks');
    }
}
