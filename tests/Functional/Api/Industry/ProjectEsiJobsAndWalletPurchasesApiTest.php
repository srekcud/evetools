<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry;

use App\Entity\CachedIndustryJob;
use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepJobMatch;
use App\Entity\IndustryStepPurchase;
use App\Enum\IndustryActivityType;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-206')]
final class ProjectEsiJobsAndWalletPurchasesApiTest extends WebTestCase
{
    use BuildsCarrierProjectWithFuelBlockSteps;
    use SeedsJitaOrderBooksAndCostIndices;

    private const string JOBS_START_DATE = '2026-10-31T00:00:00+00:00';
    private const int FUEL_BLOCK_JOB_ID = 7_206_101;
    private const int CARRIER_JOB_ID = 7_206_102;
    private const int CANCELLED_FUEL_BLOCK_JOB_ID = 7_206_103;
    private const int FUEL_BLOCK_JOB_BEFORE_THE_PROJECT_ID = 7_206_104;
    private const int FUEL_BLOCK_JOB_OF_THE_INTRUDER_ID = 7_206_105;
    private const float FUEL_BLOCK_JOB_COST = 48_500.0;
    private const float CARRIER_JOB_COST = 1_250_000.0;
    private const int JITA_STATION_ID = 60_003_760;
    private const int UNLINKED_FUEL_BLOCK_BUY_ID = 8_206_001;
    private const int FUEL_BLOCK_BUY_LINKED_TO_THIS_PROJECT_ID = 8_206_002;
    private const int ISOTOPE_BUY_ID = 8_206_003;
    private const int FUEL_BLOCK_SALE_ID = 8_206_004;
    private const int FUEL_BLOCK_BUY_LINKED_TO_ANOTHER_PROJECT_ID = 8_206_005;

    protected function setUp(): void
    {
        $this->setUpCarrierProjectWithFuelBlockStep();
        $this->recordEsiRequestsInsteadOfSendingThem();
        $this->project->setJobsStartDate(new \DateTimeImmutable(self::JOBS_START_DATE));
        $this->em->flush();
    }

