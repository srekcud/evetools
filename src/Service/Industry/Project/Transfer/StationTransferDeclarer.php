<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Transfer;

use App\Dto\Transfer\TransferEndpoint;
use App\Dto\Transfer\TransferLine;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStationTransfer;
use App\Entity\User;
use App\Exception\InvalidStationTransferException;
use App\Repository\CharacterRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\Industry\Project\ProjectContainerReading;
use App\Service\Industry\Project\ProjectContainerReadings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class StationTransferDeclarer
{
    public function __construct(
        private IndustryProjectService $projectService,
        private MapSolarSystemRepository $solarSystemRepository,
        private CharacterRepository $characterRepository,
        private ProjectContainerReadings $containerReadings,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<IndustryProjectStationTransfer>
     */
    public function declare(IndustryProject $project, User $declarer, StationTransferRequest $request, \DateTimeImmutable $declaredAt): array
    {
        $quantityByTypeId = $this->quantityByTypeIdOf($project, $request->lines);
        $this->assertSolarSystemsExist($request);
        $this->assertCarrierBelongsTo($declarer, $request->carrierCharacterId);

        if (null !== $request->from->locationId ? $request->from->locationId === $request->to->locationId : (null === $request->to->locationId && $request->from->solarSystemId === $request->to->solarSystemId)) {
            throw new InvalidStationTransferException('The departure and the arrival are the same place');
        }

        $hub = $this->projectService->favoriteSystemsOf($project->getOwner())->courierHub
            ?? throw new \LogicException('The favorite systems of a project owner always carry a courier hub.');
        $routeLegs = TransferRoute::legs($request->from->solarSystemId, $request->to->solarSystemId, $hub->solarSystemId);
        if ([] === $routeLegs) {
            throw new InvalidStationTransferException('Jita to Jita is not a transfer');
        }

        $quantityByLocationAndType = $this->quantityByLocationAndTypeOf($project);
        $routeId = Uuid::v7();
        $lastIndex = \count($routeLegs) - 1;
        $legs = [];
        foreach ($routeLegs as $legIndex => $routeLeg) {
            $from = new TransferEndpoint($routeLeg->fromSolarSystemId, 0 === $legIndex ? $request->from->locationId : null);
            $to = new TransferEndpoint($routeLeg->toSolarSystemId, $legIndex === $lastIndex ? $request->to->locationId : null);
            $lines = [];
            foreach ($quantityByTypeId as $typeId => $quantity) {
                $lines[] = new TransferLine($typeId, $quantity, $this->baselineOf($quantityByLocationAndType, $from->locationId, $typeId), $this->baselineOf($quantityByLocationAndType, $to->locationId, $typeId));
            }

            $leg = new IndustryProjectStationTransfer(
                $project,
                $declarer,
                $routeId,
                $legIndex,
                $routeLeg->carrierKind,
                $from,
                $to,
                $lines,
                $declaredAt,
                0 === $legIndex ? $request->carrierCharacterId : null,
                0 === $legIndex ? $request->contractId : null,
            );
            $this->entityManager->persist($leg);
            $legs[] = $leg;
        }

        return $legs;
    }

    /**
     * @param list<array{typeId: int, quantity: int}> $lines
     *
     * @return array<int, int>
     */
    private function quantityByTypeIdOf(IndustryProject $project, array $lines): array
    {
        $bomTypeIds = [];
        foreach ($project->getBomItems() as $bomLine) {
            $bomTypeIds[$bomLine->getTypeId()] = true;
        }

        $quantityByTypeId = [];
        foreach ($lines as $line) {
            if ($line['quantity'] < 1) {
                throw new InvalidStationTransferException('A line needs a quantity of at least 1');
            }
            if (!isset($bomTypeIds[$line['typeId']])) {
                throw new InvalidStationTransferException(\sprintf('Type %d is not in the bill of materials of the project', $line['typeId']));
            }
            $quantityByTypeId[$line['typeId']] = ($quantityByTypeId[$line['typeId']] ?? 0) + $line['quantity'];
        }

        if ([] === $quantityByTypeId) {
            throw new InvalidStationTransferException('A transfer needs at least one line');
        }

        return $quantityByTypeId;
    }

    private function assertSolarSystemsExist(StationTransferRequest $request): void
    {
        foreach ([$request->from->solarSystemId, $request->to->solarSystemId] as $solarSystemId) {
            if (null === $this->solarSystemRepository->findBySolarSystemId($solarSystemId)) {
                throw new InvalidStationTransferException(\sprintf('Solar system %d is unknown', $solarSystemId));
            }
        }
    }

    private function assertCarrierBelongsTo(User $declarer, ?int $carrierCharacterId): void
    {
        if (null === $carrierCharacterId) {
            return;
        }

        if ($this->characterRepository->findByEveCharacterId($carrierCharacterId)?->getUser() !== $declarer) {
            throw new InvalidStationTransferException('The carrier is not a character of the declarer');
        }
    }

    /**
     * @return array<int, array<int, int>> known quantities by location id, by type id
     */
    private function quantityByLocationAndTypeOf(IndustryProject $project): array
    {
        $quantities = [];
        foreach ($this->containerReadings->of($project) as $reading) {
            if (null === $reading->locationId || null === $reading->contents) {
                continue;
            }
            $quantities[$reading->locationId] = $this->quantityByTypeIdOfReading($reading);
        }

        return $quantities;
    }

    /**
     * @return array<int, int>
     */
    private function quantityByTypeIdOfReading(ProjectContainerReading $reading): array
    {
        $quantityByTypeId = [];
        foreach ($reading->contents ?? [] as $content) {
            $quantityByTypeId[$content->typeId] = $content->quantity;
        }

        return $quantityByTypeId;
    }

    /**
     * @param array<int, array<int, int>> $quantityByLocationAndType
     */
    private function baselineOf(array $quantityByLocationAndType, ?int $locationId, int $typeId): ?int
    {
        if (null === $locationId || !isset($quantityByLocationAndType[$locationId])) {
            return null;
        }

        return $quantityByLocationAndType[$locationId][$typeId] ?? 0;
    }
}
