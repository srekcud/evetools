<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\ProjectT2BlueprintCopyProposalResource;
use App\ApiResource\Input\Industry\Project\DeclareT2BlueprintCopiesInput;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectDeclaredBlueprintCopy;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\Industry\Project\T2BlueprintCopyProposal;
use App\Service\Industry\Project\T2BlueprintCopyProposer;
use App\Service\UuidOrNotFound;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use App\State\Provider\Industry\Project\ProjectT2BlueprintCopyProposalResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<DeclareT2BlueprintCopiesInput, ProjectT2BlueprintCopyProposalResource>
 */
final readonly class DeclareT2BlueprintCopiesProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private ProjectAccessChecker $accessChecker,
        private T2BlueprintCopyProposer $proposer,
        private IndustryProjectService $projectService,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectT2BlueprintCopyProposalResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $projectId = $uriVariables['projectId'] ?? throw new NotFoundHttpException('Project not found');
        $project = $this->projectRepository->find(UuidOrNotFound::of($projectId, 'Project not found'));
        if (null === $project) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertOwner($user, $project);

        $declaredCopies = $this->declaredCopiesOf($data, $this->proposer->proposalOf($project));
        $previousCopies = $project->getDeclaredBlueprintCopies()->getValues();
        $previousRuns = $project->declaredRunsByProductTypeId();

        $project->replaceDeclaredBlueprintCopies($declaredCopies);
        try {
            $this->projectService->buildBom($project, $project->getOwner(), refuseToDropWorkedLines: ProjectStatus::Draft !== $project->getStatus());
        } catch (\RuntimeException $refusal) {
            $this->restore($project, $previousCopies, $previousRuns);

            throw new BadRequestHttpException($refusal->getMessage());
        }

        $this->entityManager->flush();

        return ProjectT2BlueprintCopyProposalResourceMapper::toResource($projectId, $this->proposer->proposalOf($project));
    }

    /**
     * @return list<IndustryProjectDeclaredBlueprintCopy>
     */
    private function declaredCopiesOf(DeclareT2BlueprintCopiesInput $input, T2BlueprintCopyProposal $proposal): array
    {
        if (T2BlueprintCopyProposal::STATE_READY !== $proposal->state) {
            throw new BadRequestHttpException('The T2 blueprint copies of this project cannot be declared now.');
        }

        $proposedRunsByProductTypeId = [];
        foreach ($proposal->lines as $line) {
            $proposedRunsByProductTypeId[$line->productTypeId] = $line->proposedRuns;
        }

        $declaredCopies = [];
        foreach ($input->copies ?? throw new BadRequestHttpException('The list of copies is required.') as $copy) {
            $productTypeId = $copy->productTypeId ?? throw new BadRequestHttpException('The product of a copy is required.');
            $runs = $copy->runs ?? throw new BadRequestHttpException('The runs of a copy are required.');
            if (!\array_key_exists($productTypeId, $proposedRunsByProductTypeId)) {
                throw new BadRequestHttpException(\sprintf('Product %d is not in the T2 blueprint copy proposal.', $productTypeId));
            }
            if (isset($declaredCopies[$productTypeId])) {
                throw new BadRequestHttpException(\sprintf('Product %d is declared more than once.', $productTypeId));
            }
            if ($runs < 1 || $runs > $proposedRunsByProductTypeId[$productTypeId]) {
                throw new BadRequestHttpException(\sprintf('The runs of product %d must be between 1 and %d.', $productTypeId, $proposedRunsByProductTypeId[$productTypeId]));
            }

            $declaredCopies[$productTypeId] = new IndustryProjectDeclaredBlueprintCopy($productTypeId, $runs);
        }

        return array_values($declaredCopies);
    }

    /**
     * @param list<IndustryProjectDeclaredBlueprintCopy> $previousCopies
     * @param array<int, int>                            $previousRuns
     */
    private function restore(IndustryProject $project, array $previousCopies, array $previousRuns): void
    {
        foreach ($previousCopies as $previousCopy) {
            $previousCopy->setRuns($previousRuns[$previousCopy->getProductTypeId()]);
        }
        $project->replaceDeclaredBlueprintCopies($previousCopies);
    }
}
