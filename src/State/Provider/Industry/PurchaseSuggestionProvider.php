<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\PurchaseSuggestionListResource;
use App\ApiResource\Industry\PurchaseSuggestionResource;
use App\Entity\IndustryStepPurchase;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Repository\CachedWalletTransactionRepository;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryStepPurchaseRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Service\Industry\IndustryCalculationService;
use App\Service\UuidOrNotFound;
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
        private readonly IndustryActivityMaterialRepository $materialRepository,
        private readonly IndustryStepPurchaseRepository $purchaseRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PurchaseSuggestionListResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($uriVariables['id'], 'Project not found'));

        if ($project === null || $project->getUser() !== $user) {
            throw new NotFoundHttpException('Project not found');
        }

        $materialTypeIds = [];
        foreach ($project->getSteps() as $step) {
            if ($step->getDepth() > 0 && $step->getActivityType() !== 'copy') {
                $materialTypeIds[$step->getProductTypeId()] = true;
            }
        }

        if (empty($materialTypeIds)) {
            $blueprintTypeIds = [];
            foreach ($project->getSteps() as $step) {
                if ($step->getActivityType() !== 'copy') {
                    $blueprintTypeIds[$step->getBlueprintTypeId()] = true;
                }
            }
            if (!empty($blueprintTypeIds)) {
                $materialsByBlueprint = $this->materialRepository->findMaterialsForBlueprints(
                    array_keys($blueprintTypeIds),
                    [IndustryActivityType::Manufacturing->value, IndustryActivityType::Reaction->value],
                );
                foreach ($materialsByBlueprint as $materials) {
                    foreach ($materials as $material) {
                        $materialTypeIds[$material['materialTypeId']] = true;
                    }
                }
            }
        }

        if (empty($materialTypeIds)) {
            $result = new PurchaseSuggestionListResource();
            $result->id = $uriVariables['id'];
            return $result;
        }

        $foundTransactions = [];
        foreach ($user->getCharacters() as $character) {
            $transactions = $this->transactionRepository->findBuysByTypeIds(
                $character,
                array_keys($materialTypeIds),
            );

            foreach ($transactions as $tx) {
                $foundTransactions[] = [$character, $tx];
            }
        }

        $purchases = [];
        if ($foundTransactions !== []) {
            $purchases = $this->purchaseRepository->findBy([
                'transaction' => array_map(static fn (array $found) => $found[1], $foundTransactions),
            ]);
        }

        $suggestions = [];
        foreach ($foundTransactions as [$character, $tx]) {
            $linkingPurchases = array_filter(
                $purchases,
                static fn (IndustryStepPurchase $purchase) => $purchase->getTransaction() === $tx,
            );
            $linkedToCurrentProject = array_any(
                $linkingPurchases,
                static fn (IndustryStepPurchase $purchase) => $purchase->getStep()->getProject() === $project,
            );

            if ($linkingPurchases !== [] && !$linkedToCurrentProject) {
                continue;
            }

            $suggestion = new PurchaseSuggestionResource();
            $suggestion->transactionId = $tx->getTransactionId();
            $suggestion->transactionUuid = (string) $tx->getId();
            $suggestion->typeId = $tx->getTypeId();
            $suggestion->typeName = $this->calculationService->resolveTypeName($tx->getTypeId());
            $suggestion->quantity = $tx->getQuantity();
            $suggestion->unitPrice = $tx->getUnitPrice();
            $suggestion->totalPrice = $tx->getTotalPrice();
            $suggestion->date = $tx->getDate()->format('c');
            $suggestion->characterName = $character->getName();
            $suggestion->locationId = $tx->getLocationId();
            $suggestion->alreadyLinked = $linkedToCurrentProject;

            $suggestions[] = $suggestion;
        }

        $result = new PurchaseSuggestionListResource();
        $result->id = $uriVariables['id'];
        $result->suggestions = $suggestions;
        $result->totalCount = count($suggestions);

        return $result;
    }
}
