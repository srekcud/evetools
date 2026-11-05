<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\ProjectSaleResource;
use App\ApiResource\Input\Industry\Project\RecordSaleFromWalletTransactionInput;
use App\Entity\CachedWalletTransaction;
use App\Entity\IndustryProjectSale;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Repository\CachedWalletTransactionRepository;
use App\Repository\IndustryProjectSaleRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\ResolvesSaleOfProject;
use App\Service\Mercure\MercurePublisherService;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<RecordSaleFromWalletTransactionInput, ProjectSaleResource>
 */
final readonly class RecordSaleFromWalletTransactionProcessor implements ProcessorInterface
{
    use ResolvesSaleOfProject;

    private const string ALREADY_LINKED_MESSAGE = 'This transaction is already linked to a sale.';

    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private ProjectAccessChecker $accessChecker,
        private CachedWalletTransactionRepository $transactionRepository,
        private IndustryProjectSaleRepository $saleRepository,
        private EntityManagerInterface $entityManager,
        private MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectSaleResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof RecordSaleFromWalletTransactionInput);

        $project = $this->projectOf($this->projectRepository, $uriVariables);
        $this->accessChecker->assertAdminOrOwner($user, $project);

        if ($project->isCompleted()) {
            throw new BadRequestHttpException('Cannot record a sale on a completed project');
        }

        $transaction = $this->transactionOf($user, $data->transactionId);
        $typeName = $this->assertSellsTheProduction($transaction, $project);
        $quantity = $data->quantity ?? $transaction->getQuantity();
        if ($quantity > $transaction->getQuantity()) {
            throw new BadRequestHttpException('The quantity exceeds the quantity of the transaction.');
        }
        if ($this->saleRepository->findOneBy(['walletTransaction' => $transaction]) !== null) {
            throw new ConflictHttpException(self::ALREADY_LINKED_MESSAGE);
        }

        $sale = new IndustryProjectSale();
        $sale->setProject($project);
        $sale->setTypeId($transaction->getTypeId());
        $sale->setTypeName($typeName);
        $sale->setQuantity($quantity);
        $sale->setUnitPrice($transaction->getUnitPrice());
        $sale->recalculateTotalPrice();
        $sale->setSoldAt($transaction->getDate());
        $sale->setRecordedBy($user);
        $sale->setSeller($transaction->getCharacter());
        $sale->setWalletTransaction($transaction);

        $this->entityManager->persist($sale);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            throw new ConflictHttpException(self::ALREADY_LINKED_MESSAGE, $exception);
        }

        $this->mercurePublisher->publishGroupProjectEvent(
            $project->getId()->toRfc4122(),
            'sale_recorded',
            [
                'saleId' => $sale->getId()->toRfc4122(),
                'typeName' => $sale->getTypeName(),
                'quantity' => $sale->getQuantity(),
                'totalPrice' => $sale->getTotalPrice(),
            ],
        );

        return ProjectSaleResource::fromSale($sale);
    }

    private function transactionOf(User $user, string $transactionId): CachedWalletTransaction
    {
        $transaction = Uuid::isValid($transactionId)
            ? $this->transactionRepository->find(Uuid::fromString($transactionId))
            : null;

        if ($transaction === null || !$user->getCharacters()->contains($transaction->getCharacter())) {
            throw new NotFoundHttpException('Transaction not found');
        }

        return $transaction;
    }

    private function assertSellsTheProduction(CachedWalletTransaction $transaction, IndustryProject $project): string
    {
        if ($transaction->isBuy()) {
            throw new BadRequestHttpException('This transaction is a purchase, not a sale.');
        }

        $typeName = $project->getItemTypeName($transaction->getTypeId());
        if ($typeName === null) {
            throw new BadRequestHttpException('The type of this transaction is not an item of the project.');
        }

        if ($transaction->getDate() < $project->getCreatedAt()) {
            throw new BadRequestHttpException('This transaction predates the project.');
        }

        return $typeName;
    }
}
