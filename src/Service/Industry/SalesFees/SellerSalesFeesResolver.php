<?php

declare(strict_types=1);

namespace App\Service\Industry\SalesFees;

use App\Constant\EveConstants;
use App\Entity\CachedCharacterSkill;
use App\Entity\CachedCharacterStanding;
use App\Entity\Character;
use App\Entity\User;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\CachedCharacterStandingRepository;

class SellerSalesFeesResolver
{
    private const string NPC_CORPORATION_TYPE = 'npc_corp';
    private const string FACTION_TYPE = 'faction';
    private const float NEUTRAL_STANDING = 0.0;

    public function __construct(
        private readonly CachedCharacterSkillRepository $skillRepository,
        private readonly CachedCharacterStandingRepository $standingRepository,
        private readonly SalesFeeCalculator $calculator,
    ) {
    }

    public function forUser(User $user): ?SalesFees
    {
        $seller = $user->getMainCharacter();
        if ($seller === null || $seller->getStandingsSyncedAt() === null) {
            return null;
        }

        $accounting = $this->skillRepository->findOneBy(['character' => $seller, 'skillId' => CachedCharacterSkill::SKILL_ACCOUNTING]);
        $brokerRelations = $this->skillRepository->findOneBy(['character' => $seller, 'skillId' => CachedCharacterSkill::SKILL_BROKER_RELATIONS]);
        if ($accounting === null || $brokerRelations === null) {
            return null;
        }

        $standings = $this->standingRepository->findBy(['character' => $seller]);

        return $this->calculator->calculate(
            $accounting->getLevel(),
            $brokerRelations->getLevel(),
            $this->standingTowards($standings, EveConstants::JITA_NPC_FACTION_ID, self::FACTION_TYPE),
            $this->standingTowards($standings, EveConstants::JITA_NPC_CORPORATION_ID, self::NPC_CORPORATION_TYPE),
        );
    }

    /**
     * @param CachedCharacterStanding[] $standings
     */
    private function standingTowards(array $standings, int $fromId, string $fromType): float
    {
        foreach ($standings as $standing) {
            if ($standing->getFromId() === $fromId && $standing->getFromType() === $fromType) {
                return $standing->getStanding();
            }
        }

        return self::NEUTRAL_STANDING;
    }
}
