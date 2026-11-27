<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Planetary;

use ApiPlatform\Metadata\Patch;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Planetary\PlanetarySettingsResource;
use App\Dto\Assets\AssetScope;
use App\Entity\Character;
use App\Entity\User;
use App\Repository\AssetBrowseRepository;
use App\Service\Planetary\PlanetaryHomeLocationCatalog;
use App\State\Processor\Planetary\PlanetarySettingsProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(PlanetarySettingsProcessor::class)]
#[Group('issue-1394')]
final class PlanetarySettingsProcessorTest extends TestCase
{
    private const string UNKNOWN_LOCATION_MESSAGE = 'This location is not known to any of your characters.';
    private const string ALMA_UUID = '0193a000-0000-7000-8000-000000001394';
    private const int DEFAULT_STRUCTURE_ID = 1_049_588_174_021;
    private const string DEFAULT_STRUCTURE_NAME = 'C-J6MT - 1st Taj Mahgoon (Keepstar)';
    private const int PREFERRED_STRUCTURE_ID = 1_028_858_195_007;
    private const string PREFERRED_STRUCTURE_NAME = 'Ashab - Preferred Fortizar';
    private const int CANDIDATE_STATION_ID = 60_008_494;
    private const string CANDIDATE_STATION_NAME = 'Amarr VIII (Oris) - Emperor Family Academy';
    private const int CANDIDATE_STRUCTURE_ID = 1_030_000_000_123;
    private const string CANDIDATE_STRUCTURE_NAME = 'Ashab - Market Fortizar';
    private const int STORED_HOME_ID = 1_030_000_000_777;
    private const string STORED_HOME_NAME = 'Old Home Raitaru';
    private const int UNKNOWN_LOCATION_ID = 1_030_000_000_999;

    private User $user;
    private int $flushCount = 0;

    protected function setUp(): void
    {
        $alma = (new Character())->setName('Alma Voss');
        (new \ReflectionProperty(Character::class, 'id'))->setValue($alma, Uuid::fromString(self::ALMA_UUID));
        $this->user = (new User())->addCharacter($alma);
    }

    public function testAMinimumOfOneWeekIsAccepted(): void
    {
        $resource = $this->process($this->resourceWith(coverageWeeks: 1));

        $this->assertSame(1, $this->user->getPlanetaryCoverageWeeks());
        $this->assertSame(1, $resource->coverageWeeks);
        $this->assertSame(1, $this->flushCount);
    }

    public function testAMaximumOfFiftyTwoWeeksIsAccepted(): void
    {
        $resource = $this->process($this->resourceWith(coverageWeeks: 52));

        $this->assertSame(52, $this->user->getPlanetaryCoverageWeeks());
        $this->assertSame(52, $resource->coverageWeeks);
        $this->assertSame(1, $this->flushCount);
    }

    public function testZeroWeeksIsRefusedOnCoverageWeeksAndTheStoredValueIsKept(): void
    {
        $this->user->setPlanetaryCoverageWeeks(5);

        $this->assertRefused(['coverageWeeks'], $this->resourceWith(coverageWeeks: 0));

        $this->assertSame(5, $this->user->getPlanetaryCoverageWeeks());
        $this->assertSame(0, $this->flushCount);
    }

    public function testFiftyThreeWeeksIsRefusedOnCoverageWeeksAndTheStoredValueIsKept(): void
    {
        $this->user->setPlanetaryCoverageWeeks(5);

        $this->assertRefused(['coverageWeeks'], $this->resourceWith(coverageWeeks: 53));

        $this->assertSame(5, $this->user->getPlanetaryCoverageWeeks());
        $this->assertSame(0, $this->flushCount);
    }

    public function testANegativeNumberOfWeeksIsRefusedOnCoverageWeeks(): void
    {
        $this->assertRefused(['coverageWeeks'], $this->resourceWith(coverageWeeks: -3));

        $this->assertSame(3, $this->user->getPlanetaryCoverageWeeks());
    }

    public function testAHomeLocationOfOneOfTheCharactersIsStoredWithTheNameOfTheCandidate(): void
    {
        $resource = $this->resourceWith(coverageWeeks: 3, homeLocationId: self::CANDIDATE_STRUCTURE_ID);
        $resource->homeLocationName = 'Name sent by the client';

        $result = $this->process($resource);

        $this->assertSame(self::CANDIDATE_STRUCTURE_ID, $this->user->getPlanetaryHomeLocationId());
        $this->assertSame(self::CANDIDATE_STRUCTURE_NAME, $this->user->getPlanetaryHomeLocationName());
        $this->assertSame(self::CANDIDATE_STRUCTURE_ID, $result->homeLocationId);
        $this->assertSame(self::CANDIDATE_STRUCTURE_NAME, $result->homeLocationName);
        $this->assertSame(1, $this->flushCount);
    }

