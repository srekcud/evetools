<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use App\Entity\IndustryProject;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\Project\Courier\CourierCandidate;
use App\Service\Industry\Project\Courier\CourierContract;
use App\Service\Industry\Project\Courier\CourierContractState;
use App\Service\Industry\Project\Courier\CourierLinkedContract;
use App\Service\Industry\Project\Courier\CourierOverview;
use App\Service\Industry\Project\Courier\CourierQuote;
use App\Service\TypeNameResolver;
use App\State\Provider\Industry\Project\ProjectCourierResourceMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectCourierResourceMapper::class)]
#[Group('issue-423')]
final class ProjectCourierResourceMapperTest extends TestCase
{
    private const int JITA_STATION = 60_003_760;
    private const int WORKSHOP_STRUCTURE = 1_035_466_617_946;
    private const int UNKNOWN_STRUCTURE = 1_035_466_000_001;
    private const string JITA_STATION_NAME = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant';
    private const string WORKSHOP_NAME = 'Perimeter - Ishtar Workshop';

    private ProjectCourierResourceMapper $mapper;

    protected function setUp(): void
    {
        $calculationService = $this->createStub(IndustryCalculationService::class);
        $calculationService->method('resolveFacilityName')->willReturnCallback(
            static fn (int $locationId): ?string => match ($locationId) {
                self::JITA_STATION => self::JITA_STATION_NAME,
                self::WORKSHOP_STRUCTURE => self::WORKSHOP_NAME,
                default => null,
            },
        );
        $typeNameResolver = $this->createStub(TypeNameResolver::class);
        $typeNameResolver->method('findNames')->willReturn([]);
        $this->mapper = new ProjectCourierResourceMapper($typeNameResolver, $calculationService);
    }

    public function testCandidatesCarryTheNamesOfTheirStartAndEndLocations(): void
    {
        $resource = $this->mapper->toResource(
            new IndustryProject(),
            $this->overview(null, [new CourierCandidate($this->contract(self::JITA_STATION, self::WORKSHOP_STRUCTURE), true)]),
        );

        self::assertSame(
            [self::JITA_STATION, self::JITA_STATION_NAME, self::WORKSHOP_STRUCTURE, self::WORKSHOP_NAME],
            [
                $resource->candidates[0]['startLocationId'], $resource->candidates[0]['startLocationName'],
                $resource->candidates[0]['endLocationId'], $resource->candidates[0]['endLocationName'],
            ],
        );
    }

    public function testTheLinkedContractCarriesTheNamesOfItsStartAndEndLocations(): void
    {
        $resource = $this->mapper->toResource(
            new IndustryProject(),
            $this->overview($this->linkedContract(self::JITA_STATION, self::WORKSHOP_STRUCTURE), []),
        );

        self::assertSame(
            [self::JITA_STATION_NAME, self::WORKSHOP_NAME],
            [$resource->contract['startLocationName'], $resource->contract['endLocationName']],
        );
    }

    public function testAnUnknownLocationNameIsNullAndItsIdIsKept(): void
    {
        $resource = $this->mapper->toResource(
            new IndustryProject(),
            $this->overview(null, [new CourierCandidate($this->contract(self::JITA_STATION, self::UNKNOWN_STRUCTURE), false)]),
        );

        self::assertSame(
            [self::JITA_STATION_NAME, self::UNKNOWN_STRUCTURE, null],
            [$resource->candidates[0]['startLocationName'], $resource->candidates[0]['endLocationId'], $resource->candidates[0]['endLocationName']],
        );
    }

    public function testAMissingLocationHasNoNameAndNoLookup(): void
    {
        $resource = $this->mapper->toResource(
            new IndustryProject(),
            $this->overview($this->linkedContract(null, null), []),
        );

        self::assertSame(
            [null, null, null, null],
            [
                $resource->contract['startLocationId'], $resource->contract['startLocationName'],
                $resource->contract['endLocationId'], $resource->contract['endLocationName'],
            ],
        );
    }

    public function testTheFiguresOfTheContractRowsAreUnchanged(): void
    {
        $resource = $this->mapper->toResource(
            new IndustryProject(),
            $this->overview(null, [new CourierCandidate($this->contract(self::JITA_STATION, self::WORKSHOP_STRUCTURE), true)]),
        );

        self::assertSame(
            [2_112_351_001, 'waiting', 25.0, 7_500.0, 376_550.0, true],
            [
                $resource->candidates[0]['characterId'], $resource->candidates[0]['state'], $resource->candidates[0]['volume'],
                $resource->candidates[0]['reward'], $resource->candidates[0]['collateral'], $resource->candidates[0]['matchesQuote'],
            ],
        );
    }

    private function contract(?int $startLocationId, ?int $endLocationId): CourierContract
    {
        return new CourierContract(
            9_001, 2_112_351_001, CourierContractState::Waiting, 25.0, 7_500.0, 376_550.0,
            $startLocationId, $endLocationId, new \DateTimeImmutable('2026-11-05T10:00:00+00:00'),
        );
    }

    private function linkedContract(?int $startLocationId, ?int $endLocationId): CourierLinkedContract
    {
        return new CourierLinkedContract(
            9_001, 2_112_351_001, CourierContractState::InTransit, null, 40.0, 9_000.0, 5_000_000.0,
            $startLocationId, $endLocationId, new \DateTimeImmutable('2026-11-03T10:00:00+00:00'), new \DateTimeImmutable('2026-11-06T09:00:00+00:00'),
        );
    }

    /**
     * @param list<CourierCandidate> $candidates
     */
    private function overview(?CourierLinkedContract $contract, array $candidates): CourierOverview
    {
        return new CourierOverview(
            new CourierQuote(25.0, 376_550, 7_500, 2, 'ready', []),
            30_000_142, 'Jita', 30_000_144, 'Perimeter', true, $contract, [], $candidates,
        );
    }
}
