<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Me;

use App\Entity\BridgeAcknowledgement;
use App\Entity\Character;
use App\Entity\User;
use App\Enum\BridgeTodoKey;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-627')]
final class BridgeAcknowledgementApiTest extends WebTestCase
{
    private const string ENDPOINT = '/api/me/bridge/acknowledgements';
    private const int EXPIRED_EXTRACTORS_COUNT = 2;
    private const int REACKNOWLEDGED_EXPIRED_EXTRACTORS_COUNT = 5;
    private const int EXPIRING_EXTRACTORS_COUNT = 4;
    private const int TRIGGERED_PRICE_ALERTS_COUNT = 1;
    private const int ABOVE_INT4_COUNT = 2_147_483_648;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $nextEveCharacterId = 2_112_627_001;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testPostingAnItemKeyAndCountCreatesTheAcknowledgementOfTheCurrentUser(): void
    {
        $user = $this->persistPilot('Ilsa Verhoeven');
        $this->client->loginUser($user, 'api');

        $this->send('POST', self::ENDPOINT, ['itemKey' => 'expiredExtractors', 'count' => self::EXPIRED_EXTRACTORS_COUNT]);

        self::assertResponseStatusCodeSame(201);
        $json = $this->responseJson();
        self::assertSame('expiredExtractors', $json['itemKey']);
        self::assertSame(self::EXPIRED_EXTRACTORS_COUNT, $json['acknowledgedCount']);
        self::assertSame([['expiredExtractors', self::EXPIRED_EXTRACTORS_COUNT]], $this->storedFor($user));
    }

