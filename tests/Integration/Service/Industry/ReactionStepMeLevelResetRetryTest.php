<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;
use App\Enum\IndustryActivityType;
use App\Enum\ProjectCorrectionStatus;
use App\Exception\BlueprintProductMissingFromSdeException;
use App\Repository\IndustryProjectRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\IndustryStepCalculator;
use App\Service\Industry\ProjectCorrectionOutcome;
use App\Service\Industry\ReactionStepMeLevelReset;
use App\Service\Mercure\MercurePublisherService;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Unit\Service\Industry\AutowiresByType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ReactionStepMeLevelReset::class)]
#[Group('issue-190')]
final class ReactionStepMeLevelResetRetryTest extends IntegrationTestCase
{
    use AutowiresByType;

    private const int CARBIDE_TYPE_ID = 999_190_101;
    private const int CARBIDE_FORMULA_TYPE_ID = 999_190_102;
    private const int ALLOY_TYPE_ID = 999_190_201;
    private const int ALLOY_FORMULA_TYPE_ID = 999_190_202;
    private const int CARBIDE_RUNS = 12;
    private const int CARBIDES_PER_CARBIDE_RUN = 3_000;
    private const int ALLOYS_PER_CARBIDE_RUN = 50;
    private const int ALLOYS_PER_ALLOY_RUN = 40;
    private const int CARBIDE_ME_LEVEL = 10;
    private const string PROJECT_NAME = 'Carbide of the reaction ME reset retry';
    private const string LABEL_OF_THE_CARBIDE_TYPE_MISSING_FROM_THE_SDE = 'type 999190101 (name missing from the SDE)';

    #[Group('issue-301')]
    public function testProjectFailedOnAFormulaMissingFromTheSdeKeepsItsMeAndIsResetOnceTheSdeIsFixed(): void
    {
        $this->seedCarbideFormulaWithoutTheProductOfTheAlloyFormula();
        $projectId = $this->carbideProjectAtMeTenWithItsAlloyStep();
        $this->flushAndClear();

        $failedOutcomes = $this->reset()->run(false);

        self::assertSame(
            [[$projectId->toRfc4122(), self::LABEL_OF_THE_CARBIDE_TYPE_MISSING_FROM_THE_SDE, self::CARBIDE_RUNS, ProjectCorrectionStatus::Failed, null, BlueprintProductMissingFromSdeException::class.': Product of blueprint '.self::ALLOY_FORMULA_TYPE_ID.' missing from the SDE']],
            self::summaryOf($failedOutcomes),
        );
        self::assertSame(
            [[self::CARBIDE_TYPE_ID, 10, 12, 36_000], [self::ALLOY_TYPE_ID, 0, 14, 540]],
            $this->persistedMeRunsAndQuantityOfEachStep($projectId),
        );

        $this->em->persist((new IndustryActivityProduct())->setTypeId(self::ALLOY_FORMULA_TYPE_ID)->setActivityId(IndustryActivityType::Reaction->value)->setProductTypeId(self::ALLOY_TYPE_ID)->setQuantity(self::ALLOYS_PER_ALLOY_RUN));
        $this->flushAndClear();

        $retriedOutcomes = $this->reset()->run(false);

        self::assertSame(
            [[$projectId->toRfc4122(), self::LABEL_OF_THE_CARBIDE_TYPE_MISSING_FROM_THE_SDE, self::CARBIDE_RUNS, ProjectCorrectionStatus::Changed, '1 reaction steps ME 10 -> 0, 1 steps recalculated, steps 2 -> 2', null]],
            self::summaryOf($retriedOutcomes),
        );
        self::assertSame(
            [[self::CARBIDE_TYPE_ID, 0, 12, 36_000], [self::ALLOY_TYPE_ID, 0, 15, 600]],
            $this->persistedMeRunsAndQuantityOfEachStep($projectId),
        );
        self::assertSame([], $this->reset()->run(false));
    }

