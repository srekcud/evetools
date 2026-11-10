<?php

declare(strict_types=1);

namespace App\Service\ESI;

use App\Entity\Character;
use App\Enum\CorporationRoleStatus;
use App\Exception\EsiApiException;
use App\Exception\EveAuthRequiredException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

final readonly class CorporationRoleReader
{
    private const string DIRECTOR_ROLE = 'Director';
    private const int FORBIDDEN_STATUS_CODE = 403;
    private const string CACHE_KEY_PREFIX = 'holder_';

    public function __construct(
        private CharacterService $characterService,
        private CacheItemPoolInterface $corporationRoleHolderCache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<Character> $characters
     * @param list<string>    $roles
     * @param list<string>    $scopes
     */
    public function findHolder(int $corporationId, array $characters, array $roles, array $scopes): CorporationRoleLookup
    {
        return $this->readAsHolder($corporationId, $characters, $roles, $scopes, static fn (Character $holder): Character => $holder);
    }

    /**
     * @param list<Character> $characters
     * @param list<string>    $roles
     * @param list<string>    $scopes
     * @param \Closure(Character): mixed $read throws EsiApiException or EveAuthRequiredException when the ESI refuses the read
     */
    public function readAsHolder(int $corporationId, array $characters, array $roles, array $scopes, \Closure $read): CorporationRoleLookup
    {
        $acceptedRoles = [...$roles, self::DIRECTOR_ROLE];
        $candidates = $this->candidatesOf($characters, $scopes);
        $cacheKey = $this->cacheKeyOf($corporationId, $roles);
        $remembered = $this->rememberedHolderOf($cacheKey, $characters, $candidates);

        $deniedByEsi = false;
        $failedOnEsi = false;
        $needsReconnection = false;
        $rolesReadByCharacterName = [];
        $unreadableCharacterNames = [];

        $attempts = $remembered === null ? $candidates : [$remembered, ...array_filter($candidates, static fn (Character $candidate): bool => $candidate !== $remembered)];

        foreach ($attempts as $candidate) {
            try {
                if ($candidate !== $remembered) {
                    $rolesRead = $this->characterService->readCorporationRoles($candidate);
                    if (array_intersect($acceptedRoles, $rolesRead) === []) {
                        $rolesReadByCharacterName[$candidate->getName()] = $rolesRead;

                        continue;
                    }
                }
                $value = $read($candidate);
                $this->remember($cacheKey, $candidate);

                return new CorporationRoleLookup(CorporationRoleStatus::Held, $candidate, $value);
            } catch (EsiApiException $exception) {
                if ($exception->statusCode === self::FORBIDDEN_STATUS_CODE) {
                    $deniedByEsi = true;
                    $this->forgetIf($cacheKey, $candidate === $remembered);
                } else {
                    $failedOnEsi = true;
                    $unreadableCharacterNames[] = $candidate->getName();
                }
                $this->logFailure($corporationId, $candidate, $exception);
            } catch (EveAuthRequiredException $exception) {
                $needsReconnection = true;
                $this->forgetIf($cacheKey, $candidate === $remembered);
                $this->logFailure($corporationId, $candidate, $exception);
            } catch (\UnexpectedValueException $exception) {
                $failedOnEsi = true;
                $unreadableCharacterNames[] = $candidate->getName();
                $this->logFailure($corporationId, $candidate, $exception);
            }
        }

        $status = match (true) {
            $failedOnEsi => CorporationRoleStatus::EsiError,
            $deniedByEsi => CorporationRoleStatus::RoleAbsent,
            $needsReconnection || $this->anyCannotRead($characters, $scopes) => CorporationRoleStatus::ReconnectRequired,
            default => CorporationRoleStatus::RoleAbsent,
        };

        return new CorporationRoleLookup($status, null, null, $rolesReadByCharacterName, $unreadableCharacterNames);
    }

    /**
     * @param list<Character> $characters
     * @param list<string>    $scopes
     *
     * @return list<Character>
     */
    private function candidatesOf(array $characters, array $scopes): array
    {
        $candidates = array_values(array_filter($characters, fn (Character $character): bool => $this->canRead($character, $scopes)));
        usort($candidates, static fn (Character $left, Character $right): int => [$right->isMain(), $left->getEveCharacterId()] <=> [$left->isMain(), $right->getEveCharacterId()]);

        return $candidates;
    }

    /**
     * @param list<Character> $characters
     * @param list<string>    $scopes
     */
    private function anyCannotRead(array $characters, array $scopes): bool
    {
        foreach ($characters as $character) {
            if (!$this->canRead($character, $scopes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $scopes
     */
    private function canRead(Character $character, array $scopes): bool
    {
        return $character->isAuthValid() && $character->getEveToken()?->hasAllScopes($scopes) === true;
    }

    /**
     * @param list<string> $roles
     */
    private function cacheKeyOf(int $corporationId, array $roles): string
    {
        $sortedRoles = $roles;
        sort($sortedRoles);

        return sprintf('%s%d_%s', self::CACHE_KEY_PREFIX, $corporationId, hash('xxh3', implode(',', $sortedRoles)));
    }

    /**
     * @param list<Character> $characters
     * @param list<Character> $candidates
     */
    private function rememberedHolderOf(string $cacheKey, array $characters, array $candidates): ?Character
    {
        $item = $this->corporationRoleHolderCache->getItem($cacheKey);
        if (!$item->isHit()) {
            return null;
        }

        foreach ($candidates as $candidate) {
            if ($candidate->getEveCharacterId() === $item->get()) {
                return $candidate;
            }
        }

        foreach ($characters as $character) {
            if ($character->getEveCharacterId() === $item->get()) {
                $this->corporationRoleHolderCache->deleteItem($cacheKey);

                break;
            }
        }

        return null;
    }

    private function remember(string $cacheKey, Character $holder): void
    {
        $item = $this->corporationRoleHolderCache->getItem($cacheKey);
        if ($item->isHit() && $item->get() === $holder->getEveCharacterId()) {
            return;
        }

        $this->corporationRoleHolderCache->save($item->set($holder->getEveCharacterId()));
    }

    private function forgetIf(string $cacheKey, bool $isRemembered): void
    {
        if ($isRemembered) {
            $this->corporationRoleHolderCache->deleteItem($cacheKey);
        }
    }

    private function logFailure(int $corporationId, Character $candidate, \Throwable $exception): void
    {
        $this->logger->warning('Corporation role read failed for a candidate', [
            'corporation_id' => $corporationId,
            'character_id' => $candidate->getEveCharacterId(),
            'error' => $exception->getMessage(),
        ]);
    }
}
