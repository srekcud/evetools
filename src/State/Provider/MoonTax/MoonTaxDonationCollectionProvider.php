<?php

declare(strict_types=1);

namespace App\State\Provider\MoonTax;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MoonTax\MoonTaxCorporationResource;
use App\ApiResource\MoonTax\MoonTaxDonationEntryResource;
use App\Entity\CorporationWalletDonation;
use App\Repository\CorporationWalletDonationRepository;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MoonTax\MoonTaxAllianceResolver;
use App\Service\MoonTax\MoonTaxManagerAccess;
use App\Service\MoonTax\MoonTaxStatementReader;
use App\Service\MoonTax\MoonTaxStatementView;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;

/**
 * @implements ProviderInterface<MoonTaxDonationEntryResource>
 */
final readonly class MoonTaxDonationCollectionProvider implements ProviderInterface
{
    private const int HISTORY_DAYS = 90;

    public function __construct(
        private Security $security,
        private MoonTaxManagerAccess $managerAccess,
        private CorporationWalletDonationRepository $donationRepository,
        private MoonTaxStatementReader $statementReader,
        private MoonTaxAllianceResolver $nameResolver,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<MoonTaxDonationEntryResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $corporationId = (int) $uriVariables['corporationId'];
        $this->managerAccess->assertManages(MiningOperationRequest::userOf($this->security), $corporationId);

        $since = $this->clock->now()->modify(sprintf('-%d days', self::HISTORY_DAYS));
        $donations = $this->donationRepository->findOfCorporationSince($corporationId, $since);
        if ($donations === []) {
            return [];
        }

        $minerIds = array_flip(array_map(
            static fn (MoonTaxStatementView $view): int => $view->characterId,
            $this->statementReader->ofCorporation($corporationId),
        ));
        $names = $this->nameResolver->characterNames($this->namedCharacterIds($donations));

        return array_map(
            fn (CorporationWalletDonation $donation): MoonTaxDonationEntryResource => $this->toResource($donation, $names, $minerIds),
            $donations,
        );
    }

    /**
     * @param list<CorporationWalletDonation> $donations
     *
     * @return list<int>
     */
    private function namedCharacterIds(array $donations): array
    {
        $ids = [];
        foreach ($donations as $donation) {
            $ids[] = $donation->getFirstPartyId();
            if ($donation->getAttributedCharacterId() !== null) {
                $ids[] = $donation->getAttributedCharacterId();
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<int, ?string> $names
     * @param array<int, int>     $minerIds
     */
    private function toResource(CorporationWalletDonation $donation, array $names, array $minerIds): MoonTaxDonationEntryResource
    {
        $attributedCharacterId = $donation->getAttributedCharacterId();

        $resource = new MoonTaxDonationEntryResource();
        $resource->corporation = MoonTaxCorporationResource::identifiedBy($donation->getCorporationId());
        $resource->journalId = $donation->getJournalId();
        $resource->corporationId = $donation->getCorporationId();
        $resource->date = $donation->getDate();
        $resource->amount = $donation->getAmount();
        $resource->reason = $donation->getReason();
        $resource->firstPartyId = $donation->getFirstPartyId();
        $resource->firstPartyName = $names[$donation->getFirstPartyId()];
        $resource->attributedCharacterId = $attributedCharacterId;
        $resource->attributedCharacterName = $attributedCharacterId === null ? null : $names[$attributedCharacterId];
        $resource->attributedAt = $donation->getAttributedAt();
        $resource->payerIsMiner = isset($minerIds[$attributedCharacterId ?? $donation->getFirstPartyId()]);

        return $resource;
    }
}
