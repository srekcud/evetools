<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class IndustryBlacklistService
{
    /**
     * Predefined group categories for the blacklist UI.
     * Each category maps to SDE group IDs.
     */
    public const BLACKLIST_CATEGORIES = [
        [
            'key' => 'advanced_components',
            'label' => 'Advanced Components',
            'groupIds' => [334],
        ],
        [
            'key' => 'capital_components',
            'label' => 'Capital Components',
            'groupIds' => [873],
        ],
        [
            'key' => 'advanced_capital_components',
            'label' => 'Advanced Capital Components',
            'groupIds' => [913],
        ],
        [
            'key' => 'hybrid_components',
            'label' => 'Hybrid Components',
            'groupIds' => [964],
        ],
        [
            'key' => 'fuel_blocks',
            'label' => 'Fuel Blocks',
            'groupIds' => [1136],
        ],
        [
            'key' => 'tools',
            'label' => 'Tools (R.A.M.)',
            'groupIds' => [332],
        ],
        [
            'key' => 'simple_reactions',
            'label' => 'Simple Reactions',
            'groupIds' => [436],
        ],
        [
            'key' => 'complex_reactions',
            'label' => 'Composite Reactions',
            'groupIds' => [484],
        ],
        [
            'key' => 'hybrid_reactions',
            'label' => 'Hybrid Reactions',
            'groupIds' => [977],
        ],
        [
            'key' => 'biochem_reactions',
            'label' => 'Biochemical Reactions',
            'groupIds' => [661, 662],
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Resolve the user's blacklist (groups + individual types) into a flat array of type IDs.
     */
    public function resolveBlacklistedTypeIds(User $user): array
    {
        $typeIds = $user->getIndustryBlacklistTypeIds();
        $groupIds = $user->getIndustryBlacklistGroupIds();

        if (!empty($groupIds)) {
            $conn = $this->entityManager->getConnection();
            $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
            $rows = $conn->fetchAllAssociative(
                "SELECT type_id FROM sde_inv_types WHERE group_id IN ({$placeholders}) AND published = true",
                array_values($groupIds),
            );
            foreach ($rows as $row) {
                $typeIds[] = (int) $row['type_id'];
            }
        }

        return array_values(array_unique($typeIds));
    }

    /**
     * Get the blacklist categories with their enabled state for a user.
     */
    public function getCategories(User $user): array
    {
        $userGroupIds = $user->getIndustryBlacklistGroupIds();

        return array_map(function (array $cat) use ($userGroupIds) {
            // A category is blacklisted if ALL its group IDs are in the user's blacklist
            $enabled = !empty(array_intersect($cat['groupIds'], $userGroupIds));
            return [
                'key' => $cat['key'],
                'label' => $cat['label'],
                'groupIds' => $cat['groupIds'],
                'blacklisted' => $enabled,
            ];
        }, self::BLACKLIST_CATEGORIES);
    }

    /**
     * Get individually blacklisted items with names.
     */
    public function getBlacklistedItems(User $user): array
    {
        $typeIds = $user->getIndustryBlacklistTypeIds();
        if (empty($typeIds)) {
            return [];
        }

        $conn = $this->entityManager->getConnection();
        $placeholders = implode(',', array_fill(0, count($typeIds), '?'));
        $rows = $conn->fetchAllAssociative(
            "SELECT type_id, type_name FROM sde_inv_types WHERE type_id IN ({$placeholders})",
            array_values($typeIds),
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'typeId' => (int) $row['type_id'],
                'typeName' => $row['type_name'],
            ];
        }
        usort($items, fn (array $a, array $b) => strcasecmp($a['typeName'], $b['typeName']));

        return $items;
    }
}
