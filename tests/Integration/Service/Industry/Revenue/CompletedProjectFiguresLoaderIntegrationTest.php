<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Industry\Revenue;

use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectProduct;
use App\Entity\IndustryProjectSale;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Service\Industry\Revenue\CompletedProjectFigures;
use App\Service\Industry\Revenue\CompletedProjectFiguresLoader;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(CompletedProjectFiguresLoader::class)]
#[Group('issue-360')]
final class CompletedProjectFiguresLoaderIntegrationTest extends IntegrationTestCase
{
    private const string PERIOD_START = '2026-10-08T00:00:00+00:00';
    private const string PERIOD_END = '2026-11-07T00:00:00+00:00';
    private const string COMPLETED_IN_PERIOD = '2026-10-20T12:00:00+00:00';
    private const string COMPLETED_BEFORE_PERIOD = '2026-09-01T12:00:00+00:00';
    private const string JOB_START = '2026-10-10T08:00:00+00:00';
    private const float BROKER_FEE_PERCENT = 3.0;
    private const float SALES_TAX_PERCENT = 4.0;
    private const int SABRE = 22456;
    private const int ISHTAR = 12005;
    private const int HECATE = 35683;
    private const int MUNINN = 12015;
    private const int TRITANIUM = 34;
    private const float AMOUNT_TOLERANCE = 0.01;

    private int $nextEsiJobId = 410_000_001;

