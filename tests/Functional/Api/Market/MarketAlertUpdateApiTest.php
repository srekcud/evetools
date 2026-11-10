<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Market;

use App\Entity\Character;
use App\Entity\MarketPriceAlert;
use App\Entity\User;
use App\Enum\AlertDirection;
use App\Enum\AlertPriceSource;
use App\Enum\AlertStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-537')]
final class MarketAlertUpdateApiTest extends WebTestCase
{
    private const int PYERITE = 35;
    private const int MEXALLON = 36;
    private const int ISOGEN = 37;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $nextEveCharacterId = 2_112_537_001;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testPatchingTheThresholdOfATriggeredAlertRearmsIt(): void
    {
        $user = $this->persistPilot();
        $alert = $this->persistAlert($user, self::PYERITE, 'Pyerite', AlertDirection::Below, 9.125, AlertPriceSource::JitaBuy, AlertStatus::Triggered);
        $this->client->loginUser($user, 'api');

        $this->patch($alert, ['threshold' => 8.0]);

        self::assertResponseStatusCodeSame(200);
        $json = $this->responseJson();
        self::assertSame('active', $json['status']);
        self::assertSame(8.0, $json['threshold']);
        self::assertArrayHasKey('triggeredAt', $json);
        self::assertNull($json['triggeredAt']);
        $reloaded = $this->reload($alert);
        self::assertSame([AlertStatus::Active, 8.0], [$reloaded->getStatus(), $reloaded->getThreshold()]);
        self::assertNull($reloaded->getTriggeredAt());
    }

    public function testPatchingAnEmptyBodyRearmsATriggeredAlertWithoutChangingItsThreshold(): void
    {
        $user = $this->persistPilot();
        $alert = $this->persistAlert($user, self::MEXALLON, 'Mexallon', AlertDirection::Above, 61.5, AlertPriceSource::JitaSell, AlertStatus::Triggered);
        $this->client->loginUser($user, 'api');

        $this->patch($alert, []);

        self::assertResponseStatusCodeSame(200);
        $reloaded = $this->reload($alert);
        self::assertSame([AlertStatus::Active, 61.5, AlertDirection::Above], [$reloaded->getStatus(), $reloaded->getThreshold(), $reloaded->getDirection()]);
        self::assertNull($reloaded->getTriggeredAt());
    }

    public function testPatchingCannotChangeTheItemNorThePriceSourceOfAnAlert(): void
    {
        $user = $this->persistPilot();
        $alert = $this->persistAlert($user, self::ISOGEN, 'Isogen', AlertDirection::Above, 47.0, AlertPriceSource::JitaSell, AlertStatus::Active);
        $this->client->loginUser($user, 'api');

        $this->patch($alert, ['threshold' => 12.0, 'typeId' => self::PYERITE, 'typeName' => 'Pyerite', 'priceSource' => 'jita_buy']);

        self::assertResponseStatusCodeSame(200);
        $reloaded = $this->reload($alert);
        self::assertSame(
            [self::ISOGEN, 'Isogen', AlertPriceSource::JitaSell, 12.0, AlertStatus::Active],
            [$reloaded->getTypeId(), $reloaded->getTypeName(), $reloaded->getPriceSource(), $reloaded->getThreshold(), $reloaded->getStatus()],
        );
    }

    public function testPatchingTheAlertOfAnotherUserIsForbiddenAndLeavesItUntouched(): void
    {
        $owner = $this->persistPilot();
        $stranger = $this->persistPilot();
        $alert = $this->persistAlert($owner, self::PYERITE, 'Pyerite', AlertDirection::Below, 9.125, AlertPriceSource::JitaBuy, AlertStatus::Triggered);
        $this->client->loginUser($stranger, 'api');

        $this->patch($alert, ['threshold' => 8.0]);

        self::assertResponseStatusCodeSame(403);
        $reloaded = $this->reload($alert);
        self::assertSame([AlertStatus::Triggered, 9.125], [$reloaded->getStatus(), $reloaded->getThreshold()]);
    }

    #[DataProvider('invalidBodies')]
    public function testPatchingAZeroThresholdOrAnUnknownDirectionIsABadRequest(array $invalidBody): void
    {
        $user = $this->persistPilot();
        $alert = $this->persistAlert($user, self::MEXALLON, 'Mexallon', AlertDirection::Below, 61.5, AlertPriceSource::JitaSell, AlertStatus::Triggered);
        $this->client->loginUser($user, 'api');

        $this->patch($alert, $invalidBody);
        self::assertResponseStatusCodeSame(400);

        $reloaded = $this->reload($alert);
        self::assertSame([AlertStatus::Triggered, 61.5, AlertDirection::Below], [$reloaded->getStatus(), $reloaded->getThreshold(), $reloaded->getDirection()]);
    }

    public function testPatchingAnExpiredAlertIsAConflict(): void
    {
        $user = $this->persistPilot();
        $alert = $this->persistAlert($user, self::ISOGEN, 'Isogen', AlertDirection::Above, 47.0, AlertPriceSource::JitaSell, AlertStatus::Expired);
        $this->client->loginUser($user, 'api');

        $this->patch($alert, ['threshold' => 12.0]);

        self::assertResponseStatusCodeSame(409);
        $reloaded = $this->reload($alert);
        self::assertSame([AlertStatus::Expired, 47.0], [$reloaded->getStatus(), $reloaded->getThreshold()]);
    }

    private function persistPilot(): User
    {
        $user = new User();
        $this->em->persist($user);
        $character = (new Character())
            ->setEveCharacterId($this->nextEveCharacterId++)
            ->setName('Alert Pilot ' . $this->nextEveCharacterId)
            ->setCorporationId(98_537_001)
            ->setCorporationName('Verhoeven Deep Core');
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($character);
        $this->em->flush();

        return $user;
    }

    private function persistAlert(
        User $user,
        int $typeId,
        string $typeName,
        AlertDirection $direction,
        float $threshold,
        AlertPriceSource $priceSource,
        AlertStatus $status,
    ): MarketPriceAlert {
        $alert = (new MarketPriceAlert())
            ->setUser($user)
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setDirection($direction)
            ->setThreshold($threshold)
            ->setPriceSource($priceSource)
            ->setStatus($status);
        if ($status === AlertStatus::Triggered) {
            $alert->setTriggeredAt(new \DateTimeImmutable('2026-11-09T18:30:00+00:00'));
        }
        $this->em->persist($alert);
        $this->em->flush();

        return $alert;
    }

    private function reload(MarketPriceAlert $alert): MarketPriceAlert
    {
        $alertId = $alert->getId();
        self::assertNotNull($alertId);
        $this->em->clear();
        $reloaded = $this->em->find(MarketPriceAlert::class, $alertId);
        self::assertInstanceOf(MarketPriceAlert::class, $reloaded);

        return $reloaded;
    }

    public static function invalidBodies(): iterable
    {
        yield 'zero threshold' => [['threshold' => 0]];
        yield 'unknown direction' => [['direction' => 'sideways']];
    }

    private function patch(MarketPriceAlert $alert, array $body): void
    {
        $this->client->request(
            'PATCH',
            '/api/market/alerts/' . $alert->getId()?->toRfc4122(),
            server: ['CONTENT_TYPE' => 'application/merge-patch+json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode($body === [] ? new \stdClass() : $body, JSON_THROW_ON_ERROR),
        );
    }

    private function responseJson(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
