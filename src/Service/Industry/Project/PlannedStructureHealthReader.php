<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\Character;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Exception\EsiApiException;
use App\Exception\EveAuthRequiredException;
use App\Industry\Application\StructureHealth;
use App\Industry\Application\StructureHealthAssessor;
use App\Repository\CharacterRepository;
use App\Service\ESI\CharacterService;
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
        private CharacterService $characterService,
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
        $candidates = $this->candidatesOf($reader);
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
                $esiStructuresByCorporation[$corporationId] = $this->readCorporation($corporationId, $candidates);
            }
            $esiStructure = $esiStructuresByCorporation[$corporationId][$locationId] ?? null;
            if (null !== $esiStructure) {
                $health[$structure->getId()->toRfc4122()] = $this->assess($esiStructure);
            }
        }

        return $health;
    }

    /**
     * @return list<Character>
     */
    private function candidatesOf(User $reader): array
    {
        $candidates = array_values(array_filter(
            $this->characterRepository->findByUser($reader),
            static fn (Character $character): bool => $character->isAuthValid()
                && true === $character->getEveToken()?->hasAllScopes(self::REQUIRED_SCOPES),
        ));
        usort($candidates, static fn (Character $left, Character $right): int => $left->getEveCharacterId() <=> $right->getEveCharacterId());

        return $candidates;
    }

    /**
     * @param list<Character> $candidates
     *
     * @return array<int, array<string, mixed>>|null by structure id
     */
    private function readCorporation(int $corporationId, array $candidates): ?array
    {
        $reader = $this->stationManagerOf($corporationId, $candidates);
        if (null === $reader) {
            return null;
        }

        try {
            $rawStructures = $this->esiClient->getPaginated("/corporations/{$corporationId}/structures/", $reader->getEveToken());
        } catch (EsiApiException|EveAuthRequiredException $e) {
            $this->logger->warning('Corporation structures unreadable, planned structure health stays unknown', [
                'corporation_id' => $corporationId,
                'character_id' => $reader->getEveCharacterId(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $structures = [];
        foreach ($rawStructures as $raw) {
            if (\is_array($raw) && \is_int($raw['structure_id'] ?? null)) {
                $structures[$raw['structure_id']] = $raw;
            }
        }

        return $structures;
    }

    /**
     * @param list<Character> $candidates
     */
    private function stationManagerOf(int $corporationId, array $candidates): ?Character
    {
        foreach ($candidates as $candidate) {
            if ($candidate->getCorporationId() !== $corporationId) {
                continue;
            }
            try {
                if ($this->characterService->hasRole($candidate, self::STATION_MANAGER_ROLE)) {
                    return $candidate;
                }
            } catch (EsiApiException|EveAuthRequiredException $e) {
                $this->logger->warning('Character roles unreadable, the character is not a corporation structures reader', [
                    'corporation_id' => $corporationId,
                    'character_id' => $candidate->getEveCharacterId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
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
