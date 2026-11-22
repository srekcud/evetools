<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectSale;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectSaleRepository;
use App\Service\Industry\Project\DistributionResult;
use App\Service\Industry\Project\ProjectDistributionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectDistributionService::class)]
#[Group('issue-1315')]
final class ProjectDistributionServiceSurplusStoredTest extends TestCase
{
    private const int PYERITE = 35;
    private const int MORPHITE = 11_399;
    private const int RAM_STARSHIP_TECH = 11_478;
    private const int HAMMERHEAD_II = 11_993;

    private IndustryProject $project;
    private IndustryProjectParticipant $member;
    private User $storer;

    /** @var list<IndustryProjectContribution> */
    private array $contributions = [];

    protected function setUp(): void
    {
        $this->storer = new User();
        $this->project = (new IndustryProject())->setOwner($this->storer)->setName('Hammerhead batch')->setStatus(ProjectStatus::Selling)->setBrokerFeePercent(2.0)->setSalesTaxPercent(3.0);
        $character = $this->createStub(Character::class);
        $character->method('getName')->willReturn('Ursula Kane');
        $user = $this->createStub(User::class);
        $user->method('getMainCharacter')->willReturn($character);
        $this->member = (new IndustryProjectParticipant())->setUser($user)->setRole(ParticipantRole::Owner)->setStatus(ParticipantStatus::Accepted);
        (new \ReflectionProperty(IndustryProjectParticipant::class, 'id'))->setValue($this->member, Uuid::v4());
        $this->project->addMember($this->member);
    }

    public function testStoredSurplusIsCountedAtItsTotalValueWhileThePayoutsAndTheMarginAreUnchanged(): void
    {
        $this->contribute($this->materialLine(self::PYERITE, 'Pyerite', 800), 950, 7_600.0);

        $notStored = $this->distribution();
        $this->project->markSurplusStored($this->storer, new \DateTimeImmutable('2026-11-22T09:30:00+00:00'));
        $stored = $this->distribution();

        self::assertSame([false, null], [$notStored->surplusStored, $notStored->storedSurplusValue]);
        self::assertSame([true, 1_200.0], [$stored->surplusStored, $stored->storedSurplusValue]);
        foreach ([$notStored, $stored] as $result) {
            self::assertSame([11_400.0, 7_600.0, 50.0, 11_400.0], [$result->netRevenue, $result->totalProjectCost, $result->marginPercent, $result->members[0]->payoutTotal]);
        }
    }

    public function testStoredSurplusWithAnUnknownTotalIsFlaggedStoredWithoutAValueNeverZero(): void
    {
        $this->contribute($this->materialLine(self::PYERITE, 'Pyerite', 800), 950, 7_600.0);
        $this->contribute($this->materialLine(self::MORPHITE, 'Morphite', 150), 160, null);
        $this->project->markSurplusStored($this->storer, new \DateTimeImmutable('2026-11-22T09:30:00+00:00'));

        $result = $this->distribution();

        self::assertTrue($result->surplusStored);
        self::assertNull($result->storedSurplusValue);
    }

    public function testStoredSurplusMadeOnlyOfProductionSurplusIsWorthZero(): void
    {
        $this->contribute($this->materialLine(self::PYERITE, 'Pyerite', 950), 950, 7_600.0);
        $this->project->addBomItem(
            (new IndustryProjectBomLine())->setTypeId(self::RAM_STARSHIP_TECH)->setTypeName('R.A.M.- Starship Tech')->setRequiredQuantity(300)->setIsJob(true)->setJobGroup('component')->setSurplusQuantity(12),
        );
        $this->project->markSurplusStored($this->storer, new \DateTimeImmutable('2026-11-22T09:30:00+00:00'));

        $result = $this->distribution();

        self::assertTrue($result->surplusStored);
        self::assertSame(0.0, $result->storedSurplusValue);
    }

    public function testProjectWithoutStoredSurplusKeepsNoValueEvenWhenItHasASurplus(): void
    {
        $this->contribute($this->materialLine(self::PYERITE, 'Pyerite', 800), 950, 7_600.0);

        $result = $this->distribution();

        self::assertFalse($result->surplusStored);
        self::assertNull($result->storedSurplusValue);
    }

    private function materialLine(int $typeId, string $typeName, int $requiredQuantity): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())->setTypeId($typeId)->setTypeName($typeName)->setRequiredQuantity($requiredQuantity)->setIsJob(false);
        $this->project->addBomItem($line);

        return $line;
    }

    private function contribute(IndustryProjectBomLine $line, int $quantity, ?float $estimatedValue): void
    {
        $contribution = (new IndustryProjectContribution())
            ->setMember($this->member)
            ->setBomItem($line)
            ->setType(ContributionType::Material)
            ->setQuantity($quantity)
            ->setEstimatedValue($estimatedValue)
            ->setStatus(ContributionStatus::Approved)
            ->setIsAutoDetected(false)
            ->setIsVerified(false);
        $this->project->addContribution($contribution);
        $this->contributions[] = $contribution;
    }

    private function distribution(): DistributionResult
    {
        $contributions = $this->createStub(IndustryProjectContributionRepository::class);
        $contributions->method('findApprovedByProject')->willReturn($this->contributions);
        $sales = $this->createStub(IndustryProjectSaleRepository::class);
        $sales->method('findBy')->willReturn([(new IndustryProjectSale())->setTypeId(self::HAMMERHEAD_II)->setQuantity(100)->setUnitPrice(120.0)->setTotalPrice(12_000.0)]);

        return (new ProjectDistributionService($contributions, $sales))->calculateDistribution($this->project);
    }
}
