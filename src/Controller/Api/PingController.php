<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Placeholder for the deferred REST API (/api/v1, see docs/DECISIONS.md).
 * Requires authentication via the stateless `api` firewall (no authenticator is configured yet => 401).
 * Real controllers must stay thin and call the services in App\Service.
 */
#[Route('/api/v1')]
final class PingController
{
    #[Route('/ping', name: 'api_v1_ping', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok', 'version' => 'v1']);
    }
}
