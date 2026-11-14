<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Assets;

use App\Entity\Character;
use App\Entity\TypePackagedVolume;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-890')]
final class PackagedVolumesApiTest extends WebTestCase
{
    private const string PATH = '/api/me/assets/packaged-volumes';
    private const int RIFTER_TYPE_ID = 890_587;
    private const float RIFTER_PACKAGED_VOLUME = 2_500.0;
    private const array STATION_CONTAINER_TYPE_IDS = [17_366, 17_367, 17_368];

    private static int $nextEveCharacterId = 2_113_890_501;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAnonymousVisitorIsRejected(): void
    {
        $this->client->request('GET', self::PATH, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testAnswersTheStoredVolumesTheExcludedTypesAndTheRefreshDate(): void
    {
        $this->em->persist(new TypePackagedVolume(self::RIFTER_TYPE_ID, self::RIFTER_PACKAGED_VOLUME, new \DateTimeImmutable('2030-02-03 04:05:06')));
        $user = $this->persistPilot();
        $this->em->flush();

        $this->client->loginUser($user, 'api');
        $this->client->request('GET', self::PATH, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(self::RIFTER_PACKAGED_VOLUME, (float) $body['volumes'][(string) self::RIFTER_TYPE_ID]);
        self::assertSame(self::STATION_CONTAINER_TYPE_IDS, $body['excludedTypeIds']);
        self::assertStringStartsWith('2030-02-03T04:05:06', $body['refreshedAt']);
    }

    private function persistPilot(): User
    {
        $character = (new Character())
            ->setEveCharacterId(self::$nextEveCharacterId++)
            ->setName('Pilot '.self::$nextEveCharacterId)
            ->setCorporationId(98_890_001)
            ->setCorporationName('Solberg Industries');
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($user);
        $this->em->persist($character);

        return $user;
    }
}