    private function seedCarbideFormulaWithoutTheProductOfTheAlloyFormula(): void
    {
        $reaction = IndustryActivityType::Reaction->value;
        $this->em->persist((new IndustryActivityProduct())->setTypeId(self::CARBIDE_FORMULA_TYPE_ID)->setActivityId($reaction)->setProductTypeId(self::CARBIDE_TYPE_ID)->setQuantity(self::CARBIDES_PER_CARBIDE_RUN));
        $this->em->persist((new IndustryActivityMaterial())->setTypeId(self::CARBIDE_FORMULA_TYPE_ID)->setActivityId($reaction)->setMaterialTypeId(self::ALLOY_TYPE_ID)->setQuantity(self::ALLOYS_PER_CARBIDE_RUN));
    }

    private function carbideProjectAtMeTenWithItsAlloyStep(): Uuid
    {
        $project = (new IndustryProject())
            ->setUser($this->createUser())
            ->setName(self::PROJECT_NAME)
            ->setProductTypeId(self::CARBIDE_TYPE_ID)
            ->setRuns(self::CARBIDE_RUNS);
        $project->addStep(self::reactionStep(self::CARBIDE_FORMULA_TYPE_ID, self::CARBIDE_TYPE_ID, depth: 0, runs: self::CARBIDE_RUNS, quantity: self::CARBIDE_RUNS * self::CARBIDES_PER_CARBIDE_RUN, meLevel: self::CARBIDE_ME_LEVEL, sortOrder: 0));
        $project->addStep(self::reactionStep(self::ALLOY_FORMULA_TYPE_ID, self::ALLOY_TYPE_ID, depth: 1, runs: 14, quantity: 540, meLevel: 0, sortOrder: 1));
        $this->em->persist($project);
        $this->em->flush();

        $projectId = $project->getId();
        self::assertNotNull($projectId);

        return $projectId;
    }

    private static function reactionStep(int $formulaTypeId, int $productTypeId, int $depth, int $runs, int $quantity, int $meLevel, int $sortOrder): IndustryProjectStep
    {
        return (new IndustryProjectStep())
            ->setBlueprintTypeId($formulaTypeId)
            ->setProductTypeId($productTypeId)
            ->setActivityType('reaction')
            ->setDepth($depth)
            ->setRuns($runs)
            ->setQuantity($quantity)
            ->setMeLevel($meLevel)
            ->setTeLevel(0)
            ->setSortOrder($sortOrder);
    }

    private function reset(): ReactionStepMeLevelReset
    {
        $container = self::getContainer();
        $calculationService = $container->get(IndustryCalculationService::class);

        return self::autowired(ReactionStepMeLevelReset::class, [
            $container->get(IndustryProjectRepository::class),
            $this->em,
            $calculationService,
            self::autowired(IndustryStepCalculator::class, [
                $calculationService,
                $container->get(IndustryActivityMaterialRepository::class),
                $container->get(IndustryActivityProductRepository::class),
                $this->em,
            ]),
            new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
        ]);
    }

    private function persistedMeRunsAndQuantityOfEachStep(Uuid $projectId): array
    {
        $this->em->clear();
        $project = $this->em->find(IndustryProject::class, $projectId);
        self::assertNotNull($project);
        $steps = $project->getSteps()->toArray();
        usort($steps, static fn (IndustryProjectStep $a, IndustryProjectStep $b): int => $a->getSortOrder() <=> $b->getSortOrder());

        return array_map(
            static fn (IndustryProjectStep $step): array => [$step->getProductTypeId(), $step->getMeLevel(), $step->getRuns(), $step->getQuantity()],
            $steps,
        );
    }

    private static function summaryOf(array $outcomes): array
    {
        return array_map(
            static fn (ProjectCorrectionOutcome $outcome): array => [$outcome->projectId, $outcome->label, $outcome->runs, $outcome->status, $outcome->change, $outcome->reason],
            $outcomes,
        );
    }
}
