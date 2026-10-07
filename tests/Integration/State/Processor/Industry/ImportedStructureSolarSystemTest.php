<?php

declare(strict_types=1);

namespace App\Tests\Integration\State\Processor\Industry;

use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Industry\CreateStructureInput;
use App\ApiResource\Input\Industry\UpdateStructureInput;
use App\Entity\CachedStructure;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStructureConfig;
use App\Entity\IndustryUserSettings;
use App\Entity\User;
use App\Repository\IndustryStructureConfigRepository;
use App\Service\Industry\IndustryCalculationService;
use App\State\Processor\Industry\CreateStructureProcessor;
use App\State\Processor\Industry\UpdateStructureProcessor;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

/**
 * Issue #77: an imported production structure (known ESI locationId) records its solar system,
 * resolved server-side from the structure cache the ESI search and the corporation list already
 * fill (CachedStructure). The favorite system can then pick it for a step.
 *
 * Processors are taken from the container (authenticated through the token storage) so the test
 * does not depend on their constructor signature.
 */
final class ImportedStructureSolarSystemTest extends IntegrationTestCase
{
    private const int CORPORATION_ID = 98_000_001;
    private const int RAITARU_LOCATION_ID = 1_035_466_617_946;
    private const int UNCACHED_LOCATION_ID = 1_035_466_617_948;
    private const int FAVORITE_SYSTEM_ID = 30_000_142;
    private const int OTHER_SYSTEM_ID = 30_002_187;
    /** Not in the SDE: no rig category, only the base structure bonuses apply. */
    private const int PRODUCT_TYPE_ID = 999_999_001;

    private User $pilot;

    protected function setUp(): void
    {
        parent::setUp();
        $character = $this->createCharacter(name: 'Industry Pilot'); // corporation 98_000_001
        $pilot = $character->getUser();
        \assert($pilot instanceof User);
        $pilot->setMainCharacter($character);
        $this->pilot = $pilot;
        $this->createCachedStructure(self::RAITARU_LOCATION_ID, self::FAVORITE_SYSTEM_ID);
        $this->em->flush();

        self::getContainer()->get('security.token_storage')
            ->setToken(new UsernamePasswordToken($this->pilot, 'api', $this->pilot->getRoles()));
    }

    public function testImportedStructureRecordsTheSolarSystemOfTheCachedStructure(): void
    {
        $this->import(self::RAITARU_LOCATION_ID, solarSystemId: null);

        self::assertSame(self::FAVORITE_SYSTEM_ID, $this->storedStructure(self::RAITARU_LOCATION_ID)->getSolarSystemId());
    }

    public function testImportedStructureKeepsTheCachedSolarSystemOverTheSubmittedOne(): void
    {
        $this->import(self::RAITARU_LOCATION_ID, solarSystemId: self::OTHER_SYSTEM_ID);

        self::assertSame(self::FAVORITE_SYSTEM_ID, $this->storedStructure(self::RAITARU_LOCATION_ID)->getSolarSystemId());
    }

    public function testImportedStructureNotInTheCacheKeepsTheSubmittedSolarSystem(): void
    {
        $this->import(self::UNCACHED_LOCATION_ID, solarSystemId: self::OTHER_SYSTEM_ID);

        self::assertSame(self::OTHER_SYSTEM_ID, $this->storedStructure(self::UNCACHED_LOCATION_ID)->getSolarSystemId());
    }

    public function testImportedStructureWithUnknownSolarSystemStaysUnknown(): void
    {
        $this->import(self::UNCACHED_LOCATION_ID, solarSystemId: null);

        self::assertNull($this->storedStructure(self::UNCACHED_LOCATION_ID)->getSolarSystemId());
    }

    public function testReimportedDeletedStructureRecordsTheCachedSolarSystem(): void
    {
        $deleted = $this->storeImportedStructureWithoutSolarSystem(self::RAITARU_LOCATION_ID, 'raitaru')
            ->hideForUser($this->userIdOf($this->pilot))
            ->setIsDeleted(true);
        $this->em->flush();

        $this->import(self::RAITARU_LOCATION_ID, solarSystemId: null);

        $restored = $this->storedStructure(self::RAITARU_LOCATION_ID);
        self::assertSame($deleted->getId()?->toRfc4122(), $restored->getId()?->toRfc4122());
        self::assertSame(self::FAVORITE_SYSTEM_ID, $restored->getSolarSystemId());
    }

