<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Assets;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-358')]
final class CorpAssetVisibilityApiTest extends WebTestCase
{
    private const string CORPORATION_ASSETS_SCOPE = 'esi-assets.read_corporation_assets.v1';
    private const int CORPORATION_ID = 98_358_301;
    private const string VISIBILITY_PATH = '/api/me/corporation/assets/visibility';

    private static int $nextEveCharacterId = 2_113_358_301;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testDirectorSavesAListOfPlayersForADivisionAndReadsItBackWithTheirNames(): void
    {
        $aria = $this->persistPlayer('Aria Solberg');
        $bob = $this->persistPlayer('Bob Tamm');
        $this->em->flush();
        $bobId = $bob->getId()->toRfc4122();

        $this->request($aria, 'GET'); self::assertResponseIsSuccessful(); $this->request($aria, 'PUT', ['visibleDivisions' => [2], 'restrictedDivisions' => [1 => [$bobId]]]);
        self::assertResponseIsSuccessful();

        $this->request($aria, 'GET');

        self::assertResponseIsSuccessful();
        $visibility = $this->responseJson();
        self::assertSame([2], $visibility['visibleDivisions']);
        self::assertSame([1 => [['id' => $bobId, 'name' => 'Bob Tamm']]], $visibility['restrictedDivisions']);
        self::assertSame([2], $visibility['accessibleDivisions']);
        self::assertTrue($visibility['isDirector']);
        self::assertTrue($visibility['directorConnected']);
    }

    private function replaceEsi(): void
    {
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('get')->willReturnCallback(static fn (string $endpoint): array => match (true) {
            str_ends_with($endpoint, '/roles/') => ['roles' => ['Director']],
            str_ends_with($endpoint, '/divisions/') => ['hangar' => [
                ['division' => 1, 'name' => 'Minerals'],
                ['division' => 2, 'name' => 'Fuel'],
                ['division' => 3, 'name' => 'Ammo'],
            ]],
            default => throw new \LogicException('Unexpected ESI request '.$endpoint),
        });
        self::getContainer()->set(EsiClient::class, $esiClient);
        $tokenManager = $this->createStub(TokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturn('valid-access-token');
        self::getContainer()->set(TokenManager::class, $tokenManager);
        self::getContainer()->set('logger', new NullLogger());
    }

    private function persistPlayer(string $name): User
    {
        $character = (new Character())
            ->setEveCharacterId(self::$nextEveCharacterId++)
            ->setName($name)
            ->setCorporationId(self::CORPORATION_ID)
            ->setCorporationName('Solberg Industries');
        $token = (new EveToken())
            ->setAccessToken('access-'.$name)
            ->setRefreshTokenEncrypted('refresh-'.$name)
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('+20 minutes'))
            ->setScopes([self::CORPORATION_ASSETS_SCOPE]);
        $character->setEveToken($token);
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($user);
        $this->em->persist($character);
        $this->em->persist($token);

        return $user;
    }

    private function request(User $user, string $method, ?array $body = null): void
    {
        $this->em->flush();
        $this->em->clear();
        $userId = $user->getId();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->replaceEsi();
        $this->client->loginUser($this->em->find(User::class, $userId), 'api');
        $this->client->request(
            $method,
            self::VISIBILITY_PATH,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private function responseJson(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
