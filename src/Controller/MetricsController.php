<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Metrics\MetricsCollector;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Prometheus text exposition. Admins, or scrapers from an allow-listed network (APP_METRICS_ALLOWED_CIDRS). */
final class MetricsController extends AbstractController
{
    /** @param string $allowedCidrs comma separated */
    public function __construct(
        private readonly MetricsCollector $metrics,
        #[Autowire('%env(APP_METRICS_ALLOWED_CIDRS)%')]
        private readonly string $allowedCidrs,
    ) {
    }

    #[Route('/metrics', name: 'metrics', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        $isAdmin = $user instanceof User && $user->isAdmin();
        $cidrs = array_values(array_filter(array_map('trim', explode(',', $this->allowedCidrs))));
        $internal = null !== $request->getClientIp() && [] !== $cidrs && IpUtils::checkIp($request->getClientIp(), $cidrs);

        if (!$isAdmin && !$internal) {
            throw $this->createAccessDeniedException('Metrics are restricted.');
        }

        return new Response($this->metrics->render(), 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']);
    }
}
