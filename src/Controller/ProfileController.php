<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\InvalidCsrfToken;
use App\Exception\InvalidTimezone;
use App\Form\ChangePasswordType;
use App\Form\ProfileType;
use App\Service\Audit\AuditLogger;
use App\Service\Time\TimezoneResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ProfileController extends AbstractController
{
    #[Route('/profile', name: 'app_profile', methods: ['GET', 'POST'])]
    public function profile(Request $request, #[CurrentUser] User $user, EntityManagerInterface $em, TimezoneResolver $tz): Response
    {
        $form = $this->createForm(ProfileType::class, $user);
        $form->get('timezoneAuto')->setData(!$user->isTimezoneManual());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $auto = (bool) $form->get('timezoneAuto')->getData();
            $user->setTimezone($tz->normalize($user->getTimezone()))->setTimezoneManual(!$auto);
            $em->flush();
            $this->addFlash('success', 'Profile saved.');

            return $this->redirectToRoute('app_profile');
        }

        return $this->render('profile/index.html.twig', [
            'form' => $form,
            'user' => $user,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/profile/password', name: 'app_profile_password', methods: ['GET', 'POST'])]
    public function password(Request $request, #[CurrentUser] User $user, EntityManagerInterface $em, UserPasswordHasherInterface $hasher, AuditLogger $audit): Response
    {
        $form = $this->createForm(ChangePasswordType::class, null, ['require_current_password' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($hasher->hashPassword($user, (string) $form->get('plainPassword')->getData()));
            $em->persist($audit->passwordEvent($user, AuditLogger::PASSWORD_CHANGED, $user));
            $em->flush();
            $this->addFlash('success', 'Your password was changed.');

            return $this->redirectToRoute('app_profile');
        }

        return $this->render('profile/password.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** Receives the browser-detected IANA timezone (see assets/controllers/timezone_controller.js). */
    #[Route('/profile/timezone', name: 'app_profile_timezone', methods: ['POST'])]
    public function detectedTimezone(Request $request, #[CurrentUser] User $user, TimezoneResolver $resolver): JsonResponse
    {
        try {
            $payload = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidTimezone('Request body must be valid JSON.');
        }
        if (!\is_array($payload)) {
            throw new InvalidTimezone('Request body must be a JSON object.');
        }
        $token = $payload['_token'] ?? null;
        if (!\is_string($token) || !$this->isCsrfTokenValid('detect_timezone', $token)) {
            throw new InvalidCsrfToken();
        }
        $detected = $payload['timezone'] ?? null;
        if (!\is_string($detected)) {
            throw new InvalidTimezone('"timezone" must be a string.');
        }

        $changed = $resolver->applyDetected($user, $detected);

        return new JsonResponse(['changed' => $changed, 'timezone' => $user->getTimezone(), 'manual' => $user->isTimezoneManual()]);
    }
}
