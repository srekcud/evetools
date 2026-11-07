<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Service\Industry\Project\ProjectSurplusBuilder;
use App\Service\Industry\Project\ProjectSurplusLine;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectSurplusBuilder::class)]
#[Group('issue-352')]
final class ProjectSurplusBuilderTest extends TestCase
{
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int ISOGEN = 37;
    private const int NOCXIUM = 38;
    private const int MORPHITE = 11_399;
    private const int RAM_STARSHIP_TECH = 11_478;

    private ProjectSurplusBuilder $builder;
    private IndustryProject $project;
    private IndustryProjectParticipant $owner;
    private IndustryProjectParticipant $member;

    protected function setUp(): void
    {
        $this->builder = new ProjectSurplusBuilder();
        $this->project = (new IndustryProject())->setOwner(new User())->setName('Ishtar batch')->setStatus(ProjectStatus::Completed);
        $this->owner = $this->participant(ParticipantRole::Owner);
        $this->member = $this->participant(ParticipantRole::Member);
    }

    public function testMaterialContributedAboveTheRequiredQuantityIsASurplusValuedAtTheContributionPrice(): void
    {
        $tritanium = $this->materialLine(self::TRITANIUM, 'Tritanium', 12_000);
        $this->contribute($this->owner, $tritanium, ContributionType::Material, 12_500, 53_125.0);

        $surplus = $this->builder->build($this->project);

        self::assertEquals(
            [new ProjectSurplusLine($this->idOf($tritanium), self::TRITANIUM, 'Tritanium', 12_000, 12_500, 500, 4.25, 2_125.0)],
            $surplus->lines,
        );
        self::assertSame(2_125.0, $surplus->totalValue);
    }

    public function testContributionsOfSeveralParticipantsOnOneLineAreAddedAndPricedAtTheirWeightedAverage(): void
    {
        $morphite = $this->materialLine(self::MORPHITE, 'Morphite', 150);
        $this->contribute($this->owner, $morphite, ContributionType::Material, 100, 1_850_000.0);
        $this->contribute($this->member, $morphite, ContributionType::Material, 60, 1_110_000.0);

        $surplus = $this->builder->build($this->project);

        self::assertEquals(
            [new ProjectSurplusLine($this->idOf($morphite), self::MORPHITE, 'Morphite', 150, 160, 10, 18_500.0, 185_000.0)],
            $surplus->lines,
        );
        self::assertSame(185_000.0, $surplus->totalValue);
    }

    public function testLineExactlyCoveredOrShortOfItsRequiredQuantityIsNotListed(): void
    {
        $nocxium = $this->materialLine(self::NOCXIUM, 'Nocxium', 600);
        $pyerite = $this->materialLine(self::PYERITE, 'Pyerite', 1_000);
        $this->contribute($this->owner, $nocxium, ContributionType::Material, 600, 480_000.0);
        $this->contribute($this->owner, $pyerite, ContributionType::Material, 900, 9_000.0);

        $surplus = $this->builder->build($this->project);

        self::assertSame([], $surplus->lines);
        self::assertSame(0.0, $surplus->totalValue);
    }

    public function testOnlyApprovedMaterialContributionsCount(): void
    {
        $tritanium = $this->materialLine(self::TRITANIUM, 'Tritanium', 12_000);
        $this->contribute($this->owner, $tritanium, ContributionType::Material, 12_000, 51_000.0);
        $this->contribute($this->member, $tritanium, ContributionType::Material, 5_000, 21_250.0, ContributionStatus::Pending);
        $this->contribute($this->member, $tritanium, ContributionType::Material, 4_000, 17_000.0, ContributionStatus::Rejected);

        $surplus = $this->builder->build($this->project);

        self::assertSame([], $surplus->lines);
    }

    public function testContributionsOfOtherTypesDoNotCount(): void
    {
        $tritanium = $this->materialLine(self::TRITANIUM, 'Tritanium', 12_000);
        $this->contribute($this->owner, $tritanium, ContributionType::Material, 12_000, 51_000.0);
        $this->contribute($this->owner, $tritanium, ContributionType::JobInstall, 3_000, 90_000.0);
        $this->contribute($this->member, $tritanium, ContributionType::Bpc, 2_000, 80_000.0);
        $this->contribute($this->member, $tritanium, ContributionType::LineRental, 1_000, 10_000.0);

        $surplus = $this->builder->build($this->project);

        self::assertSame([], $surplus->lines);
    }

    public function testContributionWithoutBomLineDoesNotCount(): void
    {
        $this->materialLine(self::TRITANIUM, 'Tritanium', 12_000);
        $this->contribute($this->owner, null, ContributionType::Material, 99_000, 400_000.0);

        $surplus = $this->builder->build($this->project);

        self::assertSame([], $surplus->lines);
    }

