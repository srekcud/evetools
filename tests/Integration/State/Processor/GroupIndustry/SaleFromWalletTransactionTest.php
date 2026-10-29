<?php

declare(strict_types=1);

namespace App\Tests\Integration\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\ApiResource\GroupIndustry\GroupIndustrySaleResource;
use App\ApiResource\GroupIndustry\GroupSaleSuggestionResource;
use App\ApiResource\Input\GroupIndustry\RecordSaleFromWalletTransactionInput;
use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectItem;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\GroupIndustrySale;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Enum\GroupProjectStatus;
use App\Repository\CachedWalletTransactionRepository;
use App\Repository\GroupIndustryProjectRepository;
use App\Repository\GroupIndustrySaleRepository;
use App\Service\GroupIndustry\GroupIndustryDistributionService;
use App\Service\Mercure\MercurePublisherService;
use App\State\Processor\GroupIndustry\DeleteSaleProcessor;
use App\State\Processor\GroupIndustry\RecordSaleFromWalletTransactionProcessor;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use App\State\Provider\GroupIndustry\GroupSaleItemProvider;
use App\State\Provider\GroupIndustry\GroupSaleSuggestionProvider;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Mercure\HubInterface;

#[Group('issue-241')]
final class SaleFromWalletTransactionTest extends IntegrationTestCase
{
    private const int SABRE_TYPE_ID = 22456;
    private const float AMOUNT_TOLERANCE = 0.01;

    private string $ownerId;
    private string $projectId;
    private string $manualSaleVId;
    private string $t1Id;
    private string $t7Id;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = $this->createUser();
        $oMain = $this->createCharacter($owner, 'O-Main')->setEveCharacterId(2120000001);
        $oAlt = $this->createCharacter($owner, 'O-Alt')->setEveCharacterId(2120000002);
        $owner->setMainCharacter($oMain);
        $project = (new GroupIndustryProject())
            ->setOwner($owner)
            ->setName('Sabre')
            ->setStatus(GroupProjectStatus::Selling)
            ->setBrokerFeePercent(3.0)
            ->setSalesTaxPercent(4.0);
        (new \ReflectionProperty(GroupIndustryProject::class, 'createdAt'))->setValue($project, new \DateTimeImmutable('2026-10-20T10:00:00+00:00'));
        $this->em->persist($project);
        $project->addMember((new GroupIndustryProjectMember())->setUser($owner)->setRole(GroupMemberRole::Owner)->setStatus(GroupMemberStatus::Accepted));
        $project->addItem((new GroupIndustryProjectItem())->setTypeId(self::SABRE_TYPE_ID)->setTypeName('Sabre')->setMeLevel(10)->setRuns(1));
        $manualSaleV = (new GroupIndustrySale())
            ->setTypeId(0)
            ->setTypeName('Sabre')
            ->setQuantity(3)
            ->setUnitPrice(61_250_000.0)
            ->recalculateTotalPrice()
            ->setSoldAt(new \DateTimeImmutable('2026-10-24T22:00:00+00:00'))
            ->setRecordedBy($owner);
        $project->addSale($manualSaleV);
        $t1 = $this->transaction(6_100_000_001, $oMain, 3, 61_250_000.0, '2026-10-24T21:05:00+00:00');
        $t7 = $this->transaction(6_100_000_007, $oAlt, 12, 60_000_000.0, '2026-10-26T11:45:00+00:00');
        $this->em->flush();

