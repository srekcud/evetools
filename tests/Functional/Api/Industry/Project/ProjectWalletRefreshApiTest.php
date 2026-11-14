<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\Character;
use App\Entity\CharacterWalletReading;
use App\Entity\EveToken;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Message\SyncCharacterWalletTransactions;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[Group('issue-953')]
final class ProjectWalletRefreshApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const string WALLET_SCOPE = 'esi-wallet.read_character_wallet.v1';
    private const string ASSETS_SCOPE = 'esi-assets.read_assets.v1';

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
    }

    public function testMemberQueuesTheDueCharacterAndIsToldAboutTheCharacterStillCached(): void
    {
        $dueCharacter = $this->addWalletCharacter($this->member, 'Brann Hauler', [self::WALLET_SCOPE], '-5 minutes');
        $this->addWalletCharacter($this->member, 'Brann Trader', [self::WALLET_SCOPE], '+40 minutes');
        $this->em->flush();

        $this->client->loginUser($this->member, 'api');
        $this->request('POST', $this->walletRefreshUri());

        self::assertResponseStatusCodeSame(200);
        self::assertSame([1, 1], $this->counters());
        self::assertEquals([new SyncCharacterWalletTransactions($this->idOfCharacter($dueCharacter))], $this->dispatchedMessages());
    }

    public function testCharacterNeverReadIsDue(): void
    {
        $neverReadCharacter = $this->addWalletCharacter($this->member, 'Brann Scout', [self::WALLET_SCOPE], null);
        $this->em->flush();

        $this->client->loginUser($this->member, 'api');
        $this->request('POST', $this->walletRefreshUri());

        self::assertResponseStatusCodeSame(200);
        self::assertSame([1, 0], $this->counters());
        self::assertEquals([new SyncCharacterWalletTransactions($this->idOfCharacter($neverReadCharacter))], $this->dispatchedMessages());
    }

    public function testCharactersOfAnotherMemberAreNeverQueued(): void
    {
        $memberCharacter = $this->addWalletCharacter($this->member, 'Brann Hauler', [self::WALLET_SCOPE], '-5 minutes');
        $this->addWalletCharacter($this->owner, 'Aria Hauler', [self::WALLET_SCOPE], '-2 hours');
        $this->addWalletCharacter($this->owner, 'Aria Trader', [self::WALLET_SCOPE], '+15 minutes');
        $this->em->flush();

        $this->client->loginUser($this->member, 'api');
        $this->request('POST', $this->walletRefreshUri());

        self::assertResponseStatusCodeSame(200);
        self::assertSame([1, 0], $this->counters());
        self::assertEquals([new SyncCharacterWalletTransactions($this->idOfCharacter($memberCharacter))], $this->dispatchedMessages());
    }

    public function testNoEligibleCharacterAnswersZeroCountersWithoutQueuingAnything(): void
    {
        $this->addWalletCharacter($this->member, 'Brann Miner', [self::ASSETS_SCOPE], '-5 minutes');
        $this->addWalletCharacter($this->member, 'Brann Lapsed', [self::WALLET_SCOPE], '-5 minutes')->markAuthInvalid();
        $this->em->flush();

        $this->client->loginUser($this->member, 'api');
        $this->request('POST', $this->walletRefreshUri());

        self::assertResponseStatusCodeSame(200);
        self::assertSame([0, 0], $this->counters());
        self::assertSame([], $this->dispatchedMessages());
    }

    public function testOutsiderIsRefusedAndNothingIsQueued(): void
    {
        $this->addWalletCharacter($this->outsider, 'Elia Hauler', [self::WALLET_SCOPE], '-5 minutes');
        $this->em->flush();

        $this->client->loginUser($this->outsider, 'api');
        $this->request('POST', $this->walletRefreshUri());

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->dispatchedMessages());
    }

    public function testPendingMemberIsRefusedAndNothingIsQueued(): void
    {
        $this->addWalletCharacter($this->pendingMember, 'Dorn Hauler', [self::WALLET_SCOPE], '-5 minutes');
        $this->em->flush();

        $this->client->loginUser($this->pendingMember, 'api');
        $this->request('POST', $this->walletRefreshUri());

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->dispatchedMessages());
    }

    public function testCompletedProjectIsRefusedAndNothingIsQueued(): void
    {
        $this->addWalletCharacter($this->member, 'Brann Hauler', [self::WALLET_SCOPE], '-5 minutes');
        $this->ishtarBatch->setStatus(ProjectStatus::Completed);
        $this->em->flush();

        $this->client->loginUser($this->member, 'api');
        $this->request('POST', $this->walletRefreshUri());

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->dispatchedMessages());
    }

    private function walletRefreshUri(): string
    {
        return $this->projectUri($this->ishtarBatch).'/wallet-refresh';
    }

    /**
     * @return array{int, int}
     */
    private function counters(): array
    {
        $response = $this->responseJson();
        self::assertArrayHasKey('queuedCharacters', $response);
        self::assertArrayHasKey('notDueCharacters', $response);

        return [$response['queuedCharacters'], $response['notDueCharacters']];
    }

    /**
     * @return list<object>
     */
    private function dispatchedMessages(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);

        return array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent());
    }

    private function idOfCharacter(Character $character): string
    {
        return (string) $character->getId()?->toRfc4122();
    }

    /**
     * @param list<string> $scopes
     */
    private function addWalletCharacter(User $user, string $name, array $scopes, ?string $nextReadAtModifier): Character
    {
        $character = (new Character())
            ->setEveCharacterId(self::$nextEveCharacterId++)
            ->setName($name)
            ->setCorporationId($user->getMainCharacter()?->getCorporationId())
            ->setCorporationName('Corporation');
        $token = (new EveToken())
            ->setAccessToken('access-'.$name)
            ->setRefreshTokenEncrypted('refresh-'.$name)
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('+20 minutes'))
            ->setScopes($scopes);
        $character->setEveToken($token);
        $user->addCharacter($character);
        $this->em->persist($character);
        $this->em->persist($token);

        if ($nextReadAtModifier !== null) {
            $this->em->persist(
                (new CharacterWalletReading())
                    ->setCharacter($character)
                    ->setReadAt(new \DateTimeImmutable('-30 minutes'))
                    ->setNextReadAt(new \DateTimeImmutable($nextReadAtModifier)),
            );
        }

        return $character;
    }
}