    private User $maren;
    private User $brann;
    private Character $marenCharacter;
    private Character $brannCharacter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->maren = $this->createUser();
        $this->marenCharacter = $this->createCharacter($this->maren, 'Maren Esk');
        $this->maren->setMainCharacter($this->marenCharacter);
        $this->brann = $this->createUser();
        $this->brannCharacter = $this->createCharacter($this->brann, 'Brann Holt');
        $this->brann->setMainCharacter($this->brannCharacter);
    }

    public function testOwnCompletedProjectGivesItsProfitCapitalProductAndOnlyTheHoursOfItsOwnLinkedJobs(): void
    {
        $project = $this->completedProject($this->maren, 'Sabre', self::SABRE);
        $owner = $this->member($project, $this->maren, ParticipantRole::Owner);
        $this->sale($project, $this->maren, quantity: 10, unitPrice: 1_000_000.0);
        $this->contribution($project, $owner, ContributionType::Material, 3_000_000.0);
        $this->contribution($project, $owner, ContributionType::JobInstall, 2_000_000.0);
        $jobLine = $this->jobLine($project, 'Sabre', self::SABRE);
        $this->linkJob($jobLine, $this->marenCharacter, hours: 36);
        $this->linkJob($jobLine, $this->marenCharacter, hours: 12);
        $this->linkJob($jobLine, $this->brannCharacter, hours: 24);
        $this->flushAndClear();

        $figures = $this->figuresOf($this->maren);

        self::assertCount(1, $figures);
        $sabre = $this->figureOf($figures, 'Sabre');
        self::assertSame(self::SABRE, $sabre->productTypeId);
        self::assertFalse($sabre->hasMultipleProducts);
        self::assertSame(self::COMPLETED_IN_PERIOD, $sabre->completedAt->format('c'));
        self::assertEqualsWithDelta(4_300_000.0, $sabre->profit, self::AMOUNT_TOLERANCE);
        self::assertEqualsWithDelta(5_000_000.0, $sabre->capital, self::AMOUNT_TOLERANCE);
        self::assertSame(48.0, $sabre->slotHours);
    }

    public function testOnlyCompletedProjectsOfTheUserCompletedInThePeriodAreLoaded(): void
    {
        $this->soloProject($this->maren, 'Included', self::SABRE, ProjectStatus::Completed, self::COMPLETED_IN_PERIOD);
        $this->soloProject($this->maren, 'Completed too early', self::ISHTAR, ProjectStatus::Completed, self::COMPLETED_BEFORE_PERIOD);
        $this->soloProject($this->maren, 'Still running', self::HECATE, ProjectStatus::InProgress, null);
        $this->soloProject($this->brann, 'Someone else', self::MUNINN, ProjectStatus::Completed, self::COMPLETED_IN_PERIOD);
        $pendingInvitation = $this->soloProject($this->brann, 'Pending invitation', 11_999, ProjectStatus::Completed, self::COMPLETED_IN_PERIOD);
        $this->member($pendingInvitation, $this->maren, ParticipantRole::Member, ParticipantStatus::Pending);
        $removedMember = $this->soloProject($this->brann, 'Removed member', 12_001, ProjectStatus::Completed, self::COMPLETED_IN_PERIOD);
        $this->member($removedMember, $this->maren, ParticipantRole::Member, ParticipantStatus::Removed);
        $this->flushAndClear();

        $figures = $this->figuresOf($this->maren);

        self::assertSame(
            ['Included'],
            array_map(static fn (CompletedProjectFigures $figure): ?string => $figure->productTypeName, $figures),
        );
    }

    public function testGroupParticipantGetsOnlyHisOwnShareAndNoHoursWhileTheOwnerGetsHisOwn(): void
    {
        $project = $this->completedProject($this->maren, 'Ishtar', self::ISHTAR);
        $owner = $this->member($project, $this->maren, ParticipantRole::Owner);
        $contributor = $this->member($project, $this->brann, ParticipantRole::Member);
        $this->sale($project, $this->maren, quantity: 10, unitPrice: 1_000_000.0);
        $this->contribution($project, $owner, ContributionType::Material, 6_000_000.0);
        $this->contribution($project, $contributor, ContributionType::Material, 2_000_000.0);
        $this->linkJob($this->jobLine($project, 'Ishtar', self::ISHTAR), $this->marenCharacter, hours: 20);
        $this->flushAndClear();

        $contributorFigure = $this->figureOf($this->figuresOf($this->brann), 'Ishtar');
        $ownerFigure = $this->figureOf($this->figuresOf($this->maren), 'Ishtar');

        self::assertEqualsWithDelta(325_000.0, $contributorFigure->profit, self::AMOUNT_TOLERANCE);
        self::assertEqualsWithDelta(2_000_000.0, $contributorFigure->capital, self::AMOUNT_TOLERANCE);
        self::assertSame(0.0, $contributorFigure->slotHours);
        self::assertEqualsWithDelta(975_000.0, $ownerFigure->profit, self::AMOUNT_TOLERANCE);
        self::assertEqualsWithDelta(6_000_000.0, $ownerFigure->capital, self::AMOUNT_TOLERANCE);
        self::assertSame(20.0, $ownerFigure->slotHours);
    }

    public function testLinkedJobMissingFromTheCacheLeavesTheHoursUnknownButKeepsTheProfit(): void
    {
        $project = $this->completedProject($this->maren, 'Hecate', self::HECATE);
        $owner = $this->member($project, $this->maren, ParticipantRole::Owner);
        $this->sale($project, $this->maren, quantity: 4, unitPrice: 2_000_000.0);
        $this->contribution($project, $owner, ContributionType::Material, 4_000_000.0);
        $jobLine = $this->jobLine($project, 'Hecate', self::HECATE);
        $this->linkJob($jobLine, $this->marenCharacter, hours: 10);
        $this->linkJobAbsentFromTheCache($jobLine, $this->marenCharacter);
        $this->flushAndClear();

        $hecate = $this->figureOf($this->figuresOf($this->maren), 'Hecate');

        self::assertNull($hecate->slotHours);
        self::assertEqualsWithDelta(3_440_000.0, $hecate->profit, self::AMOUNT_TOLERANCE);
        self::assertEqualsWithDelta(4_000_000.0, $hecate->capital, self::AMOUNT_TOLERANCE);
    }

    public function testMissingFeesOrNoApprovedContributionLeaveProfitAndCapitalUnknown(): void
    {
        $withoutFees = $this->completedProject($this->maren, 'Muninn', self::MUNINN, brokerFeePercent: null);
        $feeOwner = $this->member($withoutFees, $this->maren, ParticipantRole::Owner);
        $this->sale($withoutFees, $this->maren, quantity: 5, unitPrice: 1_000_000.0);
        $this->contribution($withoutFees, $feeOwner, ContributionType::Material, 2_000_000.0);
        $withoutContribution = $this->completedProject($this->brann, 'Ishtar', self::ISHTAR);
        $contributor = $this->member($withoutContribution, $this->brann, ParticipantRole::Owner);
        $this->member($withoutContribution, $this->maren, ParticipantRole::Member);
        $this->sale($withoutContribution, $this->brann, quantity: 5, unitPrice: 1_000_000.0);
        $this->contribution($withoutContribution, $contributor, ContributionType::Material, 2_000_000.0);
        $this->flushAndClear();

        $figures = $this->figuresOf($this->maren);

        self::assertCount(2, $figures);
        foreach (['Muninn', 'Ishtar'] as $productName) {
            $figure = $this->figureOf($figures, $productName);
            self::assertNull($figure->profit);
            self::assertNull($figure->capital);
        }
    }

    public function testProjectWithSeveralProductsIsFlaggedWithoutAnyProductIdentity(): void
    {
        $project = $this->completedProject($this->maren, 'Sabre', self::SABRE);
        $this->product($project, 'Ishtar', self::ISHTAR);
        $owner = $this->member($project, $this->maren, ParticipantRole::Owner);
        $this->sale($project, $this->maren, quantity: 4, unitPrice: 1_000_000.0);
        $this->contribution($project, $owner, ContributionType::Material, 2_000_000.0);
        $this->flushAndClear();

        $figures = $this->figuresOf($this->maren);

        self::assertCount(1, $figures);
        self::assertTrue($figures[0]->hasMultipleProducts);
        self::assertNull($figures[0]->productTypeId);
        self::assertNull($figures[0]->productTypeName);
        self::assertEqualsWithDelta(1_720_000.0, $figures[0]->profit, self::AMOUNT_TOLERANCE);
        self::assertEqualsWithDelta(2_000_000.0, $figures[0]->capital, self::AMOUNT_TOLERANCE);
    }

    /**
     * @return list<CompletedProjectFigures>
     */
    private function figuresOf(User $user): array
    {
        $loader = self::getContainer()->get(CompletedProjectFiguresLoader::class);
        $reloadedUser = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $reloadedUser);

        return $loader->loadFor(
            $reloadedUser,
            new \DateTimeImmutable(self::PERIOD_START),
            new \DateTimeImmutable(self::PERIOD_END),
        );
    }

    /**
     * @param list<CompletedProjectFigures> $figures
     */
    private function figureOf(array $figures, string $productTypeName): CompletedProjectFigures
    {
        $byProductName = [];
        foreach ($figures as $figure) {
            $byProductName[(string) $figure->productTypeName] = $figure;
        }
        self::assertArrayHasKey($productTypeName, $byProductName);

        return $byProductName[$productTypeName];
    }

    private function soloProject(User $owner, string $productName, int $productTypeId, ProjectStatus $status, ?string $completedAt): IndustryProject
    {
        $project = $this->completedProject($owner, $productName, $productTypeId, $status, $completedAt);
        $member = $this->member($project, $owner, ParticipantRole::Owner);
        $this->sale($project, $owner, quantity: 2, unitPrice: 1_000_000.0);
        $this->contribution($project, $member, ContributionType::Material, 1_000_000.0);

        return $project;
    }

    private function completedProject(
        User $owner,
        string $productName,
        int $productTypeId,
        ProjectStatus $status = ProjectStatus::Completed,
        ?string $completedAt = self::COMPLETED_IN_PERIOD,
        ?float $brokerFeePercent = self::BROKER_FEE_PERCENT,
    ): IndustryProject {
        $project = (new IndustryProject())
            ->setOwner($owner)
            ->setName($productName . ' batch')
            ->setStatus($status)
            ->setBrokerFeePercent($brokerFeePercent)
            ->setSalesTaxPercent(self::SALES_TAX_PERCENT)
            ->setCompletedAt($completedAt === null ? null : new \DateTimeImmutable($completedAt));
        $this->em->persist($project);
        $this->product($project, $productName, $productTypeId);

        return $project;
    }

    private function product(IndustryProject $project, string $typeName, int $typeId): void
    {
        $project->addItem(
            (new IndustryProjectProduct())
                ->setTypeId($typeId)
                ->setTypeName($typeName)
                ->setRuns(1)
                ->setSortOrder($project->getItems()->count()),
        );
    }

    private function member(
        IndustryProject $project,
        User $user,
        ParticipantRole $role,
        ParticipantStatus $status = ParticipantStatus::Accepted,
    ): IndustryProjectParticipant {
        $member = (new IndustryProjectParticipant())->setUser($user)->setRole($role)->setStatus($status);
        $project->addMember($member);

        return $member;
    }

    private function sale(IndustryProject $project, User $recordedBy, int $quantity, float $unitPrice): void
    {
        $project->addSale((new IndustryProjectSale())
            ->setTypeId(0)
            ->setTypeName('Sale')
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setTotalPrice($quantity * $unitPrice)
            ->setVenue('Jita IV - Moon 4')
            ->setSoldAt(new \DateTimeImmutable(self::COMPLETED_IN_PERIOD))
            ->setRecordedBy($recordedBy));
    }

    private function contribution(IndustryProject $project, IndustryProjectParticipant $member, ContributionType $type, float $estimatedValue): void
    {
        $bomLine = (new IndustryProjectBomLine())
            ->setTypeId(self::TRITANIUM)
            ->setTypeName('Tritanium')
            ->setRequiredQuantity(1000);
        $project->addBomItem($bomLine);
        $project->addContribution((new IndustryProjectContribution())
            ->setMember($member)
            ->setBomItem($bomLine)
            ->setType($type)
            ->setQuantity(1000)
            ->setEstimatedValue($estimatedValue)
            ->setStatus(ContributionStatus::Approved));
    }

    private function jobLine(IndustryProject $project, string $typeName, int $typeId): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity(1)
            ->setIsJob(true);
        $project->addBomItem($line);

        return $line;
    }

    private function linkJob(IndustryProjectBomLine $line, Character $character, int $hours): void
    {
        $esiJobId = $this->nextEsiJobId++;
        $start = new \DateTimeImmutable(self::JOB_START);
        $this->em->persist((new CachedIndustryJob())
            ->setCharacter($character)
            ->setJobId($esiJobId)
            ->setActivityId(1)
            ->setBlueprintTypeId($line->getTypeId())
            ->setProductTypeId($line->getTypeId())
            ->setRuns(1)
            ->setStatus('delivered')
            ->setStartDate($start)
            ->setEndDate($start->modify("+{$hours} hours")));
        $this->match($line, $character, $esiJobId);
    }

    private function linkJobAbsentFromTheCache(IndustryProjectBomLine $line, Character $character): void
    {
        $this->match($line, $character, $this->nextEsiJobId++);
    }

    private function match(IndustryProjectBomLine $line, Character $character, int $esiJobId): void
    {
        $line->addJobMatch((new IndustryProjectJobMatch())
            ->setEsiJobId($esiJobId)
            ->setRuns(1)
            ->setStatus('delivered')
            ->setCharacterName($character->getName()));
    }
}
