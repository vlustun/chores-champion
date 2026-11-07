<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Task;
use App\Entity\TaskUser;
use App\Entity\User;
use App\Enum\RecurrenceKind;
use App\Enum\TaskPriority;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class TaskCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Task::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Task')
            ->setEntityLabelInPlural('Tasks')
            ->setDefaultSort(['id' => 'DESC'])
            ->setSearchFields(['title', 'description']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('type'))
            ->add(ChoiceFilter::new('priority')->setChoices(array_column(array_map(
                static fn (TaskPriority $p) => [$p->label(), $p->value],
                TaskPriority::cases(),
            ), 1, 0)))
            ->add(BooleanFilter::new('isActive'));
    }

    public function configureFields(string $pageName): iterable
    {
        $tz = $this->getUser() instanceof User ? $this->getUser()->getTimezone() : 'UTC';

        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('title');
        yield TextareaField::new('description')->hideOnIndex();
        yield AssociationField::new('type');
        yield ChoiceField::new('priority')
            ->setChoices(array_column(array_map(static fn (TaskPriority $p) => [$p->label(), $p], TaskPriority::cases()), 1, 0))
            ->renderAsBadges([
                TaskPriority::Low->value => 'secondary',
                TaskPriority::Medium->value => 'info',
                TaskPriority::High->value => 'warning',
                TaskPriority::Urgent->value => 'danger',
            ]);
        yield IntegerField::new('rewardValue', 'Reward value')->setHelp('Base points (0–'.Task::MAX_REWARD.'); empty = default.');
        yield ChoiceField::new('recurrenceKind', 'Repeats')
            ->setChoices(array_column(array_map(static fn (RecurrenceKind $k) => [$k->label(), $k], RecurrenceKind::cases()), 1, 0))
            ->setHelp('One off: once. Reusable: can be picked again. Recurring: scheduling mechanics come later.');
        yield BooleanField::new('isActive', 'Available');
        yield AssociationField::new('createdBy')->hideOnForm();
        yield DateTimeField::new('createdAt')->hideOnForm()->setTimezone($tz)->hideOnIndex();
        yield DateTimeField::new('updatedAt')->hideOnForm()->setTimezone($tz)->hideOnIndex();
    }

    public function createEntity(string $entityFqcn): Task
    {
        $task = new Task();
        $user = $this->getUser();
        if ($user instanceof User) {
            $task->setCreatedBy($user);
        }

        return $task;
    }

    /**
     * Tasks with history are soft-deleted (deactivated): claims reference them (FK RESTRICT).
     */
    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        \assert($entityInstance instanceof Task);
        if ($entityManager->getRepository(TaskUser::class)->count(['task' => $entityInstance]) > 0) {
            $entityInstance->setIsActive(false);
            $entityManager->flush();
            $this->addFlash('warning', 'This task has history, so it was deactivated instead of deleted.');

            return;
        }
        parent::deleteEntity($entityManager, $entityInstance);
    }
}
