<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Tests\Support\Fixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProfilePasswordTest extends WebTestCase
{
    use Fixtures;

    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = $this->makeLoginUser('current-pass-1');
        $this->client->loginUser($this->user);
    }

    private function submit(string $current, string $new, ?string $repeat = null): void
    {
        $this->client->request('GET', '/profile/password');
        $this->client->submitForm('Change password', [
            'change_password[currentPassword]' => $current,
            'change_password[plainPassword][first]' => $new,
            'change_password[plainPassword][second]' => $repeat ?? $new,
        ]);
    }

    private function valid(string $password): bool
    {
        $this->em()->clear();

        return static::getContainer()->get('security.user_password_hasher')->isPasswordValid($this->em()->find(User::class, $this->user->getId()), $password);
    }

    public function testProfileLinksToChangePassword(): void
    {
        $crawler = $this->client->request('GET', '/profile');
        self::assertCount(1, $crawler->filter('a[href="/profile/password"]'));
    }

    public function testChangeWithCorrectCurrentPassword(): void
    {
        $this->submit('current-pass-1', 'another-pass-2');

        self::assertResponseRedirects('/profile');
        self::assertTrue($this->valid('another-pass-2'));
        $events = $this->em()->getRepository(AuditLog::class)->findBy(['action' => 'pw_change']);
        self::assertCount(1, $events);
        self::assertSame($this->user->getId(), $events[0]->getActor()?->getId());
    }

    public function testWrongCurrentPasswordIsRejected(): void
    {
        $this->submit('wrong-password', 'another-pass-2');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'current password is wrong');
        self::assertTrue($this->valid('current-pass-1'));
    }

    public function testMismatchAndShortAreRejected(): void
    {
        $this->submit('current-pass-1', 'another-pass-2', 'not-the-same');
        self::assertResponseStatusCodeSame(422);

        $this->submit('current-pass-1', 'short');
        self::assertResponseStatusCodeSame(422);
        self::assertTrue($this->valid('current-pass-1'));
    }

    public function testMissingCsrfTokenIsRejected(): void
    {
        $this->client->request('POST', '/profile/password', ['change_password' => [
            'currentPassword' => 'current-pass-1',
            'plainPassword' => ['first' => 'another-pass-2', 'second' => 'another-pass-2'],
        ]]);

        self::assertResponseStatusCodeSame(422);
        self::assertTrue($this->valid('current-pass-1'));
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        static::ensureKernelShutdown();
        $anon = static::createClient();
        $anon->request('GET', '/profile/password');
        self::assertResponseRedirects('/login');
    }
}
