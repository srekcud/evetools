<?php

declare(strict_types=1);

namespace App\State\Processor\MoonTax;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\MoonTax\MoonTaxRefineryInput;
use App\ApiResource\MoonTax\MoonTaxCorporationResource;
use App\ApiResource\MoonTax\MoonTaxRefineryResource;
use App\Entity\MoonTaxRefinerySetting;
use App\Enum\MoonTaxPeriodKind;
use App\Repository\CorporationMiningExtractionRepository;
use App\Repository\MoonTaxRefinerySettingRepository;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MoonTax\MoonTaxManagerAccess;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @implements ProcessorInterface<MoonTaxRefineryInput, MoonTaxRefineryResource>
 */
final readonly class SetMoonTaxRefineryProcessor implements ProcessorInterface
{
    private const int RATE_DECIMALS = 2;

    public function __construct(
        private Security $security,
        private MoonTaxManagerAccess $managerAccess,
        private CorporationMiningExtractionRepository $extractionRepository,
        private MoonTaxRefinerySettingRepository $settingRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MoonTaxRefineryResource
    {
        \assert($data instanceof MoonTaxRefineryInput);

        $corporationId = (int) $uriVariables['corporationId'];
        $structureId = (int) $uriVariables['structureId'];
        $this->managerAccess->assertManages(MiningOperationRequest::userOf($this->security), $corporationId);

        if (!$this->extractionRepository->existsForRefinery($corporationId, $structureId)) {
            throw new UnprocessableEntityHttpException('This refinery has no moon extraction known for the corporation');
        }

        $setting = $this->settingRepository->findOneBy(['corporationId' => $corporationId, 'structureId' => $structureId])
            ?? (new MoonTaxRefinerySetting())->setCorporationId($corporationId)->setStructureId($structureId);
        $setting
            ->setOpenToAlliance((bool) $data->openToAlliance)
            ->setPeriod(MoonTaxPeriodKind::from((string) $data->period))
            ->setRateR4(round((float) $data->rateR4, self::RATE_DECIMALS))
            ->setRateR8(round((float) $data->rateR8, self::RATE_DECIMALS))
            ->setRateR16(round((float) $data->rateR16, self::RATE_DECIMALS))
            ->setRateR32(round((float) $data->rateR32, self::RATE_DECIMALS))
            ->setRateR64(round((float) $data->rateR64, self::RATE_DECIMALS))
            ->setWalletDivision((int) $data->walletDivision);

        $this->entityManager->persist($setting);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            $this->logger->warning('Moon tax refinery setting written concurrently', ['corporation_id' => $corporationId, 'structure_id' => $structureId, 'error' => $exception->getMessage()]);

            throw new ConflictHttpException('The refinery setting was changed concurrently, retry', $exception);
        }

        $resource = new MoonTaxRefineryResource();
        $resource->corporation = MoonTaxCorporationResource::identifiedBy($corporationId);
        $resource->corporationId = $corporationId;
        $resource->structureId = $structureId;
        $resource->openToAlliance = $setting->isOpenToAlliance();
        $resource->period = $setting->getPeriod()->value;
        $resource->rateR4 = $setting->getRateR4();
        $resource->rateR8 = $setting->getRateR8();
        $resource->rateR16 = $setting->getRateR16();
        $resource->rateR32 = $setting->getRateR32();
        $resource->rateR64 = $setting->getRateR64();
        $resource->walletDivision = $setting->getWalletDivision();

        return $resource;
    }
}