    public function testAHomeLocationUnknownToTheCharactersIsRefusedWithTheExactMessageAndNothingIsWritten(): void
    {
        $this->user->setPlanetaryHomeLocation(self::STORED_HOME_ID, self::STORED_HOME_NAME);

        try {
            $this->process($this->resourceWith(coverageWeeks: 3, homeLocationId: self::UNKNOWN_LOCATION_ID));
            $this->fail('A location unknown to the characters must be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                [['homeLocationId', self::UNKNOWN_LOCATION_MESSAGE]],
                $this->violationsOf($exception),
            );
        }

        $this->assertSame(self::STORED_HOME_ID, $this->user->getPlanetaryHomeLocationId());
        $this->assertSame(self::STORED_HOME_NAME, $this->user->getPlanetaryHomeLocationName());
        $this->assertSame(0, $this->flushCount);
    }

    public function testAValidCoverageIsNotWrittenWhenTheHomeLocationOfTheSamePatchIsRefused(): void
    {
        $this->user->setPlanetaryCoverageWeeks(5);

        $this->assertRefused(['homeLocationId'], $this->resourceWith(coverageWeeks: 9, homeLocationId: self::UNKNOWN_LOCATION_ID));

        $this->assertSame(5, $this->user->getPlanetaryCoverageWeeks());
        $this->assertSame(0, $this->flushCount);
    }

    public function testANullHomeLocationClearsTheIdentifierAndTheName(): void
    {
        $this->user->setPlanetaryHomeLocation(self::CANDIDATE_STATION_ID, self::CANDIDATE_STATION_NAME);

        $result = $this->process($this->resourceWith(coverageWeeks: 3, homeLocationId: null));

        $this->assertNull($this->user->getPlanetaryHomeLocationId());
        $this->assertNull($this->user->getPlanetaryHomeLocationName());
        $this->assertNull($result->homeLocationId);
        $this->assertNull($result->homeLocationName);
        $this->assertSame(1, $this->flushCount);
    }

    public function testAStoredHomeLocationThatIsNoLongerACandidateSurvivesAPatchOfTheCoverageAlone(): void
    {
        $this->user->setPlanetaryHomeLocation(self::STORED_HOME_ID, self::STORED_HOME_NAME);

        $result = $this->process($this->resourceWith(coverageWeeks: 6, homeLocationId: self::STORED_HOME_ID));

        $this->assertSame(6, $this->user->getPlanetaryCoverageWeeks());
        $this->assertSame(self::STORED_HOME_ID, $this->user->getPlanetaryHomeLocationId());
        $this->assertSame(self::STORED_HOME_NAME, $this->user->getPlanetaryHomeLocationName());
        $this->assertSame(self::STORED_HOME_ID, $result->homeLocationId);
        $this->assertSame(self::STORED_HOME_NAME, $result->homeLocationName);
    }

    public function testTheReturnedResourceCarriesTheEffectiveHubOfTheUser(): void
    {
        $this->user
            ->setPreferredMarketStructureId(self::PREFERRED_STRUCTURE_ID)
            ->setPreferredMarketStructureName(self::PREFERRED_STRUCTURE_NAME);

        $result = $this->process($this->resourceWith(coverageWeeks: 8));

        $this->assertSame(self::PREFERRED_STRUCTURE_ID, $result->hubStructureId);
        $this->assertSame(self::PREFERRED_STRUCTURE_NAME, $result->hubStructureName);
        $this->assertSame(8, $result->coverageWeeks);
    }

    public function testTheReturnedResourceFallsBackToTheConfiguredHubWhenTheUserHasNone(): void
    {
        $result = $this->process($this->resourceWith(coverageWeeks: 8));

        $this->assertSame(self::DEFAULT_STRUCTURE_ID, $result->hubStructureId);
        $this->assertSame(self::DEFAULT_STRUCTURE_NAME, $result->hubStructureName);
    }

    #[Group('issue-1458')]
    public function testExcludedTypeIdsAreSortedWithoutDuplicatesStoredAndReturned(): void
    {
        $result = $this->process($this->resourceWith(coverageWeeks: 3, excludedTypeIds: [3, 1, 3]));

        $this->assertSame([1, 3], $this->user->getPlanetaryExcludedTypeIds());
        $this->assertSame([1, 3], $result->excludedTypeIds);
        $this->assertSame(1, $this->flushCount);
    }

    #[Group('issue-1458')]
    public function testAnEmptyListRestoresEveryExcludedType(): void
    {
        $this->user->setPlanetaryExcludedTypeIds([1, 3]);
        $this->assertSame([1, 3], $this->user->getPlanetaryExcludedTypeIds());

        $result = $this->process($this->resourceWith(coverageWeeks: 3, excludedTypeIds: []));

        $this->assertSame([], $this->user->getPlanetaryExcludedTypeIds());
        $this->assertSame([], $result->excludedTypeIds);
        $this->assertSame(1, $this->flushCount);
    }

