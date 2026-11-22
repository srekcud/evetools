<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Factory;

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
use App\Repository\IndustryProjectParticipantRepository;
use App\Service\Industry\Factory\FactoryProjectStock;
use App\Service\Industry\Factory\FactoryProjectStockLine;
use App\Service\Industry\Project\ProjectSurplusBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(FactoryProjectStock::class)]
#[Group('issue-1314')]
final class FactoryProjectStockTest extends TestCase
{
    private const int TRITANIUM = 34;
    private const int ISOGEN = 37;
    private const int MORPHITE = 11_399;
    private const int PYERITE = 35;

    private User $user;

    /** @var list<IndustryProjectParticipant> */
    private array $memberships = [];

    protected function setUp(): void
    {
        $this->user = new User();
    }

    public function testUnfinishedProjectReservesTheApprovedMaterialContributions(): void
    {
        $project = $this->project('Rifter batch', ProjectStatus::InProgress);
        $tritanium = $this->materialLine($project, self::TRITANIUM, 'Tritanium', 100);
        $this->contribute($project, $tritanium, 40);
        $this->contribute($project, $tritanium, 20);

        $lines = $this->factoryProjectStock()->of($this->user);

        self::assertEquals(
            [new FactoryProjectStockLine($project->getId()->toRfc4122(), 'Rifter batch', 'in_progress', self::TRITANIUM, 'Tritanium', 'reserved', 60)],
            $lines,
        );
    }

    public function testStockIsSummedPerProjectAndPerType(): void
    {
        $rifter = $this->project('Rifter batch', ProjectStatus::InProgress);
        $this->contribute($rifter, $this->materialLine($rifter, self::TRITANIUM, 'Tritanium', 100), 60);
        $this->contribute($rifter, $this->materialLine($rifter, self::PYERITE, 'Pyerite', 80), 35);
        $merlin = $this->project('Merlin batch', ProjectStatus::Published);
        $this->contribute($merlin, $this->materialLine($merlin, self::TRITANIUM, 'Tritanium', 90), 25);

        $lines = $this->factoryProjectStock()->of($this->user);

        self::assertSame(
            ['Merlin batch|34|reserved|25', 'Rifter batch|34|reserved|60', 'Rifter batch|35|reserved|35'],
            $this->describe($lines),
        );
    }

    public function testPendingRejectedNonMaterialAndOrphanContributionsAreNotStock(): void
    {
        $project = $this->project('Rifter batch', ProjectStatus::InProgress);
        $tritanium = $this->materialLine($project, self::TRITANIUM, 'Tritanium', 100);
        $this->contribute($project, $tritanium, 40, ContributionStatus::Pending);
        $this->contribute($project, $tritanium, 40, ContributionStatus::Rejected);
        $this->contribute($project, $tritanium, 25, ContributionStatus::Approved, ContributionType::JobInstall);
        $this->contribute($project, null, 50);

        $lines = $this->factoryProjectStock()->of($this->user);

        self::assertSame([], $lines);
    }

    public function testCompletedProjectConsumesTheRequiredQuantityAndReturnsTheMaterialSurplus(): void
    {
        $project = $this->project('Ishtar batch', ProjectStatus::Completed);
        $this->contribute($project, $this->materialLine($project, self::TRITANIUM, 'Tritanium', 100), 130);

        $lines = $this->factoryProjectStock()->of($this->user);

        self::assertSame(['Ishtar batch|34|consumed|100', 'Ishtar batch|34|returned|30'], $this->describe($lines));
        self::assertSame('completed', $lines[0]->projectStatus);
    }

    public function testCompletedProjectExactlyCoveredHasNoReturnedLine(): void
    {
        $project = $this->project('Ishtar batch', ProjectStatus::Completed);
        $this->contribute($project, $this->materialLine($project, self::TRITANIUM, 'Tritanium', 100), 100);

        $lines = $this->factoryProjectStock()->of($this->user);

        self::assertSame(['Ishtar batch|34|consumed|100'], $this->describe($lines));
    }

    public function testProductionSurplusOfACompletedProjectIsNotReturnedStock(): void
    {
        $project = $this->project('Ishtar batch', ProjectStatus::Completed);
        $morphite = $this->jobLine($project, self::MORPHITE, 'Morphite', 40, 10);
        $this->contribute($project, $morphite, 40);

        $lines = $this->factoryProjectStock()->of($this->user);

        self::assertSame(['Ishtar batch|11399|consumed|40'], $this->describe($lines));
    }

