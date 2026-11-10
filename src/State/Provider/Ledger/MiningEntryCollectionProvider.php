<?php

declare(strict_types=1);

namespace App\State\Provider\Ledger;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Ledger\MiningEntryResource;
use App\Entity\MiningEntry;
use App\Entity\User;
use App\Repository\MiningEntryRepository;
use App\Service\MiningValuation\MiningProfileUnitPriceReading;
use App\Service\MiningValuation\MiningProfileUnitPrices;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<MiningEntryResource>
 */
class MiningEntryCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly MiningEntryRepository $miningEntryRepository,
        private readonly RequestStack $requestStack,
        private readonly MiningProfileUnitPrices $unitPrices,
    ) {
    }

    /**
     * @return MiningEntryResource[]
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $request = $this->requestStack->getCurrentRequest();
        $days = (int) ($request?->query->get('days', 30) ?? 30);
        $typeId = $request?->query->get('typeId');
        $usage = $request?->query->get('usage');

        $to = new \DateTimeImmutable();
        $from = $to->modify("-{$days} days");

        // Don't exclude any usages from the list - user should see all entries
        // The exclusion logic only applies to STATS calculations, not to the list display
        $entries = $this->miningEntryRepository->findByUserAndDateRange($user, $from, $to, null, 500);

        // Apply additional filters
        if ($typeId !== null) {
            $typeId = (int) $typeId;
            $entries = array_filter($entries, fn(MiningEntry $e) => $e->getTypeId() === $typeId);
        }

        if ($usage !== null) {
            $entries = array_filter($entries, fn(MiningEntry $e) => $e->getUsage() === $usage);
        }

        $uniqueTypeIds = array_values(array_unique(array_map(fn(MiningEntry $e) => $e->getTypeId(), $entries)));
        $reading = $this->unitPrices->read($user, $uniqueTypeIds);

        return array_map(
            fn(MiningEntry $e) => $this->toResource($e, $reading),
            array_values($entries),
        );
    }

    private function toResource(MiningEntry $entry, MiningProfileUnitPriceReading $reading): MiningEntryResource
    {
        $resource = new MiningEntryResource();
        $resource->id = $entry->getId()?->toRfc4122() ?? '';
        $resource->characterId = $entry->getCharacterId();
        $resource->characterName = $entry->getCharacterName();
        $resource->date = $entry->getDate()->format('Y-m-d');
        $resource->typeId = $entry->getTypeId();
        $resource->typeName = $entry->getTypeName();
        $resource->solarSystemId = $entry->getSolarSystemId();
        $resource->solarSystemName = $entry->getSolarSystemName();
        $resource->quantity = $entry->getQuantity();
        $resource->unitPrice = $entry->getUnitPrice();
        $resource->totalValue = $entry->getTotalValue();
        $resource->usage = $entry->getUsage();
        $resource->linkedProjectId = $entry->getLinkedProjectId();
        $resource->linkedCorpProjectId = $entry->getLinkedCorpProjectId();
        $resource->syncedAt = $entry->getSyncedAt()->format('c');
        $resource->unitValue = $reading->unitPricesByTypeId[$entry->getTypeId()];
        $resource->valuationSource = $reading->valuationSource->toArray();

        if (isset($reading->pricesByTypeId[$entry->getTypeId()])) {
            $prices = $reading->pricesByTypeId[$entry->getTypeId()];
            $resource->compressedTypeId = $prices['compressedTypeId'];
            $resource->compressedTypeName = $prices['compressedTypeName'];
            $resource->compressedUnitPrice = $prices['compressedUnitPrice'];
            $resource->compressedEquivalentPrice = $prices['compressedEquivalentPrice'];
            $resource->structureUnitPrice = $prices['structureUnitPrice'];
            $resource->structureCompressedUnitPrice = $prices['structureCompressedUnitPrice'];
            $resource->structureCompressedEquivalentPrice = $prices['structureCompressedEquivalentPrice'];
            $resource->rawUnitPrice = $prices['rawUnitPrice'];
            $resource->reprocessYield = $prices['reprocessYield'] ?? null;
            $resource->reprocessValue = $prices['reprocessValue'] ?? null;
            $resource->structureReprocessValue = $prices['structureReprocessValue'] ?? null;
        }

        return $resource;
    }
}
