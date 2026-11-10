<?php

declare(strict_types=1);

namespace App\State\Processor\Mining;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Input\Mining\MiningValuationProfileInput;
use App\ApiResource\Mining\MiningValuationProfileResource;
use App\Entity\MiningValuationProfile;
use App\Entity\User;
use App\Enum\MarketSide;
use App\Enum\MiningValuationForm;
use App\Enum\MiningValuationMarket;
use App\Repository\MiningValuationProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * @implements ProcessorInterface<MiningValuationProfileInput, MiningValuationProfileResource>
 */
final readonly class UpdateMiningValuationProfileProcessor implements ProcessorInterface
{
    private const string STRUCTURE_REQUIRED_MESSAGE = 'A structure is required for the structure market.';

    public function __construct(
        private Security $security,
        private MiningValuationProfileRepository $profileRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MiningValuationProfileResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof MiningValuationProfileInput);

        $stored = $this->profileRepository->findOneBy(['user' => $user]);
        $profile = $stored ?? (new MiningValuationProfile())->setUser($user);

        $this->rejectStructureMarketWithoutStructure($profile, $data);
        $this->apply($profile, $data);

        if ($stored === null) {
            $this->entityManager->persist($profile);
        }

        $this->entityManager->flush();

        $resource = new MiningValuationProfileResource();
        $resource->market = $profile->getMarket()->value;
        $resource->side = $profile->getSide()->value;
        $resource->form = $profile->getForm()->value;
        $resource->structureId = $profile->getStructureId();
        $resource->structureName = $profile->getStructureName();
        $resource->exportTax = $profile->getExportTax();
        $resource->persisted = true;

        return $resource;
    }

    private function rejectStructureMarketWithoutStructure(MiningValuationProfile $profile, MiningValuationProfileInput $input): void
    {
        $market = $input->market === null ? $profile->getMarket() : MiningValuationMarket::from($input->market);
        $structureId = $input->structureId ?? ($input->clearStructure ? null : $profile->getStructureId());

        if ($market !== MiningValuationMarket::Structure || $structureId !== null) {
            return;
        }

        throw new ValidationException(new ConstraintViolationList([
            new ConstraintViolation(self::STRUCTURE_REQUIRED_MESSAGE, self::STRUCTURE_REQUIRED_MESSAGE, [], $input, 'structureId', null),
        ]));
    }

    private function apply(MiningValuationProfile $profile, MiningValuationProfileInput $input): void
    {
        if ($input->market !== null) {
            $profile->setMarket(MiningValuationMarket::from($input->market));
        }

        if ($input->side !== null) {
            $profile->setSide(MarketSide::from($input->side));
        }

        if ($input->form !== null) {
            $profile->setForm(MiningValuationForm::from($input->form));
        }

        if ($input->clearStructure) {
            $profile->setStructureId(null);
            $profile->setStructureName(null);
        }

        if ($input->structureId !== null) {
            $profile->setStructureId($input->structureId);
            $profile->setStructureName($input->structureName);
        }

        if ($input->exportTax !== null) {
            $profile->setExportTax($input->exportTax);
        }
    }
}
