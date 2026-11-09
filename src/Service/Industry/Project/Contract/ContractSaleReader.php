<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Contract;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectSale;
use App\Entity\User;
use App\Exception\EsiApiException;
use App\Exception\EveAuthRequiredException;
use App\Repository\IndustryProjectSaleRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;

final readonly class ContractSaleReader
{
    public const int MAX_CANDIDATES = 20;
    public const string REASON_NO_TOKEN = 'no_token';
    public const string REASON_ESI_ERROR = 'esi_error';

    private const string CONTRACTS_SCOPE = 'esi-contracts.read_character_contracts.v1';
    private const string ITEM_EXCHANGE_TYPE = 'item_exchange';
    private const string FINISHED_STATUS = 'finished';

    public function __construct(
        private EsiClient $esiClient,
        private TokenManager $tokenManager,
        private IndustryProjectSaleRepository $saleRepository,
    ) {
    }

    public function read(User $reader, IndustryProject $project): ContractSaleCandidatesRead
    {
        $finished = [];
        $unreadableReasonByEveCharacterId = [];

        foreach ($reader->getCharacters() as $character) {
            $token = $character->getEveToken();
            if ($token === null || !$token->hasScope(self::CONTRACTS_SCOPE)) {
                $unreadableReasonByEveCharacterId[$character->getEveCharacterId()] = self::REASON_NO_TOKEN;

                continue;
            }

            try {
                foreach ($this->finishedSalesOf($character, $token, $project) as $contract) {
                    $finished[] = [$character, $token, $contract];
                }
            } catch (EveAuthRequiredException) {
                $unreadableReasonByEveCharacterId[$character->getEveCharacterId()] = self::REASON_NO_TOKEN;
            } catch (EsiApiException) {
                $unreadableReasonByEveCharacterId[$character->getEveCharacterId()] = self::REASON_ESI_ERROR;
            }
        }

        $unlinked = $this->withoutLinkedContracts($finished);
        usort($unlinked, static fn (array $first, array $second): int => $second[2]['completedAt'] <=> $first[2]['completedAt']);

        $candidates = [];
        foreach (\array_slice($unlinked, 0, self::MAX_CANDIDATES) as [$character, $token, $contract]) {
            try {
                $candidate = $this->candidateOf($character, $token, $project, $contract);
            } catch (EveAuthRequiredException) {
                $unreadableReasonByEveCharacterId[$character->getEveCharacterId()] = self::REASON_NO_TOKEN;

                continue;
            } catch (EsiApiException) {
                $unreadableReasonByEveCharacterId[$character->getEveCharacterId()] = self::REASON_ESI_ERROR;

                continue;
            }

            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return new ContractSaleCandidatesRead($candidates, $unreadableReasonByEveCharacterId);
    }

    /**
     * @return list<array{contractId: int, price: float, completedAt: \DateTimeImmutable}>
     */
    private function finishedSalesOf(Character $character, EveToken $token, IndustryProject $project): array
    {
        $this->tokenManager->getValidAccessToken($token);
        $eveCharacterId = $character->getEveCharacterId();

        $finished = [];
        foreach ($this->esiClient->getPaginated("/characters/{$eveCharacterId}/contracts/", $token) as $esiContract) {
            if (self::ITEM_EXCHANGE_TYPE !== ($esiContract['type'] ?? null)
                || self::FINISHED_STATUS !== ($esiContract['status'] ?? null)
                || (int) ($esiContract['issuer_id'] ?? 0) !== $eveCharacterId
                || !isset($esiContract['date_completed'])) {
                continue;
            }

            $completedAt = new \DateTimeImmutable($esiContract['date_completed']);
            if ($completedAt <= $project->getCreatedAt()) {
                continue;
            }

            $finished[] = [
                'contractId' => (int) $esiContract['contract_id'],
                'price' => (float) $esiContract['price'],
                'completedAt' => $completedAt,
            ];
        }

        return $finished;
    }

    /**
     * @param list<array{0: Character, 1: EveToken, 2: array{contractId: int, price: float, completedAt: \DateTimeImmutable}}> $finished
     *
     * @return list<array{0: Character, 1: EveToken, 2: array{contractId: int, price: float, completedAt: \DateTimeImmutable}}>
     */
    private function withoutLinkedContracts(array $finished): array
    {
        if ($finished === []) {
            return [];
        }

        $linkedContractIds = array_map(
            static fn (IndustryProjectSale $sale): ?int => $sale->getContractId(),
            $this->saleRepository->findBy(['contractId' => array_map(static fn (array $entry): int => $entry[2]['contractId'], $finished)]),
        );

        return array_values(array_filter($finished, static fn (array $entry): bool => !\in_array($entry[2]['contractId'], $linkedContractIds, true)));
    }

    /**
     * @param array{contractId: int, price: float, completedAt: \DateTimeImmutable} $contract
     */
    private function candidateOf(Character $character, EveToken $token, IndustryProject $project, array $contract): ?ContractSaleCandidate
    {
        $eveCharacterId = $character->getEveCharacterId();
        $quantityByTypeId = [];
        foreach ($this->esiClient->get("/characters/{$eveCharacterId}/contracts/{$contract['contractId']}/items/", $token) as $item) {
            if (($item['is_included'] ?? false) !== true) {
                continue;
            }
            $quantityByTypeId[(int) $item['type_id']] = ($quantityByTypeId[(int) $item['type_id']] ?? 0) + (int) $item['quantity'];
        }

        if (\count($quantityByTypeId) !== 1) {
            return null;
        }

        $typeId = array_key_first($quantityByTypeId);
        $typeName = $project->getItemTypeName($typeId);
        if ($typeName === null) {
            return null;
        }

        return new ContractSaleCandidate(
            $contract['contractId'],
            $typeId,
            $typeName,
            $quantityByTypeId[$typeId],
            $contract['price'],
            $contract['completedAt'],
            $eveCharacterId,
            $character->getName(),
        );
    }
}
