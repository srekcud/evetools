<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\Character;
use App\Entity\Notification;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\PlanetaryRoute;
use App\Enum\ExtractorExpiryLevel;
use App\Repository\PlanetaryColonyRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\ESI\PlanetaryService;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Planetary\PlanetaryExpiryThresholdResolver;
use App\Service\Sde\SdeCelestialNameResolver;
use App\Service\TypeNameResolver;
use App\Service\UnknownTypeName;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

class PlanetarySyncService
{
    public function __construct(
        private readonly PlanetaryService $planetaryService,
        private readonly PlanetaryColonyRepository $colonyRepository,
        private readonly MapSolarSystemRepository $solarSystemRepository,
        private readonly SdeCelestialNameResolver $celestialNameResolver,
        private readonly TypeNameResolver $typeNameResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly MercurePublisherService $mercurePublisher,
        private readonly NotificationDispatcher $notificationDispatcher,
        private readonly LoggerInterface $logger,
        private readonly PlanetaryExpiryThresholdResolver $expiryThresholdResolver,
        private readonly ClockInterface $clock,
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
                    $colony->setPlanetName($this->resolvePlanetName($planetId));
                }

                $notifiedLevels = $this->notifiedLevelsByPinId($colony);

                // Clear existing pins and routes (orphanRemoval handles deletion)
                $colony->clearPins();
                $colony->clearRoutes();

                // Flush to trigger orphan removal before adding new pins/routes
                $this->entityManager->flush();

                // Process pins from detail
                $this->processPins($colony, $detail['pins'] ?? [], $notifiedLevels);

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
     * @param array<int, array{expiryTime: \DateTimeImmutable, level: ExtractorExpiryLevel}> $notifiedLevels
     */
    private function processPins(PlanetaryColony $colony, array $pinsData, array $notifiedLevels): void
    {
        // Collect type IDs to resolve names in batch
        $typeIds = array_unique(array_column($pinsData, 'type_id'));
        $typeNames = $this->typeNameResolver->findNames($typeIds);

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

            $remembered = $notifiedLevels[$pin->getPinId()] ?? null;
            if ($remembered !== null && $remembered['expiryTime'] == $pin->getExpiryTime()) {
                $pin->setExpiryNotifiedLevel($remembered['level']);
            }

            $colony->addPin($pin);
        }
    }

    private function resolvePlanetName(int $planetId): ?string
    {
        $sdeName = $this->celestialNameResolver->planetName($planetId);
        if ($sdeName !== null) {
            return $sdeName;
        }

        $this->logger->warning('Planet missing from the SDE, read from ESI', ['planetId' => $planetId]);

        try {
            return $this->planetaryService->fetchPlanetInfo($planetId)['name'];
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to resolve planet name', [
                'planetId' => $planetId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array<int, array{expiryTime: \DateTimeImmutable, level: ExtractorExpiryLevel}>
     */
    private function notifiedLevelsByPinId(PlanetaryColony $colony): array
    {
        $notifiedLevels = [];
        foreach ($colony->getPins() as $pin) {
            $expiryTime = $pin->getExpiryTime();
            $level = $pin->getExpiryNotifiedLevel();
            if ($expiryTime !== null && $level !== null) {
                $notifiedLevels[$pin->getPinId()] = ['expiryTime' => $expiryTime, 'level' => $level];
            }
        }

        return $notifiedLevels;
    }

    private function claimNotification(PlanetaryPin $pin, ExtractorExpiryLevel $level): bool
    {
        if (!$level->isHigherThan($pin->getExpiryNotifiedLevel())) {
            return false;
        }

        $pin->setExpiryNotifiedLevel($level);
        $this->entityManager->flush();

        return true;
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
        $user = $character->getUser();
        if ($userId === null || $user === null) {
            return;
        }

        $now = $this->clock->now();
        $threshold = $now->modify(sprintf('+%d minutes', $this->expiryThresholdResolver->minutesFor($user)));

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
                $productTypeId = $pin->getExtractorProductTypeId();
                $productName = $this->typeNameResolver->findName($productTypeId);
                $productLabel = $productName ?? UnknownTypeName::label($productTypeId);

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
                    if ($this->claimNotification($pin, ExtractorExpiryLevel::Expired)) {
                        $this->notificationDispatcher->dispatch(
                            $user,
                            Notification::CATEGORY_PLANETARY,
                            Notification::LEVEL_ERROR,
                            'Extractor expired',
                            sprintf('%s extractor on %s has expired (%s)', $productLabel, $planetName, $character->getName()),
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
                    $memoryLevel = $minutesRemaining < 30 ? ExtractorExpiryLevel::Critical : ExtractorExpiryLevel::Warning;
                    if ($this->claimNotification($pin, $memoryLevel)) {
                        $notifLevel = $minutesRemaining < 30 ? Notification::LEVEL_CRITICAL : Notification::LEVEL_WARNING;
                        $this->notificationDispatcher->dispatch(
                            $user,
                            Notification::CATEGORY_PLANETARY,
                            $notifLevel,
                            'Extractor expiring soon',
                            sprintf('%s extractor on %s expires in %d min (%s)', $productLabel, $planetName, $minutesRemaining, $character->getName()),
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
}
