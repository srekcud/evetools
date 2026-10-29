<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\StepPurchaseResource;
use App\ApiResource\Input\Industry\CreatePurchaseInput;
use App\Entity\CachedWalletTransaction;
use App\Entity\IndustryStepPurchase;
use App\Entity\User;
use App\Repository\CachedWalletTransactionRepository;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectStepRepository;
use App\Repository\IndustryStepPurchaseRepository;
use App\Service\UuidOrNotFound;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<CreatePurchaseInput, StepPurchaseResource>
 */
class CreatePurchaseProcessor implements ProcessorInterface
{
    private const string TRANSACTION_NOT_FOUND_MESSAGE = 'Transaction not found';
    private const string ALREADY_LINKED_MESSAGE = 'This transaction is already linked to a purchase.';

    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectStepRepository $stepRepository,
        private readonly CachedWalletTransactionRepository $transactionRepository,
        private readonly IndustryStepPurchaseRepository $purchaseRepository,
        private readonly IndustryResourceMapper $mapper,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StepPurchaseResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($uriVariables['id'], 'Project not found'));

        if ($project === null || $project->getUser() !== $user) {
            throw new NotFoundHttpException('Project not found');
        }

        $step = $this->stepRepository->find(UuidOrNotFound::of($uriVariables['stepId'], 'Step not found'));

        if ($step === null || $step->getProject() !== $project) {
            throw new NotFoundHttpException('Step not found');
        }

        assert($data instanceof CreatePurchaseInput);

        $purchase = new IndustryStepPurchase();
        $purchase->setStep($step);

        if ($data->transactionId !== null) {
            $transaction = $this->findOwnedTransaction($user, $data->transactionId);

            if ($this->purchaseRepository->findOneBy(['transaction' => $transaction]) !== null) {
                throw new ConflictHttpException(self::ALREADY_LINKED_MESSAGE);
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

        $step->addPurchase($purchase);
        $this->entityManager->persist($purchase);
        $this->entityManager->flush();

        return $this->mapper->purchaseToResource($purchase);
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