    #[Group('issue-1458')]
    public function testAZeroTypeIdIsRefusedOnExcludedTypeIdsAndTheStoredListIsKept(): void
    {
        $this->user->setPlanetaryExcludedTypeIds([1, 3]);
        $this->user->setPlanetaryCoverageWeeks(5);

        $this->assertRefused(['excludedTypeIds'], $this->resourceWith(coverageWeeks: 9, excludedTypeIds: [0]));

        $this->assertSame([1, 3], $this->user->getPlanetaryExcludedTypeIds());
        $this->assertSame(5, $this->user->getPlanetaryCoverageWeeks());
        $this->assertSame(0, $this->flushCount);
    }

    #[Group('issue-1458')]
    public function testANegativeTypeIdIsRefusedOnExcludedTypeIds(): void
    {
        $this->assertRefused(['excludedTypeIds'], $this->resourceWith(coverageWeeks: 3, excludedTypeIds: [4, -5]));

        $this->assertSame([], $this->user->getPlanetaryExcludedTypeIds());
    }

    #[Group('issue-1458')]
    public function testTwoHundredExcludedTypesAreAcceptedAndTwoHundredAndOneAreRefused(): void
    {
        $accepted = $this->process($this->resourceWith(coverageWeeks: 3, excludedTypeIds: range(1, 200)));

        $this->assertSame(range(1, 200), $accepted->excludedTypeIds);
        $this->assertSame(range(1, 200), $this->user->getPlanetaryExcludedTypeIds());

        $this->assertRefused(['excludedTypeIds'], $this->resourceWith(coverageWeeks: 3, excludedTypeIds: range(1, 201)));

        $this->assertSame(range(1, 200), $this->user->getPlanetaryExcludedTypeIds());
    }

    public function testAnAnonymousVisitorIsRejected(): void
    {
        $this->expectException(UnauthorizedHttpException::class);

        $this->processAs(null, $this->resourceWith(coverageWeeks: 4));
    }

    /**
     * @param list<int> $excludedTypeIds
     */
    private function resourceWith(int $coverageWeeks, ?int $homeLocationId = null, array $excludedTypeIds = []): PlanetarySettingsResource
    {
        $resource = new PlanetarySettingsResource();
        $resource->coverageWeeks = $coverageWeeks;
        $resource->homeLocationId = $homeLocationId;
        $resource->excludedTypeIds = $excludedTypeIds;

        return $resource;
    }

    private function process(PlanetarySettingsResource $data): PlanetarySettingsResource
    {
        return $this->processAs($this->user, $data);
    }

    private function processAs(?User $user, PlanetarySettingsResource $data): PlanetarySettingsResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushCount;
        });

        $assetBrowse = $this->createStub(AssetBrowseRepository::class);
        $assetBrowse->method('summarizeLocations')->willReturnCallback(
            static fn (AssetScope $scope): array => $scope->characterId === self::ALMA_UUID
                ? [
                    ['locationId' => self::CANDIDATE_STATION_ID, 'locationType' => 'station', 'locationName' => self::CANDIDATE_STATION_NAME, 'solarSystemName' => 'Amarr', 'itemCount' => 4, 'shipCount' => 0, 'divisions' => [], 'cachedAt' => '2026-11-26T08:00:00+00:00'],
                    ['locationId' => self::CANDIDATE_STRUCTURE_ID, 'locationType' => 'structure', 'locationName' => self::CANDIDATE_STRUCTURE_NAME, 'solarSystemName' => 'Ashab', 'itemCount' => 9, 'shipCount' => 1, 'divisions' => [], 'cachedAt' => '2026-11-26T08:00:00+00:00'],
                ]
                : [],
        );

        $processor = new PlanetarySettingsProcessor(
            $security,
            $entityManager,
            new PlanetaryHomeLocationCatalog($assetBrowse),
            self::DEFAULT_STRUCTURE_ID,
            self::DEFAULT_STRUCTURE_NAME,
        );

        return $processor->process($data, new Patch());
    }

    /**
     * @param list<string> $propertyPaths
     */
    private function assertRefused(array $propertyPaths, PlanetarySettingsResource $data): void
    {
        try {
            $this->process($data);
            $this->fail('The patch must be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame($propertyPaths, array_column($this->violationsOf($exception), 0));
        }
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function violationsOf(ValidationException $exception): array
    {
        $violations = [];
        foreach ($exception->getConstraintViolationList() as $violation) {
            $violations[] = [$violation->getPropertyPath(), (string) $violation->getMessage()];
        }

        return $violations;
    }
}
