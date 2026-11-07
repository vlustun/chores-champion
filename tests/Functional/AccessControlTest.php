<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AccessControlTest extends WebTestCase
{
    use Fixtures;

    protected function setUp(): void
    {
        // login throttling state lives in the cache: start every test with a clean slate
        static::bootKernel();
        static::getContainer()->get('cache.app')->clear();
        static::ensureKernelShutdown();
    }

    /** @return iterable<string, array{string}> */
    public static function protectedPages(): iterable
    {
        foreach (['/tasks', '/tasks/new', '/my', '/week', '/profile', '/admin', '/admin/task', '/admin/user', '/admin/audit-log'] as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('protectedPages')]
    public function testAnonymousIsSentToLogin(string $url): void
    {
        $client = static::createClient();
        $client->request('GET', $url);

        self::assertResponseRedirects('http://localhost/login');
    }

    #[DataProvider('protectedPages')]
    public function testUserReachesOnlyNonAdminPages(string $url): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeUser());
        $client->request('GET', $url);

        if (str_starts_with($url, '/admin')) {
            self::assertResponseStatusCodeSame(403);
            self::assertSelectorTextContains('h1', 'Forbidden');
        } elseif ('/' === $url) {
            self::assertResponseRedirects('/tasks');
        } else {
            self::assertResponseIsSuccessful();
        }
    }

    #[DataProvider('protectedPages')]
    public function testAdminReachesEverything(string $url): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeUser(roles: ['ROLE_ADMIN']));
        $client->request('GET', $url);

        '/' === $url ? self::assertResponseRedirects('/tasks') : self::assertResponseIsSuccessful();
    }

    public function testLoginSucceedsAndRedirectsToTasks(): void
    {
        $client = static::createClient();
        $user = $this->makeLoginUser('s3cretpass');
        $client->request('GET', '/login');
        $client->submitForm('Log in', ['_username' => $user->getEmail(), '_password' => 's3cretpass']);

        self::assertResponseRedirects('/tasks');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testBadPasswordAndUnknownUserGiveTheSameGenericError(): void
    {
        $client = static::createClient();
        $user = $this->makeLoginUser('s3cretpass');

        $messages = [];
        foreach ([[$user->getEmail(), 'wrong'], ['nobody@example.test', 'whatever']] as [$email, $pass]) {
            $client->request('GET', '/login');
            $client->submitForm('Log in', ['_username' => $email, '_password' => $pass]);
            $client->followRedirect();
            $messages[] = $client->getCrawler()->filter('.flash-danger')->text();
        }

        self::assertSame($messages[0], $messages[1], 'no user enumeration');
        self::assertStringContainsString('Invalid credentials', $messages[0]);
    }

    public function testLoginWithoutCsrfTokenIsRejected(): void
    {
        $client = static::createClient();
        $user = $this->makeLoginUser('s3cretpass');
        $client->request('POST', '/login', ['_username' => $user->getEmail(), '_password' => 's3cretpass']);
        $client->followRedirect();

        self::assertSelectorExists('.flash-danger');
        self::assertNull(static::getContainer()->get('security.token_storage')->getToken()?->getUser() ?? null);
    }

    public function testDisabledAccountCannotLogIn(): void
    {
        $client = static::createClient();
        $user = $this->makeLoginUser('s3cretpass');
        $user->setIsActive(false);
        $this->em()->flush();

        $client->request('GET', '/login');
        $client->submitForm('Log in', ['_username' => $user->getEmail(), '_password' => 's3cretpass']);
        $client->followRedirect();

        self::assertSelectorTextContains('.flash-danger', 'disabled');
    }

    public function testLoginIsThrottledAfterRepeatedFailures(): void
    {
        $client = static::createClient();
        $client->disableReboot(); // keep the in-memory rate limiter state between requests
        $user = $this->makeLoginUser('s3cretpass');

        for ($i = 0; $i < 7; ++$i) {
            $client->request('GET', '/login');
            $client->submitForm('Log in', ['_username' => $user->getEmail(), '_password' => 'wrong'.$i]);
            $client->followRedirect();
        }
        // even the right password is refused now
        $client->request('GET', '/login');
        $client->submitForm('Log in', ['_username' => $user->getEmail(), '_password' => 's3cretpass']);
        $client->followRedirect();

        self::assertSelectorTextContains('.flash-danger', 'Too many failed login attempts');
    }

    public function testMetricsEndpointIsRestricted(): void
    {
        $client = static::createClient();

        $client->request('GET', '/metrics', server: ['REMOTE_ADDR' => '127.0.0.1']);
        self::assertResponseIsSuccessful(); // allow-listed network
        self::assertStringContainsString('text/plain', (string) $client->getResponse()->headers->get('Content-Type'));

        $client->request('GET', '/metrics', server: ['REMOTE_ADDR' => '203.0.113.50']);
        self::assertResponseRedirects('http://localhost/login'); // anonymous outsider

        $client->loginUser($this->makeUser());
        $client->request('GET', '/metrics', server: ['REMOTE_ADDR' => '203.0.113.50']);
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($this->makeUser(roles: ['ROLE_ADMIN']));
        $client->request('GET', '/metrics', server: ['REMOTE_ADDR' => '203.0.113.50']);
        self::assertResponseIsSuccessful();
    }
}
