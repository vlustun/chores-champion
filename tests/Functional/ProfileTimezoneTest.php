<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Tests\Support\Fixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProfileTimezoneTest extends WebTestCase
{
    use Fixtures;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    private function token(): string
    {
        $crawler = $this->client->request('GET', '/profile');

        return (string) $crawler->filter('body')->attr('data-timezone-token-value');
    }

    /** 
     * @param array<string, mixed>|string $body
     *
     * @return array<string, mixed> 
     */
    private function post(array|string $body): array
    {
        $this->client->request('POST', '/profile/timezone', server: ['CONTENT_TYPE' => 'application/json'], content: \is_array($body) ? json_encode($body) : $body);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function reload(User $u): User
    {
        $this->em()->clear();

        return $this->em()->find(User::class, $u->getId());
    }

    public function testDetectedTimezoneIsStored(): void
    {
        $user = $this->makeUser('UTC');
        $this->client->loginUser($user);

        $json = $this->post(['timezone' => 'Europe/Vilnius', '_token' => $this->token()]);

        self::assertResponseIsSuccessful();
        self::assertSame(['changed' => true, 'timezone' => 'Europe/Vilnius', 'manual' => false], $json);
        self::assertSame('Europe/Vilnius', $this->reload($user)->getTimezone());
    }

    public function testManualTimezoneIsNotOverwrittenByDetection(): void
    {
        $user = $this->makeUser('Asia/Tokyo');
        $user->setTimezoneManual(true);
        $this->em()->flush();
        $this->client->loginUser($user);

        $json = $this->post(['timezone' => 'Europe/Vilnius', '_token' => $this->token()]);

        self::assertFalse($json['changed']);
        self::assertSame('Asia/Tokyo', $this->reload($user)->getTimezone());
    }

    /** @return iterable<string, array{mixed}> */
    public static function badTimezones(): iterable
    {
        yield 'unknown' => ['Mars/Olympus'];
        yield 'offset' => ['+02:00'];
        yield 'empty' => [''];
        yield 'number' => [42];
        yield 'array' => [['Europe/Vilnius']];
        yield 'null' => [null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badTimezones')]
    public function testInvalidTimezoneIsRejectedWithProblemJson(mixed $value): void
    {
        $user = $this->makeUser('UTC');
        $this->client->loginUser($user);

        $json = $this->post(['timezone' => $value, '_token' => $this->token()]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_timezone', $json['code']);
        self::assertStringContainsString('application/problem+json', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame('UTC', $this->reload($user)->getTimezone());
    }

    public function testBadCsrfAndBadBody(): void
    {
        $this->client->loginUser($this->makeUser());

        $json = $this->post(['timezone' => 'Europe/Vilnius', '_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('invalid_csrf_token', $json['code']);

        $this->post(['timezone' => 'Europe/Vilnius']);
        self::assertResponseStatusCodeSame(403);

        $this->post('{not json');
        self::assertResponseStatusCodeSame(400);

        $this->post('"just a string"');
        self::assertResponseStatusCodeSame(400);
    }

    public function testAnonymousCannotPost(): void
    {
        $this->client->request('POST', '/profile/timezone', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertResponseStatusCodeSame(401);
    }

    public function testProfileFormSavesManualTimezoneAndValidates(): void
    {
        $user = $this->makeUser('UTC');
        $this->client->loginUser($user);

        $this->client->request('GET', '/profile');
        $this->client->submitForm('Save', ['profile[name]' => 'New Name', 'profile[phoneNr]' => '+370 600 12345', 'profile[timezone]' => 'Pacific/Auckland', 'profile[timezoneAuto]' => false]);
        self::assertResponseRedirects('/profile');

        $user = $this->reload($user);
        self::assertSame('New Name', $user->getName());
        self::assertSame('Pacific/Auckland', $user->getTimezone());
        self::assertTrue($user->isTimezoneManual(), 'unchecking auto-detect pins the timezone');

        $this->client->request('GET', '/profile');
        $this->client->submitForm('Save', ['profile[name]' => '', 'profile[phoneNr]' => 'abc', 'profile[timezone]' => 'UTC']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testTimesAreRenderedInTheUsersTimezone(): void
    {
        $user = $this->makeUser('Pacific/Kiritimati'); // UTC+14
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/week');

        $time = $crawler->filter('time')->first();
        $iso = $time->attr('datetime');
        self::assertStringEndsWith('+00:00', $iso, 'machine-readable value stays UTC');
        $expected = (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('Pacific/Kiritimati'))->format('D j M, H:i');
        self::assertSame($expected, trim($time->text()));
    }
}
