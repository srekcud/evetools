<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\ProjectSettlementResource;
use App\ApiResource\Input\Industry\Project\ConfirmTransferReceivedInput;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectTransferReceipt;
use App\Entity\User;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectTransferReceiptRepository;
use App\Service\Industry\Project\ProjectSettlementCalculator;
use App\Service\Industry\Project\SettlementTransfer;
use App\Service\Mercure\MercurePublisherService;
use App\Service\UuidOrNotFound;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use App\State\Provider\Industry\Project\ProjectSettlementResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<ConfirmTransferReceivedInput, ProjectSettlementResource>
 */
final readonly class ConfirmTransferReceivedProcessor implements ProcessorInterface
{
    private const string TRANSFER_NOT_FOUND = 'Transfer not found';

    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private IndustryProjectParticipantRepository $participantRepository,
        private IndustryProjectTransferReceiptRepository $receiptRepository,
        private ProjectAccessChecker $accessChecker,
        private ProjectSettlementCalculator $settlementCalculator,
        private ProjectSettlementResourceMapper $mapper,
        private EntityManagerInterface $entityManager,
        private MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectSettlementResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of((string) ($uriVariables['projectId'] ?? ''), 'Project not found'));
        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAcceptedMember($user, $project);

        $fromParticipantId = (string) ($uriVariables['fromParticipantId'] ?? '');
        $toParticipantId = (string) ($uriVariables['toParticipantId'] ?? '');
        $transfer = $this->plannedTransfer($project, $fromParticipantId, $toParticipantId);

        if ($transfer->toUserId !== $user->getId()?->toRfc4122()) {
            throw new AccessDeniedHttpException('Only the recipient can tick a transfer');
        }

        $received = $data->received === true;
        $this->recordReceipt($project, $fromParticipantId, $toParticipantId, $transfer->amountCents, $received);

        $projectId = $project->getId()->toRfc4122();
        $this->mercurePublisher->publishGroupProjectEvent($projectId, 'transfer_received', [
            'fromParticipantId' => $fromParticipantId,
            'toParticipantId' => $toParticipantId,
            'received' => $received,
        ]);

        return $this->mapper->map($projectId, $this->settlementCalculator->calculate($project), $user);
    }

    private function plannedTransfer(IndustryProject $project, string $fromParticipantId, string $toParticipantId): SettlementTransfer
    {
        foreach ($this->settlementCalculator->calculate($project)->transfers ?? [] as $transfer) {
            if ($transfer->fromParticipantId === $fromParticipantId && $transfer->toParticipantId === $toParticipantId) {
                return $transfer;
            }
        }

        throw new NotFoundHttpException(self::TRANSFER_NOT_FOUND);
    }

    private function recordReceipt(IndustryProject $project, string $fromParticipantId, string $toParticipantId, int $amountCents, bool $received): void
    {
        $from = $this->participantRepository->find($fromParticipantId);
        $to = $this->participantRepository->find($toParticipantId);
        if ($from === null || $to === null) {
            throw new NotFoundHttpException(self::TRANSFER_NOT_FOUND);
        }

        $receipt = $this->receiptRepository->findOneByTransfer($project, $from, $to);

        if (!$received) {
            if ($receipt !== null) {
                $this->entityManager->remove($receipt);
                $this->entityManager->flush();
            }

            return;
        }

        $receipt ??= (new IndustryProjectTransferReceipt())->setProject($project)->setFromParticipant($from)->setToParticipant($to);
        $receipt->setAmountCents($amountCents)->setReceivedAt(new \DateTimeImmutable());
        $this->entityManager->persist($receipt);
        $this->entityManager->flush();
    }
}
