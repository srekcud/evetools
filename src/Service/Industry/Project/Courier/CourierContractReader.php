<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Courier;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Exception\EsiApiException;
use App\Exception\EveAuthRequiredException;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;

final readonly class CourierContractReader
{
    public const int MAX_CANDIDATES = 20;
    public const string REASON_NO_TOKEN = 'no_token';
    public const string REASON_ESI_ERROR = 'esi_error';
    public const string REASON_CONTRACT_NOT_FOUND = 'contract_not_found';

    private const string COURIER_TYPE = 'courier';
    private const float MATCH_TOLERANCE = 0.01;

    public function __construct(
        private EsiClient $esiClient,
        private TokenManager $tokenManager,
    ) {
    }

    public function read(User $owner, \DateTimeImmutable $issuedAfter): CourierContractsRead
    {
        $contracts = [];
        $unreadableReasonByCharacterId = [];

        foreach ($owner->getCharacters() as $character) {
            $token = $character->getEveToken();
            if (null === $token) {
                $unreadableReasonByCharacterId[$character->getEveCharacterId()] = self::REASON_NO_TOKEN;

                continue;
            }

            try {
                $contracts = [...$contracts, ...$this->courierContractsOf($character, $token, $issuedAfter)];
            } catch (EveAuthRequiredException) {
                $unreadableReasonByCharacterId[$character->getEveCharacterId()] = self::REASON_NO_TOKEN;
            } catch (EsiApiException) {
                $unreadableReasonByCharacterId[$character->getEveCharacterId()] = self::REASON_ESI_ERROR;
            }
        }

        usort($contracts, static fn (CourierContract $first, CourierContract $second): int => $second->issuedAt <=> $first->issuedAt);

        return new CourierContractsRead($contracts, $unreadableReasonByCharacterId);
    }

    /**
     * @return list<CourierCandidate>
     */
    public function candidatesOf(User $owner, \DateTimeImmutable $issuedAfter, CourierQuote $quote): array
    {
        return $this->candidatesIn($this->read($owner, $issuedAfter), $quote);
    }

    /**
     * @return list<CourierCandidate>
     */
    public function candidatesIn(CourierContractsRead $read, CourierQuote $quote): array
    {
        return array_map(
            fn (CourierContract $contract): CourierCandidate => new CourierCandidate($contract, $this->matchesQuote($contract, $quote)),
            \array_slice($read->contracts, 0, self::MAX_CANDIDATES),
        );
    }

    /**
     * @return list<CourierContract>
     */
    private function courierContractsOf(Character $character, EveToken $token, \DateTimeImmutable $issuedAfter): array
    {
        $this->tokenManager->getValidAccessToken($token);
        $eveCharacterId = $character->getEveCharacterId();
        $contracts = [];
        foreach ($this->esiClient->getPaginated("/characters/{$eveCharacterId}/contracts/", $token) as $esiContract) {
            $issuedAt = new \DateTimeImmutable($esiContract['date_issued']);
            if (self::COURIER_TYPE !== $esiContract['type'] || $issuedAt <= $issuedAfter) {
                continue;
            }

            $contracts[] = new CourierContract(
                (int) $esiContract['contract_id'],
                $eveCharacterId,
                CourierContractState::fromEsiStatus($esiContract['status']),
                $this->optionalFloat($esiContract, 'volume'),
                $this->optionalFloat($esiContract, 'reward'),
                $this->optionalFloat($esiContract, 'collateral'),
                $this->optionalInt($esiContract, 'start_location_id'),
                $this->optionalInt($esiContract, 'end_location_id'),
                $issuedAt,
            );
        }

        return $contracts;
    }

    private function matchesQuote(CourierContract $contract, CourierQuote $quote): bool
    {
        if (CourierQuoteCalculator::STATUS_READY !== $quote->quoteStatus || null === $quote->volumeCubicMeters || null === $quote->reward) {
            return false;
        }

        return $this->isWithinTolerance($contract->volume, $quote->volumeCubicMeters)
            && $this->isWithinTolerance($contract->reward, (float) $quote->reward);
    }

    private function isWithinTolerance(?float $actual, float $expected): bool
    {
        return null !== $actual && abs($actual - $expected) <= $expected * self::MATCH_TOLERANCE;
    }

    /**
     * @param array<string, mixed> $esiContract
     */
    private function optionalFloat(array $esiContract, string $key): ?float
    {
        return isset($esiContract[$key]) ? (float) $esiContract[$key] : null;
    }

    /**
     * @param array<string, mixed> $esiContract
     */
    private function optionalInt(array $esiContract, string $key): ?int
    {
        return isset($esiContract[$key]) ? (int) $esiContract[$key] : null;
    }
}
