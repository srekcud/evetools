<?php

declare(strict_types=1);

namespace App\Tests\Integration\State\Processor\Industry;

use ApiPlatform\Metadata\Patch;
use App\ApiResource\Industry\ProjectStepResource;
use App\ApiResource\Input\Industry\UpdateStepInput;
use App\Entity\CachedCharacterSkill;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\Sde\IndustryActivity;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Industry\Application\InventionCatalog;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Repository\Sde\IndustryBlueprintRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\IndustryBlacklistService;
use App\Service\Industry\IndustryBonusService;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\IndustryProjectFactory;
use App\Service\Industry\IndustryTreeService;
use App\Service\Mercure\MercurePublisherService;
use App\State\Processor\Industry\UpdateStepProcessor;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Unit\Service\Industry\AutowiresByType;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

#[Group('issue-185')]
final class UpdateStepFixedRunsTest extends IntegrationTestCase
{
    use AutowiresByType;

    private const int SHIP_CATEGORY_ID = 999_993_006;
    private const int CRUISER_GROUP_ID = 999_993_026;
    private const int PLATING_GROUP_ID = 999_993_334;
    private const int ALLOY_GROUP_ID = 999_993_018;

    private const int CRUISER_TYPE_ID = 999_993_620;
    private const int CRUISER_BLUEPRINT_TYPE_ID = 999_993_621;
    private const int PLATING_TYPE_ID = 999_993_111;
    private const int PLATING_BLUEPRINT_TYPE_ID = 999_993_112;
    private const int ALLOY_TYPE_ID = 999_993_036;

    private const int CARRIER_GROUP_ID = 999_993_547;
    private const int FUEL_BLOCK_GROUP_ID = 999_993_136;
    private const int ISOTOPE_GROUP_ID = 999_993_423;
    private const int CARRIER_TYPE_ID = 999_993_640;
    private const int CARRIER_BLUEPRINT_TYPE_ID = 999_993_641;
    private const int FUEL_BLOCK_TYPE_ID = 999_993_412;
    private const int FUEL_BLOCK_BLUEPRINT_TYPE_ID = 999_993_413;
    private const int ISOTOPE_TYPE_ID = 999_993_017;
    private const int FUEL_BLOCKS_PER_CARRIER_RUN = 120;
    private const int FUEL_BLOCKS_PER_FUEL_BLOCK_RUN = 40;
    private const int ISOTOPES_PER_FUEL_BLOCK_RUN = 9;
    private const int CARRIER_BASE_TIME_PER_RUN_SECONDS = 100;
    private const int FUEL_BLOCK_BASE_TIME_PER_RUN_SECONDS = 600;
    private const float MAX_JOB_DURATION_DAYS_SPLITTING_THE_FUEL_BLOCK_IN_THREE_JOBS = 4000 / 86400;
    private const float MAX_JOB_DURATION_DAYS_SPLITTING_THE_FUEL_BLOCK_IN_FOUR_JOBS = 3600 / 86400;

    private const int PLATINGS_PER_CRUISER_RUN = 4;
    private const int ALLOYS_PER_PLATING_RUN = 11;
    private const int CRUISER_BASE_TIME_PER_RUN_SECONDS = 700;
    private const int PLATING_BASE_TIME_PER_RUN_SECONDS = 600;

    private const int CRUISER_RUNS = 5;
    private const int PROJECT_ME_GIVING_A_PLATING_NEED_OF_18 = 10;
    private const int PROJECT_ME_GIVING_A_PLATING_NEED_OF_20 = 0;
    private const int PROJECT_TE = 20;
    private const float MAX_JOB_DURATION_DAYS_WITHOUT_SPLIT = 2.0;
    private const float MAX_JOB_DURATION_DAYS_SPLITTING_THE_PLATING_IN_TWO_JOBS = 4800 / 86400;
    private const float MAX_JOB_DURATION_DAYS_SPLITTING_THE_PLATING_IN_FOUR_JOBS = 2400 / 86400;
    private const int CRUISER_RUNS_GIVING_A_PLATING_NEED_OF_30 = 8;
    private const int PROJECT_ME_GIVING_A_PLATING_NEED_OF_30 = 7;

    private User $pilot;
    private IndustryProjectFactory $factory;
    private UpdateStepProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $character = $this->createCharacter(name: 'Fixed Runs Pilot');
        $pilot = $character->getUser();
        \assert($pilot instanceof User);
        $pilot->setMainCharacter($character);
        $this->pilot = $pilot;
        $this->em->persist((new CachedCharacterSkill())->setCharacter($character)->setSkillId(CachedCharacterSkill::SKILL_INDUSTRY)->setLevel(0)->setIsManual(true));
        $this->seedCruiserRecipe();
        $this->seedCarrierRecipe();
        $this->em->flush();

