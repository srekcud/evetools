<?php

declare(strict_types=1);

namespace App\Service\Industry\Blueprint;

use App\Constant\EveConstants;
use App\Entity\Character;
use App\Entity\OwnedBlueprint;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Repository\CachedAssetRepository;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\CorpAssetVisibilityRepository;
use App\Repository\OwnedBlueprintRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Service\ESI\CorporationDirectorAccess;
use App\Service\TypeNameResolver;

class BlueprintLibraryReader
{
    public const string STATE_KNOWN = 'known';
    public const string STATE_NO_DIRECTOR = 'no_director';
    public const string STATE_NO_VISIBILITY = 'no_visibility';

    public const string KIND_ORIGINAL = 'bpo';
    public const string KIND_COPY = 'bpc';
    public const string KIND_REACTION = 'bpr';

    private const array KIND_RANK = [self::KIND_ORIGINAL => 0, self::KIND_REACTION => 1, self::KIND_COPY => 2];

    public const string SCOPE_PERSONAL = 'personal';
    public const string SCOPE_CORPORATION = 'corporation';

    public const string UNKNOWN_TYPE_NAME = 'unknown_type';

    public function __construct(
        private readonly OwnedBlueprintRepository $ownedBlueprintRepository,
        private readonly CorpAssetVisibilityRepository $visibilityRepository,
        private readonly CorporationDirectorAccess $directorAccess,
        private readonly TypeNameResolver $typeNameResolver,
        private readonly CachedIndustryJobRepository $jobRepository,
        private readonly CachedAssetRepository $assetRepository,
        private readonly IndustryActivityRepository $activityRepository,
        private readonly IndustryActivityProductRepository $productRepository,
    ) {
    }

    public function readFor(User $user): BlueprintLibrary
    {
        $main = $user->getMainCharacter() ?? throw new \LogicException('Cannot read the blueprint library of a user without main character');
        $corporationId = $main->getCorporationId();

        $personal = $this->ownedBlueprintRepository->findByCharacters($user->getCharacters()->getValues());
        [$corporationState, $corporate] = $this->corporationBlueprints($corporationId, $user);

        $blueprints = [...$personal, ...$corporate];
        $blueprintTypeIds = array_values(array_unique(array_map(
            static fn (OwnedBlueprint $blueprint): int => $blueprint->getTypeId(),
            $blueprints,
        )));
        $products = $this->productRepository->findProductsForBlueprints($blueprintTypeIds, [
            IndustryActivityType::Manufacturing->value,
            IndustryActivityType::Reaction->value,
        ]);
        $names = $this->typeNameResolver->findNames(array_values(array_unique([
            ...$blueprintTypeIds,
            ...array_map(static fn (IndustryActivityProduct $product): int => $product->getProductTypeId(), array_values($products)),
        ])));

        $itemIdsInProgress = array_flip($this->jobRepository->findBlueprintItemIdsInProgress(array_map(
            static fn (OwnedBlueprint $blueprint): int => $blueprint->getItemId(),
            $blueprints,
        )));

        $divisionNames = $corporate === [] ? [] : $this->assetRepository->findDistinctDivisions($corporationId);
        $reactionFormulaTypeIds = $this->reactionFormulaTypeIdsAmong($blueprints);

        $rows = array_map(
            fn (OwnedBlueprint $blueprint): BlueprintLibraryRow => $this->rowOf($blueprint, $names, $divisionNames, $main, isset($itemIdsInProgress[$blueprint->getItemId()]), isset($reactionFormulaTypeIds[$blueprint->getTypeId()]), $products),
            $blueprints,
        );
        usort($rows, self::compareRows(...));

        return new BlueprintLibrary($corporationState, $rows);
    }

    /** @return array{string, list<OwnedBlueprint>} */
    private function corporationBlueprints(int $corporationId, User $user): array
    {
        if (!$this->directorAccess->isConnected($corporationId, [EveConstants::CORPORATION_BLUEPRINTS_SCOPE])) {
            return [self::STATE_NO_DIRECTOR, []];
        }

        $visibility = $this->visibilityRepository->findByCorporationId($corporationId);
        if ($visibility?->hidesEveryBlueprintFrom($user) === true) {
            return [self::STATE_NO_VISIBILITY, []];
        }

        return [
            self::STATE_KNOWN,
            array_values(array_filter(
                $this->ownedBlueprintRepository->findByCorporationId($corporationId),
                static fn (OwnedBlueprint $blueprint): bool => $visibility?->showsBlueprintIn($blueprint->getDivisionNumber(), $user) ?? true,
            )),
        ];
    }

    /**
     * @param list<OwnedBlueprint> $blueprints
     * @return array<int, true>
     */
    private function reactionFormulaTypeIdsAmong(array $blueprints): array
    {
        $reaction = IndustryActivityType::Reaction->value;
        $activities = $this->activityRepository->findByTypeIdsAndActivityIds(
            array_map(static fn (OwnedBlueprint $blueprint): int => $blueprint->getTypeId(), $blueprints),
            [$reaction],
        );

        $typeIds = [];
        foreach ($activities as $activity) {
            $typeIds[$activity->getTypeId()] = true;
        }

        return $typeIds;
    }

    /**
     * @param array<int, string> $names
     * @param array<int, string> $divisionNames
     * @param array<string, IndustryActivityProduct> $products
     */
    private function rowOf(OwnedBlueprint $blueprint, array $names, array $divisionNames, Character $main, bool $usedByRunningJob, bool $isReactionFormula, array $products): BlueprintLibraryRow
    {
        $character = $blueprint->getCharacter();
        $isOriginal = $blueprint->isOriginal();
        $divisionNumber = $blueprint->getDivisionNumber();
        $kind = match (true) {
            $isReactionFormula => self::KIND_REACTION,
            $isOriginal => self::KIND_ORIGINAL,
            default => self::KIND_COPY,
        };

        $activity = $kind === self::KIND_REACTION ? IndustryActivityType::Reaction : IndustryActivityType::Manufacturing;
        $productTypeId = ($products[$blueprint->getTypeId() . '-' . $activity->value] ?? null)?->getProductTypeId();

        return new BlueprintLibraryRow(
            $blueprint->getTypeId(),
            $names[$blueprint->getTypeId()] ?? self::UNKNOWN_TYPE_NAME,
            $kind,
            $blueprint->getMaterialEfficiency(),
            $blueprint->getTimeEfficiency(),
            $kind === self::KIND_COPY ? $blueprint->getRuns() : null,
            $character === null ? self::SCOPE_CORPORATION : self::SCOPE_PERSONAL,
            $character === null ? $main->getCorporationName() : $character->getName(),
            $blueprint->getLocationId(),
            $divisionNumber,
            $divisionNumber === null ? null : $divisionNames[$divisionNumber] ?? null,
            $isOriginal && $blueprint->getQuantity() > 0 ? $blueprint->getQuantity() : 1,
            $usedByRunningJob,
            $productTypeId,
            $productTypeId === null ? null : $names[$productTypeId] ?? self::UNKNOWN_TYPE_NAME,
        );
    }

    private static function compareRows(BlueprintLibraryRow $left, BlueprintLibraryRow $right): int
    {
        return [$left->name, self::KIND_RANK[$left->kind], $right->me]
            <=> [$right->name, self::KIND_RANK[$right->kind], $left->me];
    }
}
