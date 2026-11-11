<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Admin;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Message\SyncBlueprints;
use App\Message\SyncCharacterAssets;
use App\Message\SyncCostIndices;
use App\Service\ESI\AuthenticationService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineReceivedStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Uid\Uuid;

#[Group('issue-644')]
final class FailedMessagesApiTest extends WebTestCase
{
    private const int ADMIN_EVE_CHARACTER_ID = 2_113_644_101;
    private const int PLAYER_EVE_CHARACTER_ID = 2_113_644_102;
    private const string FAILED_MESSAGES_URI = '/api/admin/failed-messages';
    private const string UNKNOWN_MESSAGE_ID = '987654321';
    private const string RENAMED_CLASS = 'App\Message\RenamedMessage';
    private const string ASSETS_CLASS = SyncCharacterAssets::class;
    private const string BLUEPRINTS_CLASS = SyncBlueprints::class;
    private const string COST_INDICES_CLASS = SyncCostIndices::class;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Uuid $adminId = null;

    protected function setUp(): void
    {
        $_ENV['ADMIN_CHARACTER_IDS'] = $_SERVER['ADMIN_CHARACTER_IDS'] = (string) self::ADMIN_EVE_CHARACTER_ID;
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $_ENV['ADMIN_CHARACTER_IDS'] = $_SERVER['ADMIN_CHARACTER_IDS'] = '';
        parent::tearDown();
    }

    public function testAnAdminListsTheFailedMessagesGroupedByMessageClass(): void
    {
        $this->loginAsAdmin();
        $assetsFailedAt = new \DateTimeImmutable('2026-11-10 08:15:00', new \DateTimeZone('UTC'));
        $this->sendFailed(new SyncCharacterAssets('char-1'));
        $this->sendFailed(new SyncCharacterAssets('char-2'));
        $this->sendFailed(new SyncCharacterAssets('char-3'));
        $blueprintsId = $this->sendFailed(
            new SyncBlueprints(),
            new SentToFailureTransportStamp('async'),
            new ErrorDetailsStamp(\LogicException::class, 0, 'Blueprint page missing'),
            new RedeliveryStamp(2, $assetsFailedAt),
        );

        $this->request('GET', self::FAILED_MESSAGES_URI);

        self::assertResponseIsSuccessful();
        $body = $this->jsonBody();
        self::assertSame(4, $body['total']);
        self::assertSame(
            [
                ['messageClass' => self::ASSETS_CLASS, 'count' => 3],
                ['messageClass' => self::BLUEPRINTS_CLASS, 'count' => 1],
            ],
            $body['classes'],
        );
        self::assertCount(4, $body['messages']);
        $newest = $body['messages'][0];
        self::assertSame($blueprintsId, $newest['id']);
        self::assertSame(self::BLUEPRINTS_CLASS, $newest['messageClass']);
        self::assertSame(\LogicException::class, $newest['exceptionClass']);
        self::assertSame('Blueprint page missing', $newest['exceptionMessage']);
        self::assertSame(2, $newest['retryCount']);
        self::assertIsString($newest['failedAt']);
        self::assertEquals($assetsFailedAt, new \DateTimeImmutable($newest['failedAt']));
    }

    public function testAMessageWithoutRedeliveryStampExposesNullRetryCountAndFailureDate(): void
    {
        $this->loginAsAdmin();
        $this->sendFailed(
            new SyncBlueprints(),
            new SentToFailureTransportStamp('async'),
            new ErrorDetailsStamp(\RuntimeException::class, 0, 'boom'),
        );

        $this->request('GET', self::FAILED_MESSAGES_URI);

        self::assertResponseIsSuccessful();
        $message = $this->jsonBody()['messages'][0];
        self::assertArrayHasKey('retryCount', $message);
        self::assertNull($message['retryCount']);
        self::assertArrayHasKey('failedAt', $message);
        self::assertNull($message['failedAt']);
    }

    public function testTheMessageClassFilterRestrictsTheMessagesButNotTheTotalNorTheClasses(): void
    {
        $this->loginAsAdmin();
        $this->sendFailed(new SyncCharacterAssets('char-1'));
        $blueprintsId = $this->sendFailed(new SyncBlueprints());
        $this->sendFailed(new SyncCharacterAssets('char-2'));

        $this->request('GET', self::FAILED_MESSAGES_URI . '?messageClass=' . urlencode(self::BLUEPRINTS_CLASS));

        self::assertResponseIsSuccessful();
        $body = $this->jsonBody();
        self::assertSame(3, $body['total']);
        self::assertSame(
            [
                ['messageClass' => self::ASSETS_CLASS, 'count' => 2],
                ['messageClass' => self::BLUEPRINTS_CLASS, 'count' => 1],
            ],
            $body['classes'],
        );
        self::assertCount(1, $body['messages']);
        self::assertSame($blueprintsId, $body['messages'][0]['id']);
    }

