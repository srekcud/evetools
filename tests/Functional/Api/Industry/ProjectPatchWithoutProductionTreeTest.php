<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry;

use App\Entity\IndustryProject;
use App\Entity\User;
use App\Service\Industry\IndustryTreeService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-204')]
final class ProjectPatchWithoutProductionTreeTest extends WebTestCase
{
    private const int PRODUCT_TYPE_ID = 999_204_587;
    private const int BLUEPRINT_TYPE_ID = 999_204_588;
    private const int PROJECT_RUNS = 3;
    private const int PATCHED_PROJECT_RUNS = 10;
    private const int PROJECT_ME_LEVEL = 8;
    private const string ORIGINAL_NOTES = 'Haul minerals from Jita';
    private const string PATCHED_NOTES = 'Haul minerals from Amarr';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private IndustryTreeService&MockObject $treeService;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->treeService = $this->createMock(IndustryTreeService::class);
        self::getContainer()->set(IndustryTreeService::class, $this->treeService);
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testPatchingProjectNotesDoesNotBuildTheProductionTree(): void
    {
        $owner = $this->persistUser();
        $project = $this->persistProject($owner);
        $this->em->flush();

        $this->treeService->expects(self::never())->method('buildProductionTree');

        $this->client->loginUser($owner, 'api');
        $this->patchProject($project, ['notes' => self::PATCHED_NOTES]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::PATCHED_NOTES, $this->responseJson()['notes']);
        $this->em->clear();
        self::assertSame(self::PATCHED_NOTES, $this->em->find(IndustryProject::class, $project->getId())?->getNotes());
    }

    public function testPatchingProjectRunsBuildsTheProductionTreeOnceForTheRegeneration(): void
    {
        $owner = $this->persistUser();
        $project = $this->persistProject($owner);
        $this->em->flush();

        $runsOfBuiltProductionTrees = [];
        $this->treeService->expects(self::atLeastOnce())
            ->method('buildProductionTree')
            ->willReturnCallback(function (int $productTypeId, int $runs) use (&$runsOfBuiltProductionTrees): array {
                $runsOfBuiltProductionTrees[] = $runs;

                return $this->rootNodeWithoutMaterials();
            });

        $this->client->loginUser($owner, 'api');
        $this->patchProject($project, ['runs' => self::PATCHED_PROJECT_RUNS]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::PATCHED_PROJECT_RUNS, $this->responseJson()['runs']);
        self::assertSame([self::PATCHED_PROJECT_RUNS], $runsOfBuiltProductionTrees);
    }

    public function testPatchingAnotherUsersProjectReturnsNotFoundAndKeepsItsNotes(): void
    {
        $owner = $this->persistUser();
        $intruder = $this->persistUser();
        $project = $this->persistProject($owner);
        $this->em->flush();

        $this->treeService->expects(self::never())->method('buildProductionTree');

        $this->client->loginUser($intruder, 'api');
        $this->patchProject($project, ['notes' => self::PATCHED_NOTES]);

        self::assertResponseStatusCodeSame(404);
        $this->em->clear();
        self::assertSame(self::ORIGINAL_NOTES, $this->em->find(IndustryProject::class, $project->getId())?->getNotes());
    }

    private function persistUser(): User
    {
        $user = new User();
        $this->em->persist($user);

        return $user;
    }

    private function persistProject(User $owner): IndustryProject
    {
        $project = (new IndustryProject())
            ->setUser($owner)
            ->setProductTypeId(self::PRODUCT_TYPE_ID)
            ->setRuns(self::PROJECT_RUNS)
            ->setMeLevel(self::PROJECT_ME_LEVEL)
            ->setNotes(self::ORIGINAL_NOTES);
        $this->em->persist($project);

        return $project;
    }

    private function patchProject(IndustryProject $project, array $changes): void
    {
        $this->client->request(
            'PATCH',
            '/api/industry/projects/' . $project->getId()?->toRfc4122(),
            server: ['CONTENT_TYPE' => 'application/merge-patch+json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode($changes, JSON_THROW_ON_ERROR),
        );
    }

    private function responseJson(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function rootNodeWithoutMaterials(): array
    {
        return [
            'blueprintTypeId' => self::BLUEPRINT_TYPE_ID,
            'productTypeId' => self::PRODUCT_TYPE_ID,
            'productTypeName' => 'Test Frigate',
            'quantity' => self::PATCHED_PROJECT_RUNS,
            'runs' => self::PATCHED_PROJECT_RUNS,
            'outputPerRun' => 1,
            'depth' => 0,
            'activityType' => 'manufacturing',
            'hasCopy' => false,
            'materials' => [],
        ];
    }
}
