<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\Sde\InvType;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\StaStationRepository;
use App\Service\Industry\IndustryBonusService;
use App\Service\Industry\IndustryCalculationService;
use App\Service\TypeNameResolver;
use Doctrine\ORM\EntityManagerInterface;

trait ResolvesTypeNamesFromSde
{
    private const int NITROGEN_FUEL_BLOCK_TYPE_ID = 4051;
    private const string NITROGEN_FUEL_BLOCK_NAME = 'Nitrogen Fuel Block';
    private const int ROBOTICS_TYPE_ID = 9848;

    private function sdeTypeRepositoryKnowingOnlyNitrogenFuelBlock(): InvTypeRepository
    {
        $nitrogenFuelBlock = (new InvType())
            ->setTypeId(self::NITROGEN_FUEL_BLOCK_TYPE_ID)
            ->setTypeName(self::NITROGEN_FUEL_BLOCK_NAME);

        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('find')->willReturnCallback(
            static fn (mixed $typeId): ?InvType => $typeId === self::NITROGEN_FUEL_BLOCK_TYPE_ID ? $nitrogenFuelBlock : null,
        );

        return $invTypeRepository;
    }

    private function calculationServiceOnSdeKnowingOnlyNitrogenFuelBlock(): IndustryCalculationService
    {
        $invTypeRepository = $this->sdeTypeRepositoryKnowingOnlyNitrogenFuelBlock();

        return new IndustryCalculationService(
            $invTypeRepository,
            new TypeNameResolver($invTypeRepository),
            $this->createStub(IndustryBonusService::class),
            $this->createStub(IndustryStructureConfigRepository::class),
            $this->createStub(IndustryUserSettingsRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(StaStationRepository::class),
            $this->createStub(CachedStructureRepository::class),
        );
    }
}
