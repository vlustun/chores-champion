<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\TaskUser;
use App\Entity\User;
use App\Service\Audit\AuditLogger;
use App\Service\PasswordReset\PasswordResetService;
use App\Service\Time\TimezoneResolver;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TelephoneField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;

#[IsGranted('ROLE_ADMIN')]
final class UserCrudController extends AbstractCrudController
{
    private const RESET_CSRF = 'admin_send_password_reset';

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PasswordResetService $resets,
        private readonly AuditLogger $audit,
        private readonly AdminUrlGenerator $adminUrls,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {
    }

    public function configureActions(Actions $actions): Actions
    {
        $send = Action::new('sendPasswordReset', 'Send password reset', 'fa fa-key')
            ->linkToUrl(fn (User $user): string => $this->adminUrls
                ->unsetAll()
                ->setController(self::class)
                ->setAction('sendPasswordReset')
                ->setEntityId($user->getId())
                ->set('_token', $this->csrf->getToken(self::RESET_CSRF)->getValue())
                ->generateUrl())
            ->renderAsForm()
            ->displayIf(static fn (User $user): bool => $user->isActive());

        return $actions
            ->add(Crud::PAGE_INDEX, $send)
            ->add(Crud::PAGE_EDIT, $send)
            ->add(Crud::PAGE_DETAIL, $send);
    }

    /** POST only (rendered as a form) and CSRF-checked: emails a reset link, or shows it once when mail is disabled. */
    #[AdminRoute(path: '/{entityId}/send-password-reset', name: 'send_password_reset', options: ['methods' => ['POST']])]
    public function sendPasswordReset(AdminContext $context, Request $request): RedirectResponse
    {
        if (!$this->csrf->isTokenValid(new CsrfToken(self::RESET_CSRF, (string) $request->query->get('_token')))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $user = $context->getEntity()->getInstance();
        \assert($user instanceof User);

        $back = $this->adminUrls->unsetAll()->setController(self::class)->setAction(Action::INDEX)->generateUrl();
        if (!$user->isActive()) {
            $this->addFlash('danger', 'This account is disabled.');

            return $this->redirect($back);
        }

        try {
            $link = $this->resets->send($user);
            $em = $this->container->get('doctrine')->getManager();
            $em->persist($this->audit->passwordEvent($user, AuditLogger::PASSWORD_RESET_SENT));
            $em->flush();
        } catch (ResetPasswordExceptionInterface $e) {
            $this->addFlash('warning', 'A reset link was requested for this user recently. Try again later.');

            return $this->redirect($back);
        } catch (\Symfony\Component\Mailer\Exception\TransportExceptionInterface) {
            $this->addFlash('danger', 'The email could not be sent. Check MAILER_DSN, or set the password directly via Edit.');

            return $this->redirect($back);
        }

        if ($this->resets->isMailDisabled()) {
            $this->addFlash('warning', \sprintf('Mail is not configured, so nothing was sent. One-time reset link for %s (valid 1 hour): %s', $user->getEmail(), $link));
        } else {
            $this->addFlash('success', \sprintf('Password reset email sent to %s.', $user->getEmail()));
        }

        return $this->redirect($back);
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('User')->setEntityLabelInPlural('Users')->setSearchFields(['name', 'email'])->setDefaultSort(['name' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('name');
        yield EmailField::new('email');
        yield TelephoneField::new('phoneNr', 'Phone')->setRequired(false);
        yield ChoiceField::new('timezone')->setChoices(array_combine(TimezoneResolver::all(), TimezoneResolver::all()));
        yield ChoiceField::new('roles')->setChoices(['Administrator' => 'ROLE_ADMIN'])->allowMultipleChoices()->renderExpanded()->setHelp('Every user is a regular user; tick to also make an administrator.');
        yield TextField::new('plainPassword', 'Password')
            ->setFormType(PasswordType::class)
            ->setFormTypeOptions(['required' => Crud::PAGE_NEW === $pageName, 'attr' => ['autocomplete' => 'new-password']])
            ->setHelp(Crud::PAGE_EDIT === $pageName ? 'Leave empty to keep the current password.' : 'Minimum 8 characters.')
            ->onlyOnForms();
        yield BooleanField::new('isActive', 'Active')->setHelp('Disabled users cannot log in. Users are never deleted: they have history.');
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        \assert($entityInstance instanceof User);
        if (null === $entityInstance->getPlainPassword()) {
            $this->addFlash('danger', 'A password is required for new users.');

            return;
        }
        $this->hashPassword($entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        \assert($entityInstance instanceof User);
        $current = $this->getUser();
        // lock-out protection: admins cannot demote or disable themselves
        if ($current instanceof User && $current->getId() === $entityInstance->getId() && (!$entityInstance->isAdmin() || !$entityInstance->isActive())) {
            $entityManager->refresh($entityInstance);
            $this->addFlash('danger', 'You cannot remove your own admin rights or disable your own account.');

            return;
        }
        if (null !== $entityInstance->getPlainPassword()) {
            $this->hashPassword($entityInstance);
        }
        $entityInstance->setEmail($entityInstance->getEmail());
        parent::updateEntity($entityManager, $entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        \assert($entityInstance instanceof User);
        $current = $this->getUser();
        if ($current instanceof User && $current->getId() === $entityInstance->getId()) {
            $this->addFlash('danger', 'You cannot delete yourself.');

            return;
        }
        if ($entityManager->getRepository(TaskUser::class)->count(['user' => $entityInstance]) > 0
            || $entityManager->getRepository(\App\Entity\Task::class)->count(['createdBy' => $entityInstance]) > 0) {
            $entityInstance->setIsActive(false);
            $entityManager->flush();
            $this->addFlash('warning', 'This user has history, so the account was disabled instead of deleted.');

            return;
        }
        parent::deleteEntity($entityManager, $entityInstance);
    }

    private function hashPassword(User $user): void
    {
        $plain = $user->getPlainPassword();
        if (null !== $plain) {
            $user->setPassword($this->hasher->hashPassword($user, $plain));
            $user->eraseCredentials();
        }
    }
}
