<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\PurchaseSuggestionListResource;
use App\ApiResource\Industry\PurchaseSuggestionResource;
use App\Entity\User;
use App\Repository\CachedWalletTransactionRepository;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryStepPurchaseRepository;
use App\Service\Industry\IndustryCalculationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProviderInterface<PurchaseSuggestionListResource>
 */
class PurchaseSuggestionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly CachedWalletTransactionRepository $transactionRepository,
        private readonly IndustryStepPurchaseRepository $purchaseRepository,
        private readonly IndustryCalculationService $calculationService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PurchaseSuggestionListResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(Uuid::fromString($uriVariables['id']));

        if ($project === null || $project->getUser() !== $user) {
            throw new NotFoundHttpException('Project not found');
        }

        // Collect all material type IDs needed by this project.
        // First try from sub-steps (depth > 0), then fallback to SDE blueprint materials.
        $materialTypeIds = [];
        foreach ($project->getSteps() as $step) {
            if ($step->getDepth() > 0 && $step->getActivityType() !== 'copy') {
                $materialTypeIds[$step->getProductTypeId()] = true;
            }
        }

        // If no sub-steps, extract materials directly from SDE blueprints
        if (empty($materialTypeIds)) {
            $blueprintTypeIds = [];
            foreach ($project->getSteps() as $step) {
                if ($step->getActivityType() !== 'copy') {
                    $blueprintTypeIds[$step->getBlueprintTypeId()] = true;
                }
            }
            if (!empty($blueprintTypeIds)) {
                $conn = $this->entityManager->getConnection();
                $placeholders = implode(',', array_fill(0, count($blueprintTypeIds), '?'));
                $rows = $conn->fetchAllAssociative(
                    "SELECT DISTINCT material_type_id FROM sde_industry_activity_materials WHERE type_id IN ({$placeholders}) AND activity_id = 1",
                    array_values(array_keys($blueprintTypeIds)),
                );
                foreach ($rows as $row) {
                    $materialTypeIds[(int) $row['material_type_id']] = true;
                }
            }
        }

        if (empty($materialTypeIds)) {
            $result = new PurchaseSuggestionListResource();
            $result->id = $uriVariables['id'];
            return $result;
        }

        // Get already-linked transaction IDs for this project
        $linkedTransactionIds = [];
        foreach ($project->getSteps() as $step) {
            foreach ($step->getPurchases() as $purchase) {
                $tx = $purchase->getTransaction();
                if ($tx !== null) {
                    $linkedTransactionIds[(string) $tx->getId()] = true;
                }
            }
        }

        // Find matching wallet transactions for all user's characters
        $suggestions = [];
        foreach ($user->getCharacters() as $character) {
            $transactions = $this->transactionRepository->findBuysByTypeIds(
                $character,
                array_keys($materialTypeIds),
            );

            foreach ($transactions as $tx) {
                $suggestion = new PurchaseSuggestionResource();
                $suggestion->transactionId = $tx->getTransactionId();
                $suggestion->transactionUuid = (string) $tx->getId();
                $suggestion->typeId = $tx->getTypeId();
                $suggestion->typeName = $this->calculationService->resolveTypeName($tx->getTypeId());
                $suggestion->quantity = $tx->getQuantity();
                $suggestion->unitPrice = $tx->getUnitPrice();
                $suggestion->totalPrice = $tx->getTotalPrice();
                $suggestion->date = $tx->getDate()->format('c');
                $suggestion->characterName = $character->getName() ?? 'Unknown';
                $suggestion->locationId = $tx->getLocationId();
                $suggestion->alreadyLinked = isset($linkedTransactionIds[(string) $tx->getId()]);

                $suggestions[] = $suggestion;
            }
        }

        $result = new PurchaseSuggestionListResource();
        $result->id = $uriVariables['id'];
        $result->suggestions = $suggestions;
        $result->totalCount = count($suggestions);

        return $result;
    }
}
