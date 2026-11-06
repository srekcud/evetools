<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\ProjectContributionResource;
use App\ApiResource\Input\Industry\Project\SubmitContributionInput;
use App\Entity\User;
use App\Enum\ContributionType;
use App\Enum\ParticipantStatus;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\ProjectContributionService;
use App\Service\Industry\Project\PilotName;
use App\Service\Mercure\MercurePublisherService;
use App\Service\UuidOrNotFound;
use App\State\Provider\Industry\Project\ProjectResourceMapper;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<SubmitContributionInput, ProjectContributionResource>
 */
class SubmitContributionProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectParticipantRepository $memberRepository,
        private readonly IndustryProjectBomLineRepository $bomItemRepository,
        private readonly ProjectContributionService $contributionService,
        private readonly ProjectResourceMapper $mapper,
        private readonly MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectContributionResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof SubmitContributionInput);

        $projectId = $uriVariables['projectId'] ?? null;
        if ($projectId === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($projectId, 'Project not found'));
        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        // User must be an accepted member of the project
        $member = $this->memberRepository->findOneBy([
            'project' => $project,
            'user' => $user,
            'status' => ParticipantStatus::Accepted,
        ]);
        if ($member === null) {
            throw new AccessDeniedHttpException('You must be an accepted member to contribute');
        }

        if ($project->isCompleted()) {
            throw new BadRequestHttpException('Cannot contribute to a completed project');
        }

        // Resolve BOM item
        if ($data->bomItemId === null) {
            throw new BadRequestHttpException('bomItemId is required');
        }

        $bomItem = $this->bomItemRepository->find(Uuid::fromString($data->bomItemId));
        if ($bomItem === null || $bomItem->getProject() !== $project) {
            throw new NotFoundHttpException('BOM item not found');
        }

        // Resolve contribution type
        $type = ContributionType::tryFrom($data->type);
        if ($type === null) {
            throw new BadRequestHttpException(sprintf('Invalid contribution type "%s"', $data->type));
        }

        try {
            $contribution = $this->contributionService->submit(
                $member,
                $bomItem,
                $type,
                $data->quantity,
                $data->estimatedValue,
                $data->note,
            );
        } catch (\DomainException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        $this->mercurePublisher->publishGroupProjectEvent(
            $project->getId()->toRfc4122(),
            'contribution_submitted',
            [
                'contributionId' => $contribution->getId()->toRfc4122(),
                'type' => $contribution->getType()->value,
                'characterName' => PilotName::of($member->getUser()),
                'bomItemName' => $contribution->getBomItem()?->getTypeName(),
                'quantity' => $contribution->getQuantity(),
                'value' => $contribution->getEstimatedValue(),
            ],
        );

        return $this->mapper->contributionToResource($contribution);
    }
}
