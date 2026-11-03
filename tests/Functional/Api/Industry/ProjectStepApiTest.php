<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry;

use App\Entity\CachedIndustryJob;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepJobMatch;
use App\Entity\IndustryStepPurchase;
use App\Entity\IndustryStructureConfig;
use App\Enum\IndustryActivityType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-206')]
final class ProjectStepApiTest extends WebTestCase
{
    use BuildsCarrierProjectWithFuelBlockSteps;

    private const string FUEL_BLOCK_SPLIT_GROUP_ID = '0b8f2a5e-6d1c-4e7b-9a20-206000000001';
    private const int FIRST_FUEL_BLOCK_JOB_RUNS = 4;
    private const int SECOND_FUEL_BLOCK_JOB_RUNS = 2;
    private const int ESI_JOB_ID = 7_206_001;
    private const string NOT_A_UUID = 'abc';

    protected function setUp(): void
    {
        $this->setUpCarrierProjectWithFuelBlockStep();
    }

    public function testAddingAStepFromTheFuelBlockTypeAnswersAListOfTheNewStepAtFortyUnitsPerRun(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('POST', $this->projectUri().'/steps', ['typeId' => self::FUEL_BLOCK_TYPE_ID, 'runs' => 3, 'meLevel' => 4, 'teLevel' => 6]);

        self::assertResponseStatusCodeSame(201);
        $newStep = $this->onlyStepOf($this->responseJson());
        self::assertSame(
            [self::FUEL_BLOCK_BLUEPRINT_TYPE_ID, self::FUEL_BLOCK_TYPE_ID, 'Test Fuel Block', 3, 120, 0, 'manufacturing', 2, 4, 6],
            $this->stepFieldsOf($newStep, ['blueprintTypeId', 'productTypeId', 'productTypeName', 'runs', 'quantity', 'depth', 'activityType', 'sortOrder', 'meLevel', 'teLevel']),
        );
        self::assertSame(['splitGroupId', 'totalGroupRuns'], $this->absentFieldsOf($newStep, ['splitGroupId', 'totalGroupRuns']));
        self::assertSame([[6, 240, 0, null, false], [3, 120, 0, null, false]], $this->storedJobsOfProduct(self::FUEL_BLOCK_TYPE_ID));
    }

