<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\Sde\InvType;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\IndustryActivityRepository;
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
    private const int HYDROGEN_FUEL_BLOCK_TYPE_ID = 4246;
    private const string HYDROGEN_FUEL_BLOCK_NAME = 'Hydrogen Fuel Block';
    private const int ROBOTICS_TYPE_ID = 9848;

    private function sdeTypeRepositoryKnowingOnlyNitrogenFuelBlock(): InvTypeRepository
    {
        return $this->sdeTypeRepositoryKnowing([self::NITROGEN_FUEL_BLOCK_TYPE_ID => self::NITROGEN_FUEL_BLOCK_NAME]);
    }

    private function calculationServiceOnSdeKnowingOnlyNitrogenFuelBlock(): IndustryCalculationService
    {
        return $this->calculationServiceOnSdeKnowing([self::NITROGEN_FUEL_BLOCK_TYPE_ID => self::NITROGEN_FUEL_BLOCK_NAME]);
    }

    private function calculationServiceOnSdeKnowingNitrogenAndHydrogenFuelBlocks(): IndustryCalculationService
    {
        return $this->calculationServiceOnSdeKnowing([
            self::NITROGEN_FUEL_BLOCK_TYPE_ID => self::NITROGEN_FUEL_BLOCK_NAME,
            self::HYDROGEN_FUEL_BLOCK_TYPE_ID => self::HYDROGEN_FUEL_BLOCK_NAME,
        ]);
    }

    private function sdeTypeRepositoryKnowing(array $typeNamesByTypeId): InvTypeRepository
    {
        $knownTypes = [];
        foreach ($typeNamesByTypeId as $typeId => $typeName) {
            $knownTypes[$typeId] = (new InvType())->setTypeId($typeId)->setTypeName($typeName);
        }

        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('find')->willReturnCallback(
            static fn (mixed $typeId): ?InvType => is_int($typeId) ? ($knownTypes[$typeId] ?? null) : null,
        );
        $invTypeRepository->method('findByTypeIds')->willReturnCallback(
            static fn (array $typeIds): array => array_intersect_key($knownTypes, array_flip($typeIds)),
        );

        return $invTypeRepository;
    }

    private function calculationServiceOnSdeKnowing(array $typeNamesByTypeId): IndustryCalculationService
    {
        return $this->calculationServiceOnSdeTypeRepository($this->sdeTypeRepositoryKnowing($typeNamesByTypeId));
    }

    private function calculationServiceOnSdeTypeRepository(InvTypeRepository $invTypeRepository): IndustryCalculationService
    {
        return new IndustryCalculationService(
            new TypeNameResolver($invTypeRepository),
            $this->createStub(IndustryBonusService::class),
            $this->createStub(IndustryStructureConfigRepository::class),
            $this->createStub(IndustryUserSettingsRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(StaStationRepository::class),
            $this->createStub(CachedStructureRepository::class),
            $this->createStub(IndustryActivityRepository::class),
            $this->createStub(CachedCharacterSkillRepository::class),
        );
    }
}
