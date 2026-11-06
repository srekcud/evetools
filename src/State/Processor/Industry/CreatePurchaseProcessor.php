<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\ProjectPurchaseResource;
use App\ApiResource\Input\Industry\CreatePurchaseInput;
use App\Entity\CachedWalletTransaction;
use App\Entity\IndustryProjectPurchase;
use App\Entity\User;
use App\Repository\CachedWalletTransactionRepository;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryProjectPurchaseRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\DerivedContributionService;
use App\Service\UuidOrNotFound;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
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

        $this->accessChecker->assertOwner($user, $project);

        $line = $this->bomLineRepository->find(UuidOrNotFound::of($uriVariables['lineId'], 'BOM line not found'));
        if ($line === null || $line->getProject() !== $project) {
            throw new NotFoundHttpException('BOM line not found');
        }

        \assert($data instanceof CreatePurchaseInput);

        $purchase = new IndustryProjectPurchase();

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

            $purchase->setTransaction($transaction);
            $purchase->setTypeId($transaction->getTypeId());
            $purchase->setQuantity($data->quantity ?? $transaction->getQuantity());
            $purchase->setUnitPrice($transaction->getUnitPrice());
            $purchase->setTotalPrice($purchase->getUnitPrice() * $purchase->getQuantity());
            $purchase->setSource('esi_wallet');
        } else {
            if ($data->typeId === null || $data->quantity === null || $data->unitPrice === null) {
                throw new BadRequestHttpException('Manual purchase requires typeId, quantity and unitPrice');
            }

            $purchase->setTypeId($data->typeId);
            $purchase->setQuantity($data->quantity);
            $purchase->setUnitPrice($data->unitPrice);
            $purchase->setTotalPrice($data->unitPrice * $data->quantity);
            $purchase->setSource('manual');
        }

        $line->addPurchase($purchase);
        $this->entityManager->persist($purchase);
        $this->derivedContributionService->refreshLine($line);
        $this->entityManager->flush();

        return ProjectPurchaseResource::fromEntity($purchase);
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
