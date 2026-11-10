<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\Character;
use App\Entity\CorporationWalletDonation;
use App\Entity\MoonTaxRefinerySetting;
use App\Enum\CorporationRoleStatus;
use App\Enum\MoonTaxSyncFailure;
use App\Repository\CorporationWalletDonationRepository;
use App\Service\ESI\CorporationRoleReader;
use App\Service\ESI\EsiClient;
use App\Service\MoonTax\MoonTaxManagerAccess;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class CorporationWalletJournalSync
{
    private const array READER_SCOPES = ['esi-wallet.read_corporation_wallets.v1', MoonTaxManagerAccess::ROLES_SCOPE];
    private const array READER_ROLES = [MoonTaxManagerAccess::ACCOUNTANT_ROLE, 'Junior_Accountant'];
    private const string DONATION_REF_TYPE = 'player_donation';

    public function __construct(
        private CorporationRoleReader $corporationRoleReader,
        private EsiClient $esiClient,
        private CorporationWalletDonationRepository $donationRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<Character>              $characters
     * @param list<MoonTaxRefinerySetting> $openSettings
     */
    public function sync(int $corporationId, array $characters, array $openSettings): ?MoonTaxSyncFailure
    {
        $divisions = array_values(array_unique(array_map(static fn (MoonTaxRefinerySetting $setting): int => $setting->getWalletDivision(), $openSettings)));

        $lookup = $this->corporationRoleReader->readAsHolder(
            $corporationId,
            $characters,
            self::READER_ROLES,
            self::READER_SCOPES,
            fn (Character $reader): array => $this->readRaw($corporationId, $reader, $divisions),
        );
        if ($lookup->status !== CorporationRoleStatus::Held) {
            return MoonTaxSyncFailure::fromRoleStatus($lookup->status);
        }

        $donations = $this->readableDonations($corporationId, $lookup->value);
        if ($donations === null) {
            $this->logger->error('Corporation wallet journal response is unreadable', ['corporation_id' => $corporationId]);

            return MoonTaxSyncFailure::EsiError;
        }

        $this->store($corporationId, $donations);

        return null;
    }

    /**
     * @param list<int> $divisions
     *
     * @return list<array<mixed>>
     */
    private function readRaw(int $corporationId, Character $reader, array $divisions): array
    {
        $entries = [];
        foreach ($divisions as $division) {
            $entries = [...$entries, ...$this->esiClient->getPaginated(sprintf('/corporations/%d/wallets/%d/journal/', $corporationId, $division), $reader->getEveToken())];
        }

        return array_values($entries);
    }

    /**
     * @param list<mixed> $rawEntries
     *
     * @return list<array{journalId: int, firstPartyId: int, amount: float, date: \DateTimeImmutable, reason: ?string}>|null
     */
    private function readableDonations(int $corporationId, array $rawEntries): ?array
    {
        $donations = [];
        foreach ($rawEntries as $raw) {
            if (!is_array($raw) || !is_string($raw['ref_type'] ?? null)) {
                return null;
            }
            if ($raw['ref_type'] !== self::DONATION_REF_TYPE) {
                continue;
            }

            $donation = $this->readableDonation($raw);
            if ($donation === null) {
                return null;
            }
            if ($donation['amount'] > 0 && $donation['secondPartyId'] === $corporationId) {
                $donations[] = $donation;
            }
        }

        return $donations;
    }

    /**
     * @param array<mixed> $raw
     *
     * @return array{journalId: int, firstPartyId: int, secondPartyId: int, amount: float, date: \DateTimeImmutable, reason: ?string}|null
     */
    private function readableDonation(array $raw): ?array
    {
        $journalId = $raw['id'] ?? null;
        $firstPartyId = $raw['first_party_id'] ?? null;
        $secondPartyId = $raw['second_party_id'] ?? null;
        $amount = $raw['amount'] ?? null;
        $reason = $raw['reason'] ?? null;
        $date = is_string($raw['date'] ?? null) ? \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sp', $raw['date']) : false;
        if (!is_int($journalId) || !is_int($firstPartyId) || !is_int($secondPartyId) || !(is_int($amount) || is_float($amount)) || $date === false || ($reason !== null && !is_string($reason))) {
            return null;
        }

        return ['journalId' => $journalId, 'firstPartyId' => $firstPartyId, 'secondPartyId' => $secondPartyId, 'amount' => (float) $amount, 'date' => $date->setTimezone(new \DateTimeZone('UTC')), 'reason' => $reason];
    }

    /**
     * @param list<array{journalId: int, firstPartyId: int, amount: float, date: \DateTimeImmutable, reason: ?string}> $donations
     */
    private function store(int $corporationId, array $donations): void
    {
        if ($donations === []) {
            return;
        }

        $known = $this->donationRepository->findByJournalIds($corporationId, array_map(static fn (array $donation): int => $donation['journalId'], $donations));
        $seenJournalIds = array_keys($known);
        foreach ($donations as $donation) {
            if (in_array($donation['journalId'], $seenJournalIds, true)) {
                continue;
            }
            $this->entityManager->persist(
                (new CorporationWalletDonation())
                    ->setCorporationId($corporationId)
                    ->setJournalId($donation['journalId'])
                    ->setFirstPartyId($donation['firstPartyId'])
                    ->setAmount($donation['amount'])
                    ->setDate($donation['date'])
                    ->setReason($donation['reason']),
            );
            $seenJournalIds[] = $donation['journalId'];
        }
    }
}
