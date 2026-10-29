<?php

declare(strict_types=1);

namespace App\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\GroupIndustry\GroupSaleSuggestionResource;
use App\Entity\CachedWalletTransaction;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectItem;
use App\Entity\User;
use App\Repository\CachedWalletTransactionRepository;
use App\Repository\GroupIndustryProjectRepository;
use App\Service\GroupIndustry\ResolvesSaleOfProject;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<GroupSaleSuggestionResource>
 */
final readonly class GroupSaleSuggestionProvider implements ProviderInterface
{
    use ResolvesSaleOfProject;

    public function __construct(
        private Security $security,
        private GroupIndustryProjectRepository $projectRepository,
        private GroupProjectAccessChecker $accessChecker,
        private CachedWalletTransactionRepository $transactionRepository,
    ) {
    }

    /**
     * @return GroupSaleSuggestionResource[]
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectOf($this->projectRepository, $uriVariables);
        $this->accessChecker->assertAdminOrOwner($user, $project);

        $transactions = $this->transactionRepository->findUnlinkedSaleTransactions(
            $user->getCharacters()->toArray(),
            $this->itemTypeIds($project),
            $project->getCreatedAt(),
        );

        return array_map(
            fn (CachedWalletTransaction $transaction): GroupSaleSuggestionResource => $this->suggestionOf($transaction, $project),
            $transactions,
        );
    }

    /**
     * @return int[]
     */
    private function itemTypeIds(GroupIndustryProject $project): array
    {
        return array_values(array_unique(array_map(
            static fn (GroupIndustryProjectItem $item): int => $item->getTypeId(),
            $project->getItems()->toArray(),
        )));
    }

    private function suggestionOf(CachedWalletTransaction $transaction, GroupIndustryProject $project): GroupSaleSuggestionResource
    {
        $suggestion = new GroupSaleSuggestionResource();
        $suggestion->transactionId = $transaction->getId()->toRfc4122();
        $suggestion->typeId = $transaction->getTypeId();
        $suggestion->typeName = $project->getItemTypeName($transaction->getTypeId())
            ?? throw new \LogicException(sprintf('Type %d is not an item of the project', $transaction->getTypeId()));
        $suggestion->quantity = $transaction->getQuantity();
        $suggestion->unitPrice = $transaction->getUnitPrice();
        $suggestion->totalPrice = $transaction->getTotalPrice();
        $suggestion->date = $transaction->getDate()->format(\DateTimeInterface::ATOM);
        $suggestion->sellerCharacterId = $transaction->getCharacter()->getEveCharacterId();
        $suggestion->sellerCharacterName = $transaction->getCharacter()->getName();
        $suggestion->locationId = $transaction->getLocationId();
        $suggestion->possibleDuplicateSaleIds = $this->possibleDuplicateSaleIds($transaction, $project);

        return $suggestion;
    }

    /**
     * @return string[]
     */
    private function possibleDuplicateSaleIds(CachedWalletTransaction $transaction, GroupIndustryProject $project): array
    {
        $duplicateIds = [];
        foreach ($project->getSales() as $sale) {
            if ($sale->getWalletTransaction() === null
                && $sale->getQuantity() === $transaction->getQuantity()
                && $sale->getUnitPrice() === $transaction->getUnitPrice()) {
                $duplicateIds[] = $sale->getId()->toRfc4122();
            }
        }

        return $duplicateIds;
    }
}
