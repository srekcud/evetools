<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use App\ApiResource\Industry\Project\IndustryProjectResource;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectProduct;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\State\Provider\Industry\Project\ProjectResourceMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectResourceMapper::class)]
#[Group('issue-156')]
final class ProjectResourceMapperProjectTest extends TestCase
{
    private const int TRITANIUM_TYPE_ID = 34;
    private const int PYERITE_TYPE_ID = 35;
    private const int MEXALLON_TYPE_ID = 36;
    private const int MORPHITE_TYPE_ID = 11399;
    private const int SABRE_TYPE_ID = 22456;

    private ProjectResourceMapper $mapper;
    private IndustryProject $project;
    private IndustryProjectParticipant $ownerMembership;

    protected function setUp(): void
    {
        $this->mapper = new ProjectResourceMapper();

        $owner = new User();
        $this->project = ((new IndustryProject())->addItem((new IndustryProjectProduct())->setTypeId(587)->setTypeName('Sabre')->setRuns(1)))->setOwner($owner)->setName('Sabre batch');
        (new \ReflectionProperty(IndustryProject::class, 'id'))->setValue($this->project, Uuid::v4());
        $this->ownerMembership = (new IndustryProjectParticipant())
            ->setUser($owner)
            ->setRole(ParticipantRole::Owner)
            ->setStatus(ParticipantStatus::Accepted);
        $this->project->addMember($this->ownerMembership);
    }

    #[Group('issue-50')]
    public function testResourceExposesTheSellStructureOfTheProjectOrNothing(): void
    {
        self::assertSame([null, null], [$this->resource()->sellStructureId, $this->resource()->sellStructureName]);

        $this->project->setSellStructure(60003760, 'Jita 4-4');

        self::assertSame([60003760, 'Jita 4-4'], [$this->resource()->sellStructureId, $this->resource()->sellStructureName]);
    }

    public function testBomValueIsUnknownWhenOneMaterialHasNoPrice(): void
    {
        $this->addMaterial(self::TRITANIUM_TYPE_ID, estimatedPrice: 10.0, requiredQuantity: 1_000);
        $this->addMaterial(self::MORPHITE_TYPE_ID, estimatedPrice: null, requiredQuantity: 50);

        self::assertNull($this->resource()->totalBomValue);
    }

    public function testBomValueIsTheSumOfThePricedRequiredQuantitiesOfTheMaterials(): void
    {
        $this->addMaterial(self::TRITANIUM_TYPE_ID, estimatedPrice: 10.0, requiredQuantity: 1_000);
        $this->addMaterial(self::MORPHITE_TYPE_ID, estimatedPrice: 2_000.0, requiredQuantity: 5);

        self::assertSame(20_000.0, $this->resource()->totalBomValue);
    }

    public function testBomValueAddsMaterialsOfDifferentValues(): void
    {
        $this->addMaterial(self::TRITANIUM_TYPE_ID, estimatedPrice: 10.0, requiredQuantity: 1_000);
        $this->addMaterial(self::MORPHITE_TYPE_ID, estimatedPrice: 2_000.0, requiredQuantity: 7);
        $this->addMaterial(self::PYERITE_TYPE_ID, estimatedPrice: 12.5, requiredQuantity: 40);

        self::assertSame(24_500.0, $this->resource()->totalBomValue);
    }

    public function testMaterialWithAKnownPriceOfZeroHasABomValueOfZero(): void
    {
        $this->addMaterial(self::TRITANIUM_TYPE_ID, estimatedPrice: 0.0, requiredQuantity: 100);

        self::assertSame(0.0, $this->resource()->totalBomValue);
    }

    public function testMaterialWithAKnownPriceOfZeroNextToAnUnpricedMaterialLeavesTheBomValueUnknown(): void
    {
        $this->addMaterial(self::TRITANIUM_TYPE_ID, estimatedPrice: 0.0, requiredQuantity: 100);
        $this->addMaterial(self::MORPHITE_TYPE_ID, estimatedPrice: null, requiredQuantity: 50);

        self::assertNull($this->resource()->totalBomValue);
    }

    public static function positionOfTheOnlyUnpricedMaterial(): iterable
    {
        yield 'first of three' => [0];
        yield 'second of three' => [1];
        yield 'last of three' => [2];
    }

