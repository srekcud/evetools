<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\CachedIndustryJob;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Repository\IndustryProjectBomLineRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-1140')]
final class IndustryProjectBomLineRepositoryJobLinesTest extends IntegrationTestCase
{
    private const int CADMIUM_REACTION = 16_647;
    private const int SABRE = 22_456;
    private const int TRITANIUM = 34;
    private const int SABRE_BLUEPRINT = 22_457;
    private const int OTHER_PROJECT_REACTION = 16_649;

    private IndustryProjectBomLineRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(IndustryProjectBomLineRepository::class);
    }

    public function testJobLinesOfComponentAndFinalGroupsAreReturnedWithTheirMatchesAlreadyLoaded(): void
    {
        $project = $this->createProject('Sabre run');
        $this->addJobLine($project, self::CADMIUM_REACTION, 'component', 'reaction', 33, [[1_140_301, CachedIndustryJob::STATUS_ACTIVE, 20], [1_140_302, CachedIndustryJob::STATUS_READY, 13]]);
        $this->addJobLine($project, self::SABRE, 'final', 'manufacturing', 5, [[1_140_303, CachedIndustryJob::STATUS_DELIVERED, 5]]);
        $this->addJobLine($project, self::SABRE_BLUEPRINT, 'blueprint', 'copy', 1, [[1_140_304, CachedIndustryJob::STATUS_ACTIVE, 1]]);
        $this->addJobLine($project, self::SABRE_BLUEPRINT, 'blueprint', 'invention', 1, [[1_140_305, CachedIndustryJob::STATUS_ACTIVE, 1]]);
        $project->addBomItem((new IndustryProjectBomLine())->setTypeId(self::TRITANIUM)->setTypeName('Tritanium')->setRequiredQuantity(500)->setIsJob(false));
        $otherProject = $this->createProject('Other run');
        $this->addJobLine($otherProject, self::OTHER_PROJECT_REACTION, 'component', 'reaction', 4, [[1_140_306, CachedIndustryJob::STATUS_ACTIVE, 4]]);
        $this->flushAndClear();

        $lines = $this->repository->findJobLinesWithMatchesByProject($this->em->find(IndustryProject::class, $project->getId()));

        $linesByTypeId = [];
        foreach ($lines as $line) {
            $linesByTypeId[$line->getTypeId()] = $line;
        }
        ksort($linesByTypeId);
        self::assertSame([self::CADMIUM_REACTION, self::SABRE], array_keys($linesByTypeId));
        self::assertTrue($linesByTypeId[self::CADMIUM_REACTION]->getJobMatches()->isInitialized());
        self::assertTrue($linesByTypeId[self::SABRE]->getJobMatches()->isInitialized());
        $cadmiumMatches = $linesByTypeId[self::CADMIUM_REACTION]->getJobMatches()->toArray();
        usort($cadmiumMatches, static fn (IndustryProjectJobMatch $left, IndustryProjectJobMatch $right): int => $left->getEsiJobId() <=> $right->getEsiJobId());
        self::assertSame(
            [[1_140_301, 'active', 20], [1_140_302, 'ready', 13]],
            array_map(static fn (IndustryProjectJobMatch $match): array => [$match->getEsiJobId(), $match->getStatus(), $match->getRuns()], $cadmiumMatches),
        );
        self::assertSame([1_140_303], array_map(static fn (IndustryProjectJobMatch $match): int => $match->getEsiJobId(), $linesByTypeId[self::SABRE]->getJobMatches()->toArray()));
    }

    private function createProject(string $name): IndustryProject
    {
        $project = (new IndustryProject())->setOwner($this->createUser())->setName($name);
        $this->em->persist($project);

        return $project;
    }

    private function addJobLine(IndustryProject $project, int $typeId, string $jobGroup, string $activityType, int $runs, array $matches): void
    {
        $line = (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName('Type '.$typeId)
            ->setRequiredQuantity($runs)
            ->setIsJob(true)
            ->setJobGroup($jobGroup)
            ->setActivityType($activityType)
            ->setRuns($runs);
        $project->addBomItem($line);
        foreach ($matches as [$esiJobId, $status, $matchRuns]) {
            $line->addJobMatch((new IndustryProjectJobMatch())->setEsiJobId($esiJobId)->setStatus($status)->setRuns($matchRuns));
        }
    }
}
