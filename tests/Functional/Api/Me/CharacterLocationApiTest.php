<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Me;

use App\Entity\Character;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-1297')]
final class CharacterLocationApiTest extends WebTestCase
{
    private const string ENDPOINT = '/api/me/characters/locations';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $nextEveCharacterId = 2_112_129_701;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAnonymousRequestIsRefused(): void
    {
        $this->client->request('GET', self::ENDPOINT, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testUserSeesOnlyItsOwnCharactersWithUnknownLocationWhenNoTokenIsAvailable(): void
    {
        $user = $this->persistPilot('Ilsa Verhoeven');
        $otherMemberCharacterId = $this->persistPilot('Other Member')->getMainCharacter()?->getEveCharacterId();
        $this->client->loginUser($user, 'api');

        $this->client->request('GET', self::ENDPOINT, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $entries = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(
            [[$user->getMainCharacter()?->getEveCharacterId(), null, null, null, null, null]],
            array_map(
                static fn (array $entry): array => [$entry['eveCharacterId'], $entry['solarSystemId'], $entry['stationId'], $entry['structureId'], $entry['online'], $entry['lastLoginAt']],
                $entries,
            ),
        );
        self::assertNotContains($otherMemberCharacterId, array_column($entries, 'eveCharacterId'));
    }

    private function persistPilot(string $name): User
    {
        $user = new User();
        $this->em->persist($user);
        $character = (new Character())
            ->setEveCharacterId($this->nextEveCharacterId++)
            ->setName($name)
            ->setCorporationId(98_129_701)
            ->setCorporationName('Verhoeven Deep Core');
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($character);
        $this->em->flush();

        return $user;
    }
}
