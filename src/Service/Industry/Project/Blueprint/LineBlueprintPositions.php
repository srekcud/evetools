<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Blueprint;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectLineBlueprint;
use App\Entity\OwnedBlueprint;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Repository\CachedAssetRepository;
use App\Repository\IndustryProjectLineBlueprintRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Assets\VisibleCorporationAssetsFinder;
use App\Service\Industry\Blueprint\OriginalBlueprintFinder;
use App\Service\Industry\Project\Action\ActionPlace;
use App\Service\Industry\Project\Action\ActionPlaceResolver;
use App\Service\Industry\Project\AssetStation;
use App\Service\Industry\Project\ProjectContainerService;
use App\Service\TypeNameResolver;

final readonly class LineBlueprintPositions
{
    private const array POSITION_RANK = [
        LineBlueprintPosition::HERE => 0,
        LineBlueprintPosition::ELSEWHERE => 1,
        LineBlueprintPosition::UNKNOWN => 2,
    ];
    private const int NO_RUNS_RANK = -1;

    public function __construct(
        private IndustryProjectLineBlueprintRepository $lineBlueprintRepository,
        private OriginalBlueprintFinder $blueprintFinder,
        private IndustryActivityProductRepository $activityProductRepository,
        private ActionPlaceResolver $placeResolver,
        private TypeNameResolver $typeNameResolver,
        private CachedAssetRepository $assetRepository,
        private VisibleCorporationAssetsFinder $visibleAssetsFinder,
        private ProjectContainerService $containerService,
    ) {
    }

    /**
     * @return list<LineBlueprintReading>
     */
    public function readingsOf(IndustryProject $project): array
    {
        $context = $this->contextOf($project);
        $visibleByAttacherId = [];

        $readings = [];
        foreach ($this->lineBlueprintRepository->findByProject($project) as $attachment) {
            $attacher = $attachment->getAttachedBy();
            $blueprint = null;
            if (null !== $attacher) {
                $attacherId = $attacher->getId()?->toRfc4122() ?? '';
                $visibleByAttacherId[$attacherId] ??= $this->visibleByItemId($attacher);
                $blueprint = $visibleByAttacherId[$attacherId][$attachment->getItemId()] ?? null;
            }

            $readings[] = $this->readingOf($context, $attachment->getBomLine(), $attachment->getItemId(), $blueprint, $attacher, $attachment);
        }

        return $readings;
    }

    /**
     * @return list<LineBlueprintReading>
     */
    public function candidatesOf(IndustryProject $project, User $viewer): array
    {
        $context = $this->contextOf($project);
        $attachedItemIds = [];
        foreach ($this->lineBlueprintRepository->findByProject($project) as $attachment) {
            $attachedItemIds[$attachment->getItemId()] = true;
        }

        $lines = array_values(array_filter(
            $project->getBomItems()->toArray(),
            static fn (IndustryProjectBomLine $line): bool => $line->isManufacturingOrReactionJob(),
        ));
        usort($lines, static fn (IndustryProjectBomLine $left, IndustryProjectBomLine $right): int => [$left->getTypeName(), $left->getId()->toRfc4122()] <=> [$right->getTypeName(), $right->getId()->toRfc4122()]);

        $visible = $this->blueprintFinder->visibleBlueprintsOf($viewer);
        $readings = [];
        foreach ($lines as $line) {
            $blueprintTypeId = $this->blueprintTypeOf($line);
            if (null === $blueprintTypeId) {
                continue;
            }

            $lineReadings = [];
            foreach ($visible as $blueprint) {
                if ($blueprint->getTypeId() === $blueprintTypeId && !isset($attachedItemIds[$blueprint->getItemId()])) {
                    $lineReadings[] = $this->readingOf($context, $line, $blueprint->getItemId(), $blueprint, $viewer, null);
                }
            }
            usort($lineReadings, static fn (LineBlueprintReading $left, LineBlueprintReading $right): int => [self::POSITION_RANK[$left->position->state], -($left->blueprint?->getRuns() ?? self::NO_RUNS_RANK), $left->itemId]
                <=> [self::POSITION_RANK[$right->position->state], -($right->blueprint?->getRuns() ?? self::NO_RUNS_RANK), $right->itemId]);

            array_push($readings, ...$lineReadings);
        }

        return $readings;
    }

    public function blueprintTypeOf(IndustryProjectBomLine $line): ?int
    {
        $activity = IndustryProjectBomLine::ACTIVITY_REACTION === $line->getActivityType() ? IndustryActivityType::Reaction : IndustryActivityType::Manufacturing;

        return $this->activityProductRepository->findBlueprintForProduct($line->getTypeId(), $activity->value)?->getTypeId();
    }

    /**
     * @return array<int, OwnedBlueprint>
     */
    public function visibleByItemId(User $user): array
    {
        $blueprintsByItemId = [];
        foreach ($this->blueprintFinder->visibleBlueprintsOf($user) as $blueprint) {
            $blueprintsByItemId[$blueprint->getItemId()] = $blueprint;
        }

        return $blueprintsByItemId;
    }

    /**
     * @return array{owner: User, locator: BlueprintStationLocator, stepPlaces: array<string, ActionPlace>}
     */
    private function contextOf(IndustryProject $project): array
    {
        return [
            'owner' => $project->getOwner(),
            'locator' => new BlueprintStationLocator($this->assetRepository, $this->visibleAssetsFinder, $this->containerService),
            'stepPlaces' => [],
        ];
    }

    /**
     * @param array{owner: User, locator: BlueprintStationLocator, stepPlaces: array<string, ActionPlace>} $context
     */
    private function readingOf(array &$context, IndustryProjectBomLine $line, int $itemId, ?OwnedBlueprint $blueprint, ?User $viewer, ?IndustryProjectLineBlueprint $attachment): LineBlueprintReading
    {
        $lineId = $line->getId()->toRfc4122();
        $stepPlace = $context['stepPlaces'][$lineId] ??= $this->placeResolver->ofStep($line, $context['owner']);

        $station = null;
        $reason = null;
        if (null === $viewer) {
            $reason = LineBlueprintPosition::REASON_ATTACHER_UNKNOWN;
        } elseif (null === $blueprint) {
            $reason = LineBlueprintPosition::REASON_BLUEPRINT_NOT_SYNCED;
        } else {
            $located = $context['locator']->stationOf($blueprint, $viewer);
            if ($located instanceof AssetStation) {
                $station = $located;
            } else {
                $reason = $located;
            }
        }

        $position = LineBlueprintPosition::of($stepPlace, $station, $reason);
        $blueprintTypeId = $blueprint?->getTypeId() ?? $this->blueprintTypeOf($line);
        $blueprintTypeName = null === $blueprintTypeId ? null : $this->typeNameResolver->findName($blueprintTypeId);

        return new LineBlueprintReading(
            $attachment,
            $line,
            $itemId,
            $blueprint,
            $blueprintTypeId,
            $blueprintTypeName,
            $position,
            $this->transferToMakeOf($position, $blueprintTypeId, $blueprintTypeName, $itemId),
        );
    }

    /**
     * @return array{typeId: int, typeName: string, itemId: int, quantity: int, from: array{locationId: int, name: string, solarSystemName: ?string}, to: array{locationId: int, name: ?string, solarSystemId: ?int, solarSystemName: ?string}}|null
     */
    private function transferToMakeOf(LineBlueprintPosition $position, ?int $blueprintTypeId, ?string $blueprintTypeName, int $itemId): ?array
    {
        $from = $position->blueprintStation;
        $to = $position->stepPlace;
        if (LineBlueprintPosition::ELSEWHERE !== $position->state || null === $from || null === $to->locationId || null === $blueprintTypeId || null === $blueprintTypeName) {
            return null;
        }

        return [
            'typeId' => $blueprintTypeId,
            'typeName' => $blueprintTypeName,
            'itemId' => $itemId,
            'quantity' => 1,
            'from' => ['locationId' => $from->locationId, 'name' => $from->name, 'solarSystemName' => $from->solarSystemName],
            'to' => ['locationId' => $to->locationId, 'name' => $to->name, 'solarSystemId' => $to->solarSystemId, 'solarSystemName' => $to->solarSystemName],
        ];
    }
}
