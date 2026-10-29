<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\IndustryProject;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

final class IndustryProjectRepositoryTest extends IntegrationTestCase
{
    private const string EARLIER_CREATED_AT = '2026-10-10T09:00:00+00:00';
    private const string SHARED_CREATED_AT = '2026-10-15T09:00:00+00:00';

    private IndustryProjectRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(IndustryProjectRepository::class);
    }

    #[Group('issue-278')]
    #[Group('issue-284')]
    public function testIdsInCreationOrderAreSortedByCreatedAtThenById(): void
    {
        $owner = $this->createUser();
        $ferniteCarbideProject = $this->createProject($owner, 16673, 8, self::SHARED_CREATED_AT);
        $ceramicPowderProject = $this->createProject($owner, 16660, 12, self::SHARED_CREATED_AT);
        $nitrogenFuelBlockProject = $this->createProject($owner, 4051, 25, self::EARLIER_CREATED_AT);
        $this->flushAndClear();
        $idsCreatedAtTheSameTime = [(string) $ferniteCarbideProject->getId(), (string) $ceramicPowderProject->getId()];
        sort($idsCreatedAtTheSameTime, \SORT_STRING);
        $idsOfTheTest = [(string) $nitrogenFuelBlockProject->getId(), ...$idsCreatedAtTheSameTime];

        $ids = $this->repository->findIdsInCreationOrder();

        self::assertContainsOnlyInstancesOf(Uuid::class, $ids);
        self::assertSame(
            $idsOfTheTest,
            array_values(array_filter(
                array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids),
                static fn (string $id): bool => \in_array($id, $idsOfTheTest, true),
            )),
        );
    }

    private function createProject(User $owner, int $productTypeId, int $runs, string $createdAt): IndustryProject
    {
        $project = (new IndustryProject())
            ->setUser($owner)
            ->setProductTypeId($productTypeId)
            ->setRuns($runs);
        (new \ReflectionProperty(IndustryProject::class, 'createdAt'))->setValue($project, new \DateTimeImmutable($createdAt));
        $this->em->persist($project);

        return $project;
    }
}