    public function testAnAdminRetriesAMessageWhichReachesAsyncWithoutFailureStampsAndLeavesFailed(): void
    {
        $this->loginAsAdmin();
        $this->sendFailed(new SyncCostIndices());
        $retriedId = $this->sendFailed(new SyncCharacterAssets('char-7'));

        $this->request('POST', self::FAILED_MESSAGES_URI . '/' . $retriedId . '/retry');

        self::assertResponseIsSuccessful();
        $sent = $this->asyncTransport()->getSent();
        self::assertCount(1, $sent);
        self::assertEquals(new SyncCharacterAssets('char-7'), $sent[0]->getMessage());
        self::assertSame([], $sent[0]->all(SentToFailureTransportStamp::class));
        self::assertSame([], $sent[0]->all(RedeliveryStamp::class));
        self::assertSame([], $sent[0]->all(DoctrineReceivedStamp::class));
        self::assertSame(1, $this->failedRowCount());
        self::assertSame(0, $this->failedRowCountOfId($retriedId));
    }

    public function testAnAdminRemovesAMessageWithoutSendingAnything(): void
    {
        $this->loginAsAdmin();
        $this->sendFailed(new SyncCostIndices());
        $removedId = $this->sendFailed(new SyncCharacterAssets('char-7'));

        $this->request('DELETE', self::FAILED_MESSAGES_URI . '/' . $removedId);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->failedRowCount());
        self::assertSame(0, $this->failedRowCountOfId($removedId));
        self::assertSame([], $this->asyncTransport()->getSent());
    }

    public function testAnAdminRetriesAClassWhichLeavesTheOtherClassesInFailed(): void
    {
        $this->loginAsAdmin();
        $this->sendFailed(new SyncCharacterAssets('char-1'));
        $this->sendFailed(new SyncBlueprints());
        $this->sendFailed(new SyncCharacterAssets('char-2'));
        $this->sendFailed(new SyncCharacterAssets('char-3'));

        $this->postClassAction('retry-class', self::ASSETS_CLASS);

        self::assertResponseIsSuccessful();
        self::assertSame(['processed' => 3, 'remaining' => 0], $this->jsonBody());
        self::assertEquals(
            [new SyncCharacterAssets('char-1'), new SyncCharacterAssets('char-2'), new SyncCharacterAssets('char-3')],
            array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $this->asyncTransport()->getSent()),
        );
        self::assertSame(1, $this->failedRowCount());
    }

    public function testAnAdminRemovesAClassWithoutSendingAnything(): void
    {
        $this->loginAsAdmin();
        $this->sendFailed(new SyncCharacterAssets('char-1'));
        $this->sendFailed(new SyncBlueprints());
        $this->sendFailed(new SyncCharacterAssets('char-2'));

        $this->postClassAction('remove-class', self::ASSETS_CLASS);

        self::assertResponseIsSuccessful();
        self::assertSame(['processed' => 2, 'remaining' => 0], $this->jsonBody());
        self::assertSame(1, $this->failedRowCount());
        self::assertSame([], $this->asyncTransport()->getSent());
    }

    public function testRetryOfAMessageAlreadyRetriedIsNotFoundAndSendsNothingMore(): void
    {
        $this->loginAsAdmin();
        $retriedId = $this->sendFailed(new SyncCharacterAssets('char-7'));
        $this->request('POST', self::FAILED_MESSAGES_URI . '/' . $retriedId . '/retry');
        self::assertResponseIsSuccessful();

        $this->loginAsAdminWithAFreshClient();
        $this->request('POST', self::FAILED_MESSAGES_URI . '/' . $retriedId . '/retry');

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->failedRowCount());
    }

    public function testRemovalOfAMessageAlreadyRemovedIsNotFound(): void
    {
        $this->loginAsAdmin();
        $removedId = $this->sendFailed(new SyncCharacterAssets('char-7'));
        $this->request('DELETE', self::FAILED_MESSAGES_URI . '/' . $removedId);
        self::assertResponseIsSuccessful();

        $this->loginAsAdminWithAFreshClient();
        $this->request('DELETE', self::FAILED_MESSAGES_URI . '/' . $removedId);

        self::assertResponseStatusCodeSame(404);
    }

    public function testListingTwiceAMessageOfAVanishedClassNeverDeletesItsRow(): void
    {
        $this->loginAsAdmin();
        $vanishedId = $this->insertVanishedClassMessage();

        $this->request('GET', self::FAILED_MESSAGES_URI);

        self::assertResponseIsSuccessful();
        $firstListing = $this->jsonBody();
        self::assertSame(1, $firstListing['total']);
        self::assertSame($vanishedId, $firstListing['messages'][0]['id']);
        self::assertSame(self::RENAMED_CLASS, $firstListing['messages'][0]['messageClass']);

        $this->loginAsAdminWithAFreshClient();
        $this->request('GET', self::FAILED_MESSAGES_URI);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->jsonBody()['total']);
        self::assertSame(1, $this->failedRowCount());
    }

    public function testRetryOfAMessageOfAVanishedClassIsUnprocessableAndKeepsItsRow(): void
    {
        $this->loginAsAdmin();
        $vanishedId = $this->insertVanishedClassMessage();

        $this->request('POST', self::FAILED_MESSAGES_URI . '/' . $vanishedId . '/retry');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->failedRowCount());
        self::assertSame([], $this->asyncTransport()->getSent());
    }

    public function testRemovalOfAMessageOfAVanishedClassDeletesItsRow(): void
    {
        $this->loginAsAdmin();
        $vanishedId = $this->insertVanishedClassMessage();

        $this->request('DELETE', self::FAILED_MESSAGES_URI . '/' . $vanishedId);

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->failedRowCount());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function classActions(): iterable
    {
        yield 'retry class' => ['retry-class'];
        yield 'remove class' => ['remove-class'];
    }

    #[DataProvider('classActions')]
    public function testAClassActionWithoutMessageClassIsUnprocessable(string $action): void
    {
        $this->loginAsAdmin();
        $this->sendFailed(new SyncBlueprints());

        $this->client->request(
            'POST',
            self::FAILED_MESSAGES_URI . '/' . $action,
            server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            content: '{}',
        );

        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->failedRowCount());
        self::assertSame([], $this->asyncTransport()->getSent());
    }

    public function testANonAdminCannotListTheFailedMessages(): void
    {
        $this->client->loginUser($this->persistedUser(self::PLAYER_EVE_CHARACTER_ID), 'api');
        $this->sendFailed(new SyncBlueprints());

        $this->request('GET', self::FAILED_MESSAGES_URI);

        self::assertResponseStatusCodeSame(403);
    }

    public function testANonAdminCannotRetryAClassAndNothingIsSent(): void
    {
        $this->client->loginUser($this->persistedUser(self::PLAYER_EVE_CHARACTER_ID), 'api');
        $this->sendFailed(new SyncBlueprints());

        $this->postClassAction('retry-class', self::BLUEPRINTS_CLASS);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->asyncTransport()->getSent());
        self::assertSame(1, $this->failedRowCount());
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function adminRoutes(): iterable
    {
        yield 'list' => ['GET', self::FAILED_MESSAGES_URI, null];
        yield 'retry one' => ['POST', self::FAILED_MESSAGES_URI . '/{id}/retry', null];
        yield 'remove one' => ['DELETE', self::FAILED_MESSAGES_URI . '/{id}', null];
        yield 'retry class' => ['POST', self::FAILED_MESSAGES_URI . '/retry-class', self::BLUEPRINTS_CLASS];
        yield 'remove class' => ['POST', self::FAILED_MESSAGES_URI . '/remove-class', self::BLUEPRINTS_CLASS];
    }

    #[DataProvider('adminRoutes')]
    public function testANonAdminIsForbiddenOnEveryRouteAndNothingIsSentNorRemoved(string $method, string $uriTemplate, ?string $messageClass): void
    {
        $this->client->loginUser($this->persistedUser(self::PLAYER_EVE_CHARACTER_ID), 'api');
        $targetId = $this->sendFailed(new SyncBlueprints());
        $this->sendFailed(new SyncCharacterAssets('char-1'));

        $this->client->request(
            $method,
            str_replace('{id}', $targetId, $uriTemplate),
            server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            content: null === $messageClass ? null : json_encode(['messageClass' => $messageClass], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->asyncTransport()->getSent());
        self::assertSame(2, $this->failedRowCount());
        self::assertSame(1, $this->failedRowCountOfId($targetId));
    }

    public function testTheFailureDateIsExposedAsTheExactIso8601RedeliveryInstant(): void
    {
        $this->loginAsAdmin();
        $this->sendFailed(
            new SyncBlueprints(),
            new SentToFailureTransportStamp('async'),
            new ErrorDetailsStamp(\RuntimeException::class, 0, 'boom'),
            new RedeliveryStamp(1, new \DateTimeImmutable('2026-11-10 08:15:00', new \DateTimeZone('UTC'))),
        );

        $this->request('GET', self::FAILED_MESSAGES_URI);

        self::assertResponseIsSuccessful();
        self::assertSame('2026-11-10T08:15:00+00:00', $this->jsonBody()['messages'][0]['failedAt']);
    }

    public function testAnEmptyMessageClassFilterListsEveryMessageLikeNoFilter(): void
    {
        $this->loginAsAdmin();
        $this->sendFailed(new SyncCharacterAssets('char-1'));
        $this->sendFailed(new SyncBlueprints());
        $this->sendFailed(new SyncCharacterAssets('char-2'));

        $this->request('GET', self::FAILED_MESSAGES_URI . '?messageClass=');

        self::assertResponseIsSuccessful();
        $body = $this->jsonBody();
        self::assertSame(3, $body['total']);
        self::assertCount(3, $body['messages']);
        self::assertSame(
            [
                ['messageClass' => self::ASSETS_CLASS, 'count' => 2],
                ['messageClass' => self::BLUEPRINTS_CLASS, 'count' => 1],
            ],
            $body['classes'],
        );
    }

    private function loginAsAdmin(): void
    {
        $admin = $this->persistedUser(self::ADMIN_EVE_CHARACTER_ID);
        $this->adminId = $admin->getId();
        $this->client->loginUser($admin, 'api');
    }

    private function loginAsAdminWithAFreshClient(): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->client->loginUser($this->em->getRepository(User::class)->find($this->adminId), 'api');
    }

    private function request(string $method, string $uri): void
    {
        $this->client->request($method, $uri, server: ['HTTP_ACCEPT' => 'application/json']);
    }

    private function postClassAction(string $action, string $messageClass): void
    {
        $this->client->request(
            'POST',
            self::FAILED_MESSAGES_URI . '/' . $action,
            server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['messageClass' => $messageClass], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<mixed>
     */
    private function jsonBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function sendFailed(object $message, StampInterface ...$stamps): string
    {
        $stamps = [] === $stamps
            ? [new SentToFailureTransportStamp('async'), new ErrorDetailsStamp(\RuntimeException::class, 0, 'boom'), new RedeliveryStamp(0)]
            : $stamps;
        $failedTransport = self::getContainer()->get('messenger.transport.failed');
        self::assertInstanceOf(TransportInterface::class, $failedTransport);

        $idStamp = $failedTransport->send(new Envelope($message, $stamps))->last(TransportMessageIdStamp::class);
        self::assertInstanceOf(TransportMessageIdStamp::class, $idStamp);

        return (string) $idStamp->getId();
    }

    private function insertVanishedClassMessage(): string
    {
        $vanishedMessage = unserialize(
            sprintf('O:%d:"%s":0:{}', strlen(self::RENAMED_CLASS), self::RENAMED_CLASS),
            ['allowed_classes' => false],
        );
        $encoded = (new PhpSerializer())->encode(new Envelope($vanishedMessage, [new SentToFailureTransportStamp('async')]));

        return (string) $this->connection()->fetchOne(
            "INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at) VALUES (?, '[]', 'failed', '2026-01-01 00:00:00', '2026-01-01 00:00:00') RETURNING id",
            [$encoded['body']],
        );
    }

    private function failedRowCount(): int
    {
        return (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed'");
    }

    private function failedRowCountOfId(string $id): int
    {
        return (int) $this->connection()->fetchOne(
            "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed' AND id = ?",
            [$id],
        );
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function persistedUser(int $eveCharacterId): User
    {
        $user = new User();
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName('Pilot ' . $eveCharacterId)
            ->setCorporationId(98_000_001)
            ->setCorporationName('Test Corp');
        $character->setEveToken((new EveToken())
            ->setAccessToken('access')
            ->setRefreshTokenEncrypted('refresh')
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('+20 minutes'))
            ->setScopes(AuthenticationService::getRequiredScopes()));
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($user);
        $this->em->persist($character);
        $this->em->flush();

        return $user;
    }
}
