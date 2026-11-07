<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/** Browsers get redirected to the login form; API / XHR clients get a 401 problem+json. */
final class AuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(private readonly UrlGeneratorInterface $urls)
    {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $accept = $request->headers->get('Accept', '');
        $json = str_starts_with($request->getPathInfo(), '/api/')
            || $request->isXmlHttpRequest()
            || 'json' === $request->getContentTypeFormat()
            || (str_contains($accept, 'json') && !str_contains($accept, 'text/html'));

        if (!$json) {
            return new RedirectResponse($this->urls->generate('app_login'));
        }

        $requestId = $request->attributes->get('request_id');
        $response = new JsonResponse([
            'type' => 'about:blank#unauthenticated',
            'title' => 'Unauthorized',
            'status' => 401,
            'detail' => 'Authentication is required.',
            'code' => 'unauthenticated',
            'instance' => $request->getPathInfo(),
            'request_id' => \is_string($requestId) ? $requestId : null,
        ], 401, ['WWW-Authenticate' => 'Bearer realm="chores"']);
        $response->headers->set('Content-Type', 'application/problem+json');

        return $response;
    }
}
