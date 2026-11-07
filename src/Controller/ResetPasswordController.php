<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordType;
use App\Form\ResetPasswordRequestType;
use App\Repository\UserRepository;
use App\Service\Audit\AuditLogger;
use App\Service\PasswordReset\PasswordResetService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/** Public "forgot password" flow. Never reveals whether an email belongs to an account. */
final class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private readonly ResetPasswordHelperInterface $helper,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/forgot-password', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function request(Request $request, UserRepository $users, PasswordResetService $resets): Response
    {
        $form = $this->createForm(ResetPasswordRequestType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $users->findOneByEmail((string) $form->get('email')->getData());
            if ($user instanceof User && $user->isActive()) {
                try {
                    $resets->send($user);
                    $this->em->persist($this->audit->passwordEvent($user, AuditLogger::PASSWORD_RESET_REQUESTED));
                    $this->em->flush();
                } catch (ResetPasswordExceptionInterface $e) {
                    // throttled or failed: stay silent towards the visitor, otherwise account existence leaks
                    $this->logger->info('Password reset not sent', ['reason' => $e::class, 'user_id' => $user->getId()]);
                } catch (\Symfony\Component\Mailer\Exception\TransportExceptionInterface $e) {
                    $this->logger->error('Password reset email failed', ['exception' => $e, 'user_id' => $user->getId()]);
                }
            }

            return $this->redirectToRoute('app_check_email');
        }

        return $this->render('reset_password/request.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/forgot-password/check-email', name: 'app_check_email', methods: ['GET'])]
    public function checkEmail(): Response
    {
        return $this->render('reset_password/check_email.html.twig', ['lifetimeMinutes' => intdiv($this->helper->getTokenLifetime(), 60)]);
    }

    /** The emailed link: the token is moved into the session and the URL is cleaned, so it cannot leak via Referer. */
    #[Route('/reset-password/reset/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function reset(Request $request, UserPasswordHasherInterface $hasher, ?string $token = null): Response
    {
        if (null !== $token) {
            $this->storeTokenInSession($token);

            return $this->redirectToRoute('app_reset_password');
        }

        $token = $this->getTokenFromSession();
        if (null === $token) {
            throw $this->createNotFoundException('No reset password token found in the URL or in the session.');
        }

        try {
            /** @var User $user */
            $user = $this->helper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface) {
            $this->cleanSessionAfterReset();
            $this->addFlash('danger', 'This reset link is invalid or has expired. Please request a new one.');

            return $this->redirectToRoute('app_forgot_password');
        }
        if (!$user->isActive()) {
            $this->helper->removeResetRequest($token);
            $this->cleanSessionAfterReset();
            $this->addFlash('danger', 'This reset link is invalid or has expired. Please request a new one.');

            return $this->redirectToRoute('app_forgot_password');
        }

        $form = $this->createForm(ChangePasswordType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->helper->removeResetRequest($token);
            $user->setPassword($hasher->hashPassword($user, (string) $form->get('plainPassword')->getData()));
            $this->em->persist($this->audit->passwordEvent($user, AuditLogger::PASSWORD_RESET, $user));
            $this->em->flush();
            $this->cleanSessionAfterReset();
            $this->addFlash('success', 'Your password was changed. You can log in now.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('reset_password/reset.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
