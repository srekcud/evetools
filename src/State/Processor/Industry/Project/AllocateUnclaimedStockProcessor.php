<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\ProjectContributionResource;
use App\ApiResource\Input\Industry\Project\AllocateUnclaimedStockInput;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ParticipantStatus;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\PilotName;
use App\Service\Industry\Project\ProjectContributionService;
use App\Service\Industry\Project\UnclaimedStockGuard;
use App\Service\Mercure\MercurePublisherService;
use App\Service\UuidOrNotFound;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use App\State\Provider\Industry\Project\ProjectResourceMapper;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<AllocateUnclaimedStockInput, ProjectContributionResource>
 */
final readonly class AllocateUnclaimedStockProcessor implements ProcessorInterface
{
    private const string UNCLAIMED_STOCK_ALLOCATED_ACTION = 'unclaimed_stock_allocated';

    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private IndustryProjectBomLineRepository $bomLineRepository,
        private IndustryProjectParticipantRepository $participantRepository,
        private ProjectAccessChecker $accessChecker,
        private ProjectContributionService $contributionService,
        private UnclaimedStockGuard $unclaimedStockGuard,
        private ProjectResourceMapper $mapper,
        private MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectContributionResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        \assert($data instanceof AllocateUnclaimedStockInput);

        $project = $this->projectRepository->find(UuidOrNotFound::of($uriVariables['projectId'] ?? '', 'Project not found'))
            ?? throw new NotFoundHttpException('Project not found');

        $this->accessChecker->assertOwner($user, $project);

        $line = $this->bomLineRepository->find(UuidOrNotFound::of($data->bomItemId ?? '', 'BOM item not found'));
        if (null === $line || $line->getProject() !== $project) {
            throw new NotFoundHttpException('BOM item not found');
        }

        $quantity = $data->quantity ?? throw new BadRequestHttpException('quantity is required');
        $mode = $data->mode ?? throw new BadRequestHttpException('mode is required');
        $recipient = match ($mode) {
            AllocateUnclaimedStockInput::MODE_ASSIGN => $this->acceptedParticipantOf($project, $data->participantId),
            AllocateUnclaimedStockInput::MODE_DONATE => $project->getOwnerParticipant(),
            default => throw new BadRequestHttpException('mode must be "assign" or "donate"'),
        };

        try {
            $this->unclaimedStockGuard->assertWithin($line, $quantity);
            $contribution = AllocateUnclaimedStockInput::MODE_ASSIGN === $mode
                ? $this->contributionService->assignUnclaimedStock($recipient, $line, $quantity, $user)
                : $this->contributionService->donateUnclaimedStock($recipient, $line, $quantity, $user);
        } catch (\DomainException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        $this->publishAllocation($project, $contribution, $mode);

        return $this->mapper->contributionToResource($contribution, $user);
    }

    private function acceptedParticipantOf(IndustryProject $project, ?string $participantId): IndustryProjectParticipant
    {
        if (null === $participantId) {
            throw new BadRequestHttpException('participantId is required to assign unclaimed stock');
        }

        $participant = $this->participantRepository->find(UuidOrNotFound::of($participantId, 'Participant not found'));
        if (null === $participant || $participant->getProject() !== $project || ParticipantStatus::Accepted !== $participant->getStatus()) {
            throw new BadRequestHttpException('The unclaimed stock can only be assigned to an accepted participant of the project');
        }

        return $participant;
    }

    private function publishAllocation(IndustryProject $project, IndustryProjectContribution $contribution, string $mode): void
    {
        $this->mercurePublisher->publishGroupProjectEvent(
            $project->getId()->toRfc4122(),
            self::UNCLAIMED_STOCK_ALLOCATED_ACTION,
            [
                'contributionId' => $contribution->getId()->toRfc4122(),
                'mode' => $mode,
                'characterName' => PilotName::of($contribution->getMember()->getUser()),
                'bomItemName' => $contribution->getBomItem()?->getTypeName(),
                'quantity' => $contribution->getQuantity(),
            ],
        );
    }
}
