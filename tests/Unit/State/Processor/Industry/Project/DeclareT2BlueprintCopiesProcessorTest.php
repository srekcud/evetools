<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Industry\Project\ProjectT2BlueprintCopyProposalResource;
use App\ApiResource\Input\Industry\Project\DeclaredT2BlueprintCopyInput;
use App\ApiResource\Input\Industry\Project\DeclareT2BlueprintCopiesInput;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectDeclaredBlueprintCopy;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\Industry\Project\T2BlueprintCopyProposal;
use App\Service\Industry\Project\T2BlueprintCopyProposalLine;
use App\Service\Industry\Project\T2BlueprintCopyProposer;
use App\State\Processor\Industry\Project\DeclareT2BlueprintCopiesProcessor;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(DeclareT2BlueprintCopiesProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-804')]
final class DeclareT2BlueprintCopiesProcessorTest extends TestCase
{
    private const int ISHTAR = 12_005;
    private const int ISHTAR_PROPOSED_RUNS = 13;
    private const int HAIL_L = 12_779;
    private const int HAIL_L_PROPOSED_RUNS = 5;
    private const int RIFTER = 587;

    private EntityManagerInterface&MockObject $entityManager;
    private IndustryProjectService&MockObject $projectService;
    private IndustryProject $project;
    private User $owner;
    private User $actor;
    private string $proposalState = T2BlueprintCopyProposal::STATE_READY;
    private ?IndustryProject $foundProject;
    private DeclareT2BlueprintCopiesProcessor $processor;

    protected function setUp(): void
    {
        $this->owner = new User();
        $this->actor = $this->owner;
        $this->project = (new IndustryProject())->setOwner($this->owner)->setStatus(ProjectStatus::Draft);
        $this->foundProject = $this->project;
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->projectService = $this->createMock(IndustryProjectService::class);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturnCallback(fn (): User => $this->actor);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturnCallback(fn (): ?IndustryProject => $this->foundProject);

        $proposer = $this->createStub(T2BlueprintCopyProposer::class);
        $proposer->method('proposalOf')->willReturnCallback(fn (IndustryProject $project): T2BlueprintCopyProposal => $this->proposalOf($project));

        $this->processor = new DeclareT2BlueprintCopiesProcessor(
            $security,
            $projectRepository,
            new ProjectAccessChecker($this->createStub(IndustryProjectParticipantRepository::class)),
            $proposer,
            $this->projectService,
            $this->entityManager,
        );
    }

    public function testDeclarationReplacesTheDeclaredSetRecomputesThePlanOfADraftAndFlushesOnce(): void
    {
        $this->project->replaceDeclaredBlueprintCopies([new IndustryProjectDeclaredBlueprintCopy(self::HAIL_L, 2)]);
        $this->projectService->expects($this->once())->method('buildBom')->with($this->project, $this->owner, false);
        $this->entityManager->expects($this->once())->method('flush');

        $resource = $this->declare([self::ISHTAR => 11, self::HAIL_L => 5]);

        self::assertSame([self::ISHTAR => 11, self::HAIL_L => 5], $this->project->declaredRunsByProductTypeId());
        self::assertSame(
            [[self::ISHTAR, 11], [self::HAIL_L, 5]],
            array_map(static fn (array $line): array => [$line['productTypeId'], $line['declaredRuns']], $resource->lines),
        );
        self::assertSame($this->project->getId()->toRfc4122(), $resource->id);
    }

    public function testRecomputeOfAProjectThatIsNotADraftRefusesToDropWorkedLines(): void
    {
        $this->project->setStatus(ProjectStatus::InProgress);
        $this->projectService->expects($this->once())->method('buildBom')->with($this->project, $this->owner, true);

        $this->declare([self::ISHTAR => 13]);

        self::assertSame([self::ISHTAR => 13], $this->project->declaredRunsByProductTypeId());
    }

    public function testRunsEqualToTheProposedRunsAndToOneAreAccepted(): void
    {
        $this->entityManager->expects($this->once())->method('flush');

        $this->declare([self::ISHTAR => self::ISHTAR_PROPOSED_RUNS, self::HAIL_L => 1]);

        self::assertSame([self::ISHTAR => 13, self::HAIL_L => 1], $this->project->declaredRunsByProductTypeId());
    }

    public function testEmptyListRemovesEveryDeclarationAndRecomputesThePlan(): void
    {
        $this->project->replaceDeclaredBlueprintCopies([new IndustryProjectDeclaredBlueprintCopy(self::ISHTAR, 6)]);
        $this->projectService->expects($this->once())->method('buildBom');
        $this->entityManager->expects($this->once())->method('flush');

        $this->declare([]);

        self::assertSame([], $this->project->declaredRunsByProductTypeId());
        self::assertCount(0, $this->project->getDeclaredBlueprintCopies());
    }

    public function testRunsAboveTheProposedRunsAreRefusedAndTheDeclarationsAreLeftUnchanged(): void
    {
        $previous = new IndustryProjectDeclaredBlueprintCopy(self::HAIL_L, 3);
        $this->project->replaceDeclaredBlueprintCopies([$previous]);
        $this->projectService->expects($this->never())->method('buildBom');
        $this->entityManager->expects($this->never())->method('flush');

        try {
            $this->declare([self::ISHTAR => self::ISHTAR_PROPOSED_RUNS + 1]);
            self::fail('Runs above the proposed runs must be refused');
        } catch (BadRequestHttpException) {
            self::assertSame([$previous], $this->project->getDeclaredBlueprintCopies()->getValues());
        }
    }

    public function testZeroRunsAreRefusedAndTheDeclarationsAreLeftUnchanged(): void
    {
        $previous = new IndustryProjectDeclaredBlueprintCopy(self::HAIL_L, 3);
        $this->project->replaceDeclaredBlueprintCopies([$previous]);
        $this->projectService->expects($this->never())->method('buildBom');
        $this->entityManager->expects($this->never())->method('flush');

        try {
            $this->declare([self::ISHTAR => 0]);
            self::fail('Zero runs must be refused');
        } catch (BadRequestHttpException) {
            self::assertSame([$previous], $this->project->getDeclaredBlueprintCopies()->getValues());
        }
    }

    public function testProductAbsentFromTheProposalIsRefusedAndTheDeclarationsAreLeftUnchanged(): void
    {
        $previous = new IndustryProjectDeclaredBlueprintCopy(self::HAIL_L, 3);
        $this->project->replaceDeclaredBlueprintCopies([$previous]);
        $this->projectService->expects($this->never())->method('buildBom');
        $this->entityManager->expects($this->never())->method('flush');

        try {
            $this->declare([self::ISHTAR => 4, self::RIFTER => 1]);
            self::fail('A product that is not in the proposal must be refused');
        } catch (BadRequestHttpException) {
            self::assertSame([$previous], $this->project->getDeclaredBlueprintCopies()->getValues());
        }
    }

    public function testSameProductDeclaredTwiceIsRefused(): void
    {
        $this->projectService->expects($this->never())->method('buildBom');
        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(BadRequestHttpException::class);

        $this->processor->process($this->inputOf([[self::ISHTAR, 4], [self::ISHTAR, 5]]), new Post(), ['projectId' => $this->project->getId()->toRfc4122()]);
    }

    public function testProposalThatIsNotReadyRefusesTheDeclaration(): void
    {
        $this->proposalState = T2BlueprintCopyProposal::STATE_ASSETS_UNKNOWN;
        $this->projectService->expects($this->never())->method('buildBom');
        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(BadRequestHttpException::class);

        $this->declare([]);
    }

    public function testRefusedRecomputeRestoresTheDeclarationsAndNeverFlushes(): void
    {
        $previous = new IndustryProjectDeclaredBlueprintCopy(self::HAIL_L, 3);
        $this->project->replaceDeclaredBlueprintCopies([$previous]);
        $this->projectService->method('buildBom')->willThrowException(new \RuntimeException('The plan drops a line that already has a job'));
        $this->entityManager->expects($this->never())->method('flush');

        try {
            $this->declare([self::ISHTAR => 13]);
            self::fail('A refused recompute must be reported as a bad request');
        } catch (BadRequestHttpException $refusal) {
            self::assertSame('The plan drops a line that already has a job', $refusal->getMessage());
            self::assertSame([$previous], $this->project->getDeclaredBlueprintCopies()->getValues());
        }
    }

    public function testActorWhoDoesNotOwnTheProjectIsRefusedBeforeAnyChange(): void
    {
        $this->actor = new User();
        $this->projectService->expects($this->never())->method('buildBom');
        $this->entityManager->expects($this->never())->method('flush');

        try {
            $this->declare([self::ISHTAR => 13]);
            self::fail('Only the owner can declare blueprint copies');
        } catch (AccessDeniedHttpException) {
            self::assertSame([], $this->project->declaredRunsByProductTypeId());
        }
    }

    public function testUnknownProjectIsNotFound(): void
    {
        $this->foundProject = null;
        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(NotFoundHttpException::class);

        $this->declare([self::ISHTAR => 13]);
    }

    private function declare(array $runsByProductTypeId): ProjectT2BlueprintCopyProposalResource
    {
        $copies = [];
        foreach ($runsByProductTypeId as $productTypeId => $runs) {
            $copies[] = [$productTypeId, $runs];
        }

        return $this->processor->process($this->inputOf($copies), new Post(), ['projectId' => $this->project->getId()->toRfc4122()]);
    }

    private function inputOf(array $copies): DeclareT2BlueprintCopiesInput
    {
        $input = new DeclareT2BlueprintCopiesInput();
        $input->copies = array_map(static function (array $copy): DeclaredT2BlueprintCopyInput {
            $declared = new DeclaredT2BlueprintCopyInput();
            [$declared->productTypeId, $declared->runs] = $copy;

            return $declared;
        }, $copies);

        return $input;
    }

    private function proposalOf(IndustryProject $project): T2BlueprintCopyProposal
    {
        if (T2BlueprintCopyProposal::STATE_READY !== $this->proposalState) {
            return new T2BlueprintCopyProposal($this->proposalState, 'blueprints_not_synced', []);
        }

        $declared = $project->declaredRunsByProductTypeId();

        return new T2BlueprintCopyProposal(T2BlueprintCopyProposal::STATE_READY, null, [
            new T2BlueprintCopyProposalLine(self::ISHTAR, 'Ishtar', 15, 10, 3, 13, self::ISHTAR_PROPOSED_RUNS, $declared[self::ISHTAR] ?? 0),
            new T2BlueprintCopyProposalLine(self::HAIL_L, 'Hail L', 9, 5, null, 5, self::HAIL_L_PROPOSED_RUNS, $declared[self::HAIL_L] ?? 0),
        ]);
    }
}