    public function testUpdatingAnImportedStructureStoredWithoutSolarSystemRecordsTheCachedOne(): void
    {
        $structure = $this->storeImportedStructureWithoutSolarSystem(self::RAITARU_LOCATION_ID, 'raitaru');
        $this->em->flush();

        $input = new UpdateStructureInput();
        $input->name = 'Renamed Raitaru';
        $this->updateProcessor()->process($input, new Patch(), ['id' => $structure->getId()?->toRfc4122()]);

        self::assertSame(self::FAVORITE_SYSTEM_ID, $this->storedStructure(self::RAITARU_LOCATION_ID)->getSolarSystemId());
    }

    /**
     * Without the solar system, the favorite system has no structure and the global best
     * (the Sotiyo, 30 % time bonus) wins over the Raitaru that sits in the favorite system.
     */
    public function testStepWithoutAssignedStructureUsesTheImportedStructureOfTheFavoriteSystem(): void
    {
        $this->storeSotiyoInAnotherSystem();
        $this->setFavoriteManufacturingSystem(self::FAVORITE_SYSTEM_ID);
        $this->import(self::RAITARU_LOCATION_ID, solarSystemId: null);

        $step = $this->manufacturingStepWithoutStructure();
        $bonus = self::getContainer()->get(IndustryCalculationService::class)->getStructureBonusForStep($step);

        self::assertSame('Favorite Raitaru', $bonus['name']);
        self::assertSame(15.0, $bonus['timeBonus']);
        self::assertSame(['total' => 1.0, 'base' => 1.0, 'rig' => 0.0], $bonus['materialBonus']);
    }

    private function import(int $locationId, ?int $solarSystemId): void
    {
        // Payload the frontend sends after picking a structure in the ESI search
        $input = new CreateStructureInput();
        $input->name = 'Favorite Raitaru';
        $input->locationId = $locationId;
        $input->solarSystemId = $solarSystemId;
        $input->securityType = 'nullsec';
        $input->structureType = 'raitaru';
        $input->rigs = [];

        self::getContainer()->get(CreateStructureProcessor::class)->process($input, new Post());
    }

    private function updateProcessor(): UpdateStructureProcessor
    {
        return self::getContainer()->get(UpdateStructureProcessor::class);
    }

    /** State left in production by imports made before the fix: locationId known, no solar system. */
    private function storeImportedStructureWithoutSolarSystem(int $locationId, string $structureType): IndustryStructureConfig
    {
        $structure = (new IndustryStructureConfig())
            ->setUser($this->pilot)
            ->setName('Favorite Raitaru')
            ->setLocationId($locationId)
            ->setCorporationId(self::CORPORATION_ID)
            ->setSecurityType('nullsec')
            ->setStructureType($structureType)
            ->setRigs([]);
        $this->em->persist($structure);

        return $structure;
    }

    private function storeSotiyoInAnotherSystem(): void
    {
        $sotiyo = (new IndustryStructureConfig())
            ->setUser($this->pilot)
            ->setName('Remote Sotiyo')
            ->setSolarSystemId(self::OTHER_SYSTEM_ID)
            ->setSecurityType('nullsec')
            ->setStructureType('sotiyo')
            ->setRigs([]);
        $this->em->persist($sotiyo);
        $this->em->flush();
    }

    private function setFavoriteManufacturingSystem(int $solarSystemId): void
    {
        $settings = (new IndustryUserSettings())
            ->setUser($this->pilot)
            ->setFavoriteManufacturingSystemId($solarSystemId);
        $this->em->persist($settings);
        $this->em->flush();
    }

    private function manufacturingStepWithoutStructure(): IndustryProjectStep
    {
        $project = (new IndustryProject())
            ->setUser($this->pilot)
            ->setProductTypeId(self::PRODUCT_TYPE_ID)
            ->setRuns(10);

        return (new IndustryProjectStep())
            ->setProject($project)
            ->setProductTypeId(self::PRODUCT_TYPE_ID)
            ->setActivityType('manufacturing')
            ->setRuns(10);
    }

    private function storedStructure(int $locationId): IndustryStructureConfig
    {
        $this->em->clear();
        $rows = self::getContainer()->get(IndustryStructureConfigRepository::class)
            ->findBy(['locationId' => $locationId, 'isDeleted' => false]);
        self::assertCount(1, $rows);

        return $rows[0];
    }

    private function createCachedStructure(int $locationId, int $solarSystemId): void
    {
        $cached = (new CachedStructure())
            ->setStructureId($locationId)
            ->setName('Favorite Raitaru')
            ->setSolarSystemId($solarSystemId)
            ->setOwnerCorporationId(self::CORPORATION_ID)
            ->setTypeId(35825)
            ->setResolvedAt(new \DateTimeImmutable('2026-10-01'));
        $this->em->persist($cached);
    }

    private function userIdOf(User $user): string
    {
        $id = $user->getId();
        \assert($id instanceof Uuid);

        return $id->toRfc4122();
    }
}
