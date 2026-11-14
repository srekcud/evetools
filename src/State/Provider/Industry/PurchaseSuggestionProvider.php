<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\PurchaseSuggestionListResource;
use App\ApiResource\Industry\PurchaseSuggestionResource;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectPurchase;
use App\Entity\User;
use App\Repository\CachedWalletTransactionRepository;
use App\Repository\IndustryProjectPurchaseRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Sync\WalletReadingStateReader;
use App\Service\UuidOrNotFound;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<PurchaseSuggestionListResource>
 */
class PurchaseSuggestionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly CachedWalletTransactionRepository $transactionRepository,
        private readonly IndustryCalculationService $calculationService,
        private readonly IndustryProjectPurchaseRepository $purchaseRepository,
        private readonly ProjectAccessChecker $accessChecker,
        private readonly WalletReadingStateReader $walletReadingStateReader,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PurchaseSuggestionListResource
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

        $result = new PurchaseSuggestionListResource();
        $result->id = $uriVariables['projectId'];
        $result->walletReadings = $this->walletReadingStateReader->forUser($user);

        $typeIds = $this->suggestedTypeIdsOf($project);
        if ($typeIds === []) {
            return $result;
        }

        $foundTransactions = [];
        foreach ($user->getCharacters() as $character) {
            foreach ($this->transactionRepository->findBuysByTypeIds($character, $typeIds, $project->getCreatedAt()) as $transaction) {
                $foundTransactions[] = [$character, $transaction];
            }
        }

        $purchases = [];
        if ($foundTransactions !== []) {
            $purchases = $this->purchaseRepository->findBy([
                'transaction' => array_map(static fn (array $found) => $found[1], $foundTransactions),
            ]);
        }

        foreach ($foundTransactions as [$character, $transaction]) {
            $linkingPurchases = array_filter(
                $purchases,
                static fn (IndustryProjectPurchase $purchase): bool => $purchase->getTransaction() === $transaction,
            );
            $linkedToCurrentProject = array_any(
                $linkingPurchases,
                static fn (IndustryProjectPurchase $purchase): bool => $purchase->getBomLine()->getProject() === $project,
            );

            if ($linkingPurchases !== [] && !$linkedToCurrentProject) {
                continue;
            }

            $suggestion = new PurchaseSuggestionResource();
            $suggestion->transactionId = $transaction->getTransactionId();
            $suggestion->transactionUuid = (string) $transaction->getId();
            $suggestion->typeId = $transaction->getTypeId();
            $suggestion->typeName = $this->calculationService->resolveTypeName($transaction->getTypeId());
            $suggestion->quantity = $transaction->getQuantity();
            $suggestion->unitPrice = $transaction->getUnitPrice();
            $suggestion->totalPrice = $transaction->getTotalPrice();
            $suggestion->date = $transaction->getDate()->format('c');
            $suggestion->characterName = $character->getName();
            $suggestion->locationId = $transaction->getLocationId();
            $suggestion->alreadyLinked = $linkedToCurrentProject;

            $result->suggestions[] = $suggestion;
        }

        $result->totalCount = \count($result->suggestions);

        return $result;
    }

    /**
     * @return int[]
     */
    private function suggestedTypeIdsOf(IndustryProject $project): array
    {
        return array_values(array_unique(array_map(
            static fn (IndustryProjectBomLine $line): int => $line->getTypeId(),
            $project->getBomItems()->filter(static fn (IndustryProjectBomLine $line): bool => $line->isPurchasable())->toArray(),
        )));
    }
}
