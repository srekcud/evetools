<?php

declare(strict_types=1);

namespace App\Tests\Integration\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use App\ApiResource\Input\GroupIndustry\UpdateSaleInput;
use App\Entity\GroupIndustryBomItem;
use App\Entity\GroupIndustryContribution;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\GroupIndustrySale;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Enum\GroupProjectStatus;
use App\Repository\GroupIndustryProjectRepository;
use App\Repository\GroupIndustrySaleRepository;
use App\Service\GroupIndustry\GroupIndustryDistributionService;
use App\Service\Mercure\MercurePublisherService;
use App\State\Processor\GroupIndustry\DeleteSaleProcessor;
use App\State\Processor\GroupIndustry\UpdateSaleProcessor;
use App\State\Provider\GroupIndustry\GroupDistributionProvider;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use App\State\Provider\GroupIndustry\GroupProjectDeleteProvider;
use App\State\Provider\GroupIndustry\GroupSaleItemProvider;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Mercure\HubInterface;

#[Group('issue-164')]
final class SaleCorrectionDistributionTest extends IntegrationTestCase
{
    private const float BROKER_FEE_PERCENT = 3.0;
    private const float SALES_TAX_PERCENT = 4.0;
    private const int TRITANIUM_TYPE_ID = 34;
    private const int PYERITE_TYPE_ID = 35;
    private const float AMOUNT_TOLERANCE = 0.01;

    private string $ownerId;
    private string $projectId;
    private string $sabreSaleId;

    public function testInitialSalesGiveTheInitialDistribution(): void
    {
        $this->createSellingSabreDoctrineProject();

        self::assertEqualsWithDelta(
            $this->distributionSummary(7800000.0, 234000.0, 312000.0, 7254000.0, 45.08, 4352400.0, 2901600.0),
            $this->currentDistributionSummary(),
            self::AMOUNT_TOLERANCE,
        );
    }

    public function testCorrectedSaleCountsForItsNewTotalInTheDistribution(): void
    {
        $this->createSellingSabreDoctrineProject();

        $this->correctTheSabreSaleUnitPriceTo(950000.0);

        self::assertEqualsWithDelta(
            $this->distributionSummary(7900000.0, 237000.0, 316000.0, 7347000.0, 46.94, 4408200.0, 2938800.0),
            $this->currentDistributionSummary(),
            self::AMOUNT_TOLERANCE,
        );
    }

    public function testDeletedSaleNoLongerCountsInTheDistribution(): void
    {
        $this->createSellingSabreDoctrineProject();

        $this->deleteTheSabreSale();

        self::assertEqualsWithDelta(
            $this->distributionSummary(6000000.0, 180000.0, 240000.0, 5580000.0, 11.60, 3348000.0, 2232000.0),
            $this->currentDistributionSummary(),
            self::AMOUNT_TOLERANCE,
        );
    }

    public function testProjectInProgressBecomesDeletableOnceItsOnlySaleIsDeleted(): void
    {
        $owner = $this->createUserWithMainCharacter('Aria Solberg');
        $project = (new GroupIndustryProject())->setOwner($owner)->setName('Hecate batch')->setStatus(GroupProjectStatus::InProgress);
        $this->em->persist($project);
        $this->addMember($project, $owner, GroupMemberRole::Owner);
        $sabreSale = $this->addSale($project, $owner, 'Sabre', quantity: 2, unitPrice: 900000.0);
        $this->em->flush();
        $this->ownerId = (string) $owner->getId();
        $this->projectId = (string) $project->getId();
        $this->sabreSaleId = (string) $sabreSale->getId();
        $this->em->clear();

        $this->deleteTheSabreSale();
        $deletableProject = $this->projectDeleteProvider()->provide(new Delete(), ['id' => $this->projectId]);

        self::assertSame($this->projectId, (string) $deletableProject->id);
    }

    private function createSellingSabreDoctrineProject(): void
    {
        $owner = $this->createUserWithMainCharacter('Aria Solberg');
        $project = (new GroupIndustryProject())
            ->setOwner($owner)
            ->setName('Sabre doctrine')
            ->setStatus(GroupProjectStatus::Selling)
            ->setBrokerFeePercent(self::BROKER_FEE_PERCENT)
            ->setSalesTaxPercent(self::SALES_TAX_PERCENT);
        $this->em->persist($project);
        $this->addMember($project, $owner, GroupMemberRole::Owner);
        $memberA = $this->addMember($project, $this->createUserWithMainCharacter('Brann Holt'), GroupMemberRole::Admin);
        $memberB = $this->addMember($project, $this->createUserWithMainCharacter('Cyra Lune'), GroupMemberRole::Member);
        $this->addApprovedContribution($project, $memberA, $this->addBomItem($project, self::TRITANIUM_TYPE_ID, 'Tritanium'), 3000000.0);
        $this->addApprovedContribution($project, $memberB, $this->addBomItem($project, self::PYERITE_TYPE_ID, 'Pyerite'), 2000000.0);
        $this->addSale($project, $owner, 'Ishtar', quantity: 5, unitPrice: 1200000.0);
        $sabreSale = $this->addSale($project, $owner, 'Sabre', quantity: 2, unitPrice: 900000.0);
        $this->em->flush();

        $this->ownerId = (string) $owner->getId();
        $this->projectId = (string) $project->getId();
        $this->sabreSaleId = (string) $sabreSale->getId();
        $this->em->clear();
    }

    private function correctTheSabreSaleUnitPriceTo(float $unitPrice): void
    {
        $input = new UpdateSaleInput();
        $input->unitPrice = $unitPrice;
        $operation = new Patch();
        $uriVariables = ['projectId' => $this->projectId, 'id' => $this->sabreSaleId];
        $previousSale = $this->saleItemProvider()->provide($operation, $uriVariables);

        $this->updateSaleProcessor()->process($input, $operation, $uriVariables, ['previous_data' => $previousSale]);
        $this->em->clear();
    }