    #[DataProvider('positionOfTheOnlyUnpricedMaterial')]
    public function testOneUnpricedMaterialAmongSeveralPricedOnesLeavesTheBomValueUnknownWhateverItsPosition(int $unpricedPosition): void
    {
        $materials = [
            [self::TRITANIUM_TYPE_ID, 4.0, 1_000_000],
            [self::PYERITE_TYPE_ID, 10.0, 200_000],
            [self::MEXALLON_TYPE_ID, 50.0, 300_000],
        ];
        foreach ($materials as $position => [$typeId, $estimatedPrice, $requiredQuantity]) {
            $this->addMaterial($typeId, $position === $unpricedPosition ? null : $estimatedPrice, $requiredQuantity);
        }

        self::assertNull($this->resource()->totalBomValue);
    }

    public function testUnpricedMaterialNextToAJobLineLeavesTheBomValueUnknown(): void
    {
        $this->addMaterial(self::TRITANIUM_TYPE_ID, estimatedPrice: null, requiredQuantity: 1_000);
        $this->addJobLine(self::SABRE_TYPE_ID, requiredQuantity: 10, fulfilledQuantity: 0, estimatedPrice: null);

        self::assertNull($this->resource()->totalBomValue);
    }

    public function testJobLineWithoutPriceDoesNotMakeTheBomValueUnknown(): void
    {
        $this->addMaterial(self::TRITANIUM_TYPE_ID, estimatedPrice: 10.0, requiredQuantity: 1_000);
        $this->addJobLine(self::SABRE_TYPE_ID, requiredQuantity: 10, fulfilledQuantity: 0, estimatedPrice: null);

        self::assertSame(10_000.0, $this->resource()->totalBomValue);
    }

    public function testJobLineWithAPriceDoesNotEnterTheBomValue(): void
    {
        $this->addMaterial(self::TRITANIUM_TYPE_ID, estimatedPrice: 10.0, requiredQuantity: 1_000);
        $this->addJobLine(self::SABRE_TYPE_ID, requiredQuantity: 10, fulfilledQuantity: 0, estimatedPrice: 50_000_000.0);

        self::assertSame(10_000.0, $this->resource()->totalBomValue);
    }

    public function testBomProgressIsTheMeanOfTheProgressOfEachMaterialWhateverItsUnits(): void
    {
        $this->addMaterial(self::TRITANIUM_TYPE_ID, estimatedPrice: 4.0, requiredQuantity: 1_000_000, fulfilledQuantity: 500_000);
        $this->addMaterial(self::MORPHITE_TYPE_ID, estimatedPrice: 2_000.0, requiredQuantity: 10, fulfilledQuantity: 10);

        self::assertSame(75.0, $this->resource()->fulfillmentPercent);
    }

    public function testBomProgressCapsAnOverDeliveredMaterialAtOneHundredPercent(): void
    {
        $this->addMaterial(self::TRITANIUM_TYPE_ID, estimatedPrice: 4.0, requiredQuantity: 100, fulfilledQuantity: 150);
        $this->addMaterial(self::MORPHITE_TYPE_ID, estimatedPrice: 2_000.0, requiredQuantity: 100, fulfilledQuantity: 0);

        self::assertSame(50.0, $this->resource()->fulfillmentPercent);
    }

    public function testBomProgressIsRoundedToTwoDecimals(): void
    {
        $this->addMaterial(self::TRITANIUM_TYPE_ID, estimatedPrice: 4.0, requiredQuantity: 300, fulfilledQuantity: 100);
        $this->addMaterial(self::PYERITE_TYPE_ID, estimatedPrice: 10.0, requiredQuantity: 300, fulfilledQuantity: 100);
        $this->addMaterial(self::MEXALLON_TYPE_ID, estimatedPrice: 50.0, requiredQuantity: 300, fulfilledQuantity: 100);

        self::assertSame(33.33, $this->resource()->fulfillmentPercent);
    }

    public function testBomProgressRoundsUpTheSecondDecimal(): void
    {
        $this->addMaterial(self::MEXALLON_TYPE_ID, estimatedPrice: 50.0, requiredQuantity: 300_000, fulfilledQuantity: 100_000);
        $this->addMaterial(self::MORPHITE_TYPE_ID, estimatedPrice: null, requiredQuantity: 100_000, fulfilledQuantity: 0);

        self::assertSame(16.67, $this->resource()->fulfillmentPercent);
    }

