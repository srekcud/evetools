<?php

declare(strict_types=1);

namespace App\Service\Industry\Blueprint;

use App\Entity\Character;
use App\Entity\OwnedBlueprint;
use App\Entity\User;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\CharacterRepository;
use App\Repository\CorpAssetVisibilityRepository;
use App\Repository\OwnedBlueprintRepository;
use App\Service\TypeNameResolver;

class BlueprintLibraryReader
{
    public const string STATE_KNOWN = 'known';
    public const string STATE_NO_DIRECTOR = 'no_director';
    public const string STATE_NO_VISIBILITY = 'no_visibility';

    public const string KIND_ORIGINAL = 'bpo';
    public const string KIND_COPY = 'bpc';

    public const string SCOPE_PERSONAL = 'personal';
    public const string SCOPE_CORPORATION = 'corporation';

    public const string UNKNOWN_TYPE_NAME = 'unknown_type';

    public function __construct(
        private readonly OwnedBlueprintRepository $ownedBlueprintRepository,
        private readonly CorpAssetVisibilityRepository $visibilityRepository,
        private readonly CharacterRepository $characterRepository,
        private readonly TypeNameResolver $typeNameResolver,
        private readonly CachedIndustryJobRepository $jobRepository,
    ) {
    }

    public function readFor(User $user): BlueprintLibrary
    {
        $main = $user->getMainCharacter() ?? throw new \LogicException('Cannot read the blueprint library of a user without main character');
        $corporationId = $main->getCorporationId();

        $personal = $this->ownedBlueprintRepository->findByCharacters($user->getCharacters()->getValues());
        [$corporationState, $corporate] = $this->corporationBlueprints($corporationId, $user);

        $blueprints = [...$personal, ...$corporate];
        $names = $this->typeNameResolver->findNames(array_values(array_unique(array_map(
            static fn (OwnedBlueprint $blueprint): int => $blueprint->getTypeId(),
            $blueprints,
        ))));

        $itemIdsInProgress = array_flip($this->jobRepository->findBlueprintItemIdsInProgress(array_map(
            static fn (OwnedBlueprint $blueprint): int => $blueprint->getItemId(),
            $blueprints,
        )));

        $rows = array_map(
            fn (OwnedBlueprint $blueprint): BlueprintLibraryRow => $this->rowOf($blueprint, $names, $main, isset($itemIdsInProgress[$blueprint->getItemId()])),
            $blueprints,
        );
        usort($rows, self::compareRows(...));

        return new BlueprintLibrary($corporationState, $rows);
    }

    /** @return array{string, list<OwnedBlueprint>} */
    private function corporationBlueprints(int $corporationId, User $user): array
    {
        if ($this->characterRepository->findWithCorpBlueprintsAccess($corporationId) === null) {
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

    /** @param array<int, string> $names */
    private function rowOf(OwnedBlueprint $blueprint, array $names, Character $main, bool $usedByRunningJob): BlueprintLibraryRow
    {
        $character = $blueprint->getCharacter();
        $isOriginal = $blueprint->isOriginal();

        return new BlueprintLibraryRow(
            $blueprint->getTypeId(),
            $names[$blueprint->getTypeId()] ?? self::UNKNOWN_TYPE_NAME,
            $isOriginal ? self::KIND_ORIGINAL : self::KIND_COPY,
            $blueprint->getMaterialEfficiency(),
            $blueprint->getTimeEfficiency(),
            $isOriginal ? null : $blueprint->getRuns(),
            $character === null ? self::SCOPE_CORPORATION : self::SCOPE_PERSONAL,
            $character === null ? $main->getCorporationName() : $character->getName(),
            $blueprint->getLocationId(),
            $blueprint->getDivisionNumber(),
            $isOriginal && $blueprint->getQuantity() > 0 ? $blueprint->getQuantity() : 1,
            $usedByRunningJob,
        );
    }

    private static function compareRows(BlueprintLibraryRow $left, BlueprintLibraryRow $right): int
    {
        return [$left->name, $left->kind === self::KIND_ORIGINAL ? 0 : 1, $right->me]
            <=> [$right->name, $right->kind === self::KIND_ORIGINAL ? 0 : 1, $left->me];
    }
}
