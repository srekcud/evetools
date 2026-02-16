<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\Character;
use App\Entity\Notification;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\PlanetaryRoute;
use App\Repository\PlanetaryColonyRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\ESI\PlanetaryService;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Notification\NotificationDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class PlanetarySyncService
{
    public function __construct(
        private readonly PlanetaryService $planetaryService,
        private readonly PlanetaryColonyRepository $colonyRepository,
        private readonly MapSolarSystemRepository $solarSystemRepository,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MercurePublisherService $mercurePublisher,
        private readonly NotificationDispatcher $notificationDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Sync all planetary colonies for a character.
     *
     * @return int Number of colonies synced
     */
    public function syncCharacterColonies(Character $character): int
    {
        $userId = $character->getUser()?->getId()?->toRfc4122();

        if ($userId !== null) {
            $this->mercurePublisher->syncStarted($userId, 'planetary', 'Fetching planetary colonies...');
        }

        try {
            $colonies = $this->planetaryService->fetchColonies($character);

            if (empty($colonies)) {
                // Remove all colonies for this character (they were deleted in-game)
                $existingColonies = $this->colonyRepository->findByCharacter($character);
                foreach ($existingColonies as $orphan) {
                    $this->entityManager->remove($orphan);
                    $this->logger->info('Removed orphaned colony', [
                        'characterName' => $character->getName(),
                        'planetId' => $orphan->getPlanetId(),
                        'solarSystem' => $orphan->getSolarSystemName(),
                    ]);
                }
                if (!empty($existingColonies)) {
                    $this->entityManager->flush();
                }

                if ($userId !== null) {
                    $this->mercurePublisher->syncCompleted($userId, 'planetary', 'No planetary colonies', [
                        'total' => 0,
                    ]);
                }
                return 0;
            }

            if ($userId !== null) {
                $this->mercurePublisher->syncProgress($userId, 'planetary', 20, sprintf('%d colonies found...', count($colonies)));
            }

            $syncedCount = 0;
            $totalColonies = count($colonies);

            foreach ($colonies as $index => $colonyData) {
                $planetId = $colonyData['planet_id'];

                // Fetch colony detail (pins + routes)
                $detail = $this->planetaryService->fetchColonyDetail($character, $planetId);

                // Find existing colony or create new
                $colony = $this->colonyRepository->findByCharacterAndPlanet($character, $planetId);

                if ($colony === null) {
                    $colony = new PlanetaryColony();
                    $colony->setCharacter($character);
                    $colony->setPlanetId($planetId);
                    $this->entityManager->persist($colony);
                }

                // Update colony fields from colonies list
                $colony->setPlanetType($colonyData['planet_type'] ?? 'unknown');
                $colony->setSolarSystemId($colonyData['solar_system_id']);
                $colony->setUpgradeLevel($colonyData['upgrade_level'] ?? 0);
                $colony->setNumPins($colonyData['num_pins'] ?? 0);
                $colony->setLastUpdate(new \DateTimeImmutable($colonyData['last_update']));
                $colony->setCachedAt(new \DateTimeImmutable());

                // Resolve solar system name from SDE
                $solarSystem = $this->solarSystemRepository->findBySolarSystemId($colonyData['solar_system_id']);
                if ($solarSystem !== null) {
                    $colony->setSolarSystemName($solarSystem->getSolarSystemName());
                }

                // Resolve planet name from ESI (public endpoint, only if not already set)
                if ($colony->getPlanetName() === null) {
                    try {
                        $planetInfo = $this->planetaryService->fetchPlanetInfo($planetId);
                        $colony->setPlanetName($planetInfo['name']);
                    } catch (\Throwable $e) {
                        $this->logger->warning('Failed to resolve planet name', [
                            'planetId' => $planetId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Clear existing pins and routes (orphanRemoval handles deletion)
                $colony->clearPins();
                $colony->clearRoutes();

                // Flush to trigger orphan removal before adding new pins/routes
                $this->entityManager->flush();

                // Process pins from detail
                $this->processPins($colony, $detail['pins'] ?? []);

                // Process routes from detail
                $this->processRoutes($colony, $detail['routes'] ?? []);

                $syncedCount++;

                // Progress update
                if ($userId !== null) {
                    $progress = 20 + (int) (($index + 1) / $totalColonies * 70);
                    $this->mercurePublisher->syncProgress(
                        $userId,
                        'planetary',
                        $progress,
                        sprintf('Colony %d/%d processed...', $index + 1, $totalColonies),
                    );
                }

                // Throttle between colony detail calls to avoid ESI rate limits
                if ($index < $totalColonies - 1) {
                    usleep(500_000);
                }
            }

            // Cleanup: remove colonies no longer present in ESI
            $esiPlanetIds = array_column($colonies, 'planet_id');
            $existingColonies = $this->colonyRepository->findByCharacter($character);
            foreach ($existingColonies as $existing) {
                if (!in_array($existing->getPlanetId(), $esiPlanetIds, true)) {
                    $this->entityManager->remove($existing);
                    $this->logger->info('Removed orphaned colony', [
                        'characterName' => $character->getName(),
                        'planetId' => $existing->getPlanetId(),
                        'solarSystem' => $existing->getSolarSystemName(),
                    ]);
                }
            }

            $this->entityManager->flush();

            // Check for expiring/expired extractors and send alerts
            $this->checkExpiringExtractors($character, $userId);

            $this->logger->info('Planetary colonies synced', [
                'characterName' => $character->getName(),
                'colonyCount' => $syncedCount,
            ]);

            if ($userId !== null) {
                $this->mercurePublisher->syncCompleted($userId, 'planetary', sprintf('%d colonies synced', $syncedCount), [
                    'total' => $syncedCount,
                ]);
            }

            return $syncedCount;
        } catch (\Throwable $e) {
            if ($userId !== null) {
                $this->mercurePublisher->syncError($userId, 'planetary', $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * @param array<array<string, mixed>> $pinsData
     */
    private function processPins(PlanetaryColony $colony, array $pinsData): void
    {
        // Collect type IDs to resolve names in batch
        $typeIds = array_unique(array_column($pinsData, 'type_id'));
        $typeNames = [];
        if (!empty($typeIds)) {
            $types = $this->invTypeRepository->findByTypeIds($typeIds);
            foreach ($types as $typeId => $type) {
                $typeNames[$typeId] = $type->getTypeName();
            }
        }

        foreach ($pinsData as $pinData) {
            $pin = new PlanetaryPin();
            $pin->setPinId((int) $pinData['pin_id']);
            $pin->setTypeId($pinData['type_id']);
            $pin->setTypeName($typeNames[$pinData['type_id']] ?? null);
            $pin->setLatitude($pinData['latitude'] ?? null);
            $pin->setLongitude($pinData['longitude'] ?? null);

            // Factory details: schematic_id can be at top-level or nested in factory_details
            if (isset($pinData['schematic_id'])) {
                $pin->setSchematicId($pinData['schematic_id']);
            } elseif (isset($pinData['factory_details']['schematic_id'])) {
                $pin->setSchematicId($pinData['factory_details']['schematic_id']);
            }

            // Timestamps
            if (isset($pinData['install_time'])) {
                $pin->setInstallTime(new \DateTimeImmutable($pinData['install_time']));
            }
            if (isset($pinData['expiry_time'])) {
                $pin->setExpiryTime(new \DateTimeImmutable($pinData['expiry_time']));
            }
            if (isset($pinData['last_cycle_start'])) {
                $pin->setLastCycleStart(new \DateTimeImmutable($pinData['last_cycle_start']));
            }

            // Extractor details
            if (isset($pinData['extractor_details'])) {
                $extractor = $pinData['extractor_details'];
                $pin->setExtractorProductTypeId($extractor['product_type_id'] ?? null);
                $pin->setExtractorCycleTime($extractor['cycle_time'] ?? null);
                $pin->setExtractorQtyPerCycle($extractor['qty_per_cycle'] ?? null);
                $pin->setExtractorHeadRadius($extractor['head_radius'] ?? null);
                $pin->setExtractorNumHeads(isset($extractor['heads']) ? count($extractor['heads']) : null);
            }

            // Contents
            if (isset($pinData['contents']) && !empty($pinData['contents'])) {
                $pin->setContents($pinData['contents']);
            }

            $colony->addPin($pin);
        }
    }

    /**
     * @param array<array<string, mixed>> $routesData
     */
    private function processRoutes(PlanetaryColony $colony, array $routesData): void
    {
        foreach ($routesData as $routeData) {
            $route = new PlanetaryRoute();
            $route->setRouteId($routeData['route_id']);
            $route->setSourcePinId((int) $routeData['source_pin_id']);
            $route->setDestinationPinId((int) $routeData['destination_pin_id']);
            $route->setContentTypeId($routeData['content_type_id']);
            $route->setQuantity((float) $routeData['quantity']);

            if (isset($routeData['waypoints']) && !empty($routeData['waypoints'])) {
                $route->setWaypoints($routeData['waypoints']);
            }

            $colony->addRoute($route);
        }
    }

    private function checkExpiringExtractors(Character $character, ?string $userId): void
    {
        if ($userId === null) {
            return;
        }

        $user = $character->getUser();
        $now = new \DateTimeImmutable();
        $threshold = $now->modify('+2 hours');

        $colonies = $this->colonyRepository->findByCharacter($character);
        foreach ($colonies as $colony) {
            foreach ($colony->getPins() as $pin) {
                if ($pin->getExtractorProductTypeId() === null) {
                    continue;
                }
                $expiry = $pin->getExpiryTime();
                if ($expiry === null) {
                    continue;
                }

                $planetName = $colony->getPlanetName() ?? $colony->getSolarSystemName() ?? 'Unknown';
                $productName = $this->resolveTypeName($pin->getExtractorProductTypeId());

                if ($expiry < $now) {
                    // Keep existing Mercure alert for backward compat
                    $this->mercurePublisher->publishAlert($userId, 'planetary-expiry', [
                        'level' => 'expired',
                        'planetName' => $planetName,
                        'planetType' => $colony->getPlanetType(),
                        'productName' => $productName,
                        'characterName' => $character->getName(),
                        'colonyId' => $colony->getId()?->toRfc4122(),
                    ]);

                    // Dispatch notification
                    if ($user !== null) {
                        $this->notificationDispatcher->dispatch(
                            $user,
                            Notification::CATEGORY_PLANETARY,
                            Notification::LEVEL_ERROR,
                            'Extractor expired',
                            sprintf('%s extractor on %s has expired (%s)', $productName, $planetName, $character->getName()),
                            [
                                'colonyId' => $colony->getId()?->toRfc4122(),
                                'planetName' => $planetName,
                                'productName' => $productName,
                                'characterName' => $character->getName(),
                            ],
                            '/planetary',
                        );
                    }
                } elseif ($expiry < $threshold) {
                    $minutesRemaining = (int)(($expiry->getTimestamp() - $now->getTimestamp()) / 60);
                    $level = $minutesRemaining < 30 ? 'critical' : 'warning';

                    // Keep existing Mercure alert for backward compat
                    $this->mercurePublisher->publishAlert($userId, 'planetary-expiry', [
                        'level' => $level,
                        'minutesRemaining' => $minutesRemaining,
                        'planetName' => $planetName,
                        'planetType' => $colony->getPlanetType(),
                        'productName' => $productName,
                        'characterName' => $character->getName(),
                        'colonyId' => $colony->getId()?->toRfc4122(),
                    ]);

                    // Dispatch notification
                    if ($user !== null) {
                        $notifLevel = $minutesRemaining < 30 ? Notification::LEVEL_CRITICAL : Notification::LEVEL_WARNING;
                        $this->notificationDispatcher->dispatch(
                            $user,
                            Notification::CATEGORY_PLANETARY,
                            $notifLevel,
                            'Extractor expiring soon',
                            sprintf('%s extractor on %s expires in %d min (%s)', $productName, $planetName, $minutesRemaining, $character->getName()),
                            [
                                'colonyId' => $colony->getId()?->toRfc4122(),
                                'planetName' => $planetName,
                                'productName' => $productName,
                                'minutesRemaining' => $minutesRemaining,
                                'characterName' => $character->getName(),
                            ],
                            '/planetary',
                        );
                    }
                }
            }
        }
    }

    private function resolveTypeName(int $typeId): string
    {
        $type = $this->invTypeRepository->find($typeId);
        return $type?->getTypeName() ?? "Type #{$typeId}";
    }
}