    public function testBomProgressIgnoresAnUndeliveredJobLine(): void
    {
        $this->addMaterial(self::TRITANIUM_TYPE_ID, estimatedPrice: 4.0, requiredQuantity: 100, fulfilledQuantity: 100);
        $this->addJobLine(self::SABRE_TYPE_ID, requiredQuantity: 10, fulfilledQuantity: 0, estimatedPrice: null);

        self::assertSame(100.0, $this->resource()->fulfillmentPercent);
    }

    public function testBomProgressIsKnownWhenAMaterialHasNoPrice(): void
    {
        $this->addMaterial(self::TRITANIUM_TYPE_ID, estimatedPrice: null, requiredQuantity: 400, fulfilledQuantity: 100);
        $this->addMaterial(self::MORPHITE_TYPE_ID, estimatedPrice: null, requiredQuantity: 20, fulfilledQuantity: 15);

        self::assertSame(50.0, $this->resource()->fulfillmentPercent);
    }

    public function testProjectWithoutMaterialHasABomValueAndProgressOfZero(): void
    {
        $this->addJobLine(self::SABRE_TYPE_ID, requiredQuantity: 10, fulfilledQuantity: 4, estimatedPrice: null);

        $resource = $this->resource();

        self::assertSame([0.0, 0.0], [$resource->totalBomValue, $resource->fulfillmentPercent]);
    }

    public function testApprovedContributionValueAddsTheApprovedContributionsOfEveryMemberAndType(): void
    {
        $brann = $this->acceptedMember();
        $this->addContribution($this->ownerMembership, ContributionType::Material, 1_000_000.0, ContributionStatus::Approved);
        $this->addContribution($brann, ContributionType::LineRental, 250_000.0, ContributionStatus::Approved);
        $this->addContribution($brann, ContributionType::Material, 500_000.0, ContributionStatus::Pending);
        $this->addContribution($this->ownerMembership, ContributionType::JobInstall, 100_000.0, ContributionStatus::Rejected);

        self::assertSame(1_250_000.0, $this->resource()->approvedContributionValue);
    }

    public function testApprovedContributionValueCountsJobInstallAndBpcContributions(): void
    {
        $brann = $this->acceptedMember();
        $this->addContribution($this->ownerMembership, ContributionType::JobInstall, 37_500.0, ContributionStatus::Approved);
        $this->addContribution($brann, ContributionType::Bpc, 820_000.0, ContributionStatus::Approved);

        self::assertSame(857_500.0, $this->resource()->approvedContributionValue);
    }

    public function testProjectWithoutApprovedContributionHasAnApprovedContributionValueOfZero(): void
    {
        $this->addContribution($this->ownerMembership, ContributionType::Material, 500_000.0, ContributionStatus::Pending);
        $this->addContribution($this->ownerMembership, ContributionType::Material, 100_000.0, ContributionStatus::Rejected);

        self::assertSame(0.0, $this->resource()->approvedContributionValue);
    }

    #[Group('issue-363')]
    public function testApprovedContributionValueIsUnknownWhenAnApprovedContributionHasNoValueNeverPartial(): void
    {
        $brann = $this->acceptedMember();
        $this->addContribution($this->ownerMembership, ContributionType::Material, 1_000_000.0, ContributionStatus::Approved);
        $this->addContribution($brann, ContributionType::JobInstall, null, ContributionStatus::Approved);

        self::assertNull($this->resource()->approvedContributionValue);
    }

    #[Group('issue-363')]
    public function testPendingContributionWithoutValueDoesNotMakeTheApprovedContributionValueUnknown(): void
    {
        $this->addContribution($this->ownerMembership, ContributionType::Material, 1_000_000.0, ContributionStatus::Approved);
        $this->addContribution($this->acceptedMember(), ContributionType::JobInstall, null, ContributionStatus::Pending);

        self::assertSame(1_000_000.0, $this->resource()->approvedContributionValue);
    }

    public function testPrecomputedApprovedContributionValueIsUsedAsIs(): void
    {
        $this->addContribution($this->ownerMembership, ContributionType::Material, 1_000_000.0, ContributionStatus::Approved);

        $resource = $this->mapper->projectToResource($this->project, $this->ownerMembership, 3, 2_750_000.0);

        self::assertSame([3, 2_750_000.0], [$resource->membersCount, $resource->approvedContributionValue]);
    }

