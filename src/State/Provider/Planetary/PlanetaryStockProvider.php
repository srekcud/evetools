<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Planetary\PlanetarySettingsResource;
use App\ApiResource\Planetary\PlanetaryStockResource;
use App\Entity\User;
use App\Service\Planetary\Stock\PlanetaryStockReader;
use App\Service\Planetary\Stock\PlanetaryStockSettings;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<PlanetaryStockResource>
 */
final readonly class PlanetaryStockProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private PlanetaryStockReader $reader,
        private int $defaultMarketStructureId,
        private string $defaultMarketStructureName,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlanetaryStockResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Authentication required');
        }

        $settings = PlanetarySettingsResource::forUser($user, $this->defaultMarketStructureId, $this->defaultMarketStructureName);
        $this->reader->read($user, new PlanetaryStockSettings($settings->homeLocationId, $settings->hubStructureId, $settings->coverageWeeks));

        $resource = new PlanetaryStockResource();
        $resource->homeLocationId = $settings->homeLocationId;
        $resource->homeLocationName = $settings->homeLocationName;
        $resource->hubStructureId = $settings->hubStructureId;
        $resource->hubStructureName = $settings->hubStructureName;
        $resource->coverageWeeks = $settings->coverageWeeks;

        return $resource;
    }
}
