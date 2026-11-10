<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\CachedWalletJournalEntry;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Repository\CachedWalletJournalEntryRepository;

final readonly class TransferJournalVerifier
{
    private const int CENTS_PER_ISK = 100;

    public function __construct(
        private CachedWalletJournalEntryRepository $journalRepository,
    ) {
    }

    /**
     * @return list<CachedWalletJournalEntry>
     */
    public function donationsOf(IndustryProject $project): array
    {
        return $this->journalRepository->findIncomingDonationsMentioning(ProjectPaymentReference::of($project->getId()));
    }

    /**
     * @param list<CachedWalletJournalEntry> $donations
     */
    public function verifiedAt(
        array $donations,
        IndustryProjectParticipant $from,
        IndustryProjectParticipant $to,
        int $amountCents,
    ): ?\DateTimeImmutable {
        $recipientId = $to->getUser()?->getId();
        $payer = $from->getUser();
        if ($recipientId === null || $payer === null) {
            return null;
        }

        $payerEveCharacterIds = $payer->getCharacters()->map(static fn (Character $character): int => $character->getEveCharacterId())->toArray();

        $receivedCents = 0;
        $lastReceivedAt = null;
        foreach ($donations as $donation) {
            if ($donation->getCharacter()->getUser()?->getId()?->equals($recipientId) !== true
                || !\in_array($donation->getFirstPartyId(), $payerEveCharacterIds, true)) {
                continue;
            }

            $receivedCents += (int) round($donation->getAmount() * self::CENTS_PER_ISK);
            $lastReceivedAt = $lastReceivedAt === null || $donation->getDate() > $lastReceivedAt ? $donation->getDate() : $lastReceivedAt;
        }

        return $receivedCents >= $amountCents ? $lastReceivedAt : null;
    }
}
