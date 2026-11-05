<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry;

use App\Entity\CachedIndustryJob;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepJobMatch;
use App\Entity\IndustryStepPurchase;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-206')]
final class ProjectStepOfAnotherProjectApiTest extends WebTestCase
{
    use BuildsCarrierProjectWithFuelBlockSteps;

    private const int ESI_JOB_ID = 7_206_201;
    private const int OTHER_FUEL_BLOCK_RUNS = 3;
    private const int OTHER_FUEL_BLOCK_QUANTITY = 120;
    private const int OTHER_PURCHASED_ISOTOPES = 27;
    private const float OTHER_ISOTOPE_UNIT_PRICE = 600.0;

    private IndustryProjectStep $stepOfAnotherProject;
    private IndustryStepPurchase $purchaseOfAnotherProject;

    protected function setUp(): void
    {
        $this->setUpCarrierProjectWithFuelBlockStep();
    }

    #[DataProvider('stepOperationsOnAStepOfAnotherProject')]
    public function testOperationThroughTheOwnProjectOnAStepOfAnotherProjectIsNotFoundAndChangesNothing(string $otherProjectHolder, string $method, string $uriAfterTheProject, ?array $body): void
    {
        $this->persistFuelBlockStepOfAnotherProjectOf($otherProjectHolder === 'owner' ? $this->owner : $this->intruder);
        $this->persistEsiJobOfTheOwner();
        $stepId = $this->idOf($this->stepOfAnotherProject);
        $purchaseId = (string) $this->purchaseOfAnotherProject->getId()?->toRfc4122();

        $this->client->loginUser($this->owner, 'api');
        $this->request(
            $method,
            $this->projectUri().str_replace(['{stepId}', '{purchaseId}'], [$stepId, $purchaseId], $uriAfterTheProject),
            $body === null ? null : array_map(static fn (mixed $value): mixed => $value === '{stepId}' ? $stepId : $value, $body),
        );

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Step not found'], $this->errorDetail());
        self::assertSame([[3, 120, 0, null, false, 'auto']], $this->storedStepsOfTheOtherProject());
        self::assertSame([[self::ISOTOPE_TYPE_ID, 27, 16_200.0]], $this->storedPurchasesOfTheOtherStep());
        self::assertSame(0, $this->em->getRepository(IndustryStepJobMatch::class)->count([]));
        self::assertSame([[6, 240, 0, null, false]], $this->storedJobsOfProduct(self::FUEL_BLOCK_TYPE_ID));
    }

    public static function stepOperationsOnAStepOfAnotherProject(): iterable
    {
        $operations = [
            'add a job to a step' => ['POST', '/steps', ['stepId' => '{stepId}', 'runs' => 2]],
            'update a step' => ['PATCH', '/steps/{stepId}', ['runs' => 5]],
            'delete a step' => ['DELETE', '/steps/{stepId}', null],
            'split a step' => ['POST', '/steps/{stepId}/split', ['numberOfJobs' => 2]],
            'merge a split group' => ['POST', '/steps/{stepId}/merge', []],
            'link an ESI job' => ['POST', '/steps/{stepId}/link-job', ['esiJobId' => self::ESI_JOB_ID]],
            'record a purchase' => ['POST', '/steps/{stepId}/purchases', ['typeId' => self::FUEL_BLOCK_TYPE_ID, 'quantity' => 40, 'unitPrice' => 15_300.0]],
            'delete a purchase' => ['DELETE', '/steps/{stepId}/purchases/{purchaseId}', null],
        ];

        foreach (['owner' => 'another project of the owner', 'intruder' => 'a project of another user'] as $otherProjectHolder => $otherProjectLabel) {
            foreach ($operations as $operationLabel => $operation) {
                yield $operationLabel.' of '.$otherProjectLabel => [$otherProjectHolder, ...$operation];
            }
        }
    }

    private function persistFuelBlockStepOfAnotherProjectOf(User $holder): void
    {
        $anotherProject = (new IndustryProject())->setProductTypeId(587)->setRuns(1)->setOwner($holder)->setProductTypeId(self::FUEL_BLOCK_TYPE_ID)->setRuns(self::OTHER_FUEL_BLOCK_RUNS)->setMeLevel(0);
        $this->em->persist($anotherProject);
        $this->stepOfAnotherProject = (new IndustryProjectStep())
            ->setBlueprintTypeId(self::FUEL_BLOCK_BLUEPRINT_TYPE_ID)
            ->setProductTypeId(self::FUEL_BLOCK_TYPE_ID)
            ->setRuns(self::OTHER_FUEL_BLOCK_RUNS)
            ->setQuantity(self::OTHER_FUEL_BLOCK_QUANTITY)
            ->setDepth(0)
            ->setActivityType('manufacturing')
            ->setSortOrder(0)
            ->setMeLevel(0)
            ->setTeLevel(0);
        $anotherProject->addStep($this->stepOfAnotherProject);
        $this->purchaseOfAnotherProject = (new IndustryStepPurchase())
            ->setTypeId(self::ISOTOPE_TYPE_ID)
            ->setQuantity(self::OTHER_PURCHASED_ISOTOPES)
            ->setUnitPrice(self::OTHER_ISOTOPE_UNIT_PRICE)
            ->setTotalPrice(self::OTHER_PURCHASED_ISOTOPES * self::OTHER_ISOTOPE_UNIT_PRICE)
            ->setSource('manual');
        $this->stepOfAnotherProject->addPurchase($this->purchaseOfAnotherProject);
        $this->em->flush();
    }

    private function persistEsiJobOfTheOwner(): void
    {
        $character = $this->owner->getMainCharacter();
        self::assertNotNull($character);
        $this->em->persist((new CachedIndustryJob())
            ->setCharacter($character)
            ->setJobId(self::ESI_JOB_ID)
            ->setActivityId(IndustryActivityType::Manufacturing->value)
            ->setBlueprintTypeId(self::FUEL_BLOCK_BLUEPRINT_TYPE_ID)
            ->setProductTypeId(self::FUEL_BLOCK_TYPE_ID)
            ->setRuns(self::OTHER_FUEL_BLOCK_RUNS)
            ->setStatus('active')
            ->setStartDate(new \DateTimeImmutable('2026-11-02T08:00:00+00:00'))
            ->setEndDate(new \DateTimeImmutable('2026-11-04T08:00:00+00:00'))
            ->setCachedAt(new \DateTimeImmutable('2026-11-03T08:00:00+00:00')));
        $this->em->flush();
    }

    private function storedStepsOfTheOtherProject(): array
    {
        $this->em->clear();
        $steps = $this->em->getRepository(IndustryProjectStep::class)->findBy(['project' => $this->stepOfAnotherProject->getProject()?->getId()]);

        return array_map(
            static fn (IndustryProjectStep $step): array => [$step->getRuns(), $step->getQuantity(), $step->getSplitIndex(), $step->getTotalGroupRuns(), $step->isRunsFixed(), $step->getJobMatchMode()],
            $steps,
        );
    }

    private function storedPurchasesOfTheOtherStep(): array
    {
        $purchases = $this->em->getRepository(IndustryStepPurchase::class)->findBy(['step' => $this->stepOfAnotherProject->getId()]);

        return array_map(
            static fn (IndustryStepPurchase $purchase): array => [$purchase->getTypeId(), $purchase->getQuantity(), $purchase->getTotalPrice()],
            $purchases,
        );
    }
}
