<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Entity\Character;
use App\Entity\Notification;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Repository\PlanetaryColonyRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Notification\NotificationDispatcher;
use Symfony\Component\Clock\ClockInterface;

final readonly class PlanetaryAlertEvaluator
{
    private const int STORAGE_FACILITIES_GROUP_ID = 1029;
    private const float STORAGE_FULL_RATIO = 0.95;
    private const int SECONDS_PER_MINUTE = 60;
    private const string PLANETARY_ROUTE = '/planetary';
    private const string KIND_LAUNCHPAD_EMPTY = 'launchpad_empty';
    private const string KIND_STORAGE_FULL = 'storage_full';
    private const string KIND_DATA_STALE = 'data_stale';

    public function __construct(
        private PlanetaryColonyRepository $colonyRepository,
        private PlanetaryInputNeedsCalculator $inputNeedsCalculator,
        private InvTypeRepository $invTypeRepository,
        private PlanetaryExpiryThresholdResolver $expiryThresholdResolver,
        private NotificationRepository $notificationRepository,
        private NotificationDispatcher $notificationDispatcher,
        private ClockInterface $clock,
    ) {
    }

    public function evaluate(Character $character): void
    {
        $user = $character->getUser();
        if ($user === null) {
            return;
        }

        $colonies = $this->colonyRepository->findByCharacter($character);
        if ($colonies === []) {
            return;
        }

        $now = $this->clock->now();
        $windowMinutes = $this->expiryThresholdResolver->minutesFor($user);
        $coloniesById = $this->indexById($colonies);
        $sentNotifications = $this->notificationRepository->findByUserAndCategorySince(
            $user,
            Notification::CATEGORY_PLANETARY,
            $this->earliestLastUpdate($colonies),
        );

        $alerts = [
            ...$this->launchpadAlerts($colonies, $coloniesById, $character, $now, $windowMinutes),
            ...$this->storageAlerts($colonies, $character),
            ...$this->staleAlerts($colonies, $character, $now, $windowMinutes),
        ];

        foreach ($alerts as $alert) {
            if ($this->alreadySentDuringEpisode($sentNotifications, $alert['data'], $alert['colony'])) {
                continue;
            }

            $this->notificationDispatcher->dispatch(
                $user,
                Notification::CATEGORY_PLANETARY,
                $alert['level'],
                $alert['title'],
                $alert['message'],
                $alert['data'],
                self::PLANETARY_ROUTE,
            );
        }
    }

    /**
     * @param PlanetaryColony[]            $colonies
     * @param array<string, PlanetaryColony> $coloniesById
     * @return list<array{level: string, title: string, message: string, data: array<string, string>, colony: PlanetaryColony}>
     */
    private function launchpadAlerts(array $colonies, array $coloniesById, Character $character, \DateTimeImmutable $now, int $windowMinutes): array
    {
        $alerts = [];
        $limit = $now->modify(sprintf('+%d minutes', $windowMinutes));

        foreach ($this->inputNeedsCalculator->calculate($colonies)['launchpads'] as $launchpad) {
            $colony = $coloniesById[$launchpad['colonyId']];
            $emptyAt = $this->closestEmptyAt($launchpad['feeds']);
            if ($emptyAt === null) {
                continue;
            }

            $emptyAtMoment = new \DateTimeImmutable($emptyAt);
            if ($emptyAtMoment > $limit) {
                continue;
            }

            $planetName = $this->planetNameOf($colony);
            $alreadyEmpty = $emptyAtMoment <= $now;
            $alerts[] = [
                'level' => $alreadyEmpty ? Notification::LEVEL_CRITICAL : Notification::LEVEL_WARNING,
                'title' => 'Launchpad running empty',
                'message' => $alreadyEmpty
                    ? sprintf('A launchpad on %s (%s) is already empty since %s.', $planetName, $character->getName(), $emptyAtMoment->format('Y-m-d H:i T'))
                    : sprintf('A launchpad on %s (%s) runs empty at %s, within the %d minute alert window.', $planetName, $character->getName(), $emptyAtMoment->format('Y-m-d H:i T'), $windowMinutes),
                'data' => $this->baseData(self::KIND_LAUNCHPAD_EMPTY, sprintf('launchpad:%s:%d', $launchpad['colonyId'], $launchpad['pinId']), $colony, $character) + ['emptyAt' => $emptyAt],
                'colony' => $colony,
            ];
        }

        return $alerts;
    }

    /**
     * @param array<int, array{dailyRate: float, emptyAt: ?string}> $feeds
     */
    private function closestEmptyAt(array $feeds): ?string
    {
        $closest = null;
        $closestMoment = null;

        foreach ($feeds as $feed) {
            if ($feed['dailyRate'] <= 0 || $feed['emptyAt'] === null) {
                continue;
            }

            $moment = new \DateTimeImmutable($feed['emptyAt']);
            if ($closestMoment === null || $moment < $closestMoment) {
                $closest = $feed['emptyAt'];
                $closestMoment = $moment;
            }
        }

        return $closest;
    }

    /**
     * @param PlanetaryColony[] $colonies
     * @return list<array{level: string, title: string, message: string, data: array<string, string>, colony: PlanetaryColony}>
     */
    private function storageAlerts(array $colonies, Character $character): array
    {
        $sdeTypes = $this->loadSdeTypes($colonies);
        $alerts = [];

        foreach ($colonies as $colony) {
            foreach ($colony->getPins() as $pin) {
                $ratio = $this->storageFillRatio($pin, $sdeTypes);
                if ($ratio === null || $ratio < self::STORAGE_FULL_RATIO) {
                    continue;
                }

                $alerts[] = [
                    'level' => Notification::LEVEL_WARNING,
                    'title' => 'Storage almost full',
                    'message' => sprintf('A storage facility on %s (%s) is %d%% full.', $this->planetNameOf($colony), $character->getName(), (int) floor($ratio * 100)),
                    'data' => $this->baseData(self::KIND_STORAGE_FULL, sprintf('storage:%s:%d', $this->colonyIdOf($colony), $pin->getPinId()), $colony, $character),
                    'colony' => $colony,
                ];
            }
        }

        return $alerts;
    }

    /**
     * @param array<int, InvType> $sdeTypes
     */
    private function storageFillRatio(PlanetaryPin $pin, array $sdeTypes): ?float
    {
        if (!isset($sdeTypes[$pin->getTypeId()])) {
            return null;
        }

        $pinType = $sdeTypes[$pin->getTypeId()];
        if ($pinType->getGroup()->getGroupId() !== self::STORAGE_FACILITIES_GROUP_ID) {
            return null;
        }

        $capacity = $pinType->getCapacity();
        if ($capacity === null || $capacity <= 0) {
            return null;
        }

        $filled = 0.0;
        foreach ($pin->getContents() ?? [] as $content) {
            if (!isset($sdeTypes[$content['type_id']])) {
                return null;
            }

            $volume = $sdeTypes[$content['type_id']]->getVolume();
            if ($volume === null) {
                return null;
            }

            $filled += $content['amount'] * $volume;
        }

        return $filled / $capacity;
    }

    /**
     * @param PlanetaryColony[] $colonies
     * @return array<int, InvType>
     */
    private function loadSdeTypes(array $colonies): array
    {
        $typeIds = [];
        foreach ($colonies as $colony) {
            foreach ($colony->getPins() as $pin) {
                $typeIds[$pin->getTypeId()] = true;
                foreach ($pin->getContents() ?? [] as $content) {
                    $typeIds[$content['type_id']] = true;
                }
            }
        }

        return $typeIds === [] ? [] : $this->invTypeRepository->findByTypeIds(array_keys($typeIds));
    }

    /**
     * @param PlanetaryColony[] $colonies
     * @return list<array{level: string, title: string, message: string, data: array<string, string>, colony: PlanetaryColony}>
     */
    private function staleAlerts(array $colonies, Character $character, \DateTimeImmutable $now, int $windowMinutes): array
    {
        $alerts = [];

        foreach ($colonies as $colony) {
            $dataAgeSeconds = $now->getTimestamp() - $colony->getLastUpdate()->getTimestamp();
            if ($dataAgeSeconds <= $windowMinutes * self::SECONDS_PER_MINUTE) {
                continue;
            }

            $alerts[] = [
                'level' => Notification::LEVEL_INFO,
                'title' => 'Colony data is stale',
                'message' => sprintf(
                    'The colony on %s (%s) was last seen in game %d minutes ago, beyond the %d minute alert window.',
                    $this->planetNameOf($colony),
                    $character->getName(),
                    intdiv($dataAgeSeconds, self::SECONDS_PER_MINUTE),
                    $windowMinutes,
                ),
                'data' => $this->baseData(self::KIND_DATA_STALE, sprintf('stale:%s', $this->colonyIdOf($colony)), $colony, $character),
                'colony' => $colony,
            ];
        }

        return $alerts;
    }

    /**
     * @param Notification[]        $sentNotifications
     * @param array<string, string> $data
     */
    private function alreadySentDuringEpisode(array $sentNotifications, array $data, PlanetaryColony $colony): bool
    {
        foreach ($sentNotifications as $notification) {
            $sentData = $notification->getData();
            if ($sentData === null
                || ($sentData['kind'] ?? null) !== $data['kind']
                || ($sentData['subject'] ?? null) !== $data['subject']) {
                continue;
            }

            if ($notification->getCreatedAt() >= $colony->getLastUpdate()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function baseData(string $kind, string $subject, PlanetaryColony $colony, Character $character): array
    {
        return [
            'kind' => $kind,
            'subject' => $subject,
            'colonyId' => $this->colonyIdOf($colony),
            'planetName' => $this->planetNameOf($colony),
            'characterName' => $character->getName(),
        ];
    }

    private function colonyIdOf(PlanetaryColony $colony): string
    {
        $id = $colony->getId();
        if ($id === null) {
            throw new \LogicException('A persisted planetary colony is expected to have an id.');
        }

        return $id->toRfc4122();
    }

    private function planetNameOf(PlanetaryColony $colony): string
    {
        return $colony->getPlanetName() ?? sprintf('planet %d', $colony->getPlanetId());
    }

    /**
     * @param PlanetaryColony[] $colonies
     * @return array<string, PlanetaryColony>
     */
    private function indexById(array $colonies): array
    {
        $indexed = [];
        foreach ($colonies as $colony) {
            $indexed[$this->colonyIdOf($colony)] = $colony;
        }

        return $indexed;
    }

    /**
     * @param non-empty-array<PlanetaryColony> $colonies
     */
    private function earliestLastUpdate(array $colonies): \DateTimeImmutable
    {
        return min(array_map(static fn (PlanetaryColony $colony): \DateTimeImmutable => $colony->getLastUpdate(), $colonies));
    }
}
