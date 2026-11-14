<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\ProjectPurchaseResource;
use App\ApiResource\Input\Industry\CreatePurchaseInput;
use App\Entity\CachedWalletTransaction;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectPurchase;
use App\Entity\User;
use App\Repository\CachedWalletTransactionRepository;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryProjectPurchaseRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\ContainerDeliveryConfirmer;
use App\Service\Industry\Project\DerivedContributionService;
use App\Service\Industry\Project\ProjectPurchaseFactory;
use App\Service\UuidOrNotFound;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<CreatePurchaseInput, ProjectPurchaseResource>
 */
class CreatePurchaseProcessor implements ProcessorInterface
{
    private const string TRANSACTION_NOT_FOUND_MESSAGE = 'Transaction not found';
    private const string ALREADY_LINKED_MESSAGE = 'This transaction is already linked to a purchase.';
    private const string NOT_A_PURCHASE_MESSAGE = 'The wallet transaction is not a purchase.';
    private const string MANUAL_PURCHASE_OWNER_ONLY_MESSAGE = 'Only the project owner can add a manual purchase';
    private const string QUANTITY_ABOVE_TRANSACTION_MESSAGE = 'The quantity cannot exceed the quantity of the wallet transaction.';

    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectBomLineRepository $bomLineRepository,
        private readonly CachedWalletTransactionRepository $transactionRepository,
        private readonly IndustryProjectPurchaseRepository $purchaseRepository,
        private readonly ProjectAccessChecker $accessChecker,
        private readonly EntityManagerInterface $entityManager,
        private readonly DerivedContributionService $derivedContributionService,
        private readonly ContainerDeliveryConfirmer $containerDeliveryConfirmer,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectPurchaseResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($uriVariables['projectId'], 'Project not found'));
        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAcceptedMember($user, $project);

        $line = $this->bomLineRepository->find(UuidOrNotFound::of($uriVariables['lineId'], 'BOM line not found'));
        if ($line === null || $line->getProject() !== $project) {
            throw new NotFoundHttpException('BOM line not found');
        }

        \assert($data instanceof CreatePurchaseInput);

        if ($data->transactionId !== null) {
            $transaction = $this->findOwnedTransaction($user, $data->transactionId);

            if ($this->purchaseRepository->findOneBy(['transaction' => $transaction]) !== null) {
                throw new ConflictHttpException(self::ALREADY_LINKED_MESSAGE);
            }

            if (!$transaction->isBuy()) {
                throw new BadRequestHttpException(self::NOT_A_PURCHASE_MESSAGE);
            }

            if ($data->quantity !== null && $data->quantity > $transaction->getQuantity()) {
                throw new BadRequestHttpException(self::QUANTITY_ABOVE_TRANSACTION_MESSAGE);
            }

            $purchase = ProjectPurchaseFactory::fromTransaction($transaction, $data->quantity);
        } else {
            if ($project->getOwner() !== $user) {
                throw new AccessDeniedHttpException(self::MANUAL_PURCHASE_OWNER_ONLY_MESSAGE);
            }

            $purchase = new IndustryProjectPurchase();

            if ($data->typeId === null || $data->quantity === null || $data->unitPrice === null) {
                throw new BadRequestHttpException('Manual purchase requires typeId, quantity and unitPrice');
            }

            $purchase->setTypeId($data->typeId);
            $purchase->setQuantity($data->quantity);
            $purchase->setUnitPrice($data->unitPrice);
            $purchase->setTotalPrice($data->unitPrice * $data->quantity);
            $purchase->setSource('manual');
        }

        $purchase->setBuyer($this->participantOf($user, $project));
        $line->addPurchase($purchase);
        $this->entityManager->persist($purchase);
        $this->derivedContributionService->refreshLine($line);
        $this->entityManager->flush();
        $this->containerDeliveryConfirmer->confirmProject($project);

        return ProjectPurchaseResource::fromEntity($purchase);
    }

    private function participantOf(User $user, IndustryProject $project): IndustryProjectParticipant
    {
        $participant = array_find(
            $project->getMembers()->toArray(),
            static fn (IndustryProjectParticipant $member): bool => $member->getUser() === $user,
        );
        if ($participant === null) {
            throw new \LogicException(sprintf('The accepted user has no participant in project %s.', $project->getId()->toRfc4122()));
        }

        return $participant;
    }

    private function findOwnedTransaction(User $user, string $transactionId): CachedWalletTransaction
    {
        $transaction = $this->transactionRepository->find(UuidOrNotFound::of($transactionId, self::TRANSACTION_NOT_FOUND_MESSAGE));

        if ($transaction === null || !$user->getCharacters()->contains($transaction->getCharacter())) {
            throw new NotFoundHttpException(self::TRANSACTION_NOT_FOUND_MESSAGE);
        }

        return $transaction;
    }
}
