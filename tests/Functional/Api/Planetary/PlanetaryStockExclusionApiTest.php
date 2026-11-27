<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Planetary;

use App\Entity\Character;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-1458')]
final class PlanetaryStockExclusionApiTest extends WebTestCase
{
    private const string SETTINGS_URI = '/api/planetary/settings';
    private const string STOCK_URI = '/api/planetary/stock';
    private const int PILOT_EVE_ID = 2_114_580_001;
    private const int OTHER_PILOT_EVE_ID = 2_114_580_002;
    private const int COOLANT = 9832;
    private const int BACTERIA = 2393;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $pilot;
    private User $otherPilot;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->pilot = $this->persistPilot('Alma Voss', self::PILOT_EVE_ID);
        $this->otherPilot = $this->persistPilot('Cora Tane', self::OTHER_PILOT_EVE_ID);
    }

    public function testTheExcludedTypesAreStoredSortedKeptByAnotherPatchRefusedWhenInvalidAndExposedByTheStock(): void
    {
        self::assertSame([], $this->request($this->pilot, 'GET', self::SETTINGS_URI)['excludedTypeIds']);
        self::assertSame([], $this->request($this->pilot, 'GET', self::STOCK_URI)['excludedTypeIds']);

        $patched = $this->request($this->pilot, 'PATCH', self::SETTINGS_URI, ['excludedTypeIds' => [self::COOLANT, self::BACTERIA, self::COOLANT]]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([self::BACTERIA, self::COOLANT], $patched['excludedTypeIds']);

        $stock = $this->request($this->pilot, 'GET', self::STOCK_URI);
        self::assertSame([self::BACTERIA, self::COOLANT], $stock['excludedTypeIds']);
        self::assertSame([], $stock['rows']);

        $coverageOnly = $this->request($this->pilot, 'PATCH', self::SETTINGS_URI, ['coverageWeeks' => 4]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(4, $coverageOnly['coverageWeeks']);
        self::assertSame([self::BACTERIA, self::COOLANT], $coverageOnly['excludedTypeIds']);

        $this->request($this->pilot, 'PATCH', self::SETTINGS_URI, ['excludedTypeIds' => [0]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([self::BACTERIA, self::COOLANT], $this->request($this->pilot, 'GET', self::SETTINGS_URI)['excludedTypeIds']);

        self::assertSame([], $this->request($this->otherPilot, 'GET', self::STOCK_URI)['excludedTypeIds']);

        $restored = $this->request($this->pilot, 'PATCH', self::SETTINGS_URI, ['excludedTypeIds' => []]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $restored['excludedTypeIds']);
        self::assertSame([], $this->request($this->pilot, 'GET', self::STOCK_URI)['excludedTypeIds']);
    }

    /**
     * @param array<string, mixed>|null $payload
     *
     * @return array<string, mixed>
     */
    private function request(User $user, string $method, string $uri, ?array $payload = null): array
    {
        $this->client->getKernel()->boot();
        $this->client->loginUser($this->em->find(User::class, $user->getId()), 'api');
        $this->client->request(
            $method,
            $uri,
            server: ['CONTENT_TYPE' => 'application/merge-patch+json', 'HTTP_ACCEPT' => 'application/json'],
            content: null === $payload ? null : json_encode($payload, JSON_THROW_ON_ERROR),
        );

        $content = (string) $this->client->getResponse()->getContent();

        return '' === $content ? [] : json_decode($content, true, flags: JSON_THROW_ON_ERROR);
    }

    private function persistPilot(string $name, int $eveCharacterId): User
    {
        $user = new User();
        $this->em->persist($user);
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName($name)
            ->setCorporationId(98_539_581)
            ->setCorporationName('Verhoeven Deep Core');
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($character);
        $this->em->flush();

        return $user;
    }
}
