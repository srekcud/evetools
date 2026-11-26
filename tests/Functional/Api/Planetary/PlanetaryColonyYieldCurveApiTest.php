<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Planetary;

use App\Entity\Character;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\Sde\DgmAttributeType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[Group('issue-1386')]
final class PlanetaryColonyYieldCurveApiTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const string NOW = '2026-11-26T09:00:00+00:00';
    private const string INSTALL = '2026-11-26T08:00:00+00:00';
    private const string EXPIRY = '2026-11-28T08:00:00+00:00';

    private const int DECAY_ATTRIBUTE_ID = 1683;
    private const int NOISE_ATTRIBUTE_ID = 1687;
    private const int EXTRACTOR_PIN_ID = 1_038_600_000_001;
    private const int STORAGE_PIN_ID = 1_038_600_000_002;
    private const int EXTRACTOR_TYPE = 2_113_861;
    private const int STORAGE_TYPE = 2_113_862;
    private const int BACTERIA = 2_113_863;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $owner;
    private PlanetaryColony $colony;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::NOW));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->persistFactor(self::DECAY_ATTRIBUTE_ID, 0.012);
        $this->persistFactor(self::NOISE_ATTRIBUTE_ID, 0.8);

        $character = (new Character())
            ->setEveCharacterId(2_113_861_001)
            ->setName('Extractor Pilot')
            ->setCorporationId(98_538_001)
            ->setCorporationName('Verhoeven Deep Core');
        $this->owner = new User();
        $this->em->persist($this->owner);
        $this->owner->addCharacter($character);
        $this->owner->setMainCharacter($character);
        $this->em->persist($character);

        $this->colony = (new PlanetaryColony())
            ->setCharacter($character)
            ->setPlanetId(40_113_861)
            ->setPlanetName('Aldranette III')
            ->setPlanetType('barren')
            ->setSolarSystemId(30_001_538)
            ->setSolarSystemName('Aldranette')
            ->setUpgradeLevel(3)
            ->setNumPins(2)
            ->setLastUpdate(new \DateTimeImmutable(self::NOW));
        $this->colony->addPin(
            (new PlanetaryPin())
                ->setPinId(self::EXTRACTOR_PIN_ID)
                ->setTypeId(self::EXTRACTOR_TYPE)
                ->setTypeName('Barren Extractor Control Unit')
                ->setExtractorProductTypeId(self::BACTERIA)
                ->setExtractorCycleTime(1800)
                ->setExtractorQtyPerCycle(4400)
                ->setInstallTime(new \DateTimeImmutable(self::INSTALL))
                ->setExpiryTime(new \DateTimeImmutable(self::EXPIRY)),
        );
        $this->colony->addPin(
            (new PlanetaryPin())
                ->setPinId(self::STORAGE_PIN_ID)
                ->setTypeId(self::STORAGE_TYPE)
                ->setTypeName('Barren Storage Facility'),
        );
        $this->em->persist($this->colony);
        $this->em->flush();
    }

    public function testDetailExposesTheYieldCurveOfTheExtractorProgram(): void
    {
        $pin = $this->pinOfDetail(self::EXTRACTOR_PIN_ID);

        self::assertArrayHasKey('yieldCurve', $pin);
        self::assertIsArray($pin['yieldCurve']);
        self::assertSame(1800, $pin['yieldCurve']['cycleSeconds']);
        self::assertCount(96, $pin['yieldCurve']['yields']);
        self::assertEqualsWithDelta(497764.1957, $pin['yieldCurve']['totalQuantity'], 0.001);
        self::assertEqualsWithDelta(10370.0874, $pin['yieldCurve']['averagePerHour'], 0.001);
        self::assertSame(2, $pin['yieldCurve']['suggestedDays']);
    }

    public function testDetailCarriesANullYieldCurveKeyForAPinWhichIsNotAnExtractor(): void
    {
        $pin = $this->pinOfDetail(self::STORAGE_PIN_ID);

        self::assertArrayHasKey('yieldCurve', $pin);
        self::assertNull($pin['yieldCurve']);
    }

    private function persistFactor(int $attributeId, float $defaultValue): void
    {
        $attribute = $this->em->find(DgmAttributeType::class, $attributeId)
            ?? (new DgmAttributeType())->setAttributeId($attributeId)->setAttributeName('yield factor '.$attributeId);
        $this->em->persist($attribute->setDefaultValue($defaultValue));
    }

    /**
     * @return array<string, mixed>
     */
    private function pinOfDetail(int $pinId): array
    {
        $this->client->loginUser($this->owner, 'api');
        $this->client->request('GET', '/api/planetary/'.$this->colony->getId()?->toRfc4122(), server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);
        $detail = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $pins = array_column($detail['pins'], null, 'pinId');
        self::assertArrayHasKey($pinId, $pins);

        return $pins[$pinId];
    }
}