    public function testPrecomputedApprovedContributionValueOfZeroIsNotReplacedByTheContributionsOfTheProject(): void
    {
        $this->addContribution($this->ownerMembership, ContributionType::Material, 1_000_000.0, ContributionStatus::Approved);

        $resource = $this->mapper->projectToResource($this->project, $this->ownerMembership, 1, 0.0);

        self::assertSame(0.0, $resource->approvedContributionValue);
    }

    #[Group('issue-347')]
    public function testUnknownFeesOfTheProjectAreCopiedAsNullAndKnownFeesAsTheyAre(): void
    {
        self::assertSame([null, null], [$this->resource()->brokerFeePercent, $this->resource()->salesTaxPercent]);

        $this->project->setBrokerFeePercent(1.38)->setSalesTaxPercent(null);
        self::assertSame([1.38, null], [$this->resource()->brokerFeePercent, $this->resource()->salesTaxPercent]);

        $this->project->setBrokerFeePercent(null)->setSalesTaxPercent(3.375);
        self::assertSame([null, 3.375], [$this->resource()->brokerFeePercent, $this->resource()->salesTaxPercent]);
    }

    #[Group('issue-341')]
    public function testSelectionPolicyOfTheProjectIsCopiedAsItIs(): void
    {
        $this->project->setAcceptsOutsideFavoriteSystem(true)->setMinimumOutsideSaving(75_000.0);

        $resource = $this->resource();

        self::assertSame([true, 75_000.0], [$resource->acceptsOutsideFavoriteSystem, $resource->minimumOutsideSaving]);
    }

    #[Group('issue-341')]
    public function testProjectWithoutSelectionPolicyRefusesAStructureOutsideTheFavoriteSystemWithTheDefaultMinimumOutsideSaving(): void
    {
        $resource = $this->resource();

        self::assertSame([false, IndustryProject::DEFAULT_MINIMUM_OUTSIDE_SAVING], [$resource->acceptsOutsideFavoriteSystem, $resource->minimumOutsideSaving]);
    }

    #[Group('issue-806')]
    public function testInventionExclusionOfTheProjectIsCopiedAsItIs(): void
    {
        $withoutExclusion = $this->resource()->excludesInvention;
        $this->project->setExcludesInvention(true);

        self::assertSame([false, true], [$withoutExclusion, $this->resource()->excludesInvention]);
    }

    private function resource(): IndustryProjectResource
    {
        return $this->mapper->projectToResource($this->project, $this->ownerMembership);
    }

    private function addMaterial(int $typeId, ?float $estimatedPrice, int $requiredQuantity, int $fulfilledQuantity = 0): void
    {
        $this->project->addBomItem(
            (new IndustryProjectBomLine())
                ->setTypeId($typeId)
                ->setTypeName(sprintf('Material %d', $typeId))
                ->setRequiredQuantity($requiredQuantity)
                ->setFulfilledQuantity($fulfilledQuantity)
                ->setEstimatedPrice($estimatedPrice),
        );
    }

    private function addJobLine(int $typeId, int $requiredQuantity, int $fulfilledQuantity, ?float $estimatedPrice): void
    {
        $this->project->addBomItem(
            (new IndustryProjectBomLine())
                ->setTypeId($typeId)
                ->setTypeName(sprintf('Job %d', $typeId))
                ->setRequiredQuantity($requiredQuantity)
                ->setFulfilledQuantity($fulfilledQuantity)
                ->setEstimatedPrice($estimatedPrice)
                ->setIsJob(true)
                ->setJobGroup('final')
                ->setActivityType('manufacturing')
                ->setRuns($requiredQuantity),
        );
    }

    private function acceptedMember(): IndustryProjectParticipant
    {
        $member = (new IndustryProjectParticipant())
            ->setUser(new User())
            ->setRole(ParticipantRole::Member)
            ->setStatus(ParticipantStatus::Accepted);
        $this->project->addMember($member);

        return $member;
    }

    private function addContribution(IndustryProjectParticipant $member, ContributionType $type, ?float $estimatedValue, ContributionStatus $status): void
    {
        $this->project->addContribution(
            (new IndustryProjectContribution())
                ->setMember($member)
                ->setType($type)
                ->setQuantity(1)
                ->setEstimatedValue($estimatedValue)
                ->setStatus($status),
        );
    }
}
