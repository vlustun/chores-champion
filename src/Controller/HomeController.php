<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Public landing page. Signed-in users go straight to their task list. */
final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_tasks');
        }

        return $this->render('home/index.html.twig');
    }
}
