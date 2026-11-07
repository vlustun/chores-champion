<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\Fixtures;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomePageTest extends WebTestCase
{
    use Fixtures;

    public function testAnonymousSeesLandingPageWithLoginLink(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Chores Champion');
        self::assertCount(1, $crawler->filter('a[href="/login"]'));
    }

    public function testAuthenticatedUserIsRedirectedToTasks(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeUser());
        $client->request('GET', '/');

        self::assertResponseRedirects('/tasks');
    }
}