    public function testMaterialContributionOnAJobLineIsTreatedLikeAnyOtherLine(): void
    {
        $ram = $this->jobLine(self::RAM_STARSHIP_TECH, 'R.A.M.- Starship Tech', 300);
        $this->contribute($this->owner, $ram, ContributionType::Material, 320, 64_000.0);

        $surplus = $this->builder->build($this->project);

        self::assertEquals(
            [new ProjectSurplusLine($this->idOf($ram), self::RAM_STARSHIP_TECH, 'R.A.M.- Starship Tech', 300, 320, 20, 200.0, 4_000.0)],
            $surplus->lines,
        );
    }

    public function testMissingContributionValueMakesThePriceTheValueAndTheTotalUnknownNeverZero(): void
    {
        $tritanium = $this->materialLine(self::TRITANIUM, 'Tritanium', 12_000);
        $isogen = $this->materialLine(self::ISOGEN, 'Isogen', 200);
        $this->contribute($this->owner, $tritanium, ContributionType::Material, 12_500, 53_125.0);
        $this->contribute($this->owner, $isogen, ContributionType::Material, 250, null);

        $surplus = $this->builder->build($this->project);

        self::assertEquals(
            [
                new ProjectSurplusLine($this->idOf($isogen), self::ISOGEN, 'Isogen', 200, 250, 50, null, null),
                new ProjectSurplusLine($this->idOf($tritanium), self::TRITANIUM, 'Tritanium', 12_000, 12_500, 500, 4.25, 2_125.0),
            ],
            $surplus->lines,
        );
        self::assertNull($surplus->totalValue);
    }

    public function testOneContributionWithoutValueAmongSeveralMakesTheLineValueUnknown(): void
    {
        $morphite = $this->materialLine(self::MORPHITE, 'Morphite', 150);
        $this->contribute($this->owner, $morphite, ContributionType::Material, 100, 1_850_000.0);
        $this->contribute($this->member, $morphite, ContributionType::Material, 60, null);

        $surplus = $this->builder->build($this->project);

        self::assertEquals(
            [new ProjectSurplusLine($this->idOf($morphite), self::MORPHITE, 'Morphite', 150, 160, 10, null, null)],
            $surplus->lines,
        );
        self::assertNull($surplus->totalValue);
    }

    public function testLinesAreSortedByTypeNameThenTypeId(): void
    {
        $tritanium = $this->materialLine(self::TRITANIUM, 'Tritanium', 10);
        $pyerite = $this->materialLine(self::PYERITE, 'Pyerite', 10);
        $isogenHomonym = $this->materialLine(self::ISOGEN + 1, 'Isogen', 10);
        $isogen = $this->materialLine(self::ISOGEN, 'Isogen', 10);
        foreach ([$tritanium, $pyerite, $isogenHomonym, $isogen] as $line) {
            $this->contribute($this->owner, $line, ContributionType::Material, 11, 11.0);
        }

        $surplus = $this->builder->build($this->project);

        self::assertSame(
            [self::ISOGEN, self::ISOGEN + 1, self::PYERITE, self::TRITANIUM],
            array_map(static fn (ProjectSurplusLine $line): int => $line->typeId, $surplus->lines),
        );
        self::assertSame(4.0, $surplus->totalValue);
    }

    public function testProjectThatIsNotCompletedHasNoSurplus(): void
    {
        $tritanium = $this->materialLine(self::TRITANIUM, 'Tritanium', 12_000);
        $this->contribute($this->owner, $tritanium, ContributionType::Material, 12_500, 53_125.0);
        $this->project->setStatus(ProjectStatus::InProgress);

        $surplus = $this->builder->build($this->project);

        self::assertSame([], $surplus->lines);
        self::assertSame(0.0, $surplus->totalValue);
    }

    private function participant(ParticipantRole $role): IndustryProjectParticipant
    {
        $participant = (new IndustryProjectParticipant())->setUser(new User())->setRole($role)->setStatus(ParticipantStatus::Accepted);
        $this->project->addMember($participant);

        return $participant;
    }

    private function materialLine(int $typeId, string $typeName, int $requiredQuantity): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())->setTypeId($typeId)->setTypeName($typeName)->setRequiredQuantity($requiredQuantity)->setIsJob(false);
        $this->project->addBomItem($line);

        return $line;
    }

    private function jobLine(int $typeId, string $typeName, int $requiredQuantity): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())->setTypeId($typeId)->setTypeName($typeName)->setRequiredQuantity($requiredQuantity)->setIsJob(true)->setJobGroup('component');
        $this->project->addBomItem($line);

        return $line;
    }

    private function contribute(
        IndustryProjectParticipant $participant,
        ?IndustryProjectBomLine $line,
        ContributionType $type,
        int $quantity,
        ?float $estimatedValue,
        ContributionStatus $status = ContributionStatus::Approved,
    ): void {
        $this->project->addContribution(
            (new IndustryProjectContribution())
                ->setMember($participant)
                ->setBomItem($line)
                ->setType($type)
                ->setQuantity($quantity)
                ->setEstimatedValue($estimatedValue)
                ->setStatus($status)
                ->setIsAutoDetected(false)
                ->setIsVerified(false),
        );
    }

    private function idOf(IndustryProjectBomLine $line): string
    {
        return $line->getId()->toRfc4122();
    }
}
