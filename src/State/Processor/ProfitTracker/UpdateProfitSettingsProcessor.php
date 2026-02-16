<?php

declare(strict_types=1);

namespace App\State\Processor\ProfitTracker;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\ProfitTracker\ProfitSettingsResource;
use App\Entity\ProfitSettings;
use App\Entity\User;
use App\Repository\ProfitSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<ProfitSettingsResource, ProfitSettingsResource>
 */
class UpdateProfitSettingsProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly ProfitSettingsRepository $settingsRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProfitSettingsResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $settings = $this->settingsRepository->getOrCreate($user);

        assert($data instanceof ProfitSettingsResource);

        // Update salesTaxRate if provided
        if (isset($data->salesTaxRate)) {
            if ($data->salesTaxRate < 0 || $data->salesTaxRate > 1) {
                throw new BadRequestHttpException('salesTaxRate must be between 0 and 1');
            }
            $settings->setSalesTaxRate($data->salesTaxRate);
        }

        // Update defaultCostSource if provided
        if (isset($data->defaultCostSource)) {
            if (!in_array($data->defaultCostSource, [
                ProfitSettings::COST_SOURCE_MARKET,
                ProfitSettings::COST_SOURCE_PROJECT,
                ProfitSettings::COST_SOURCE_MANUAL,
            ], true)) {
                throw new BadRequestHttpException(sprintf(
                    'Invalid defaultCostSource. Must be "%s", "%s", or "%s".',
                    ProfitSettings::COST_SOURCE_MARKET,
                    ProfitSettings::COST_SOURCE_PROJECT,
                    ProfitSettings::COST_SOURCE_MANUAL
                ));
            }
            $settings->setDefaultCostSource($data->defaultCostSource);
        }

        $this->entityManager->flush();

        // Return updated settings
        $resource = new ProfitSettingsResource();
        $resource->salesTaxRate = $settings->getSalesTaxRate();
        $resource->defaultCostSource = $settings->getDefaultCostSource();
        $resource->updatedAt = $settings->getUpdatedAt()->format('c');

        return $resource;
    }
}