    private function deleteTheSabreSale(): void
    {
        $operation = new Delete();
        $uriVariables = ['projectId' => $this->projectId, 'id' => $this->sabreSaleId];
        $sale = $this->saleItemProvider()->provide($operation, $uriVariables);

        $this->deleteSaleProcessor()->process($sale, $operation, $uriVariables, ['previous_data' => $sale]);
        $this->em->clear();
    }

    private function currentDistributionSummary(): array
    {
        $provider = new GroupDistributionProvider(
            $this->ownerSecurity(),
            self::getContainer()->get(GroupIndustryProjectRepository::class),
            self::getContainer()->get(GroupProjectAccessChecker::class),
            self::getContainer()->get(GroupIndustryDistributionService::class),
        );
        $distribution = $provider->provide(new Get(), ['projectId' => $this->projectId]);

        $payouts = [];
        foreach ($distribution->members as $member) {
            $payouts[$member['characterName']] = $member['payoutTotal'];
        }
        ksort($payouts);

        return [
            'totalRevenue' => $distribution->totalRevenue,
            'brokerFee' => $distribution->brokerFee,
            'salesTax' => $distribution->salesTax,
            'netRevenue' => $distribution->netRevenue,
            'marginPercent' => $distribution->marginPercent,
            'payouts' => $payouts,
        ];
    }

    private function distributionSummary(float $totalRevenue, float $brokerFee, float $salesTax, float $netRevenue, float $marginPercent, float $payoutOfA, float $payoutOfB): array
    {
        return [
            'totalRevenue' => $totalRevenue,
            'brokerFee' => $brokerFee,
            'salesTax' => $salesTax,
            'netRevenue' => $netRevenue,
            'marginPercent' => $marginPercent,
            'payouts' => ['Brann Holt' => $payoutOfA, 'Cyra Lune' => $payoutOfB],
        ];
    }

    private function saleItemProvider(): GroupSaleItemProvider
    {
        return new GroupSaleItemProvider(
            $this->ownerSecurity(),
            self::getContainer()->get(GroupIndustryProjectRepository::class),
            self::getContainer()->get(GroupIndustrySaleRepository::class),
            self::getContainer()->get(GroupProjectAccessChecker::class),
        );
    }

    private function updateSaleProcessor(): UpdateSaleProcessor
    {
        return new UpdateSaleProcessor(
            $this->ownerSecurity(),
            self::getContainer()->get(GroupIndustryProjectRepository::class),
            self::getContainer()->get(GroupIndustrySaleRepository::class),
            self::getContainer()->get(GroupProjectAccessChecker::class),
            $this->em,
            $this->silentMercurePublisher(),
        );
    }

    private function deleteSaleProcessor(): DeleteSaleProcessor
    {
        return new DeleteSaleProcessor(
            $this->ownerSecurity(),
            self::getContainer()->get(GroupIndustryProjectRepository::class),
            self::getContainer()->get(GroupIndustrySaleRepository::class),
            self::getContainer()->get(GroupProjectAccessChecker::class),
            $this->em,
            $this->silentMercurePublisher(),
        );
    }

    private function projectDeleteProvider(): GroupProjectDeleteProvider
    {
        return new GroupProjectDeleteProvider(
            $this->ownerSecurity(),
            self::getContainer()->get(GroupIndustryProjectRepository::class),
        );
    }

    private function ownerSecurity(): Security
    {
        $owner = $this->em->find(User::class, $this->ownerId);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($owner);

        return $security;
    }

    private function silentMercurePublisher(): MercurePublisherService
    {
        return new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger());
    }

    private function createUserWithMainCharacter(string $characterName): User
    {
        $user = $this->createUser();
        $user->setMainCharacter($this->createCharacter($user, $characterName));

        return $user;
    }

    private function addMember(GroupIndustryProject $project, User $user, GroupMemberRole $role): GroupIndustryProjectMember
    {
        $member = (new GroupIndustryProjectMember())
            ->setUser($user)
            ->setRole($role)
            ->setStatus(GroupMemberStatus::Accepted);
        $project->addMember($member);

        return $member;
    }

    private function addBomItem(GroupIndustryProject $project, int $typeId, string $typeName): GroupIndustryBomItem
    {
        $bomItem = (new GroupIndustryBomItem())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity(1000000);
        $project->addBomItem($bomItem);

        return $bomItem;
    }

    private function addApprovedContribution(GroupIndustryProject $project, GroupIndustryProjectMember $member, GroupIndustryBomItem $bomItem, float $estimatedValue): void
    {
        $contribution = (new GroupIndustryContribution())
            ->setMember($member)
            ->setBomItem($bomItem)
            ->setType(ContributionType::Material)
            ->setQuantity(1000)
            ->setEstimatedValue($estimatedValue)
            ->setStatus(ContributionStatus::Approved);
        $project->addContribution($contribution);
    }

    private function addSale(GroupIndustryProject $project, User $recordedBy, string $typeName, int $quantity, float $unitPrice): GroupIndustrySale
    {
        $sale = (new GroupIndustrySale())
            ->setTypeId(0)
            ->setTypeName($typeName)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setTotalPrice($quantity * $unitPrice)
            ->setVenue('Jita IV - Moon 4')
            ->setSoldAt(new \DateTimeImmutable('2026-10-20T18:30:00+00:00'))
            ->setRecordedBy($recordedBy);
        $project->addSale($sale);

        return $sale;
    }
}
