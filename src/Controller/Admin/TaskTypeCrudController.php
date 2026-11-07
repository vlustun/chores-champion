<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Task;
use App\Entity\TaskType;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Task types are data, not code: admins add new ones at runtime. */
#[IsGranted('ROLE_ADMIN')]
final class TaskTypeCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return TaskType::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Task type')->setEntityLabelInPlural('Task types')->setDefaultSort(['name' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('name');
        yield TextField::new('slug')->setHelp('Lowercase, dashes. Leave empty to generate from the name.')->setRequired(false);
        yield BooleanField::new('isActive', 'Active');
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        \assert($entityInstance instanceof TaskType);
        if ($entityManager->getRepository(Task::class)->count(['type' => $entityInstance]) > 0) {
            $entityInstance->setIsActive(false);
            $entityManager->flush();
            $this->addFlash('warning', 'This type is used by tasks, so it was deactivated instead of deleted.');

            return;
        }
        parent::deleteEntity($entityManager, $entityInstance);
    }
}
