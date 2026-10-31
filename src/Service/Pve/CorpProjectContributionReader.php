<?php

declare(strict_types=1);

namespace App\Service\Pve;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Enum\CorporationProjectReadFailure;
use App\Exception\EsiApiException;
use App\Exception\EveAuthRequiredException;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

final readonly class CorpProjectContributionReader
{
    private const PROJECTS_SCOPE = 'esi-corporations.read_projects.v1';
    private const FIRST_PLAYER_CORPORATION_ID = 2000000;
    private const COMPATIBILITY_DATE = '2025-12-16';
    private const CACHE_TTL_PROJECTS = 900;
    private const CACHE_TTL_CONTRIBUTORS = 300;
    private const HTTP_FORBIDDEN = 403;
    private const HTTP_NOT_FOUND = 404;
    private const NETWORK_ERROR_STATUS = 0;

    public function __construct(
        private EsiClient $esiClient,
        private TokenManager $tokenManager,
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
        private CorpProjectContributionAccounting $contributionAccounting,
    ) {
    }

    public function read(User $user, \DateTimeImmutable $from): CorpProjectContributionReading
    {
        $reading = new CorpProjectContributionReading();
        $readCorporationIds = [];
        $accountedQuantities = $this->contributionAccounting->accountedQuantitiesOf($user);

        foreach ($user->getCharacters() as $character) {
            $corporationId = $character->getCorporationId();
            if (
                $character->getEveToken() === null
                || $corporationId < self::FIRST_PLAYER_CORPORATION_ID
                || in_array($corporationId, $readCorporationIds, true)
            ) {
                continue;
            }
            $readCorporationIds[] = $corporationId;

            $reading = $reading->merge($this->readCorporation($user, $character, $from, $accountedQuantities));
        }

        return $reading;
    }

    private function readCorporation(User $user, Character $corporationCharacter, \DateTimeImmutable $from, CorpProjectAccountedQuantities $accountedQuantities): CorpProjectContributionReading
    {
        $corporationId = $corporationCharacter->getCorporationId();
        $corporationName = $corporationCharacter->getCorporationName();

        $corporationCharacters = [];
        foreach ($user->getCharacters() as $character) {
            if ($character->getCorporationId() === $corporationId && $character->getEveToken() !== null) {
                $corporationCharacters[$character->getEveCharacterId()] = $character;
            }
        }

        $refusedCharacterIds = [];
        $hasValidCharacterWithoutScope = false;

        foreach ($corporationCharacters as $candidate) {
            $candidateToken = $candidate->getEveToken();
            if ($candidateToken === null || !$candidate->isAuthValid()) {
                continue;
            }
            if (!$candidateToken->hasScope(self::PROJECTS_SCOPE)) {
                $hasValidCharacterWithoutScope = true;
                continue;
            }

            try {
                $this->tokenManager->getValidAccessToken($candidateToken);
                $projects = $this->cachedProjects($corporationId, $candidateToken);
            } catch (EveAuthRequiredException) {
                $refusedCharacterIds[$candidate->getEveCharacterId()] = true;
                continue;
            } catch (EsiApiException|\UnexpectedValueException $exception) {
                return new CorpProjectContributionReading(
                    errors: [$this->readError($corporationName, null, null, $exception)],
                    refusedCharacterIds: $refusedCharacterIds,
                    scannedProjects: null,
                );
            }

            $this->logger->info('Corp projects: Found ' . count($projects) . ' projects for corp ' . $corporationId);

            return $this->readProjects($projects, $candidateToken, $corporationCharacters, $corporationId, $corporationName, $from, $refusedCharacterIds, $accountedQuantities);
        }

        return new CorpProjectContributionReading(
            errors: [new CorporationProjectReadError(
                $corporationName,
                null,
                null,
                $hasValidCharacterWithoutScope ? CorporationProjectReadFailure::MissingProjectsScope : CorporationProjectReadFailure::NoValidCharacter,
            )],
            refusedCharacterIds: $refusedCharacterIds,
            scannedProjects: null,
        );
    }

    /**
     * @param array<mixed> $projects
     * @param array<int, Character> $corporationCharacters
     * @param array<int, true> $refusedCharacterIds
     */
    private function readProjects(
        array $projects,
        EveToken $readerToken,
        array $corporationCharacters,
        int $corporationId,
        string $corporationName,
        \DateTimeImmutable $from,
        array $refusedCharacterIds,
        CorpProjectAccountedQuantities $accountedQuantities,
    ): CorpProjectContributionReading {
        $reading = new CorpProjectContributionReading(refusedCharacterIds: $refusedCharacterIds);

        foreach ($projects as $project) {
            try {
                if ($this->requiredListedField($project, 'state') === 'Deleted') {
                    continue;
                }
                $this->requiredListedField($project, 'id');
                $lastModified = $this->parseDate($project['last_modified'] ?? null);
            } catch (\UnexpectedValueException $exception) {
                $reading = $reading->merge(new CorpProjectContributionReading(
                    errors: [$this->readError($corporationName, $project['name'] ?? 'Project', null, $exception)],
                    scannedProjects: 1,
                ));
                continue;
            }
            if ($lastModified < $from) {
                continue;
            }

            $reading = $reading->merge($this->readProject(
                $project,
                $lastModified,
                $readerToken,
                $corporationCharacters,
                $corporationId,
                $corporationName,
                $from,
                $reading->refusedCharacterIds,
                $accountedQuantities,
            ));
        }

        return $reading;
    }

    /**
     * @param array<mixed> $project
     * @param array<int, Character> $corporationCharacters
     * @param array<int, true> $refusedCharacterIds
     */
    private function readProject(
        array $project,
        \DateTimeImmutable $projectLastModified,
        EveToken $readerToken,
        array $corporationCharacters,
        int $corporationId,
        string $corporationName,
        \DateTimeImmutable $from,
        array $refusedCharacterIds,
        CorpProjectAccountedQuantities $accountedQuantities,
    ): CorpProjectContributionReading {
        $projectId = $project['id'];
        $projectName = $project['name'] ?? 'Project';
        $errors = [];

        try {
            $contributorIds = $this->cachedContributors($corporationId, $projectId, $readerToken);
        } catch (EveAuthRequiredException) {
            return $this->readerRefusedAfterTheList($projectName);
        } catch (EsiApiException|\UnexpectedValueException $exception) {
            if (!$exception instanceof EsiApiException || $exception->statusCode !== self::HTTP_FORBIDDEN) {
                return new CorpProjectContributionReading(
                    errors: [$this->readError($corporationName, $projectName, null, $exception)],
                    scannedProjects: 1,
                );
            }
            $contributorIds = null;
        }

        $askedCharacters = [];
        foreach ($corporationCharacters as $characterId => $character) {
            $token = $character->getEveToken();
            if (
                ($contributorIds !== null && !in_array($characterId, $contributorIds, true))
                || $token === null
                || !$character->isAuthValid()
                || isset($refusedCharacterIds[$characterId])
            ) {
                continue;
            }
            if (!$token->hasScope(self::PROJECTS_SCOPE)) {
                if ($contributorIds !== null) {
                    $errors[] = new CorporationProjectReadError(
                        $corporationName,
                        $projectName,
                        $character->getName(),
                        CorporationProjectReadFailure::MissingProjectsScope,
                    );
                }
                continue;
            }
            $askedCharacters[$characterId] = $token;
        }

        if ($askedCharacters === []) {
            return new CorpProjectContributionReading(errors: $errors, scannedProjects: 1);
        }

        try {
            $projectDetails = $this->cachedProjectDetails($corporationId, $projectId, $readerToken);
        } catch (EveAuthRequiredException) {
            return $this->readerRefusedAfterTheList($projectName);
        } catch (EsiApiException|\UnexpectedValueException $exception) {
            return new CorpProjectContributionReading(
                errors: [...$errors, $this->readError($corporationName, $projectName, null, $exception)],
                scannedProjects: 1,
            );
        }

        $typeId = $projectDetails['configuration']['deliver_item']['items'][0]['type_id'] ?? 0;
        $rewardPerContribution = $projectDetails['contribution']['reward_per_contribution'] ?? null;
        $rewardPerContribution = $rewardPerContribution === null ? null : (float) $rewardPerContribution;

        $contributions = [];
        $newlyRefusedCharacterIds = [];

        foreach ($askedCharacters as $characterId => $token) {
            $character = $corporationCharacters[$characterId];

            try {
                $contribution = $this->esiClient->getUnversioned(
                    "/corporations/{$corporationId}/projects/{$projectId}/contribution/{$characterId}",
                    $token,
                    self::COMPATIBILITY_DATE,
                );

                $this->logger->debug('Corp projects: Contribution of character ' . $characterId . ': ' . json_encode($contribution));

                $contributed = $contribution['contributed'] ?? null;
                if (!is_int($contributed)) {
                    throw new \UnexpectedValueException("ESI contribution of character {$characterId} to project {$projectId} has no integer 'contributed'");
                }
                if ($contributed <= 0) {
                    continue;
                }

                $contributedAt = isset($contribution['last_modified'])
                    ? $this->parseDate($contribution['last_modified'])
                    : $projectLastModified;
                if ($contributedAt < $from) {
                    continue;
                }

                if ($accountedQuantities->isLegacyIdentifierDeclined($projectId, $characterId)) {
                    continue;
                }

                $accountedQuantity = $accountedQuantities->quantityOf($projectId, $characterId);
                if ($accountedQuantity === null) {
                    $errors[] = new CorporationProjectReadError($corporationName, $projectName, $character->getName(), CorporationProjectReadFailure::LegacyImportQuantityUnknown);
                    continue;
                }
                if ($contributed < $accountedQuantity) {
                    $errors[] = new CorporationProjectReadError($corporationName, $projectName, $character->getName(), CorporationProjectReadFailure::ContributionBelowImported);
                    continue;
                }
                if ($contributed === $accountedQuantity) {
                    continue;
                }

                $contributions[] = new CorpProjectContribution(
                    $projectId,
                    $projectName,
                    $corporationName,
                    $characterId,
                    $character->getName(),
                    $typeId,
                    $contributed,
                    $accountedQuantity,
                    $rewardPerContribution,
                    $contributedAt,
                );
            } catch (EveAuthRequiredException) {
                $newlyRefusedCharacterIds[$characterId] = true;
            } catch (EsiApiException|\UnexpectedValueException $exception) {
                if ($contributorIds === null && $exception instanceof EsiApiException && $exception->statusCode === self::HTTP_NOT_FOUND) {
                    continue;
                }
                $errors[] = $this->readError($corporationName, $projectName, $character->getName(), $exception);
            }
        }

        return new CorpProjectContributionReading($contributions, $errors, $newlyRefusedCharacterIds, 1);
    }

    private function readerRefusedAfterTheList(string $projectName): CorpProjectContributionReading
    {
        $this->logger->debug("Corp projects: Reader refused while reading project {$projectName}, project skipped");

        return new CorpProjectContributionReading(scannedProjects: 1);
    }

    /**
     * @param array<mixed> $project
     */
    private function requiredListedField(array $project, string $field): mixed
    {
        return $project[$field] ?? throw new \UnexpectedValueException("ESI listed project has no '{$field}'");
    }

    private function readError(string $corporationName, ?string $projectName, ?string $characterName, EsiApiException|\UnexpectedValueException $exception): CorporationProjectReadError
    {
        $this->logger->warning("Corp projects: Error for corp {$corporationName}: {$exception->getMessage()}");

        if ($exception instanceof EsiApiException) {
            return new CorporationProjectReadError(
                $corporationName,
                $projectName,
                $characterName,
                CorporationProjectReadFailure::EsiError,
                $exception->statusCode === self::NETWORK_ERROR_STATUS ? null : $exception->statusCode,
            );
        }

        return new CorporationProjectReadError($corporationName, $projectName, $characterName, CorporationProjectReadFailure::UnexpectedResponse);
    }

    private function parseDate(mixed $value): \DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new \UnexpectedValueException('ESI date is missing or is not a string');
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $exception) {
            throw new \UnexpectedValueException("ESI date '{$value}' is unreadable", previous: $exception);
        }
    }

    /**
     * @return array<mixed>
     */
    private function cachedProjects(int $corporationId, EveToken $token): array
    {
        $cacheItem = $this->cache->getItem("corp_projects_{$corporationId}");

        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        $projects = $this->esiClient->getUnversionedCursorPaginated(
            "/corporations/{$corporationId}/projects?state=All",
            $token,
            self::COMPATIBILITY_DATE,
            'projects',
        );

        $cacheItem->set($projects);
        $cacheItem->expiresAfter(self::CACHE_TTL_PROJECTS);
        $this->cache->save($cacheItem);

        return $projects;
    }

    /**
     * @return list<int>
     */
    private function cachedContributors(int $corporationId, string $projectId, EveToken $token): array
    {
        $cacheItem = $this->cache->getItem("corp_project_contributors_{$corporationId}_{$projectId}");

        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        $contributors = $this->esiClient->getUnversionedCursorPaginated(
            "/corporations/{$corporationId}/projects/{$projectId}/contributors",
            $token,
            self::COMPATIBILITY_DATE,
            'contributors',
        );

        $contributorIds = array_map(
            static fn (array $contributor): int => (int) ($contributor['character_id'] ?? $contributor['id'] ?? throw new \UnexpectedValueException("ESI contributor of project {$projectId} has no character id")),
            $contributors,
        );

        $cacheItem->set($contributorIds);
        $cacheItem->expiresAfter(self::CACHE_TTL_CONTRIBUTORS);
        $this->cache->save($cacheItem);

        return $contributorIds;
    }

    /**
     * @return array<mixed>
     */
    private function cachedProjectDetails(int $corporationId, string $projectId, EveToken $token): array
    {
        $cacheItem = $this->cache->getItem("corp_project_details_{$corporationId}_{$projectId}");

        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        $projectDetails = $this->esiClient->getUnversioned(
            "/corporations/{$corporationId}/projects/{$projectId}",
            $token,
            self::COMPATIBILITY_DATE,
        );

        $cacheItem->set($projectDetails);
        $cacheItem->expiresAfter(self::CACHE_TTL_PROJECTS);
        $this->cache->save($cacheItem);

        return $projectDetails;
    }
}