    public function testPostingTheSameItemKeyAgainReplacesTheCountAndKeepsASingleRow(): void
    {
        $user = $this->persistPilot('Ilsa Verhoeven');
        $this->client->loginUser($user, 'api');

        $this->send('POST', self::ENDPOINT, ['itemKey' => 'expiredExtractors', 'count' => self::EXPIRED_EXTRACTORS_COUNT]);
        $userId = $user->getId();
        self::ensureKernelShutdown();
        $this->setUp();
        $user = $this->em->find(User::class, $userId);
        $this->client->loginUser($user, 'api');
        $this->send('POST', self::ENDPOINT, ['itemKey' => 'expiredExtractors', 'count' => self::REACKNOWLEDGED_EXPIRED_EXTRACTORS_COUNT]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(self::REACKNOWLEDGED_EXPIRED_EXTRACTORS_COUNT, $this->responseJson()['acknowledgedCount']);
        self::assertSame([['expiredExtractors', self::REACKNOWLEDGED_EXPIRED_EXTRACTORS_COUNT]], $this->storedFor($user));
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testInvalidBodyIsRefusedWith422AndStoresNothing(array $body): void
    {
        $user = $this->persistPilot('Ilsa Verhoeven');
        $this->client->loginUser($user, 'api');

        $this->send('POST', self::ENDPOINT, $body);

        self::assertSame([422, []], [$this->client->getResponse()->getStatusCode(), $this->storedFor($user)]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'character key' => [['itemKey' => 'charactersToReconnect', 'count' => 1]];
        yield 'unknown key' => [['itemKey' => 'unknownExtractors', 'count' => 1]];
        yield 'count of 0' => [['itemKey' => 'expiredExtractors', 'count' => 0]];
        yield 'negative count' => [['itemKey' => 'expiredExtractors', 'count' => -1]];
        yield 'count above int4' => [['itemKey' => 'expiredExtractors', 'count' => self::ABOVE_INT4_COUNT]];
        yield 'missing itemKey' => [['count' => 2]];
        yield 'missing count' => [['itemKey' => 'expiredExtractors']];
    }

    public function testCollectionListsOnlyTheAcknowledgementsOfTheCurrentUser(): void
    {
        $userA = $this->persistPilot('Ilsa Verhoeven');
        $userB = $this->persistPilot('Kaelen Vorn');
        $this->persistAcknowledgement($userA, BridgeTodoKey::TriggeredPriceAlerts, self::TRIGGERED_PRICE_ALERTS_COUNT);
        $this->persistAcknowledgement($userB, BridgeTodoKey::ExpiredExtractors, self::EXPIRED_EXTRACTORS_COUNT);
        $this->persistAcknowledgement($userB, BridgeTodoKey::ExpiringExtractors, self::EXPIRING_EXTRACTORS_COUNT);
        $this->client->loginUser($userA, 'api');

        $this->send('GET', self::ENDPOINT);

        self::assertResponseIsSuccessful();
        self::assertSame(
            [['itemKey' => 'triggeredPriceAlerts', 'acknowledgedCount' => self::TRIGGERED_PRICE_ALERTS_COUNT]],
            array_map(
                static fn (array $row): array => ['itemKey' => $row['itemKey'], 'acknowledgedCount' => $row['acknowledgedCount']],
                $this->responseJson(),
            ),
        );
    }

    public function testDeletingTheKeyOfAnotherUserIsRefusedWith404AndLeavesTheirRow(): void
    {
        $userA = $this->persistPilot('Ilsa Verhoeven');
        $userB = $this->persistPilot('Kaelen Vorn');
        $this->persistAcknowledgement($userB, BridgeTodoKey::ExpiredExtractors, self::EXPIRED_EXTRACTORS_COUNT);
        $this->client->loginUser($userA, 'api');

        $this->send('DELETE', self::ENDPOINT . '/expiredExtractors');

        self::assertSame(
            [404, [['expiredExtractors', self::EXPIRED_EXTRACTORS_COUNT]]],
            [$this->client->getResponse()->getStatusCode(), $this->storedFor($userB)],
        );
    }

    public function testDeletingTheOwnKeyRemovesTheAcknowledgementAndKeepsTheOtherKeys(): void
    {
        $user = $this->persistPilot('Ilsa Verhoeven');
        $this->persistAcknowledgement($user, BridgeTodoKey::ExpiredExtractors, self::EXPIRED_EXTRACTORS_COUNT);
        $this->persistAcknowledgement($user, BridgeTodoKey::ExpiringExtractors, self::EXPIRING_EXTRACTORS_COUNT);
        $this->client->loginUser($user, 'api');

        $this->send('DELETE', self::ENDPOINT . '/expiredExtractors');

        self::assertSame(
            [204, [['expiringExtractors', self::EXPIRING_EXTRACTORS_COUNT]]],
            [$this->client->getResponse()->getStatusCode(), $this->storedFor($user)],
        );
    }

    public function testGettingAnAbsentAcknowledgementReturns404(): void
    {
        $user = $this->persistPilot('Ilsa Verhoeven');
        $this->client->loginUser($user, 'api');

        $this->send('GET', self::ENDPOINT . '/expiredExtractors');

        self::assertResponseStatusCodeSame(404);
    }

    private function persistPilot(string $name): User
    {
        $user = new User();
        $this->em->persist($user);
        $character = (new Character())
            ->setEveCharacterId($this->nextEveCharacterId++)
            ->setName($name)
            ->setCorporationId(98_627_001)
            ->setCorporationName('Verhoeven Deep Core');
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($character);
        $this->em->flush();

        return $user;
    }

    private function persistAcknowledgement(User $user, BridgeTodoKey $itemKey, int $count): void
    {
        $this->em->persist((new BridgeAcknowledgement())->setUser($user)->setItemKey($itemKey)->setAcknowledgedCount($count));
        $this->em->flush();
    }

    /**
     * @return list<array{string, int}>
     */
    private function storedFor(User $user): array
    {
        $this->em->clear();
        $rows = $this->em->getRepository(BridgeAcknowledgement::class)->findBy(['user' => $user->getId()], ['itemKey' => 'ASC']);

        return array_map(
            static fn (BridgeAcknowledgement $row): array => [$row->getItemKey()->value, $row->getAcknowledgedCount()],
            $rows,
        );
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function send(string $method, string $uri, ?array $body = null): void
    {
        $this->client->request(
            $method,
            $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<mixed>
     */
    private function responseJson(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
