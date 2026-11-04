<?php

declare(strict_types=1);

namespace App\State\Processor\MoonTax;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\MoonTax\AttributeMoonTaxDonationInput;
use App\ApiResource\MoonTax\MoonTaxCorporationResource;
use App\ApiResource\MoonTax\MoonTaxDonationResource;
use App\Repository\CorporationWalletDonationRepository;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MoonTax\MoonTaxManagerAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProcessorInterface<AttributeMoonTaxDonationInput, MoonTaxDonationResource>
 */
final readonly class AttributeMoonTaxDonationProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MoonTaxManagerAccess $managerAccess,
        private CorporationWalletDonationRepository $donationRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MoonTaxDonationResource
    {
        \assert($data instanceof AttributeMoonTaxDonationInput);

        $user = MiningOperationRequest::userOf($this->security);
        $corporationId = (int) $uriVariables['corporationId'];
        $this->managerAccess->assertManages($user, $corporationId);

        $donation = $this->donationRepository->findOneBy(['corporationId' => $corporationId, 'journalId' => (int) $uriVariables['journalId']])
            ?? throw new NotFoundHttpException('Donation not found');
        $donation->setAttributedCharacterId($data->characterId)->setAttributedBy($user)->setAttributedAt($this->clock->now());
        $this->entityManager->flush();

        $resource = new MoonTaxDonationResource();
        $resource->corporation = MoonTaxCorporationResource::identifiedBy($corporationId);
        $resource->journalId = $donation->getJournalId();
        $resource->corporationId = $donation->getCorporationId();
        $resource->firstPartyId = $donation->getFirstPartyId();
        $resource->attributedCharacterId = $donation->getAttributedCharacterId();
        $resource->attributedAt = $donation->getAttributedAt();

        return $resource;
    }
}
