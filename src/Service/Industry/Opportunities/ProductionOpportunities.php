<?php

declare(strict_types=1);

namespace App\Service\Industry\Opportunities;

use App\Constant\EveConstants;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Repository\Sde\IndustryActivitySkillRepository;
use App\Service\Industry\AnalysisSolarSystems;
use App\Service\Industry\BatchProfitScannerService;
use App\Service\Industry\Project\ProjectSellStructures;
use App\Service\Industry\SalesFees\SellerSalesFeesResolver;
use App\Service\Industry\SlotTrackerService;
use App\Service\TypeNameResolver;

final readonly class ProductionOpportunities
{
    private const string JITA_VENUE = 'jita';
    private const string STRUCTURE_VENUE = 'structure';
    private const string ALL_CATEGORIES = 'all';
    private const string REACTION_ACTIVITY = 'reaction';

    public function __construct(
        private BatchProfitScannerService $scanner,
        private ProjectSellStructures $sellStructures,
        private SellerSalesFeesResolver $salesFeesResolver,
        private IndustryUserSettingsRepository $settingsRepository,
        private SlotTrackerService $slotTracker,
        private IndustryActivitySkillRepository $skillRequirements,
        private IndustryActivityRepository $activities,
        private CachedCharacterSkillRepository $characterSkills,
        private TypeNameResolver $typeNames,
        private ProductionOpportunityRanker $ranker,
    ) {
    }

    public function for(User $user, OpportunityMarket $market, OpportunitySort $sort, ?int $capitalCap): ProductionOpportunitiesResult
    {
        $capitalStructure = $this->sellStructures->defaultFor($user);
        $salesFees = $this->salesFeesResolver->forUser($user);
        $settings = $this->settingsRepository->findOneBy(['user' => $user]);
        $solarSystems = AnalysisSolarSystems::resolve(null, $settings);
        $exportCostPerM3 = $settings?->getExportCostPerM3() ?? EveConstants::DEFAULT_EXPORT_COST_PER_M3;

        $scanRowsByMarket = [];
        foreach ([OpportunityMarket::Jita, OpportunityMarket::Capital] as $scannedMarket) {
            if (OpportunityMarket::All !== $market && $market !== $scannedMarket) {
                continue;
            }
            if (OpportunityMarket::Capital === $scannedMarket && null === $capitalStructure) {
                continue;
            }
            $scanRowsByMarket[$scannedMarket->value] = $this->scanner->scan(
                self::ALL_CATEGORIES,
                null,
                null,
                OpportunityMarket::Jita === $scannedMarket ? self::JITA_VENUE : self::STRUCTURE_VENUE,
                OpportunityMarket::Capital === $scannedMarket ? $capitalStructure->id : null,
                $solarSystems->manufacturingSolarSystemId,
                $salesFees,
                $exportCostPerM3,
                $user,
                $solarSystems->reactionSolarSystemId,
            );
        }

        $freeSlots = $this->freeSlotsOf($user);
        $ranking = $this->ranker->rank($scanRowsByMarket, $this->contextFor($user, $scanRowsByMarket, $freeSlots, $capitalCap), $sort);

        return new ProductionOpportunitiesResult(
            $capitalCap,
            $capitalStructure?->name,
            $freeSlots,
            null !== $salesFees,
            $ranking->unknownProductCount,
            $ranking->rows,
        );
    }

    private function freeSlotsOf(User $user): ?FreeSlots
    {
        $manufacturing = 0;
        $reaction = 0;
        $knownCharacters = 0;
        foreach ($this->slotTracker->getSlotStatus($user)['characters'] as $character) {
            if ($character['skillsUnknown']) {
                continue;
            }
            ++$knownCharacters;
            $manufacturing += $this->unusedSlots($character['slots']['manufacturing']);
            $reaction += $this->unusedSlots($character['slots']['reaction']);
        }

        return 0 === $knownCharacters ? null : new FreeSlots($manufacturing, $reaction);
    }

    /**
     * @param array{used: int, max: int|null} $slots
     */
    private function unusedSlots(array $slots): int
    {
        return max(0, (int) $slots['max'] - $slots['used']);
    }

    /**
     * @param array<string, list<array<string, mixed>>> $scanRowsByMarket
     */
    private function contextFor(User $user, array $scanRowsByMarket, ?FreeSlots $freeSlots, ?int $capitalCap): OpportunityContext
    {
        $manufacturingBlueprintIds = [];
        $reactionBlueprintIds = [];
        foreach ($scanRowsByMarket as $scanRows) {
            foreach ($scanRows as $scanRow) {
                if (self::REACTION_ACTIVITY === $scanRow['activityType']) {
                    $reactionBlueprintIds[$scanRow['blueprintTypeId']] = true;
                } else {
                    $manufacturingBlueprintIds[$scanRow['blueprintTypeId']] = true;
                }
            }
        }

        $manufacturingBlueprintIds = array_keys($manufacturingBlueprintIds);
        $reactionBlueprintIds = array_keys($reactionBlueprintIds);
        $requiredSkills = $this->requiredSkillsOf($manufacturingBlueprintIds, $reactionBlueprintIds);

        return new OpportunityContext(
            $requiredSkills,
            $this->knownSkillLevelsOf($user),
            $this->skillNamesOf($requiredSkills),
            $this->baseTimesOf($manufacturingBlueprintIds, $reactionBlueprintIds),
            $freeSlots,
            $capitalCap,
        );
    }

    /**
     * @param list<int> $manufacturingBlueprintIds
     * @param list<int> $reactionBlueprintIds
     *
     * @return array<int, list<array{skillId: int, level: int}>>
     */
    private function requiredSkillsOf(array $manufacturingBlueprintIds, array $reactionBlueprintIds): array
    {
        return $this->skillRequirements->findSkillsForBlueprints($manufacturingBlueprintIds, IndustryActivityType::Manufacturing->value)
            + $this->skillRequirements->findSkillsForBlueprints($reactionBlueprintIds, IndustryActivityType::Reaction->value);
    }

    /**
     * @return list<array<int, int>>
     */
    private function knownSkillLevelsOf(User $user): array
    {
        $skillLevels = [];
        foreach ($user->getCharacters() as $character) {
            if (!$this->characterSkills->hasCachedSkills($character)) {
                continue;
            }
            $skillLevels[] = array_map(
                static fn ($skill): int => $skill->getLevel(),
                $this->characterSkills->findAllSkillsForCharacter($character),
            );
        }

        return $skillLevels;
    }

    /**
     * @param array<int, list<array{skillId: int, level: int}>> $requiredSkills
     *
     * @return array<int, string>
     */
    private function skillNamesOf(array $requiredSkills): array
    {
        $skillIds = [];
        foreach ($requiredSkills as $blueprintSkills) {
            foreach ($blueprintSkills as $skill) {
                $skillIds[$skill['skillId']] = true;
            }
        }

        return [] === $skillIds ? [] : $this->typeNames->findNames(array_keys($skillIds));
    }

    /**
     * @param list<int> $manufacturingBlueprintIds
     * @param list<int> $reactionBlueprintIds
     *
     * @return array<int, int>
     */
    private function baseTimesOf(array $manufacturingBlueprintIds, array $reactionBlueprintIds): array
    {
        $activities = $this->activities->findByTypeIdsAndActivityIds(
            array_merge($manufacturingBlueprintIds, $reactionBlueprintIds),
            [IndustryActivityType::Manufacturing->value, IndustryActivityType::Reaction->value],
        );

        $baseTimes = [];
        foreach ($manufacturingBlueprintIds as $blueprintTypeId) {
            $baseTimes[$blueprintTypeId] = ($activities[$blueprintTypeId.'-'.IndustryActivityType::Manufacturing->value] ?? null)?->getTime();
        }
        foreach ($reactionBlueprintIds as $blueprintTypeId) {
            $baseTimes[$blueprintTypeId] = ($activities[$blueprintTypeId.'-'.IndustryActivityType::Reaction->value] ?? null)?->getTime();
        }

        return array_filter($baseTimes, static fn (?int $seconds): bool => null !== $seconds);
    }
}
