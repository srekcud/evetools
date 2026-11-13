<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Repository\IndustryProjectContributionRepository;
use App\Service\Industry\Project\ContributorPaidPurchaseReader;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\Industry\Project\LineReservationService;
use App\Service\Industry\Project\PaidMaterialPurchases;
use App\Service\Industry\Project\ProjectContributionService;
use App\Service\JitaMarketService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectContributionService::class)]
#[Group('issue-953')]
#[AllowMockObjectsWithoutExpectations]
final class ProjectContributionServicePaidPriceTest extends TestCase
{
    private const int TRITANIUM_TYPE_ID = 34;
    private const float TRITANIUM_JITA_PRICE = 7.0;

    private JitaMarketService&MockObject $jitaMarket;
    private ContributorPaidPurchaseReader&MockObject $paidPurchases;
    private ProjectContributionService $service;
    private IndustryProjectParticipant $member;
    private IndustryProjectBomLine $line;
    private ?float $jitaPrice = self::TRITANIUM_JITA_PRICE;
    private int $jitaLookups = 0;

    protected function setUp(): void
    {
        $this->jitaMarket = $this->createMock(JitaMarketService::class);
        $this->jitaMarket->method('getCheapestPercentilePrices')->willReturnCallback(function (array $typeIds): array {
            ++$this->jitaLookups;

            return [self::TRITANIUM_TYPE_ID => $this->jitaPrice];
        });
        $this->paidPurchases = $this->createMock(ContributorPaidPurchaseReader::class);

        $this->service = new ProjectContributionService(
            $this->createMock(IndustryProjectContributionRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->jitaMarket,
            $this->createStub(LineReservationService::class),
            $this->createStub(IndustryProjectService::class),
            $this->paidPurchases,
        );

        $project = (new IndustryProject())->setOwner(new User());
        $project->enrolOwner();
        $this->member = (new IndustryProjectParticipant())->setUser(new User())->setRole(ParticipantRole::Member)->setStatus(ParticipantStatus::Accepted);
        $project->addMember($this->member);
        $this->line = (new IndustryProjectBomLine())->setTypeId(self::TRITANIUM_TYPE_ID)->setTypeName('Tritanium')->setRequiredQuantity(10000)->setIsJob(false);
        $project->addBomItem($this->line);
    }

    public function testPurchasesCoveringTheWholeDepositValueItAtThePaidPriceWithoutReadingJita(): void
    {
        $this->paidPurchases->method('read')->willReturn(new PaidMaterialPurchases(100, 500.0));

        $contribution = $this->service->submit($this->member, $this->line, ContributionType::Material, 100);

        $this->assertSame([500.0, false], [$contribution->getEstimatedValue(), $contribution->isValuedAtJitaPrice()]);
        $this->assertSame(0, $this->jitaLookups);
    }

    public function testPurchasesCoveringPartOfTheDepositAreValuedAtThePaidPriceAndTheRestAtJita(): void
    {
        $this->paidPurchases->method('read')->willReturn(new PaidMaterialPurchases(60, 300.0));

        $contribution = $this->service->submit($this->member, $this->line, ContributionType::Material, 100);

        $this->assertSame([580.0, true], [$contribution->getEstimatedValue(), $contribution->isValuedAtJitaPrice()]);
    }

    public function testPurchasesLargerThanTheDepositAreValuedAtTheAveragePaidPriceOfTheDepositedUnits(): void
    {
        $this->paidPurchases->method('read')->willReturn(new PaidMaterialPurchases(200, 1000.0));

        $contribution = $this->service->submit($this->member, $this->line, ContributionType::Material, 100);

        $this->assertSame([500.0, false], [$contribution->getEstimatedValue(), $contribution->isValuedAtJitaPrice()]);
    }

    public function testWithoutAnyPurchaseTheWholeDepositIsValuedAtTheJitaPriceOfTheDepositDay(): void
    {
        $this->paidPurchases->method('read')->willReturn(null);

        $contribution = $this->service->submit($this->member, $this->line, ContributionType::Material, 10);

        $this->assertSame([70.0, true], [$contribution->getEstimatedValue(), $contribution->isValuedAtJitaPrice()]);
    }

    public function testPartialPurchasesWithoutAJitaPriceRefuseTheDepositInsteadOfGuessingTheRest(): void
    {
        $this->paidPurchases->method('read')->willReturn(new PaidMaterialPurchases(60, 300.0));
        $this->jitaPrice = null;

        $this->expectException(\DomainException::class);

        $this->service->submit($this->member, $this->line, ContributionType::Material, 100);
    }

    public function testFullyPaidDepositNeedsNoJitaPrice(): void
    {
        $this->paidPurchases->method('read')->willReturn(new PaidMaterialPurchases(100, 500.0));
        $this->jitaPrice = null;

        $contribution = $this->service->submit($this->member, $this->line, ContributionType::Material, 100);

        $this->assertSame(500.0, $contribution->getEstimatedValue());
    }

    public function testEnteredValueOfAMaterialIsStillRefused(): void
    {
        $this->paidPurchases->method('read')->willReturn(null);

        $this->expectException(\DomainException::class);

        $this->service->submit($this->member, $this->line, ContributionType::Material, 100, 123.0);
    }

    public function testJobInstallKeepsItsEnteredValueAndIsNotMarkedAsJitaValued(): void
    {
        $this->paidPurchases->expects($this->never())->method('read');

        $contribution = $this->service->submit($this->member, $this->line, ContributionType::JobInstall, 2, 900.0);

        $this->assertSame([900.0, false], [$contribution->getEstimatedValue(), $contribution->isValuedAtJitaPrice()]);
    }
}
