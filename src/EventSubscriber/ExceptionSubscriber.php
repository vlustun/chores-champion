<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Exception\AppException;
use App\Exception\InvalidCsrfToken;
use App\Exception\ValidationFailed;
use App\Metrics\MetricsCollector;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Twig\Environment;

/**
 * One place that turns every Throwable into a safe response:
 *  - /api/* and JSON-ish requests => RFC 7807 application/problem+json
 *  - web => friendly Twig page
 *  - unexpected errors never leak internals (message/trace stay in the logs, correlated by request id)
 */
final class ExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'monolog.logger.security')]
        private readonly LoggerInterface $securityLogger,
        private readonly MetricsCollector $metrics,
        #[Autowire('%app.debug_errors%')]
        private readonly bool $debugErrors = false,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 0]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();
        $request = $event->getRequest();
        $problem = $this->classify($e);

        // Developers get Symfony's rich error page for genuinely unexpected failures in debug mode.
        if ($this->debugErrors && 500 === $problem['status'] && !$e instanceof AppException && !$this->wantsJson($request)) {
            return;
        }

        $requestId = $request->attributes->get('request_id');
        $problem['request_id'] = \is_string($requestId) ? $requestId : null;
        $this->log($e, $problem);
        $this->metrics->error((string) $problem['status']);

        $event->setResponse($this->wantsJson($request) ? $this->json($problem, $request) : $this->html($problem));
    }

    /** @return array{status: int, code: string, title: string, detail: string, errors?: array<string, list<string>>, headers: array<string, string>, request_id?: ?string} */
    private function classify(\Throwable $e): array
    {
        if ($e instanceof AppException) {
            $p = ['status' => $e->getHttpStatus(), 'code' => $e->getErrorCode(), 'title' => Response::$statusTexts[$e->getHttpStatus()] ?? 'Error', 'detail' => $e->getMessage(), 'headers' => []];
            if ($e instanceof ValidationFailed) {
                $p['errors'] = $e->getViolations();
            }

            return $p;
        }
        if ($e instanceof ValidationFailedException) {
            $errors = [];
            foreach ($e->getViolations() as $v) {
                $errors[$v->getPropertyPath() ?: '_'][] = (string) $v->getMessage();
            }

            return ['status' => 422, 'code' => 'validation_failed', 'title' => 'Unprocessable Entity', 'detail' => 'Validation failed.', 'errors' => $errors, 'headers' => []];
        }
        if ($e instanceof AccessDeniedException) {
            return ['status' => 403, 'code' => 'access_denied', 'title' => 'Forbidden', 'detail' => 'You do not have access to this.', 'headers' => []];
        }
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $detail = match (true) {
                404 === $status => 'We could not find what you were looking for.',
                403 === $status => 'You do not have access to this.',
                405 === $status => 'This method is not allowed here.',
                429 === $status => 'Too many requests. Please slow down.',
                $status < 500 => 'The request could not be processed.',
                default => 'Something went wrong on our side.',
            };

            return ['status' => $status, 'code' => 'http_'.$status, 'title' => Response::$statusTexts[$status] ?? 'Error', 'detail' => $detail, 'headers' => $e->getHeaders()];
        }
        if ($e instanceof RetryableException) { // deadlock / lock wait that survived the retries
            return ['status' => 503, 'code' => 'busy', 'title' => 'Service Unavailable', 'detail' => 'The system is busy. Please try again in a moment.', 'headers' => ['Retry-After' => '2']];
        }
        if ($e instanceof ConnectionException) {
            return ['status' => 503, 'code' => 'database_unavailable', 'title' => 'Service Unavailable', 'detail' => 'The service is temporarily unavailable.', 'headers' => ['Retry-After' => '10']];
        }
        if ($e instanceof UniqueConstraintViolationException) {
            return ['status' => 409, 'code' => 'conflict', 'title' => 'Conflict', 'detail' => 'That conflicts with something that already exists.', 'headers' => []];
        }

        return ['status' => 500, 'code' => 'internal_error', 'title' => 'Internal Server Error', 'detail' => 'Something went wrong on our side. Please try again later.', 'headers' => []];
    }

    /** @param array<string, mixed> $problem */
    private function log(\Throwable $e, array $problem): void
    {
        $status = $problem['status'];
        $context = [
            'status' => $status,
            'code' => $problem['code'],
            'exception' => $status >= 500 ? $e : $e::class,
            'detail' => $e instanceof AppException ? $e->getContext() : null,
        ];
        if ($e instanceof InvalidCsrfToken || 403 === $status || 401 === $status) {
            $this->securityLogger->warning('Access refused: '.$problem['code'], $context);
        } elseif ($status >= 500) {
            $this->logger->error('Unhandled error: '.$e->getMessage(), $context);
        } elseif (404 === $status) {
            $this->logger->info('Not found', $context);
        } else {
            $this->logger->notice('Request rejected: '.$problem['code'], $context);
        }
    }

    private function wantsJson(Request $request): bool
    {
        if (str_starts_with($request->getPathInfo(), '/api/')) {
            return true;
        }
        if ($request->isXmlHttpRequest() || 'json' === $request->getContentTypeFormat()) {
            return true;
        }
        $accept = $request->headers->get('Accept', '');

        return str_contains($accept, 'json') && !str_contains($accept, 'text/html');
    }

    /** @param array<string, mixed> $p */
    private function json(array $p, Request $request): JsonResponse
    {
        $body = [
            'type' => 'about:blank#'.$p['code'],
            'title' => $p['title'],
            'status' => $p['status'],
            'detail' => $p['detail'],
            'code' => $p['code'],
            'instance' => $request->getPathInfo(),
            'request_id' => $p['request_id'],
        ];
        if (isset($p['errors'])) {
            $body['errors'] = $p['errors'];
        }
        $response = new JsonResponse($body, $p['status'], $p['headers'] ?? []);
        $response->headers->set('Content-Type', 'application/problem+json');

        return $response;
    }

    /** @param array<string, mixed> $p */
    private function html(array $p): Response
    {
        try {
            $content = $this->twig->render('error/error.html.twig', ['problem' => $p]);
        } catch (\Throwable $renderError) {
            $this->logger->error('Error page failed to render', ['exception' => $renderError]);
            $content = \sprintf('<h1>%d %s</h1><p>%s</p>', $p['status'], htmlspecialchars((string) $p['title']), htmlspecialchars((string) $p['detail']));
        }

        return new Response($content, $p['status'], $p['headers'] ?? []);
    }
}
