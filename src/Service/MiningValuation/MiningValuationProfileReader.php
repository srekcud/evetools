<?php

declare(strict_types=1);

namespace App\Service\MiningValuation;

use App\Entity\User;
use App\Repository\MiningValuationProfileRepository;

final readonly class MiningValuationProfileReader
{
    public function __construct(private MiningValuationProfileRepository $profileRepository)
    {
    }

    public function read(User $user): MiningValuationSettings
    {
        $profile = $this->profileRepository->findOneBy(['user' => $user]);

        if ($profile === null) {
            return MiningValuationSettings::defaults();
        }

        return new MiningValuationSettings(
            $profile->getMarket(),
            $profile->getSide(),
            $profile->getForm(),
            $profile->getStructureId(),
            $profile->getStructureName(),
            $profile->getExportTax(),
            true,
        );
    }
}