    public function testAvailableJobsListTheJobsOfTheOwnerOnTheProjectBlueprintsSinceTheJobsStartDateWithTheirLink(): void
    {
        $this->persistEsiJobs();
        $match = (new IndustryStepJobMatch())->setEsiJobId(self::FUEL_BLOCK_JOB_ID)->setRuns(6)->setCharacterName('Aria Solberg');
        $this->fuelBlockStep->addJobMatch($match);
        $this->em->flush();

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->projectUri().'/available-jobs');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [
                [self::FUEL_BLOCK_JOB_ID, self::FUEL_BLOCK_BLUEPRINT_TYPE_ID, self::FUEL_BLOCK_TYPE_ID, 'Test Fuel Block', 6, 48_500.0, 'active', '2026-11-02T08:00:00+00:00', '2026-11-04T08:00:00+00:00', 'Aria Solberg', $this->idOf($this->fuelBlockStep), 'Test Fuel Block', $match->getId()?->toRfc4122()],
                [self::CARRIER_JOB_ID, self::CARRIER_BLUEPRINT_TYPE_ID, self::CARRIER_TYPE_ID, 'Test Carrier', 2, 1_250_000.0, 'delivered', '2026-11-01T10:00:00+00:00', '2026-11-02T10:00:00+00:00', 'Aria Solberg', 'absent', 'absent', 'absent'],
            ],
            array_map(fn (array $job): array => $this->fieldsOf($job, ['esiJobId', 'blueprintTypeId', 'productTypeId', 'productTypeName', 'runs', 'cost', 'status', 'startDate', 'endDate', 'characterName', 'linkedToStepId', 'linkedToStepName', 'matchId']), $this->responseJson()),
        );
    }

    public function testMatchingJobsLinksTheFuelBlockAndCarrierJobsOfTheOwnerAndReportsTheCharacterWithoutToken(): void
    {
        $this->persistEsiJobs();

        $this->client->loginUser($this->owner, 'api');
        $this->request('POST', $this->projectUri().'/match-jobs', []);

        self::assertResponseStatusCodeSame(201);
        $result = $this->responseJson();
        self::assertSame(
            [
                [self::CARRIER_TYPE_ID, 2, [[self::CARRIER_JOB_ID, 2, 'delivered', 1_250_000.0]]],
                [self::FUEL_BLOCK_TYPE_ID, 6, [[self::FUEL_BLOCK_JOB_ID, 6, 'active', 48_500.0]]],
            ],
            array_map(fn (array $step): array => [
                $step['productTypeId'],
                $step['runs'],
                array_map(fn (array $jobMatch): array => $this->fieldsOf($jobMatch, ['esiJobId', 'runs', 'status', 'cost']), $step['jobMatches']),
            ], $result['steps']),
        );
        self::assertSame(
            [1_298_500.0, 0, [['characterName' => 'Aria Solberg', 'reason' => 'missing_token', 'statusCode' => null]]],
            $this->fieldsOf($result, ['jobsCost', 'syncedCharacters', 'failedCharacters']),
        );
        self::assertSame([self::FUEL_BLOCK_JOB_ID, self::CARRIER_JOB_ID], $this->storedMatchedEsiJobIds());
        self::assertSame([], $this->esiRequests);
    }

    public function testPurchaseSuggestionsListTheFuelBlockBuysOfTheOwnerNotLinkedToAnotherProject(): void
    {
        $this->persistWalletTransactions();

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->projectUri().'/purchase-suggestions');

        self::assertResponseStatusCodeSame(200);
        $suggestions = $this->responseJson();
        self::assertSame(
            [
                [self::UNLINKED_FUEL_BLOCK_BUY_ID, self::FUEL_BLOCK_TYPE_ID, 'Test Fuel Block', 80, 15_100.0, 1_208_000.0, '2026-11-02T09:00:00+00:00', 'Aria Solberg', self::JITA_STATION_ID, false],
                [self::FUEL_BLOCK_BUY_LINKED_TO_THIS_PROJECT_ID, self::FUEL_BLOCK_TYPE_ID, 'Test Fuel Block', 40, 15_400.0, 616_000.0, '2026-11-01T09:00:00+00:00', 'Aria Solberg', self::JITA_STATION_ID, true],
            ],
            array_map(fn (array $suggestion): array => $this->fieldsOf($suggestion, ['transactionId', 'typeId', 'typeName', 'quantity', 'unitPrice', 'totalPrice', 'date', 'characterName', 'locationId', 'alreadyLinked']), $suggestions['suggestions']),
        );
        self::assertSame(2, $suggestions['totalCount']);
    }

    private function persistEsiJobs(): void
    {
        $owner = $this->owner->getMainCharacter();
        $intruder = $this->intruder->getMainCharacter();
        self::assertNotNull($owner);
        self::assertNotNull($intruder);
        $this->esiJob($owner, self::FUEL_BLOCK_JOB_ID, self::FUEL_BLOCK_BLUEPRINT_TYPE_ID, self::FUEL_BLOCK_TYPE_ID, 6, CachedIndustryJob::STATUS_ACTIVE, '2026-11-02T08:00:00+00:00', '2026-11-04T08:00:00+00:00', self::FUEL_BLOCK_JOB_COST);
        $this->esiJob($owner, self::CARRIER_JOB_ID, self::CARRIER_BLUEPRINT_TYPE_ID, self::CARRIER_TYPE_ID, 2, CachedIndustryJob::STATUS_DELIVERED, '2026-11-01T10:00:00+00:00', '2026-11-02T10:00:00+00:00', self::CARRIER_JOB_COST);
        $this->esiJob($owner, self::CANCELLED_FUEL_BLOCK_JOB_ID, self::FUEL_BLOCK_BLUEPRINT_TYPE_ID, self::FUEL_BLOCK_TYPE_ID, 6, CachedIndustryJob::STATUS_CANCELLED, '2026-11-02T12:00:00+00:00', '2026-11-04T12:00:00+00:00', 51_000.0);
        $this->esiJob($owner, self::FUEL_BLOCK_JOB_BEFORE_THE_PROJECT_ID, self::FUEL_BLOCK_BLUEPRINT_TYPE_ID, self::FUEL_BLOCK_TYPE_ID, 6, CachedIndustryJob::STATUS_DELIVERED, '2026-10-20T08:00:00+00:00', '2026-10-22T08:00:00+00:00', 47_000.0);
        $this->esiJob($intruder, self::FUEL_BLOCK_JOB_OF_THE_INTRUDER_ID, self::FUEL_BLOCK_BLUEPRINT_TYPE_ID, self::FUEL_BLOCK_TYPE_ID, 6, CachedIndustryJob::STATUS_ACTIVE, '2026-11-02T14:00:00+00:00', '2026-11-04T14:00:00+00:00', 49_000.0);
        $this->em->flush();
    }

    private function esiJob(Character $character, int $jobId, int $blueprintTypeId, int $productTypeId, int $runs, string $status, string $startDate, string $endDate, float $cost): void
    {
        $this->em->persist((new CachedIndustryJob())
            ->setCharacter($character)
            ->setJobId($jobId)
            ->setActivityId(IndustryActivityType::Manufacturing->value)
            ->setBlueprintTypeId($blueprintTypeId)
            ->setProductTypeId($productTypeId)
            ->setRuns($runs)
            ->setStatus($status)
            ->setCost($cost)
            ->setStartDate(new \DateTimeImmutable($startDate))
            ->setEndDate(new \DateTimeImmutable($endDate))
            ->setCachedAt(new \DateTimeImmutable('2026-11-03T08:00:00+00:00')));
    }

    private function persistWalletTransactions(): void
    {
        $owner = $this->owner->getMainCharacter();
        self::assertNotNull($owner);
        $this->walletTransaction($owner, self::UNLINKED_FUEL_BLOCK_BUY_ID, self::FUEL_BLOCK_TYPE_ID, 80, 15_100.0, true, '2026-11-02T09:00:00+00:00');
        $linkedToThisProject = $this->walletTransaction($owner, self::FUEL_BLOCK_BUY_LINKED_TO_THIS_PROJECT_ID, self::FUEL_BLOCK_TYPE_ID, 40, 15_400.0, true, '2026-11-01T09:00:00+00:00');
        $this->walletTransaction($owner, self::ISOTOPE_BUY_ID, self::ISOTOPE_TYPE_ID, 500, 612.5, true, '2026-11-02T10:00:00+00:00');
        $this->walletTransaction($owner, self::FUEL_BLOCK_SALE_ID, self::FUEL_BLOCK_TYPE_ID, 20, 16_000.0, false, '2026-11-02T11:00:00+00:00');
        $linkedToAnotherProject = $this->walletTransaction($owner, self::FUEL_BLOCK_BUY_LINKED_TO_ANOTHER_PROJECT_ID, self::FUEL_BLOCK_TYPE_ID, 120, 15_250.0, true, '2026-11-02T12:00:00+00:00');
        $this->fuelBlockStep->addPurchase($this->purchaseOf($linkedToThisProject));
        $this->fuelBlockStepOfAnotherProjectOfTheOwner()->addPurchase($this->purchaseOf($linkedToAnotherProject));
        $this->em->flush();
    }

    private function walletTransaction(Character $character, int $transactionId, int $typeId, int $quantity, float $unitPrice, bool $isBuy, string $date): CachedWalletTransaction
    {
        $transaction = (new CachedWalletTransaction())
            ->setCharacter($character)
            ->setTransactionId($transactionId)
            ->setTypeId($typeId)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setIsBuy($isBuy)
            ->setLocationId(self::JITA_STATION_ID)
            ->setClientId(91_206_001)
            ->setDate(new \DateTimeImmutable($date));
        $this->em->persist($transaction);

        return $transaction;
    }

    private function purchaseOf(CachedWalletTransaction $transaction): IndustryStepPurchase
    {
        return (new IndustryStepPurchase())
            ->setTypeId($transaction->getTypeId())
            ->setQuantity($transaction->getQuantity())
            ->setUnitPrice($transaction->getUnitPrice())
            ->setTotalPrice($transaction->getTotalPrice())
            ->setSource('esi_wallet')
            ->setTransaction($transaction);
    }

    private function fuelBlockStepOfAnotherProjectOfTheOwner(): IndustryProjectStep
    {
        $anotherProject = (new IndustryProject())->setUser($this->owner)->setProductTypeId(self::FUEL_BLOCK_TYPE_ID)->setRuns(3)->setMeLevel(0);
        $this->em->persist($anotherProject);
        $step = (new IndustryProjectStep())
            ->setBlueprintTypeId(self::FUEL_BLOCK_BLUEPRINT_TYPE_ID)
            ->setProductTypeId(self::FUEL_BLOCK_TYPE_ID)
            ->setRuns(3)
            ->setQuantity(120)
            ->setDepth(0)
            ->setActivityType('manufacturing')
            ->setSortOrder(0)
            ->setMeLevel(0)
            ->setTeLevel(0);
        $anotherProject->addStep($step);

        return $step;
    }

    private function storedMatchedEsiJobIds(): array
    {
        $this->em->clear();
        $matches = $this->em->getRepository(IndustryStepJobMatch::class)->findBy(['step' => [$this->carrierStep->getId(), $this->fuelBlockStep->getId()]], ['esiJobId' => 'ASC']);

        return array_map(static fn (IndustryStepJobMatch $match): int => $match->getEsiJobId(), $matches);
    }
}