        $container = self::getContainer();
        $this->processor = $container->get(UpdateStepProcessor::class);
        $this->factory = self::autowired(IndustryProjectFactory::class, [
            $container->get(IndustryTreeService::class),
            $container->get(IndustryBlacklistService::class),
            $container->get(IndustryBonusService::class),
            $container->get(IndustryCalculationService::class),
            $container->get(CachedCharacterSkillRepository::class),
            $container->get(InvTypeRepository::class),
            $container->get(IndustryActivityMaterialRepository::class),
            $container->get(IndustryActivityRepository::class),
            $this->em,
            new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
            $container->get(IndustryBlueprintRepository::class),
            $container->get(InventionCatalog::class),
        ]);
    }

    public function testRunsEnteredBelowTheNeedOfAStepThatIsNotSplitAreFixed(): void
    {
        $project = $this->projectWithAPlatingStepThatIsNotSplit();
        $platingStepId = $this->idOf($this->stepOfProduct($project, self::PLATING_TYPE_ID));

        $response = $this->patch($project, $platingStepId, ['runs' => 15]);

        self::assertSame([15, true], [$response->runs, $response->runsFixed]);
        self::assertSame([[$platingStepId, 15, 15, true, 0, null]], $this->platingJobsOf($this->reloaded($project)));
    }

    public function testFixedRunsOfAStepSurviveTheCascadeOfAnotherStep(): void
    {
        $project = $this->projectWithAPlatingStepThatIsNotSplit();
        $platingStepId = $this->idOf($this->stepOfProduct($project, self::PLATING_TYPE_ID));
        $this->patch($project, $platingStepId, ['runs' => 15]);

        $project = $this->reloaded($project);
        $this->patch($project, $this->idOf($this->stepOfProduct($project, self::CRUISER_TYPE_ID)), ['meLevel' => 8]);

        self::assertSame([[$platingStepId, 15, 15, true, 0, null]], $this->platingJobsOf($this->reloaded($project)));
    }

    public function testFixedRunsOfAStepSurviveTheRegenerationAfterTheProjectRunsAreDoubled(): void
    {
        $project = $this->projectWithAPlatingStepThatIsNotSplit();
        $platingStepId = $this->idOf($this->stepOfProduct($project, self::PLATING_TYPE_ID));
        $this->patch($project, $platingStepId, ['runs' => 15]);

        $project = $this->reloaded($project);
        $project->setRuns(2 * self::CRUISER_RUNS);
        $this->factory->regenerateSteps($project);

        self::assertSame([[$platingStepId, 15, 15, true, 0, null]], $this->platingJobsOf($this->reloaded($project)));
    }

    public function testRunsEnteredAboveTheNeedWithoutOverproductionAreFixedAtTheNeed(): void
    {
        $project = $this->projectWithAPlatingStepThatIsNotSplit();
        $platingStepId = $this->idOf($this->stepOfProduct($project, self::PLATING_TYPE_ID));

        $response = $this->patch($project, $platingStepId, ['runs' => 20]);

        self::assertSame([18, true], [$response->runs, $response->runsFixed]);
        self::assertSame([[$platingStepId, 18, 18, true, 0, null]], $this->platingJobsOf($this->reloaded($project)));
    }

    public function testRunsEnteredAboveTheNeedWithOverproductionAreFixedAsEntered(): void
    {
        $project = $this->projectWithAPlatingStepThatIsNotSplit();
        $platingStepId = $this->idOf($this->stepOfProduct($project, self::PLATING_TYPE_ID));

        $this->patch($project, $platingStepId, ['runs' => 20, 'overproduce' => true]);

        self::assertSame([[$platingStepId, 20, 20, true, 0, null]], $this->platingJobsOf($this->reloaded($project)));
    }

    public function testRunsEnteredOnTheFirstJobOfASplitGroupAreFixedAndTheSecondJobTakesTheRestOfTheNeed(): void
    {
        $project = $this->projectWithAPlatingSplitInTwoJobs();
        [$firstJobId, $secondJobId] = $this->platingJobIdsOf($project);

        $this->patch($project, $firstJobId, ['runs' => 15]);

        self::assertSame(
            [[$firstJobId, 15, 15, true, 0, 20], [$secondJobId, 5, 5, false, 1, 20]],
            $this->platingJobsOf($this->reloaded($project)),
        );
    }

    public function testRunsEnteredAboveTheNeedOnAJobOfASplitGroupLeaveOneRunToTheOtherJob(): void
    {
        $project = $this->projectWithAPlatingSplitInTwoJobs();
        [$firstJobId, $secondJobId] = $this->platingJobIdsOf($project);

        $this->patch($project, $firstJobId, ['runs' => 25]);

        self::assertSame(
            [[$firstJobId, 19, 19, true, 0, 20], [$secondJobId, 1, 1, false, 1, 20]],
            $this->platingJobsOf($this->reloaded($project)),
        );
    }

    public function testOverproductionOnAJobOfASplitGroupFixesTheOtherJobAtItsRuns(): void
    {
        $project = $this->projectWithAPlatingSplitInTwoJobs();
        [$firstJobId, $secondJobId] = $this->platingJobIdsOf($project);

        $this->patch($project, $firstJobId, ['runs' => 25, 'overproduce' => true]);

        self::assertSame(
            [[$firstJobId, 25, 25, true, 0, 35], [$secondJobId, 10, 10, true, 1, 35]],
            $this->platingJobsOf($this->reloaded($project)),
        );
    }

    public function testAFixedJobKeepsItsRunsWhenTheNeedRisesThenFalls(): void
    {
        $project = $this->projectWithAPlatingSplitInTwoJobs();
        [$firstJobId, $secondJobId] = $this->platingJobIdsOf($project);
        $this->patch($project, $firstJobId, ['runs' => 15]);
        $project = $this->reloaded($project);
        $cruiserStepId = $this->idOf($this->stepOfProduct($project, self::CRUISER_TYPE_ID));

        $this->patch($project, $cruiserStepId, ['runs' => 8, 'meLevel' => 7]);
        $project = $this->reloaded($project);

        self::assertSame(
            [[$firstJobId, 15, 15, true, 0, 30], [$secondJobId, 15, 15, false, 1, 30]],
            $this->platingJobsOf($project),
        );

        $this->patch($project, $cruiserStepId, ['runs' => 3]);
        $platingJobs = $this->platingJobsOf($this->reloaded($project));

        self::assertSame(
            [[$firstJobId, 15, 15, true, 0, 16], [$secondJobId, 1, false, 1, 16]],
            [$platingJobs[0], [$platingJobs[1][0], $platingJobs[1][1], $platingJobs[1][3], $platingJobs[1][4], $platingJobs[1][5]]],
        );
    }

    public function testReleasingTheFixedRunsOfAJobSharesTheNeedEquallyBetweenTheJobsOfTheGroup(): void
    {
        $project = $this->projectWithAPlatingSplitInTwoJobs();
        [$firstJobId, $secondJobId] = $this->platingJobIdsOf($project);
        $this->patch($project, $firstJobId, ['runs' => 15]);
        $project = $this->reloaded($project);

        $this->patch($project, $secondJobId, ['runsFixed' => false]);

        self::assertSame(
            [[$firstJobId, 10, 10, false, 0, 20], [$secondJobId, 10, 10, false, 1, 20]],
            $this->platingJobsOf($this->reloaded($project)),
        );
    }

    public function testRegenerationKeepsTheSplitGroupOfAFixedJobWhenTheMaximumJobDurationIsRaised(): void
    {
        $project = $this->projectWithAPlatingSplitInTwoJobs();
        [$firstJobId, $secondJobId] = $this->platingJobIdsOf($project);
        $splitGroupId = $this->stepOfProduct($project, self::PLATING_TYPE_ID)->getSplitGroupId();
        $this->patch($project, $firstJobId, ['runs' => 15]);

        $project = $this->reloaded($project);
        $project->setMaxJobDurationDays(self::MAX_JOB_DURATION_DAYS_WITHOUT_SPLIT);
        $this->factory->regenerateSteps($project);
        $project = $this->reloaded($project);

        self::assertSame(
            [[$firstJobId, 15, 15, true, 0, 20], [$secondJobId, 5, 5, false, 1, 20]],
            $this->platingJobsOf($project),
        );
        self::assertSame([$splitGroupId, $splitGroupId], array_map(
            static fn (IndustryProjectStep $job): ?string => $job->getSplitGroupId(),
            $this->platingStepsInSplitIndexOrder($project),
        ));
    }

    public function testRunsEnteredOnAJobAreCappedByTheRunsOfTheOtherFixedJobsOfTheGroup(): void
    {
        $project = $this->projectWithAPlatingSplitInThreeJobs();
        [$firstJobId, $secondJobId, $thirdJobId] = $this->platingJobIdsOf($project);
        $this->patch($project, $secondJobId, ['runs' => 10]);
        $project = $this->reloaded($project);

        $this->patch($project, $firstJobId, ['runs' => 25]);

        self::assertSame(
            [[$firstJobId, 19, 19, true, 0, 30], [$secondJobId, 10, 10, true, 1, 30], [$thirdJobId, 1, 1, false, 2, 30]],
            $this->platingJobsOf($this->reloaded($project)),
        );
    }

    public function testRunsEnteredOnTheSecondJobOfAFuelBlockGroupFixItsQuantityAtFortyUnitsPerRunAndShareTheRestOfTheNeed(): void
    {
        $project = $this->projectWithAFuelBlockNeedOf864SplitInThreeJobs();
        [$firstJobId, $secondJobId, $thirdJobId] = $this->fuelBlockJobIdsOf($project);

        $this->patch($project, $secondJobId, ['runs' => 3]);

        self::assertSame(
            [[$firstJobId, 10, 400, false, 0, 22], [$secondJobId, 3, 120, true, 1, 22], [$thirdJobId, 9, 344, false, 2, 22]],
            $this->jobsOfProduct($this->reloaded($project), self::FUEL_BLOCK_TYPE_ID),
        );
    }

    public function testOverproductionOnAFuelBlockJobFixesEveryJobAtFortyUnitsPerRun(): void
    {
        $project = $this->projectWithAFuelBlockNeedOf840SplitInThreeJobs();
        [$firstJobId, $secondJobId, $thirdJobId] = $this->fuelBlockJobIdsOf($project);

        $this->patch($project, $thirdJobId, ['runs' => 12, 'overproduce' => true]);

        self::assertSame(
            [[$firstJobId, 7, 280, true, 0, 26], [$secondJobId, 7, 280, true, 1, 26], [$thirdJobId, 12, 480, true, 2, 26]],
            $this->jobsOfProduct($this->reloaded($project), self::FUEL_BLOCK_TYPE_ID),
        );
    }

    public function testRegenerationKeepsTheFixedFuelBlockJobAtFortyUnitsPerRunAndSharesTheRestOfTheNewNeed(): void
    {
        $project = $this->projectWithAFuelBlockNeedOf864SplitInThreeJobs();
        [$firstJobId, $secondJobId, $thirdJobId] = $this->fuelBlockJobIdsOf($project);
        $this->patch($project, $secondJobId, ['runs' => 3]);

        $project = $this->reloaded($project);
        $project->setRuns(10);
        $this->factory->regenerateSteps($project);

        self::assertSame(
            [[$firstJobId, 13, 520, false, 0, 27], [$secondJobId, 3, 120, true, 1, 27], [$thirdJobId, 11, 440, false, 2, 27]],
            $this->jobsOfProduct($this->reloaded($project), self::FUEL_BLOCK_TYPE_ID),
        );
    }

    public function testRunsEnteredOnAJobWhoseCapFallsBelowOneRunAreFixedAtOneRun(): void
    {
        $project = $this->factory->createProject($this->pilot, self::CARRIER_TYPE_ID, 8, 10, self::MAX_JOB_DURATION_DAYS_SPLITTING_THE_FUEL_BLOCK_IN_FOUR_JOBS, self::PROJECT_TE);
        $project = $this->reloaded($project);
        $this->patch($project, $this->idOf($this->stepOfProduct($project, self::CARRIER_TYPE_ID)), ['runs' => 1]);
        $project = $this->reloaded($project);
        [$firstJobId, $secondJobId, $thirdJobId, $fourthJobId] = $this->fuelBlockJobIdsOf($project);

        $this->patch($project, $firstJobId, ['runs' => 5]);

        self::assertSame(
            [[$firstJobId, 1, 40, true, 0, 4], [$secondJobId, 1, 40, false, 1, 4], [$thirdJobId, 1, 28, false, 2, 4], [$fourthJobId, 1, 0, false, 3, 4]],
            $this->jobsOfProduct($this->reloaded($project), self::FUEL_BLOCK_TYPE_ID),
        );
    }

    public function testReleasingTheFixedRunsOfAGroupSharesANeedOf21RunsEquallyBetweenItsThreeJobs(): void
    {
        $project = $this->projectWithAFuelBlockNeedOf840SplitInThreeJobs();
        [$firstJobId, $secondJobId, $thirdJobId] = $this->fuelBlockJobIdsOf($project);
        $this->patch($project, $firstJobId, ['runs' => 10]);
        $project = $this->reloaded($project);

        $this->patch($project, $thirdJobId, ['runsFixed' => false]);

        self::assertSame(
            [[$firstJobId, 7, 280, false, 0, 21], [$secondJobId, 7, 280, false, 1, 21], [$thirdJobId, 7, 280, false, 2, 21]],
            $this->jobsOfProduct($this->reloaded($project), self::FUEL_BLOCK_TYPE_ID),
        );
    }

    public function testReleasingTheFixedRunsOfAGroupGivesTheExtraRunOfANeedOf22RunsToTheFirstJob(): void
    {
        $project = $this->projectWithAFuelBlockNeedOf864SplitInThreeJobs();
        [$firstJobId, $secondJobId, $thirdJobId] = $this->fuelBlockJobIdsOf($project);
        $this->patch($project, $secondJobId, ['runs' => 3]);
        $project = $this->reloaded($project);

        $this->patch($project, $firstJobId, ['runsFixed' => false]);

        self::assertSame(
            [[$firstJobId, 8, 320, false, 0, 22], [$secondJobId, 7, 280, false, 1, 22], [$thirdJobId, 7, 264, false, 2, 22]],
            $this->jobsOfProduct($this->reloaded($project), self::FUEL_BLOCK_TYPE_ID),
        );
    }

    public function testReleasingTheFixedRunsOfAStepThatIsNotSplitGivesItBackTheRunsOfItsNeed(): void
    {
        $project = $this->projectWithAPlatingStepThatIsNotSplit();
        $platingStepId = $this->idOf($this->stepOfProduct($project, self::PLATING_TYPE_ID));
        $this->patch($project, $platingStepId, ['runs' => 15]);
        $project = $this->reloaded($project);

        $response = $this->patch($project, $platingStepId, ['runsFixed' => false]);

        self::assertSame([18, false], [$response->runs, $response->runsFixed]);
        self::assertSame([[$platingStepId, 18, 18, false, 0, null]], $this->platingJobsOf($this->reloaded($project)));
    }

    public function testRunsSentWithTheReleaseOfTheFixedRunsAreIgnoredForTheRunsOfTheNeed(): void
    {
        $project = $this->projectWithAPlatingStepThatIsNotSplit();
        $platingStepId = $this->idOf($this->stepOfProduct($project, self::PLATING_TYPE_ID));

        $this->patch($project, $platingStepId, ['runs' => 15, 'runsFixed' => false]);

        self::assertSame([[$platingStepId, 18, 18, false, 0, null]], $this->platingJobsOf($this->reloaded($project)));
    }

    public function testRegenerationDoesNotSplitAgainAGroupWithAFixedJobWhenTheMaximumJobDurationIsLowered(): void
    {
        $project = $this->projectWithAPlatingSplitInTwoJobs();
        [$firstJobId, $secondJobId] = $this->platingJobIdsOf($project);
        $splitGroupId = $this->stepOfProduct($project, self::PLATING_TYPE_ID)->getSplitGroupId();
        $this->patch($project, $firstJobId, ['runs' => 15]);

        $project = $this->reloaded($project);
        $project->setMaxJobDurationDays(self::MAX_JOB_DURATION_DAYS_SPLITTING_THE_PLATING_IN_FOUR_JOBS);
        $this->factory->regenerateSteps($project);
        $project = $this->reloaded($project);

        self::assertSame(
            [[$firstJobId, 15, 15, true, 0, 20], [$secondJobId, 5, 5, false, 1, 20]],
            $this->platingJobsOf($project),
        );
        self::assertSame([$splitGroupId, $splitGroupId], array_map(
            static fn (IndustryProjectStep $job): ?string => $job->getSplitGroupId(),
            $this->platingStepsInSplitIndexOrder($project),
        ));
    }

    public function testRegenerationKeepsTheTotalGroupRunsOfAnOverproducedGroupAboveTheNeed(): void
    {
        $project = $this->projectWithAPlatingSplitInTwoJobs();
        [$firstJobId, $secondJobId] = $this->platingJobIdsOf($project);
        $this->patch($project, $firstJobId, ['runs' => 25, 'overproduce' => true]);

        $project = $this->reloaded($project);
        $this->factory->regenerateSteps($project);

        self::assertSame(
            [[$firstJobId, 25, 25, true, 0, 35], [$secondJobId, 10, 10, true, 1, 35]],
            $this->platingJobsOf($this->reloaded($project)),
        );
    }

    public function testRegenerationDoesNotSplitAFixedStepWhenTheMaximumJobDurationIsLowered(): void
    {
        $project = $this->projectWithAPlatingStepThatIsNotSplit();
        $platingStepId = $this->idOf($this->stepOfProduct($project, self::PLATING_TYPE_ID));
        $this->patch($project, $platingStepId, ['runs' => 15]);

        $project = $this->reloaded($project);
        $project->setMaxJobDurationDays(self::MAX_JOB_DURATION_DAYS_SPLITTING_THE_PLATING_IN_FOUR_JOBS);
        $this->factory->regenerateSteps($project);
        $project = $this->reloaded($project);

        self::assertSame([[$platingStepId, 15, 15, true, 0, null]], $this->platingJobsOf($project));
        self::assertNull($this->stepOfProduct($project, self::PLATING_TYPE_ID)->getSplitGroupId());
    }

    public function testRunsEnteredOnTheRootStepAreAppliedWithoutBeingFixed(): void
    {
        $project = $this->projectWithAPlatingStepThatIsNotSplit();
        $cruiserStepId = $this->idOf($this->stepOfProduct($project, self::CRUISER_TYPE_ID));

        $response = $this->patch($project, $cruiserStepId, ['runs' => 7]);

        self::assertSame([7, false], [$response->runs, $response->runsFixed]);
        self::assertSame(
            [[$cruiserStepId, 7, 7, false, 0, null]],
            $this->jobsOfProduct($this->reloaded($project), self::CRUISER_TYPE_ID),
        );
    }

    #[Group('issue-252')]
    public function testResponseToAMeLevelEnteredOnTheRootStepReturnsEveryJobOfTheRecalculatedSplitGroup(): void
    {
        $project = $this->projectWithAPlatingSplitInThreeJobs();
        [$firstJobId, $secondJobId, $thirdJobId] = $this->platingJobIdsOf($project);
        $cruiserStepId = $this->idOf($this->stepOfProduct($project, self::CRUISER_TYPE_ID));

        $response = $this->patchResponse($project, $cruiserStepId, ['meLevel' => 10]);

        self::assertSame([$cruiserStepId, 4], [$response[0]->id, \count($response)]);
        self::assertSame(
            [[$firstJobId, 10, 10, 0, 29], [$secondJobId, 10, 10, 1, 29], [$thirdJobId, 9, 9, 2, 29]],
            $this->platingJobsOfTheResponse($response),
        );
        self::assertSame(
            [[$firstJobId, 10, 10, false, 0, 29], [$secondJobId, 10, 10, false, 1, 29], [$thirdJobId, 9, 9, false, 2, 29]],
            $this->platingJobsOf($this->reloaded($project)),
        );
    }

    private function projectWithAPlatingStepThatIsNotSplit(): IndustryProject
    {
        $project = $this->factory->createProject($this->pilot, self::CRUISER_TYPE_ID, self::CRUISER_RUNS, self::PROJECT_ME_GIVING_A_PLATING_NEED_OF_18, self::MAX_JOB_DURATION_DAYS_WITHOUT_SPLIT, self::PROJECT_TE);
        $project = $this->reloaded($project);
        self::assertSame([18], array_column($this->platingJobsOf($project), 1));

        return $project;
    }

    private function projectWithAPlatingSplitInThreeJobs(): IndustryProject
    {
        $project = $this->factory->createProject($this->pilot, self::CRUISER_TYPE_ID, self::CRUISER_RUNS_GIVING_A_PLATING_NEED_OF_30, self::PROJECT_ME_GIVING_A_PLATING_NEED_OF_30, self::MAX_JOB_DURATION_DAYS_SPLITTING_THE_PLATING_IN_TWO_JOBS, self::PROJECT_TE);
        $project = $this->reloaded($project);
        self::assertSame(
            [[10, 10, false, 0, 30], [10, 10, false, 1, 30], [10, 10, false, 2, 30]],
            array_map(static fn (array $job): array => array_slice($job, 1), $this->platingJobsOf($project)),
        );

        return $project;
    }

    private function projectWithAFuelBlockNeedOf840SplitInThreeJobs(): IndustryProject
    {
        $project = $this->factory->createProject($this->pilot, self::CARRIER_TYPE_ID, 7, 0, self::MAX_JOB_DURATION_DAYS_SPLITTING_THE_FUEL_BLOCK_IN_THREE_JOBS, self::PROJECT_TE);
        $project = $this->reloaded($project);
        self::assertSame(
            [[7, 280, false, 0, 21], [7, 280, false, 1, 21], [7, 280, false, 2, 21]],
            array_map(static fn (array $job): array => array_slice($job, 1), $this->jobsOfProduct($project, self::FUEL_BLOCK_TYPE_ID)),
        );

        return $project;
    }

    private function projectWithAFuelBlockNeedOf864SplitInThreeJobs(): IndustryProject
    {
        $project = $this->factory->createProject($this->pilot, self::CARRIER_TYPE_ID, 8, 10, self::MAX_JOB_DURATION_DAYS_SPLITTING_THE_FUEL_BLOCK_IN_THREE_JOBS, self::PROJECT_TE);
        $project = $this->reloaded($project);
        self::assertSame(
            [[8, 320, false, 0, 22], [7, 280, false, 1, 22], [7, 264, false, 2, 22]],
            array_map(static fn (array $job): array => array_slice($job, 1), $this->jobsOfProduct($project, self::FUEL_BLOCK_TYPE_ID)),
        );

        return $project;
    }

    /**
     * @return list<string>
     */
    private function fuelBlockJobIdsOf(IndustryProject $project): array
    {
        return array_column($this->jobsOfProduct($project, self::FUEL_BLOCK_TYPE_ID), 0);
    }

    private function projectWithAPlatingSplitInTwoJobs(): IndustryProject
    {
        $project = $this->factory->createProject($this->pilot, self::CRUISER_TYPE_ID, self::CRUISER_RUNS, self::PROJECT_ME_GIVING_A_PLATING_NEED_OF_20, self::MAX_JOB_DURATION_DAYS_SPLITTING_THE_PLATING_IN_TWO_JOBS, self::PROJECT_TE);
        $project = $this->reloaded($project);
        self::assertSame(
            [[10, 10, false, 0, 20], [10, 10, false, 1, 20]],
            array_map(static fn (array $job): array => array_slice($job, 1), $this->platingJobsOf($project)),
        );
        self::assertCount(1, array_filter(
            $project->getSteps()->toArray(),
            static fn (IndustryProjectStep $step): bool => $step->getProductTypeId() === self::CRUISER_TYPE_ID,
        ));

        return $project;
    }

    /**
     * @param array<string, int|bool> $fields
     */
    private function patch(IndustryProject $project, string $stepId, array $fields): ProjectStepResource
    {
        return $this->patchResponse($project, $stepId, $fields)[0];
    }

    /**
     * @param array<string, int|bool> $fields
     * @return list<ProjectStepResource>
     */
    private function patchResponse(IndustryProject $project, string $stepId, array $fields): array
    {
        $input = new UpdateStepInput();
        foreach ($fields as $field => $value) {
            $input->{$field} = $value;
        }

        $owner = $project->getUser();
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($owner, 'api', $owner->getRoles()));
        $projectId = $project->getId();
        self::assertNotNull($projectId);
        $response = $this->processor->process($input, new Patch(), ['id' => $projectId->toRfc4122(), 'stepId' => $stepId]);
        $updatedSteps = \is_array($response) ? $response : [$response];
        self::assertSame($stepId, $updatedSteps[0]->id);

        return $updatedSteps;
    }

    /**
     * @param list<ProjectStepResource> $updatedSteps
     * @return list<array{string, int, int, int|null, int|null}>
     */
    private function platingJobsOfTheResponse(array $updatedSteps): array
    {
        $platingJobs = array_values(array_filter(
            $updatedSteps,
            static fn (ProjectStepResource $step): bool => $step->productTypeId === self::PLATING_TYPE_ID,
        ));
        usort($platingJobs, static fn (ProjectStepResource $a, ProjectStepResource $b): int => $a->splitIndex <=> $b->splitIndex);

        return array_map(
            static fn (ProjectStepResource $job): array => [$job->id, $job->runs, $job->quantity, $job->splitIndex, $job->totalGroupRuns],
            $platingJobs,
        );
    }

    /**
     * @return list<array{string, int, int, bool, int, int|null}>
     */
    private function platingJobsOf(IndustryProject $project): array
    {
        return $this->jobsOfProduct($project, self::PLATING_TYPE_ID);
    }

    /**
     * @return list<array{string, int, int, bool, int, int|null}>
     */
    private function jobsOfProduct(IndustryProject $project, int $productTypeId): array
    {
        return array_map(
            fn (IndustryProjectStep $job): array => [$this->idOf($job), $job->getRuns(), $job->getQuantity(), $job->isRunsFixed(), $job->getSplitIndex(), $job->getTotalGroupRuns()],
            $this->stepsOfProductInSplitIndexOrder($project, $productTypeId),
        );
    }

    /**
     * @return list<string>
     */
    private function platingJobIdsOf(IndustryProject $project): array
    {
        return array_column($this->platingJobsOf($project), 0);
    }

    /**
     * @return list<IndustryProjectStep>
     */
    private function platingStepsInSplitIndexOrder(IndustryProject $project): array
    {
        return $this->stepsOfProductInSplitIndexOrder($project, self::PLATING_TYPE_ID);
    }

    /**
     * @return list<IndustryProjectStep>
     */
    private function stepsOfProductInSplitIndexOrder(IndustryProject $project, int $productTypeId): array
    {
        $jobs = array_values(array_filter(
            $project->getSteps()->toArray(),
            static fn (IndustryProjectStep $step): bool => $step->getProductTypeId() === $productTypeId,
        ));
        usort($jobs, static fn (IndustryProjectStep $a, IndustryProjectStep $b): int => $a->getSplitIndex() <=> $b->getSplitIndex());

        return $jobs;
    }

    private function stepOfProduct(IndustryProject $project, int $productTypeId): IndustryProjectStep
    {
        foreach ($project->getSteps() as $step) {
            if ($step->getProductTypeId() === $productTypeId) {
                return $step;
            }
        }

        self::fail("No step for product {$productTypeId}");
    }

    private function reloaded(IndustryProject $project): IndustryProject
    {
        $projectId = $project->getId();
        $this->flushAndClear();
        $reloaded = $this->em->find(IndustryProject::class, $projectId);
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    private function idOf(IndustryProjectStep $step): string
    {
        $id = $step->getId();
        self::assertNotNull($id);

        return $id->toRfc4122();
    }

    private function seedCruiserRecipe(): void
    {
        $category = (new InvCategory())->setCategoryId(self::SHIP_CATEGORY_ID)->setCategoryName('Test Ship');
        $this->em->persist($category);
        $this->type(self::CRUISER_TYPE_ID, 'Test Cruiser', $this->group(self::CRUISER_GROUP_ID, 'Test Cruiser', $category));
        $this->type(self::PLATING_TYPE_ID, 'Test Plating', $this->group(self::PLATING_GROUP_ID, 'Test Plating', $category));
        $this->type(self::ALLOY_TYPE_ID, 'Test Alloy', $this->group(self::ALLOY_GROUP_ID, 'Test Alloy', $category));

        $this->manufacturingRecipe(self::CRUISER_BLUEPRINT_TYPE_ID, self::CRUISER_TYPE_ID, self::PLATING_TYPE_ID, self::PLATINGS_PER_CRUISER_RUN, self::CRUISER_BASE_TIME_PER_RUN_SECONDS);
        $this->manufacturingRecipe(self::PLATING_BLUEPRINT_TYPE_ID, self::PLATING_TYPE_ID, self::ALLOY_TYPE_ID, self::ALLOYS_PER_PLATING_RUN, self::PLATING_BASE_TIME_PER_RUN_SECONDS);
    }

    private function seedCarrierRecipe(): void
    {
        $category = $this->em->find(InvCategory::class, self::SHIP_CATEGORY_ID);
        self::assertNotNull($category);
        $this->type(self::CARRIER_TYPE_ID, 'Test Carrier', $this->group(self::CARRIER_GROUP_ID, 'Test Carrier', $category));
        $this->type(self::FUEL_BLOCK_TYPE_ID, 'Test Fuel Block', $this->group(self::FUEL_BLOCK_GROUP_ID, 'Test Fuel Block', $category));
        $this->type(self::ISOTOPE_TYPE_ID, 'Test Isotope', $this->group(self::ISOTOPE_GROUP_ID, 'Test Isotope', $category));

        $this->manufacturingRecipe(self::CARRIER_BLUEPRINT_TYPE_ID, self::CARRIER_TYPE_ID, self::FUEL_BLOCK_TYPE_ID, self::FUEL_BLOCKS_PER_CARRIER_RUN, self::CARRIER_BASE_TIME_PER_RUN_SECONDS);
        $this->manufacturingRecipe(self::FUEL_BLOCK_BLUEPRINT_TYPE_ID, self::FUEL_BLOCK_TYPE_ID, self::ISOTOPE_TYPE_ID, self::ISOTOPES_PER_FUEL_BLOCK_RUN, self::FUEL_BLOCK_BASE_TIME_PER_RUN_SECONDS, self::FUEL_BLOCKS_PER_FUEL_BLOCK_RUN);
    }

    private function manufacturingRecipe(int $blueprintTypeId, int $productTypeId, int $materialTypeId, int $baseQuantityPerRun, int $baseTimePerRunSeconds, int $outputPerRun = 1): void
    {
        $manufacturing = IndustryActivityType::Manufacturing->value;
        $this->em->persist((new IndustryActivityProduct())->setTypeId($blueprintTypeId)->setActivityId($manufacturing)->setProductTypeId($productTypeId)->setQuantity($outputPerRun));
        $this->em->persist((new IndustryActivityMaterial())->setTypeId($blueprintTypeId)->setActivityId($manufacturing)->setMaterialTypeId($materialTypeId)->setQuantity($baseQuantityPerRun));
        $this->em->persist((new IndustryActivity())->setTypeId($blueprintTypeId)->setActivityId($manufacturing)->setTime($baseTimePerRunSeconds));
    }

    private function group(int $groupId, string $name, InvCategory $category): InvGroup
    {
        $group = (new InvGroup())->setGroupId($groupId)->setGroupName($name)->setCategory($category);
        $this->em->persist($group);

        return $group;
    }

    private function type(int $typeId, string $name, InvGroup $group): void
    {
        $this->em->persist((new InvType())->setTypeId($typeId)->setTypeName($name)->setGroup($group));
    }
}
