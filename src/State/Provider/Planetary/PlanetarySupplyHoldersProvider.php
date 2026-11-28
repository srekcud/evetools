<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Planetary\PlanetarySupplyHoldersResource;
use App\Constant\EveConstants;
use App\Dto\Assets\AssetScope;
use App\Entity\Character;
use App\Entity\User;
use App\Repository\AssetBrowseRepository;
use App\Repository\PlanetaryColonyRepository;
use App\Service\Planetary\PlanetaryInputNeedsCalculator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @phpstan-type HolderEntry array{characterId: int, characterName: string, status: string, unknownReason: string|null, assetsReadAt: string|null, stale: bool, quantities: list<array{typeId: int, quantity: int}>}
 *
 * @implements ProviderInterface<PlanetarySupplyHoldersResource>
 */
final readonly class PlanetarySupplyHoldersProvider implements ProviderInterface
{
    private const string STATUS_KNOWN = 'known';
    private const string STATUS_UNKNOWN = 'unknown';
    private const string REASON_MISSING_ASSETS_SCOPE = 'missing_assets_scope';
    private const string REASON_ASSETS_NEVER_READ = 'assets_never_read';
    private const int STALE_AFTER_SECONDS = 86400;

    public function __construct(
        private Security $security,
        private PlanetaryColonyRepository $colonyRepository,
        private PlanetaryInputNeedsCalculator $inputNeedsCalculator,
        private AssetBrowseRepository $assetBrowseRepository,
        private ClockInterface $clock,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlanetarySupplyHoldersResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Authentication required');
        }

        $homeLocationId = $user->getPlanetaryHomeLocationId();
        $feedTypeIds = null === $homeLocationId ? [] : $this->feedTypeIdsOf($user);
        $now = $this->clock->now();

        $entries = [];
        foreach ($user->getCharacters() as $character) {
            $entries[] = $this->entryOf($character, $homeLocationId, $feedTypeIds, $now);
        }
        usort($entries, static fn (array $left, array $right): int => strcmp($left['characterName'], $right['characterName']));

        $resource = new PlanetarySupplyHoldersResource();
        $resource->homeLocationId = $homeLocationId;
        $resource->homeLocationName = $user->getPlanetaryHomeLocationName();
        $resource->characters = $entries;

        return $resource;
    }

    /**
     * @return list<int>
     */
    private function feedTypeIdsOf(User $user): array
    {
        $typeIds = [];
        foreach ($this->inputNeedsCalculator->calculate($this->colonyRepository->findByUser($user))['launchpads'] as $launchpad) {
            foreach ($launchpad['feeds'] as $feed) {
                $typeIds[$feed['typeId']] = $feed['typeId'];
            }
        }

        return array_values($typeIds);
    }

    /**
     * @param list<int> $feedTypeIds
     * @return HolderEntry
     */
    private function entryOf(Character $character, ?int $homeLocationId, array $feedTypeIds, \DateTimeImmutable $now): array
    {
        $readAt = $character->getLastSyncAt();
        $unknownReason = match (true) {
            true !== $character->getEveToken()?->hasScope(EveConstants::CHARACTER_ASSETS_SCOPE) => self::REASON_MISSING_ASSETS_SCOPE,
            null === $readAt => self::REASON_ASSETS_NEVER_READ,
            default => null,
        };
        $isKnown = null === $unknownReason;

        return [
            'characterId' => $character->getEveCharacterId(),
            'characterName' => $character->getName(),
            'status' => $isKnown ? self::STATUS_KNOWN : self::STATUS_UNKNOWN,
            'unknownReason' => $unknownReason,
            'assetsReadAt' => $readAt?->format('c'),
            'stale' => null !== $readAt && $isKnown && $now->getTimestamp() - $readAt->getTimestamp() > self::STALE_AFTER_SECONDS,
            'quantities' => $isKnown && null !== $homeLocationId ? $this->quantitiesOf($character, $homeLocationId, $feedTypeIds) : [],
        ];
    }

    /**
     * @param list<int> $feedTypeIds
     * @return list<array{typeId: int, quantity: int}>
     */
    private function quantitiesOf(Character $character, int $homeLocationId, array $feedTypeIds): array
    {
        $byLocation = $this->assetBrowseRepository->quantitiesByRootLocation(
            AssetScope::forCharacter($character->getId()?->toRfc4122() ?? throw new \LogicException('A loaded character is persisted')),
            [$homeLocationId],
            $feedTypeIds,
        );

        $quantities = [];
        foreach ($byLocation[$homeLocationId] ?? [] as $typeId => $quantity) {
            if ($quantity > 0) {
                $quantities[] = ['typeId' => $typeId, 'quantity' => $quantity];
            }
        }

        return $quantities;
    }
}
