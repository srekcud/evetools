<?php

declare(strict_types=1);

namespace App\Service\Industry\SellOrders;

use App\Constant\EveConstants;
use App\Entity\CachedCharacterOrder;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Enum\OrderPositionState;
use App\Enum\ProjectStatus;
use App\Repository\CachedCharacterOrderRepository;

class ProjectSellOrdersReader
{
    private const array STATE_RANK = [
        OrderPositionState::Undercut->value => 0,
        OrderPositionState::Unknown->value => 1,
        OrderPositionState::First->value => 2,
    ];

    public function __construct(
        private readonly CachedCharacterOrderRepository $orderRepository,
        private readonly ProjectProductTypes $productTypes,
        private readonly SellOrderMarketNames $marketNames,
    ) {
    }

    public function of(IndustryProject $project, User $viewer): ProjectSellOrders
    {
        if ($project->isPersonalUse() || $project->getStatus() === ProjectStatus::Completed) {
            return new ProjectSellOrders([], [], null);
        }

        $productNames = $this->productTypes->of($project);
        $rows = $this->orderRepository->findByCharactersAndTypes($viewer->getCharacters()->getValues(), array_keys($productNames));
        usort($rows, $this->byUrgency(...));

        return new ProjectSellOrders(
            array_map(fn (CachedCharacterOrder $row): ProjectSellOrder => $this->toOrder($row, $productNames[$row->getTypeId()]), $rows),
            $this->charactersToReconnect($viewer),
            $this->latestSyncOf($rows),
        );
    }

    /**
     * @return list<ProjectSellOrder>
     */
    public function undercutOrdersOf(IndustryProject $project, User $viewer): array
    {
        return array_values(array_filter(
            $this->of($project, $viewer)->orders,
            static fn (ProjectSellOrder $order): bool => $order->state === OrderPositionState::Undercut,
        ));
    }

    private function byUrgency(CachedCharacterOrder $left, CachedCharacterOrder $right): int
    {
        return self::STATE_RANK[$left->getPosition()->state->value] <=> self::STATE_RANK[$right->getPosition()->state->value]
            ?: $left->getUndercutSince() <=> $right->getUndercutSince()
            ?: $left->getOrderId() <=> $right->getOrderId();
    }

    private function toOrder(CachedCharacterOrder $row, string $typeName): ProjectSellOrder
    {
        $position = $row->getPosition();

        return new ProjectSellOrder(
            $row->getOrderId(),
            $row->getCharacter()->getName(),
            $row->getTypeId(),
            $typeName,
            $row->getVolumeRemain(),
            $row->getLocationId(),
            $this->marketNames->of($row->getLocationId()),
            $row->getPrice(),
            $position->bestPrice,
            $position->rank,
            $position->state,
            $position->unknownReason,
            $row->getUndercutSince(),
            $position->suggestedPrice,
        );
    }

    /**
     * @return list<string>
     */
    private function charactersToReconnect(User $viewer): array
    {
        $names = [];
        foreach ($viewer->getCharacters() as $character) {
            if (!$this->canReadOrders($character)) {
                $names[] = $character->getName();
            }
        }

        return $names;
    }

    private function canReadOrders(Character $character): bool
    {
        return $character->isAuthValid() && $character->getEveToken()?->hasScope(EveConstants::CHARACTER_ORDERS_SCOPE) === true;
    }

    /**
     * @param list<CachedCharacterOrder> $rows
     */
    private function latestSyncOf(array $rows): ?\DateTimeImmutable
    {
        $latest = null;
        foreach ($rows as $row) {
            if ($latest === null || $row->getSyncedAt() > $latest) {
                $latest = $row->getSyncedAt();
            }
        }

        return $latest;
    }
}
