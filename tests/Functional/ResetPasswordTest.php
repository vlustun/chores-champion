<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AuditLog;
use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use App\Tests\Support\Fixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

final class ResetPasswordTest extends WebTestCase
{
    use Fixtures;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    private function token(User $user): string
    {
        return static::getContainer()->get(ResetPasswordHelperInterface::class)->generateResetToken($user)->getToken();
    }

    private function requestReset(string $email): void
    {
        $this->client->enableProfiler();
        $this->client->request('GET', '/forgot-password');
        $this->client->submitForm('Send reset link', ['reset_password_request[email]' => $email]);
    }

    private function openReset(string $token): void
    {
        $this->client->request('GET', '/reset-password/reset/'.$token);
        $this->client->followRedirect();
    }

    public function testLoginPageLinksToForgotPassword(): void
    {
        $crawler = $this->client->request('GET', '/login');
        self::assertCount(1, $crawler->filter('a[href="/forgot-password"]'));
    }

    public function testKnownAndUnknownEmailGetTheSameResponse(): void
    {
        $user = $this->makeUser();

        $this->requestReset($user->getEmail());
        self::assertEmailCount(1);
        self::assertResponseRedirects('/forgot-password/check-email');
        $known = $this->client->followRedirect()->filter('main')->text();

        $this->requestReset('nobody@example.test');
        self::assertEmailCount(0);
        self::assertResponseRedirects('/forgot-password/check-email');
        $unknown = $this->client->followRedirect()->filter('main')->text();

        self::assertSame($known, $unknown);
    }

    public function testEmailContainsLinkAndNoPassword(): void
    {
        $user = $this->makeUser();
        $this->requestReset($user->getEmail());

        $email = self::getMailerMessage();
        self::assertEmailAddressContains($email, 'to', $user->getEmail());
        self::assertEmailHtmlBodyContains($email, '/reset-password/reset/');
    }

    public function testInactiveUserGetsNoEmailButSameResponse(): void
    {
        $user = $this->makeUser();
        $user->setIsActive(false);
        $this->em()->flush();

        $this->requestReset($user->getEmail());

        self::assertEmailCount(0);
        self::assertResponseRedirects('/forgot-password/check-email');
    }

    public function testRequestsAreThrottled(): void
    {
        $user = $this->makeUser();
        $this->requestReset($user->getEmail());
        $this->requestReset($user->getEmail());

        self::assertEmailCount(0, message: 'second request inside the throttle window sends nothing');
        self::assertResponseRedirects('/forgot-password/check-email');
    }

    public function testValidTokenResetsPasswordAndNewPasswordLogsIn(): void
    {
        $user = $this->makeLoginUser('old-password-1');
        $this->openReset($this->token($user));
        self::assertSelectorExists('form');

        $this->client->submitForm('Set password', [
            'change_password[plainPassword][first]' => 'brand-new-pass',
            'change_password[plainPassword][second]' => 'brand-new-pass',
        ]);
        self::assertResponseRedirects('/login');

        $this->em()->clear();
        $hasher = static::getContainer()->get('security.user_password_hasher');
        $fresh = $this->em()->find(User::class, $user->getId());
        self::assertTrue($hasher->isPasswordValid($fresh, 'brand-new-pass'));
        self::assertFalse($hasher->isPasswordValid($fresh, 'old-password-1'));
        self::assertSame(0, $this->em()->getRepository(ResetPasswordRequest::class)->count([]), 'token is single use');

        $this->client->request('GET', '/login');
        $this->client->submitForm('Log in', ['_username' => $user->getEmail(), '_password' => 'brand-new-pass']);
        self::assertResponseRedirects();
        self::assertStringNotContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));

        $events = $this->em()->getRepository(AuditLog::class)->findBy(['action' => 'pw_reset']);
        self::assertCount(1, $events);
        self::assertStringNotContainsString('brand-new-pass', json_encode($events[0]->getChanges()));
    }

    public function testTokenCannotBeReused(): void
    {
        $user = $this->makeUser();
        $token = $this->token($user);
        $this->openReset($token);
        $this->client->submitForm('Set password', ['change_password[plainPassword][first]' => 'brand-new-pass', 'change_password[plainPassword][second]' => 'brand-new-pass']);

        $this->client->request('GET', '/reset-password/reset/'.$token);
        $this->client->followRedirect();
        self::assertResponseRedirects('/forgot-password');
    }

    public function testMismatchedOrShortPasswordIsRejected(): void
    {
        $user = $this->makeUser();
        $this->openReset($this->token($user));

        $this->client->submitForm('Set password', ['change_password[plainPassword][first]' => 'brand-new-pass', 'change_password[plainPassword][second]' => 'different-pass']);
        self::assertResponseStatusCodeSame(422);

        $this->client->submitForm('Set password', ['change_password[plainPassword][first]' => 'short', 'change_password[plainPassword][second]' => 'short']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'at least 8');
    }

    public function testInvalidTokenIsRejected(): void
    {
        $this->openReset('not-a-real-token-at-all-0123456789abcdef');
        self::assertResponseRedirects('/forgot-password');
    }

    public function testExpiredTokenIsRejected(): void
    {
        $user = $this->makeUser();
        $token = $this->token($user);
        $this->em()->getConnection()->executeStatement("UPDATE reset_password_request SET expires_at = '2000-01-01 00:00:00'");

        $this->openReset($token);
        self::assertResponseRedirects('/forgot-password');
    }

    public function testUserDisabledAfterRequestCannotReset(): void
    {
        $user = $this->makeUser();
        $token = $this->token($user);
        $user->setIsActive(false);
        $this->em()->flush();

        $this->openReset($token);
        self::assertResponseRedirects('/forgot-password');
    }

    public function testResetPageWithoutTokenIs404(): void
    {
        $this->client->request('GET', '/reset-password/reset');
        self::assertResponseStatusCodeSame(404);
    }
}