    public function testTwoBomLinesOfTheSameTypeAreAddedPerState(): void
    {
        $project = $this->project('Ishtar batch', ProjectStatus::Completed);
        $this->contribute($project, $this->materialLine($project, self::ISOGEN, 'Isogen', 30), 40);
        $this->contribute($project, $this->materialLine($project, self::ISOGEN, 'Isogen', 20), 15);

        $lines = $this->factoryProjectStock()->of($this->user);

        self::assertSame(['Ishtar batch|37|consumed|45', 'Ishtar batch|37|returned|10'], $this->describe($lines));
    }

    public function testCompletedProjectWithoutContributionHasNoLine(): void
    {
        $project = $this->project('Ishtar batch', ProjectStatus::Completed);
        $this->materialLine($project, self::TRITANIUM, 'Tritanium', 100);

        $lines = $this->factoryProjectStock()->of($this->user);

        self::assertSame([], $lines);
    }

    #[Group('issue-1315')]
    public function testSellingProjectWithItsSurplusStoredConsumesTheRequiredQuantityAndReturnsTheMaterialSurplus(): void
    {
        $project = $this->project('Ishtar batch', ProjectStatus::Selling);
        $this->contribute($project, $this->materialLine($project, self::ISOGEN, 'Isogen', 70), 90);
        $project->markSurplusStored($this->user, new \DateTimeImmutable('2026-11-22T09:30:00+00:00'));

        $lines = $this->factoryProjectStock()->of($this->user);

        self::assertSame(['Ishtar batch|37|consumed|70', 'Ishtar batch|37|returned|20'], $this->describe($lines));
        self::assertSame('selling', $lines[0]->projectStatus);
    }

    #[Group('issue-1315')]
    public function testSellingProjectWithoutStoredSurplusStillReservesTheContributions(): void
    {
        $project = $this->project('Ishtar batch', ProjectStatus::Selling);
        $this->contribute($project, $this->materialLine($project, self::ISOGEN, 'Isogen', 70), 90);

        $lines = $this->factoryProjectStock()->of($this->user);

        self::assertSame(['Ishtar batch|37|reserved|90'], $this->describe($lines));
    }

    public function testUserWithoutAcceptedProjectHasNoStock(): void
    {
        self::assertSame([], $this->factoryProjectStock()->of($this->user));
    }

    private function factoryProjectStock(): FactoryProjectStock
    {
        $participantRepository = $this->createStub(IndustryProjectParticipantRepository::class);
        $participantRepository->method('findAcceptedWithProjectsByUser')->willReturnCallback(
            fn (User $user): array => $user === $this->user ? $this->memberships : [],
        );

        return new FactoryProjectStock($participantRepository, new ProjectSurplusBuilder());
    }

    /**
     * @param list<FactoryProjectStockLine> $lines
     *
     * @return list<string>
     */
    private function describe(array $lines): array
    {
        $described = array_map(
            static fn (FactoryProjectStockLine $line): string => \sprintf('%s|%d|%s|%d', $line->projectName, $line->typeId, $line->state, $line->quantity),
            $lines,
        );
        sort($described);

        return $described;
    }

    private function project(string $name, ProjectStatus $status): IndustryProject
    {
        $project = (new IndustryProject())->setOwner($this->user)->setName($name)->setStatus($status);
        $membership = (new IndustryProjectParticipant())->setUser($this->user)->setRole(ParticipantRole::Owner)->setStatus(ParticipantStatus::Accepted);
        $project->addMember($membership);
        $this->memberships[] = $membership;

        return $project;
    }

    private function materialLine(IndustryProject $project, int $typeId, string $typeName, int $requiredQuantity): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())->setTypeId($typeId)->setTypeName($typeName)->setRequiredQuantity($requiredQuantity)->setIsJob(false);
        $project->addBomItem($line);

        return $line;
    }

    private function jobLine(IndustryProject $project, int $typeId, string $typeName, int $requiredQuantity, int $surplusQuantity): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity($requiredQuantity)
            ->setIsJob(true)
            ->setJobGroup('component')
            ->setSurplusQuantity($surplusQuantity);
        $project->addBomItem($line);

        return $line;
    }

    private function contribute(
        IndustryProject $project,
        ?IndustryProjectBomLine $line,
        int $quantity,
        ContributionStatus $status = ContributionStatus::Approved,
        ContributionType $type = ContributionType::Material,
    ): void {
        $project->addContribution(
            (new IndustryProjectContribution())
                ->setMember($this->memberships[0])
                ->setBomItem($line)
                ->setType($type)
                ->setQuantity($quantity)
                ->setEstimatedValue(null)
                ->setStatus($status)
                ->setIsAutoDetected(false)
                ->setIsVerified(false),
        );
    }
}
