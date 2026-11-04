<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\Character;
use App\Enum\MoonTaxSyncFailure;
use App\Exception\EsiApiException;
use App\Exception\EveAuthRequiredException;
use App\Service\ESI\CharacterService;
use Psr\Log\LoggerInterface;

final readonly class MoonTaxReaderCandidates
{
    private const int FORBIDDEN_STATUS_CODE = 403;

    public function __construct(
        private CharacterService $characterService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<Character> $characters
     * @param list<string>    $requiredScopes
     * @param list<string>    $acceptedRoles
     * @param \Closure(Character): mixed $read throws EsiApiException or EveAuthRequiredException when the ESI refuses the read
     */
    public function readWithFirstAbleCharacter(array $characters, array $requiredScopes, array $acceptedRoles, \Closure $read): MoonTaxReadOutcome
    {
        $deniedByEsi = false;
        $failedOnEsi = false;
        $needsReconnection = false;

        foreach ($this->candidatesOf($characters, $requiredScopes) as $candidate) {
            try {
                if (array_intersect($acceptedRoles, $this->characterService->readCorporationRoles($candidate)) === []) {
                    continue;
                }

                return MoonTaxReadOutcome::read($read($candidate));
            } catch (EsiApiException $exception) {
                $deniedByEsi = $deniedByEsi || $exception->statusCode === self::FORBIDDEN_STATUS_CODE;
                $failedOnEsi = $failedOnEsi || $exception->statusCode !== self::FORBIDDEN_STATUS_CODE;
                $this->logFailure($candidate, $exception);
            } catch (EveAuthRequiredException $exception) {
                $needsReconnection = true;
                $this->logFailure($candidate, $exception);
            } catch (\UnexpectedValueException $exception) {
                $failedOnEsi = true;
                $this->logFailure($candidate, $exception);
            }
        }

        return MoonTaxReadOutcome::failed($this->failureOf($failedOnEsi, $deniedByEsi, $needsReconnection || $this->anyNeedsReconnection($characters, $requiredScopes)));
    }

    private function failureOf(bool $failedOnEsi, bool $deniedByEsi, bool $needsReconnection): MoonTaxSyncFailure
    {
        return match (true) {
            $failedOnEsi => MoonTaxSyncFailure::EsiError,
            $deniedByEsi => MoonTaxSyncFailure::NoAccountantToken,
            $needsReconnection => MoonTaxSyncFailure::ReconnectRequired,
            default => MoonTaxSyncFailure::NoAccountantToken,
        };
    }

    /**
     * @param list<Character> $characters
     * @param list<string>    $requiredScopes
     *
     * @return list<Character>
     */
    private function candidatesOf(array $characters, array $requiredScopes): array
    {
        $candidates = array_values(array_filter($characters, fn (Character $character): bool => $this->canRead($character, $requiredScopes)));
        usort($candidates, static fn (Character $left, Character $right): int => $left->getEveCharacterId() <=> $right->getEveCharacterId());

        return $candidates;
    }

    /**
     * @param list<Character> $characters
     * @param list<string>    $requiredScopes
     */
    private function anyNeedsReconnection(array $characters, array $requiredScopes): bool
    {
        foreach ($characters as $character) {
            if (!$this->canRead($character, $requiredScopes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $requiredScopes
     */
    private function canRead(Character $character, array $requiredScopes): bool
    {
        return $character->isAuthValid() && $character->getEveToken()?->hasAllScopes($requiredScopes) === true;
    }

    private function logFailure(Character $candidate, \Throwable $exception): void
    {
        $this->logger->warning('Moon tax read failed for a candidate', [
            'corporation_id' => $candidate->getCorporationId(),
            'character_id' => $candidate->getEveCharacterId(),
            'error' => $exception->getMessage(),
        ]);
    }
}
