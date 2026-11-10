<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\Character;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Enum\CorporationRoleStatus;
use App\Industry\Application\StructureHealth;
use App\Industry\Application\StructureHealthAssessor;
use App\Repository\CharacterRepository;
use App\Service\ESI\CorporationRoleReader;
use App\Service\ESI\EsiClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class PlannedStructureHealthReader
{
    private const string STATION_MANAGER_ROLE = 'Station_Manager';
    private const array REQUIRED_SCOPES = [
        'esi-corporations.read_structures.v1',
        'esi-characters.read_corporation_roles.v1',
    ];
    private const array OFFLINE_SERVICE_STATES = ['offline', 'cleanup'];

    public function __construct(
        private EsiClient $esiClient,
        private CorporationRoleReader $corporationRoleReader,
        private CharacterRepository $characterRepository,
        private StructureHealthAssessor $assessor,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<IndustryStructureConfig> $structures
     *
     * @return array<string, StructureHealth> by structure configuration id
     */
    public function read(User $reader, array $structures): array
    {
        $characters = array_values($this->characterRepository->findByUser($reader));
        $esiStructuresByCorporation = [];
        $health = [];

        foreach ($structures as $structure) {
            $corporationId = $structure->getCorporationId();
            $locationId = $structure->getLocationId();
            $health[$structure->getId()->toRfc4122()] = StructureHealth::unknown();
            if (null === $corporationId || null === $locationId) {
                continue;
            }
            if (!\array_key_exists($corporationId, $esiStructuresByCorporation)) {
                $esiStructuresByCorporation[$corporationId] = $this->readCorporation($corporationId, $characters);
            }
            $esiStructure = $esiStructuresByCorporation[$corporationId][$locationId] ?? null;
            if (null !== $esiStructure) {
                $health[$structure->getId()->toRfc4122()] = $this->assess($esiStructure);
            }
        }

        return $health;
    }

    /**
     * @param list<Character> $characters
     *
     * @return array<int, array<string, mixed>>|null by structure id
     */
    private function readCorporation(int $corporationId, array $characters): ?array
    {
        $members = array_values(array_filter(
            $characters,
            static fn (Character $character): bool => $character->getCorporationId() === $corporationId,
        ));

        $lookup = $this->corporationRoleReader->readAsHolder(
            $corporationId,
            $members,
            [self::STATION_MANAGER_ROLE],
            self::REQUIRED_SCOPES,
            fn (Character $holder): array => $this->esiClient->getPaginated("/corporations/{$corporationId}/structures/", $holder->getEveToken()),
        );
        if ($lookup->status !== CorporationRoleStatus::Held || !\is_array($lookup->value)) {
            return null;
        }

        $structures = [];
        foreach ($lookup->value as $raw) {
            if (\is_array($raw) && \is_int($raw['structure_id'] ?? null)) {
                $structures[$raw['structure_id']] = $raw;
            }
        }

        return $structures;
    }

    /**
     * @param array<string, mixed> $esiStructure
     */
    private function assess(array $esiStructure): StructureHealth
    {
        return $this->assessor->assess(
            $this->fuelExpiresAtOf($esiStructure),
            $this->offlineServicesOf($esiStructure),
            $this->clock->now(),
        );
    }

    /**
     * @param array<string, mixed> $esiStructure
     */
    private function fuelExpiresAtOf(array $esiStructure): ?\DateTimeImmutable
    {
        $raw = $esiStructure['fuel_expires'] ?? null;
        if (!\is_string($raw)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\DateMalformedStringException $e) {
            $this->logger->warning('Structure fuel expiry unreadable', ['structure_id' => $esiStructure['structure_id'] ?? null, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $esiStructure
     *
     * @return list<string>
     */
    private function offlineServicesOf(array $esiStructure): array
    {
        $services = $esiStructure['services'] ?? [];
        if (!\is_array($services)) {
            return [];
        }

        $offline = [];
        foreach ($services as $service) {
            if (\is_array($service) && \is_string($service['name'] ?? null) && \in_array($service['state'] ?? null, self::OFFLINE_SERVICE_STATES, true)) {
                $offline[] = $service['name'];
            }
        }

        return $offline;
    }
}
