<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Planetary;

use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\Sde\PlanetSchematic;
use App\Entity\Sde\PlanetSchematicType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[Group('issue-1396')]
final class PlanetaryPaidPriceApiTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const string NOW = '2026-11-26T12:00:00+00:00';
    private const int COMMODITIES_GROUP_ID = 990_342;
    private const int COMMODITIES_CATEGORY_ID = 990_366;
    private const int BACTERIA = 990_301;
    private const int OXYGEN = 990_302;
    private const int SCHEMATIC = 990_301;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $alice;
    private User $bob;
    private int $nextEveCharacterId = 2_139_601_001;
    private int $nextTransactionId = 6_139_600_001;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::NOW));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->seedSde();
        $this->alice = $this->persistPilot('Alice Tarn');
        $this->bob = $this->persistPilot('Bob Kess');
        $this->persistPurchase($this->alice, 10, 1_000.0, '2026-11-20T12:00:00+00:00');
        $this->persistPurchase($this->alice, 30, 2_000.0, '2026-11-21T12:00:00+00:00');
        $this->persistPurchase($this->bob, 5, 9_000.0, '2026-11-21T12:00:00+00:00');
        $this->em->flush();
    }

    public function testPaidPricesRequireAuthentication(): void
    {
        $this->client->request('GET', '/api/planetary/paid-prices', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testAUserSeesTheWeightedAveragePaidForTheirOwnPurchasesOnly(): void
    {
        $this->client->loginUser($this->alice, 'api');

        $json = $this->getPaidPrices();

        self::assertSame(90, $json['days']);
        self::assertSame([self::BACTERIA], array_column($json['prices'], 'typeId'));
        self::assertSame('Bacteria', $json['prices'][0]['typeName']);
        self::assertEqualsWithDelta(1_750.0, $json['prices'][0]['paidPrice'], 0.0001);
        self::assertSame([40, 2], [$json['prices'][0]['quantity'], $json['prices'][0]['purchaseCount']]);
    }

    public function testAnotherUserSeesTheirOwnFigures(): void
    {
        $this->client->loginUser($this->bob, 'api');

        $json = $this->getPaidPrices();

        self::assertEqualsWithDelta(9_000.0, $json['prices'][0]['paidPrice'], 0.0001);
        self::assertSame([5, 1], [$json['prices'][0]['quantity'], $json['prices'][0]['purchaseCount']]);
    }

    /**
     * @return array<string, mixed>
     */
    private function getPaidPrices(): array
    {
        $this->client->request('GET', '/api/planetary/paid-prices', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function seedSde(): void
    {
        $category = (new InvCategory())->setCategoryId(self::COMMODITIES_CATEGORY_ID)->setCategoryName('Planetary Commodities');
        $this->em->persist($category);
        $group = (new InvGroup())->setGroupId(self::COMMODITIES_GROUP_ID)->setGroupName('Basic Commodities')->setCategory($category);
        $this->em->persist($group);
        foreach ([self::BACTERIA => 'Bacteria', self::OXYGEN => 'Oxygen'] as $typeId => $typeName) {
            $this->em->persist((new InvType())->setTypeId($typeId)->setTypeName($typeName)->setGroup($group)->setVolume(0.38)->setPublished(true));
        }
        $schematic = (new PlanetSchematic())->setSchematicId(self::SCHEMATIC)->setSchematicName('Oxygen')->setCycleTime(3600);
        $this->em->persist($schematic);
        $this->em->persist((new PlanetSchematicType())->setSchematic($schematic)->setTypeId(self::BACTERIA)->setIsInput(true)->setQuantity(40));
        $this->em->persist((new PlanetSchematicType())->setSchematic($schematic)->setTypeId(self::OXYGEN)->setIsInput(false)->setQuantity(1));
        $this->em->flush();
    }

    private function persistPilot(string $name): User
    {
        $user = new User();
        $this->em->persist($user);
        $character = (new Character())
            ->setEveCharacterId($this->nextEveCharacterId++)
            ->setName($name)
            ->setCorporationId(98_539_601)
            ->setCorporationName('Verhoeven Deep Core');
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($character);
        $this->em->flush();

        return $user;
    }

    private function persistPurchase(User $user, int $quantity, float $unitPrice, string $date): void
    {
        $this->em->persist(
            (new CachedWalletTransaction())
                ->setCharacter($user->getMainCharacter())
                ->setTransactionId($this->nextTransactionId++)
                ->setIsBuy(true)
                ->setTypeId(self::BACTERIA)
                ->setQuantity($quantity)
                ->setUnitPrice($unitPrice)
                ->setDate(new \DateTimeImmutable($date))
                ->setLocationId(60003760),
        );
    }
}