        $this->ownerId = (string) $owner->getId();
        $this->projectId = (string) $project->getId();
        $this->manualSaleVId = (string) $manualSaleV->getId();
        $this->t1Id = (string) $t1->getId();
        $this->t7Id = (string) $t7->getId();
        $this->em->clear();
    }

    public function testL1SaleRecordedFromT1IsStoredLinkedToItAndSoldByTheMain(): void
    {
        $saleId = $this->recordSaleFrom($this->t1Id)->id;

        $sale = $this->em->find(GroupIndustrySale::class, $saleId);
        \assert($sale instanceof GroupIndustrySale);
        self::assertSame(
            [self::SABRE_TYPE_ID, 3, 183_750_000.0, '2026-10-24T21:05:00+00:00', 'O-Main', null, $this->t1Id],
            [
                $sale->getTypeId(),
                $sale->getQuantity(),
                $sale->getTotalPrice(),
                $sale->getSoldAt()->format(\DateTimeInterface::ATOM),
                $sale->getSeller()?->getName(),
                $sale->getVenue(),
                (string) $sale->getWalletTransaction()?->getId(),
            ],
        );
    }

    public function testL1T1IsNoLongerOfferedWhileT7StillIs(): void
    {
        $this->recordSaleFrom($this->t1Id);

        self::assertSame([$this->t7Id], array_keys($this->suggestedDuplicatesByTransaction()));
    }

    public function testL2SecondRecordingOfT1IsAConflictAndTheProjectKeepsTwoSales(): void
    {
        $this->recordSaleFrom($this->t1Id);

        $status = null;
        try {
            $this->recordSaleFrom($this->t1Id);
        } catch (HttpExceptionInterface $conflict) {
            $status = $conflict->getStatusCode();
        }

        self::assertSame([409, 2], [$status, $this->salesCount()]);
    }

    public function testUniqueIndexRefusesASecondSaleLinkedToTheSameTransaction(): void
    {
        $project = $this->em->find(GroupIndustryProject::class, $this->projectId);
        $t1 = $this->em->find(CachedWalletTransaction::class, $this->t1Id);
        \assert($project instanceof GroupIndustryProject && $t1 instanceof CachedWalletTransaction);
        $project->addSale($this->saleLinkedTo($t1, $project->getOwner()));
        $project->addSale($this->saleLinkedTo($t1, $project->getOwner()));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->em->flush();
    }

    public function testD1DeletingTheSaleOfT1OffersT1AgainWithTheManualSaleVAsPossibleDuplicate(): void
    {
        $saleId = $this->recordSaleFrom($this->t1Id)->id;

        $this->deleteSale($saleId);

        self::assertSame(
            [$this->t7Id => [], $this->t1Id => [$this->manualSaleVId]],
            $this->suggestedDuplicatesByTransaction(),
        );
    }

    public function testRevenueAfterL1CountsBothT1AndTheManualSaleV(): void
    {
        $this->recordSaleFrom($this->t1Id);

        self::assertEqualsWithDelta([367_500_000.0, 11_025_000.0, 14_700_000.0, 341_775_000.0], $this->revenueSummary(), self::AMOUNT_TOLERANCE);
    }

    public function testRevenueAfterL1AndTheDeletionOfTheManualSaleVCountsT1Only(): void
    {
        $this->recordSaleFrom($this->t1Id);

        $this->deleteSale($this->manualSaleVId);

        self::assertEqualsWithDelta([183_750_000.0, 5_512_500.0, 7_350_000.0, 170_887_500.0], $this->revenueSummary(), self::AMOUNT_TOLERANCE);
    }

    private function recordSaleFrom(string $transactionId): GroupIndustrySaleResource
    {
        $input = new RecordSaleFromWalletTransactionInput();
        $input->transactionId = $transactionId;

        $processor = new RecordSaleFromWalletTransactionProcessor(
            $this->ownerSecurity(),
            self::getContainer()->get(GroupIndustryProjectRepository::class),
            self::getContainer()->get(GroupProjectAccessChecker::class),
            self::getContainer()->get(CachedWalletTransactionRepository::class),
            self::getContainer()->get(GroupIndustrySaleRepository::class),
            $this->em,
            $this->silentMercurePublisher(),
        );
        $sale = $processor->process($input, new Post(), ['projectId' => $this->projectId]);
        $this->em->clear();

        return $sale;
    }

    private function deleteSale(string $saleId): void
    {
        $operation = new Delete();
        $uriVariables = ['projectId' => $this->projectId, 'id' => $saleId];
        $itemProvider = new GroupSaleItemProvider(
            $this->ownerSecurity(),
            self::getContainer()->get(GroupIndustryProjectRepository::class),
            self::getContainer()->get(GroupIndustrySaleRepository::class),
            self::getContainer()->get(GroupProjectAccessChecker::class),
        );
        $sale = $itemProvider->provide($operation, $uriVariables);

        $processor = new DeleteSaleProcessor(
            $this->ownerSecurity(),
            self::getContainer()->get(GroupIndustryProjectRepository::class),
            self::getContainer()->get(GroupIndustrySaleRepository::class),
            self::getContainer()->get(GroupProjectAccessChecker::class),
            $this->em,
            $this->silentMercurePublisher(),
        );
        $processor->process($sale, $operation, $uriVariables, ['previous_data' => $sale]);
        $this->em->clear();
    }

    private function suggestedDuplicatesByTransaction(): array
    {
        $provider = new GroupSaleSuggestionProvider(
            $this->ownerSecurity(),
            self::getContainer()->get(GroupIndustryProjectRepository::class),
            self::getContainer()->get(GroupProjectAccessChecker::class),
            self::getContainer()->get(CachedWalletTransactionRepository::class),
        );

        $duplicatesByTransaction = [];
        foreach ($provider->provide(new GetCollection(), ['projectId' => $this->projectId]) as $suggestion) {
            \assert($suggestion instanceof GroupSaleSuggestionResource);
            $duplicatesByTransaction[$suggestion->transactionId] = $suggestion->possibleDuplicateSaleIds;
        }
        $this->em->clear();

        return $duplicatesByTransaction;
    }

    private function revenueSummary(): array
    {
        $project = $this->em->find(GroupIndustryProject::class, $this->projectId);
        \assert($project instanceof GroupIndustryProject);
        $distributionService = self::getContainer()->get(GroupIndustryDistributionService::class);
        \assert($distributionService instanceof GroupIndustryDistributionService);
        $distribution = $distributionService->calculateDistribution($project);

        return [$distribution->totalRevenue, $distribution->brokerFee, $distribution->salesTax, $distribution->netRevenue];
    }

    private function salesCount(): int
    {
        return $this->em->getRepository(GroupIndustrySale::class)->count(['project' => $this->projectId]);
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

    private function transaction(int $transactionId, Character $character, int $quantity, float $unitPrice, string $date): CachedWalletTransaction
    {
        $transaction = (new CachedWalletTransaction())
            ->setCharacter($character)
            ->setTransactionId($transactionId)
            ->setIsBuy(false)
            ->setTypeId(self::SABRE_TYPE_ID)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setDate(new \DateTimeImmutable($date))
            ->setLocationId(60003760)
            ->setClientId(2_119_000_000 + $transactionId % 1000);
        $this->em->persist($transaction);

        return $transaction;
    }

    private function saleLinkedTo(CachedWalletTransaction $transaction, User $recordedBy): GroupIndustrySale
    {
        return (new GroupIndustrySale())
            ->setTypeId(self::SABRE_TYPE_ID)
            ->setTypeName('Sabre')
            ->setQuantity($transaction->getQuantity())
            ->setUnitPrice($transaction->getUnitPrice())
            ->recalculateTotalPrice()
            ->setSoldAt($transaction->getDate())
            ->setRecordedBy($recordedBy)
            ->setWalletTransaction($transaction);
    }
}
