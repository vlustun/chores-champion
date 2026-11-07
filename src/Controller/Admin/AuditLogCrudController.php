<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AuditLog;
use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Read-only: audit rows can neither be created, edited nor deleted from the UI. */
#[IsGranted('ROLE_ADMIN')]
final class AuditLogCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return AuditLog::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Audit entry')
            ->setEntityLabelInPlural('Audit log')
            ->setDefaultSort(['id' => 'DESC'])
            ->setSearchFields(['entityType', 'entityId', 'requestId']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('actor'))
            ->add(TextFilter::new('entityType'))
            ->add(ChoiceFilter::new('action')->setChoices(['Create' => AuditLog::CREATE, 'Update' => AuditLog::UPDATE, 'Delete' => AuditLog::DELETE]))
            ->add(DateTimeFilter::new('createdAt'));
    }

    public function configureFields(string $pageName): iterable
    {
        $tz = $this->getUser() instanceof User ? $this->getUser()->getTimezone() : 'UTC';

        yield IdField::new('id');
        yield DateTimeField::new('createdAt', 'When')->setTimezone($tz)->setFormat('medium', 'medium');
        yield AssociationField::new('actor');
        yield TextField::new('entityType', 'Entity');
        yield TextField::new('entityId', 'Entity id');
        yield ChoiceField::new('action')->setChoices(['Create' => AuditLog::CREATE, 'Update' => AuditLog::UPDATE, 'Delete' => AuditLog::DELETE])
            ->renderAsBadges([AuditLog::CREATE => 'success', AuditLog::UPDATE => 'info', AuditLog::DELETE => 'danger']);
        yield ArrayField::new('changes')->onlyOnDetail();
        yield TextField::new('ip', 'IP')->hideOnIndex();
        yield TextField::new('requestId', 'Request id')->hideOnIndex();
    }
}
