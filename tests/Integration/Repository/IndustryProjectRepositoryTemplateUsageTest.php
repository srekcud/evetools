<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectTemplate;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectRepository;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\RecordsSqlQueries;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-355')]
final class IndustryProjectRepositoryTemplateUsageTest extends IntegrationTestCase
{
    use RecordsSqlQueries;

    private IndustryProjectRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(IndustryProjectRepository::class);
    }

    public function testUsageCountsOfAllTheTemplatesComeFromOneQueryAndIgnoreTheDeletedProjects(): void
    {
        $alice = $this->createUser();
        $bob = $this->createUser();
        $hammerheadLine = $this->createTemplate($alice, 'Hammerhead line');
        $warriorLine = $this->createTemplate($alice, 'Warrior line');
        $sabreLine = $this->createTemplate($bob, 'Sabre line');
        $this->createProjectFrom($alice, $hammerheadLine, ProjectStatus::Draft);
        $this->createProjectFrom($bob, $hammerheadLine, ProjectStatus::InProgress);
        $deletedLater = $this->createProjectFrom($bob, $hammerheadLine, ProjectStatus::Completed);
        $this->createProjectFrom($alice, $sabreLine, ProjectStatus::Draft);
        $this->flushAndClear();
        $templates = $this->templatesOf([$hammerheadLine, $warriorLine, $sabreLine]);

        $countsBefore = $this->repository->countByTemplate($templates);
        $this->em->remove($this->em->getReference(IndustryProject::class, $deletedLater->getId()));
        $this->flushAndClear();
        $countsAfter = [];
        $queries = $this->sqlExecutedDuring(function () use (&$countsAfter, $hammerheadLine, $warriorLine, $sabreLine): void {
            $countsAfter = $this->repository->countByTemplate($this->templatesOf([$hammerheadLine, $warriorLine, $sabreLine]));
        });

        $hammerheadId = (string) $hammerheadLine->getId();
        $warriorId = (string) $warriorLine->getId();
        $sabreId = (string) $sabreLine->getId();
        self::assertSame([$hammerheadId => 3, $warriorId => 0, $sabreId => 1], $countsBefore);
        self::assertSame([$hammerheadId => 2, $warriorId => 0, $sabreId => 1], $countsAfter);
        self::assertCount(1, $queries);
    }

    private function createTemplate(User $owner, string $name): IndustryProjectTemplate
    {
        $template = (new IndustryProjectTemplate())
            ->setOwner($owner)
            ->setName($name)
            ->setItems([['typeId' => 2446, 'typeName' => 'Warrior II', 'meLevel' => 5, 'teLevel' => 10, 'runs' => 20]]);
        $this->em->persist($template);

        return $template;
    }

    private function createProjectFrom(User $owner, IndustryProjectTemplate $template, ProjectStatus $status): IndustryProject
    {
        $project = (new IndustryProject())->setOwner($owner)->setName($template->getName().' run')->setStatus($status)->setTemplate($template);
        $project->enrolOwner();
        $this->em->persist($project);

        return $project;
    }

    /**
     * @param list<IndustryProjectTemplate> $templates
     *
     * @return list<IndustryProjectTemplate>
     */
    private function templatesOf(array $templates): array
    {
        return array_map(
            fn (IndustryProjectTemplate $template): IndustryProjectTemplate => $this->em->getReference(IndustryProjectTemplate::class, $template->getId()),
            $templates,
        );
    }
}