    public function testAddingAStepFromThePolymerTypeAnswersAListOfAReactionAtMeZeroAndTwoHundredUnitsPerRun(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('POST', $this->projectUri().'/steps', ['typeId' => self::POLYMER_TYPE_ID, 'runs' => 3, 'meLevel' => 4]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(
            [self::POLYMER_FORMULA_TYPE_ID, self::POLYMER_TYPE_ID, 3, 600, 'reaction', 0, 0],
            $this->stepFieldsOf($this->onlyStepOf($this->responseJson()), ['blueprintTypeId', 'productTypeId', 'runs', 'quantity', 'activityType', 'meLevel', 'teLevel']),
        );
        self::assertSame([[3, 600, 0, null, false]], $this->storedJobsOfProduct(self::POLYMER_TYPE_ID));
    }

    public function testAddingAJobToTheFuelBlockStepAnswersAListOfTheNewJobThenTheUpdatedStep(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('POST', $this->projectUri().'/steps', ['stepId' => $this->idOf($this->fuelBlockStep), 'runs' => 2]);

        self::assertResponseStatusCodeSame(201);
        $response = $this->responseJson();
        self::assertSame([0, 1], array_keys($response));
        [$newJob, $updatedStep] = $response;
        self::assertSame([2, 80, 1, 8, true], $this->stepFieldsOf($newJob, ['runs', 'quantity', 'splitIndex', 'totalGroupRuns', 'runsFixed']));
        self::assertSame(
            [$this->idOf($this->fuelBlockStep), 6, 240, 0, 8, true],
            $this->stepFieldsOf($updatedStep, ['id', 'runs', 'quantity', 'splitIndex', 'totalGroupRuns', 'runsFixed']),
        );
        self::assertSame($newJob['splitGroupId'], $updatedStep['splitGroupId']);
        self::assertSame([[6, 240, 0, 8, true], [2, 80, 1, 8, true]], $this->storedJobsOfProduct(self::FUEL_BLOCK_TYPE_ID));
    }

    public function testAddingAJobToTheFuelBlockSplitGroupAnswersAListOfTheNewJobAlone(): void
    {
        $this->splitTheFuelBlockStepInTwoJobs();

        $this->client->loginUser($this->owner, 'api');
        $this->request('POST', $this->projectUri().'/steps', ['splitGroupId' => self::FUEL_BLOCK_SPLIT_GROUP_ID, 'runs' => 1]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(
            [self::FUEL_BLOCK_SPLIT_GROUP_ID, 1, 40, 2, 7, true],
            $this->stepFieldsOf($this->onlyStepOf($this->responseJson()), ['splitGroupId', 'runs', 'quantity', 'splitIndex', 'totalGroupRuns', 'runsFixed']),
        );
        self::assertSame([[4, 160, 0, 7, true], [2, 80, 1, 7, true], [1, 40, 2, 7, true]], $this->storedJobsOfProduct(self::FUEL_BLOCK_TYPE_ID));
    }

    public function testRunsEnteredOnTheFuelBlockStepAreStoredAsRunsAndAnsweredInAList(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('PATCH', $this->stepUri($this->fuelBlockStep), ['runs' => 4]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [[$this->idOf($this->fuelBlockStep), 4, 160, true]],
            array_map(fn (array $step): array => $this->stepFieldsOf($step, ['id', 'runs', 'quantity', 'runsFixed']), $this->responseJson()),
        );
        self::assertSame([[4, 160, 0, null, true]], $this->storedJobsOfProduct(self::FUEL_BLOCK_TYPE_ID));
    }

    public function testTimeEfficiencyEnteredOnTheFuelBlockStepIsAnsweredInAListOfThatStepAlone(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('PATCH', $this->stepUri($this->fuelBlockStep), ['teLevel' => 10]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [[$this->idOf($this->fuelBlockStep), 10, 6, 240]],
            array_map(fn (array $step): array => $this->stepFieldsOf($step, ['id', 'teLevel', 'runs', 'quantity']), $this->responseJson()),
        );
    }

    public function testDeletingTheSecondJobOfTheFuelBlockSplitGroupDissolvesTheGroupIntoTheFirstJob(): void
    {
        [, $secondJob] = $this->splitTheFuelBlockStepInTwoJobs();

        $this->client->loginUser($this->owner, 'api');
        $this->request('DELETE', $this->stepUri($secondJob));

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', (string) $this->client->getResponse()->getContent());
        self::assertSame([[6, 240, 0, null, false]], $this->storedJobsOfProduct(self::FUEL_BLOCK_TYPE_ID));
    }

    public function testSplittingTheFuelBlockStepInTwoJobsSharesItsRunsAtFortyUnitsPerRun(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('POST', $this->stepUri($this->fuelBlockStep).'/split', ['numberOfJobs' => 2]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(
            [[$this->idOf($this->fuelBlockStep), 3, 120, 0, 6], [3, 120, 1, 6]],
            [
                $this->stepFieldsOf($this->responseJson()[0], ['id', 'runs', 'quantity', 'splitIndex', 'totalGroupRuns']),
                $this->stepFieldsOf($this->responseJson()[1], ['runs', 'quantity', 'splitIndex', 'totalGroupRuns']),
            ],
        );
        self::assertCount(2, $this->responseJson());
        self::assertSame([[3, 120, 0, 6, false], [3, 120, 1, 6, false]], $this->storedJobsOfProduct(self::FUEL_BLOCK_TYPE_ID));
    }

    public function testSplittingTheFuelBlockStepCopiesItsEfficienciesStructureAndJobMatchModeToEveryJob(): void
    {
        $raitaru = (new IndustryStructureConfig())->setUser($this->owner)->setName('Test Raitaru 206')->setStructureType('raitaru')->setSecurityType('highsec');
        $this->em->persist($raitaru);
        $this->fuelBlockStep->setMeLevel(8)->setTeLevel(12)->setStructureConfig($raitaru)->setJobMatchMode('manual');
        $this->em->flush();

        $this->client->loginUser($this->owner, 'api');
        $this->request('POST', $this->stepUri($this->fuelBlockStep).'/split', ['numberOfJobs' => 2]);

        self::assertResponseStatusCodeSame(201);
        $raitaruId = (string) $raitaru->getId();
        self::assertSame(
            [[8, 12, $raitaruId, 'manual'], [8, 12, $raitaruId, 'manual']],
            array_map(fn (array $job): array => $this->stepFieldsOf($job, ['meLevel', 'teLevel', 'structureConfigId', 'jobMatchMode']), $this->responseJson()),
        );
    }

    public function testSplittingSevenFuelBlockRunsInTwoJobsGivesFourRunsToTheFirstJobAndThreeToTheSecond(): void
    {
        $this->fuelBlockStep->setRuns(7)->setQuantity(280);
        $this->em->flush();

        $this->client->loginUser($this->owner, 'api');
        $this->request('POST', $this->stepUri($this->fuelBlockStep).'/split', ['numberOfJobs' => 2]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(
            [[4, 160, 0, 7], [3, 120, 1, 7]],
            array_map(fn (array $job): array => $this->stepFieldsOf($job, ['runs', 'quantity', 'splitIndex', 'totalGroupRuns']), $this->responseJson()),
        );
        self::assertSame([[4, 160, 0, 7, false], [3, 120, 1, 7, false]], $this->storedJobsOfProduct(self::FUEL_BLOCK_TYPE_ID));
    }

    public function testSplittingSixFuelBlockRunsInSevenJobsIsABadRequestAndKeepsTheStep(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('POST', $this->stepUri($this->fuelBlockStep).'/split', ['numberOfJobs' => 7]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([400, 'Cannot split 6 runs into 7 jobs'], $this->errorDetail());
        self::assertSame([[6, 240, 0, null, false]], $this->storedJobsOfProduct(self::FUEL_BLOCK_TYPE_ID));
    }

    public function testSplittingAJobOfTheFuelBlockSplitGroupAgainIsABadRequestAndKeepsTheGroup(): void
    {
        [$firstJob] = $this->splitTheFuelBlockStepInTwoJobs();

        $this->client->loginUser($this->owner, 'api');
        $this->request('POST', $this->stepUri($firstJob).'/split', ['numberOfJobs' => 2]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([400, 'Step is already split. Merge first before re-splitting.'], $this->errorDetail());
        self::assertSame([[4, 160, 0, 6, false], [2, 80, 1, 6, false]], $this->storedJobsOfProduct(self::FUEL_BLOCK_TYPE_ID));
    }

    public function testMergingTheFuelBlockSplitGroupMovesTheJobMatchOfTheSecondJobToTheFirstJob(): void
    {
        [$firstJob, $secondJob] = $this->splitTheFuelBlockStepInTwoJobs();
        $secondJob->addJobMatch((new IndustryStepJobMatch())->setEsiJobId(self::ESI_JOB_ID)->setRuns(self::SECOND_FUEL_BLOCK_JOB_RUNS)->setCharacterName('Aria Solberg'));
        $this->em->flush();

        $this->client->loginUser($this->owner, 'api');
        $this->request('POST', $this->stepUri($firstJob).'/merge', []);

        self::assertResponseStatusCodeSame(201);
        $response = $this->responseJson();
        self::assertSame([$this->idOf($firstJob), 6, 240], $this->stepFieldsOf($response, ['id', 'runs', 'quantity']));
        self::assertSame(
            [[self::ESI_JOB_ID, 2, 'Aria Solberg']],
            array_map(static fn (array $match): array => [$match['esiJobId'], $match['runs'], $match['characterName']], $response['jobMatches']),
        );
        self::assertSame(1, $this->storedJobMatchCount());
    }

    public function testMergingTheFuelBlockSplitGroupAnswersTheFirstJobWithTheRunsQuantityAndPurchasesOfTheGroup(): void
    {
        [$firstJob, $secondJob] = $this->splitTheFuelBlockStepInTwoJobs();
        $secondJob->addPurchase((new IndustryStepPurchase())->setTypeId(self::FUEL_BLOCK_TYPE_ID)->setQuantity(80)->setUnitPrice(15_250.5)->setTotalPrice(1_220_040.0)->setSource('manual'));
        $this->em->flush();

        $this->client->loginUser($this->owner, 'api');
        $this->request('POST', $this->stepUri($secondJob).'/merge', []);

        self::assertResponseStatusCodeSame(201);
        $response = $this->responseJson();
        self::assertSame(
            [$this->idOf($firstJob), 6, 240, 0, 80],
            $this->stepFieldsOf($response, ['id', 'runs', 'quantity', 'splitIndex', 'purchasedQuantity']),
        );
        self::assertSame(['splitGroupId', 'totalGroupRuns'], $this->absentFieldsOf($response, ['splitGroupId', 'totalGroupRuns']));
        self::assertSame([[self::FUEL_BLOCK_TYPE_ID, 80, 1_220_040.0]], array_map(static fn (array $purchase): array => [$purchase['typeId'], $purchase['quantity'], $purchase['totalPrice']], $response['purchases']));
        self::assertSame([[6, 240, 0, null, false]], $this->storedJobsOfProduct(self::FUEL_BLOCK_TYPE_ID));
    }

    public function testLinkingAnEsiJobOfTheOwnerToTheFuelBlockStepAnswersTheStepInManualMode(): void
    {
        $this->persistEsiJobOfTheOwner();

        $this->client->loginUser($this->owner, 'api');
        $this->request('POST', $this->stepUri($this->fuelBlockStep).'/link-job', ['esiJobId' => self::ESI_JOB_ID]);

        self::assertResponseStatusCodeSame(201);
        $response = $this->responseJson();
        self::assertSame([$this->idOf($this->fuelBlockStep), 'manual'], $this->stepFieldsOf($response, ['id', 'jobMatchMode']));
        self::assertSame(
            [[self::ESI_JOB_ID, 6, 'active', 'Aria Solberg']],
            array_map(static fn (array $match): array => [$match['esiJobId'], $match['runs'], $match['status'], $match['characterName']], $response['jobMatches']),
        );
        self::assertSame(1, $this->storedJobMatchCount());
    }

    public function testUnlinkingTheJobMatchOfTheFuelBlockStepRemovesIt(): void
    {
        $match = $this->persistJobMatchOnTheFuelBlockStep();

        $this->client->loginUser($this->owner, 'api');
        $this->request('DELETE', '/api/industry/step-job-matches/'.$match->getId()?->toRfc4122());

        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, $this->storedJobMatchCount());
    }

    public function testUnlinkingTheJobMatchOfAnotherUserIsNotFoundAndKeepsIt(): void
    {
        $match = $this->persistJobMatchOnTheFuelBlockStep();

        $this->client->loginUser($this->intruder, 'api');
        $this->request('DELETE', '/api/industry/step-job-matches/'.$match->getId()?->toRfc4122());

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Job match not found'], $this->errorDetail());
        self::assertSame(1, $this->storedJobMatchCount());
    }

    public function testUnlinkingAJobMatchWhoseIdentifierIsNotAUuidIsNotFound(): void
    {
        $this->persistJobMatchOnTheFuelBlockStep();

        $this->client->loginUser($this->owner, 'api');
        $this->request('DELETE', '/api/industry/step-job-matches/'.self::NOT_A_UUID);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Job match not found'], $this->errorDetail());
        self::assertSame(1, $this->storedJobMatchCount());
    }

    #[DataProvider('stepOperations')]
    public function testStepOperationOnAProjectOfAnotherUserIsNotFoundAndChangesNoStep(string $method, string $uriAfterTheProject, ?array $body): void
    {
        $this->persistEsiJobOfTheOwner();

        $this->client->loginUser($this->intruder, 'api');
        $this->requestStepOperation($method, $uriAfterTheProject, $body, $this->idOf($this->fuelBlockStep));

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Project not found'], $this->errorDetail());
        self::assertSame([[6, 240, 0, null, false]], $this->storedJobsOfProduct(self::FUEL_BLOCK_TYPE_ID));
        self::assertSame(0, $this->storedJobMatchCount());
    }

    #[DataProvider('stepOperations')]
    public function testStepOperationOnAProjectIdentifierThatIsNotAUuidIsNotFound(string $method, string $uriAfterTheProject, ?array $body): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->requestStepOperation($method, $uriAfterTheProject, $body, $this->idOf($this->fuelBlockStep), self::NOT_A_UUID);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Project not found'], $this->errorDetail());
        self::assertSame([[6, 240, 0, null, false]], $this->storedJobsOfProduct(self::FUEL_BLOCK_TYPE_ID));
    }

    #[DataProvider('stepOperations')]
    public function testStepOperationOnAStepIdentifierThatIsNotAUuidIsNotFound(string $method, string $uriAfterTheProject, ?array $body): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->requestStepOperation($method, $uriAfterTheProject, $body, self::NOT_A_UUID);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Step not found'], $this->errorDetail());
        self::assertSame([[6, 240, 0, null, false]], $this->storedJobsOfProduct(self::FUEL_BLOCK_TYPE_ID));
    }

    public static function stepOperations(): iterable
    {
        yield 'add a job to a step' => ['POST', '/steps', ['stepId' => '{stepId}', 'runs' => 2]];
        yield 'update a step' => ['PATCH', '/steps/{stepId}', ['runs' => 4]];
        yield 'delete a step' => ['DELETE', '/steps/{stepId}', null];
        yield 'split a step' => ['POST', '/steps/{stepId}/split', ['numberOfJobs' => 2]];
        yield 'merge a split group' => ['POST', '/steps/{stepId}/merge', []];
        yield 'link an ESI job' => ['POST', '/steps/{stepId}/link-job', ['esiJobId' => self::ESI_JOB_ID]];
    }

    private function requestStepOperation(string $method, string $uriAfterTheProject, ?array $body, string $stepId, ?string $projectId = null): void
    {
        $projectUri = $projectId === null ? $this->projectUri() : '/api/industry/projects/'.$projectId;
        $this->request(
            $method,
            $projectUri.str_replace('{stepId}', $stepId, $uriAfterTheProject),
            $body === null ? null : array_map(static fn (mixed $value): mixed => $value === '{stepId}' ? $stepId : $value, $body),
        );
    }

    private function stepFieldsOf(array $step, array $fields): array
    {
        return array_map(static fn (string $field): mixed => $step[$field], $fields);
    }

    private function onlyStepOf(array $response): array
    {
        self::assertIsList($response);
        self::assertCount(1, $response);

        return $response[0];
    }

    private function absentFieldsOf(array $step, array $fields): array
    {
        return array_values(array_filter($fields, static fn (string $field): bool => !\array_key_exists($field, $step)));
    }

    private function splitTheFuelBlockStepInTwoJobs(): array
    {
        $this->fuelBlockStep
            ->setRuns(self::FIRST_FUEL_BLOCK_JOB_RUNS)
            ->setQuantity(self::FIRST_FUEL_BLOCK_JOB_RUNS * self::FUEL_BLOCKS_PER_FUEL_BLOCK_RUN)
            ->setSplitGroupId(self::FUEL_BLOCK_SPLIT_GROUP_ID)
            ->setSplitIndex(0)
            ->setTotalGroupRuns(self::FUEL_BLOCK_NEED_RUNS);
        $secondJob = $this->step(self::FUEL_BLOCK_BLUEPRINT_TYPE_ID, self::FUEL_BLOCK_TYPE_ID, self::SECOND_FUEL_BLOCK_JOB_RUNS, self::SECOND_FUEL_BLOCK_JOB_RUNS * self::FUEL_BLOCKS_PER_FUEL_BLOCK_RUN, 1)
            ->setSplitGroupId(self::FUEL_BLOCK_SPLIT_GROUP_ID)
            ->setSplitIndex(1)
            ->setTotalGroupRuns(self::FUEL_BLOCK_NEED_RUNS);
        $this->em->flush();

        return [$this->fuelBlockStep, $secondJob];
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
            ->setRuns(self::FUEL_BLOCK_NEED_RUNS)
            ->setStatus('active')
            ->setStartDate(new \DateTimeImmutable('2026-11-02T08:00:00+00:00'))
            ->setEndDate(new \DateTimeImmutable('2026-11-04T08:00:00+00:00'))
            ->setCachedAt(new \DateTimeImmutable('2026-11-03T08:00:00+00:00')));
        $this->em->flush();
    }

    private function persistJobMatchOnTheFuelBlockStep(): IndustryStepJobMatch
    {
        $match = (new IndustryStepJobMatch())->setEsiJobId(self::ESI_JOB_ID)->setRuns(self::FUEL_BLOCK_NEED_RUNS)->setCharacterName('Aria Solberg');
        $this->fuelBlockStep->addJobMatch($match);
        $this->em->flush();

        return $match;
    }

    private function storedJobMatchCount(): int
    {
        $this->em->clear();

        return $this->em->getRepository(IndustryStepJobMatch::class)->count(['step' => $this->fuelBlockStep->getId()]);
    }
}
