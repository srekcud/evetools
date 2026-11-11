<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Mercure;

use App\Entity\Character;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mercure\HubInterface;

#[Group('issue-613')]
final class MercureTokenApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testTheTokenEndpointSetsTheSubscriberAuthorizationCookie(): void
    {
        $this->client->loginUser($this->persistedUser(), 'api');

        $this->requestToken();

        self::assertResponseIsSuccessful();
        $cookie = $this->client->getResponse()->headers->getCookies()[0];
        self::assertSame('__Secure-mercure_access_token', $cookie->getName());
        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
        self::assertSame('/.well-known/mercure', $cookie->getPath());
    }

    public function testTheTokenEndpointAnswersWithTheTopicsAndTheHubUrl(): void
    {
        $user = $this->persistedUser();
        $this->client->loginUser($user, 'api');

        $this->requestToken();

        $body = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertContains(sprintf('/user/%s/notifications', $user->getId()?->toRfc4122()), $body['topics']);
        self::assertSame($this->hubPublicUrl(), $body['hubUrl']);
    }

    public function testTheTokenEndpointRefusesAnAnonymousVisitorWithoutSettingACookie(): void
    {
        $this->requestToken();

        self::assertResponseStatusCodeSame(401);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    private function requestToken(): void
    {
        $this->client->request('GET', '/api/mercure/token', server: [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_HOST' => parse_url($this->hubPublicUrl(), PHP_URL_HOST) ?: 'localhost',
        ]);
    }

    private function hubPublicUrl(): string
    {
        return self::getContainer()->get(HubInterface::class)->getPublicUrl();
    }

    private function persistedUser(): User
    {
        $user = new User();
        $character = (new Character())
            ->setEveCharacterId(2_113_613_001)
            ->setName('Noor Halvik')
            ->setCorporationId(98_613_001)
            ->setCorporationName('Halvik Freight');
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($user);
        $this->em->persist($character);
        $this->em->flush();

        return $user;
    }
}
