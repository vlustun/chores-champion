<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\EventSubscriber\ExceptionSubscriber;
use App\Exception\ForbiddenAction;
use App\Exception\InvalidStatusTransition;
use App\Exception\ValidationFailed;
use App\Tests\Support\Fixtures;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

final class ErrorHandlingTest extends WebTestCase
{
    use Fixtures;

    public function testUnknownUrlRendersFriendlyHtml404WithoutLeakingInternals(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeUser());
        $client->request('GET', '/definitely/not/here');

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'Not Found');
        self::assertStringNotContainsString('Symfony', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('vendor/', (string) $client->getResponse()->getContent());
    }

    public function testMethodNotAllowed(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeUser());
        $client->request('GET', '/my/1/start');
        self::assertResponseStatusCodeSame(405);
    }

    public function testJsonClientsGetProblemJsonWithRequestId(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeUser());
        $client->request('GET', '/nope', server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUEST_ID' => 'abc-12345678']);

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('application/problem+json', $client->getResponse()->headers->get('Content-Type'));
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(404, $body['status']);
        self::assertSame('abc-12345678', $body['request_id']);
        self::assertSame('abc-12345678', $client->getResponse()->headers->get('X-Request-Id'));
    }

    public function testMaliciousRequestIdIsReplaced(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login', server: ['HTTP_X_REQUEST_ID' => "evil\nINJECT {\"a\":1}"]);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $client->getResponse()->headers->get('X-Request-Id'));
    }

    public function testApiNamespaceAnswers401ProblemJsonWhenAnonymous(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/ping');

        self::assertResponseStatusCodeSame(401);
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('unauthenticated', $body['code']);
        self::assertNotNull($client->getResponse()->headers->get('WWW-Authenticate'));
    }

    public function testApiNamespaceUnknownRouteIs404Json(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/tasks');
        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('problem+json', $client->getResponse()->headers->get('Content-Type'));
    }

    /** @return iterable<string, array{\Throwable, int, string}> */
    public static function mapping(): iterable
    {
        yield 'domain 409' => [new InvalidStatusTransition('nope'), 409, 'invalid_status_transition'];
        yield 'domain 403' => [new ForbiddenAction(), 403, 'forbidden_action'];
        yield 'deadlock after retries' => [new DeadlockException(\Doctrine\DBAL\Driver\PDO\Exception::new(new \PDOException('deadlock')), null), 503, 'busy'];
        yield 'db down' => [new ConnectionLost(\Doctrine\DBAL\Driver\PDO\Exception::new(new \PDOException('gone away')), null), 503, 'database_unavailable'];
        yield 'unexpected' => [new \RuntimeException('SECRET internal detail: password=hunter2 at /var/www/x.php'), 500, 'internal_error'];
        yield 'type error' => [new \TypeError('Argument #1 must be of type int'), 500, 'internal_error'];
    }

    #[DataProvider('mapping')]
    public function testExceptionMapping(\Throwable $e, int $status, string $code): void
    {
        self::bootKernel();
        $subscriber = new ExceptionSubscriber(
            static::getContainer()->get('twig'),
            new \Psr\Log\NullLogger(),
            new \Psr\Log\NullLogger(),
            new \App\Metrics\MetricsCollector(new \Prometheus\Storage\InMemory()),
            false,
        );

        foreach ([['text/html', false], ['application/json', true]] as [$accept, $json]) {
            $request = Request::create('/x', server: ['HTTP_ACCEPT' => $accept]);
            $request->attributes->set('request_id', 'rid-12345678');
            $event = new ExceptionEvent(static::$kernel, $request, HttpKernelInterface::MAIN_REQUEST, $e);
            $subscriber->onException($event);

            $response = $event->getResponse();
            self::assertSame($status, $response->getStatusCode());
            self::assertStringNotContainsString('SECRET', (string) $response->getContent());
            self::assertStringNotContainsString('hunter2', (string) $response->getContent());
            self::assertStringNotContainsString('Argument #1', (string) $response->getContent());
            self::assertStringContainsString('rid-12345678', (string) $response->getContent());
            if ($json) {
                self::assertSame($code, json_decode($response->getContent(), true)['code']);
            }
        }
    }

    public function testRetryAfterHeaderOnBusy(): void
    {
        self::bootKernel();
        $subscriber = new ExceptionSubscriber(static::getContainer()->get('twig'), new \Psr\Log\NullLogger(), new \Psr\Log\NullLogger(), new \App\Metrics\MetricsCollector(new \Prometheus\Storage\InMemory()));
        $event = new ExceptionEvent(static::$kernel, Request::create('/x'), HttpKernelInterface::MAIN_REQUEST, new DeadlockException(\Doctrine\DBAL\Driver\PDO\Exception::new(new \PDOException('d')), null));
        $subscriber->onException($event);
        self::assertSame('2', $event->getResponse()->headers->get('Retry-After'));
    }

    public function testValidationFailedExposesFieldErrors(): void
    {
        self::bootKernel();
        $subscriber = new ExceptionSubscriber(static::getContainer()->get('twig'), new \Psr\Log\NullLogger(), new \Psr\Log\NullLogger(), new \App\Metrics\MetricsCollector(new \Prometheus\Storage\InMemory()));
        $violations = new ConstraintViolationList([new ConstraintViolation('Too short', null, [], null, 'title', 'x')]);
        $request = Request::create('/api/v1/tasks', 'POST');
        $event = new ExceptionEvent(static::$kernel, $request, HttpKernelInterface::MAIN_REQUEST, ValidationFailed::fromViolations($violations));
        $subscriber->onException($event);

        self::assertSame(422, $event->getResponse()->getStatusCode());
        self::assertSame(['title' => ['Too short']], json_decode($event->getResponse()->getContent(), true)['errors']);
    }

    public function testErrorsAndRequestsAreCountedInMetrics(): void
    {
        $client = static::createClient();
        $client->disableReboot(); // InMemory metrics storage lives in the container
        $client->loginUser($this->makeUser());
        $client->request('GET', '/does-not-exist');
        $client->request('GET', '/tasks');

        $client->loginUser($this->makeUser(roles: ['ROLE_ADMIN']));
        $client->request('GET', '/metrics');
        $text = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('chores_errors_total{kind="404"}', $text);
        self::assertStringContainsString('chores_http_requests_total{route="app_tasks",method="GET",status="200"}', $text);
        self::assertStringContainsString('chores_http_request_duration_seconds_bucket', $text);
    }
}
