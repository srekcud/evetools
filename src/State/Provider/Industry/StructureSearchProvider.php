<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\StructureSearchListResource;
use App\ApiResource\Industry\StructureSearchResource;
use App\Entity\CachedStructure;
use App\Entity\User;
use App\Exception\EsiApiException;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\ESI\EsiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<StructureSearchListResource>
 */
class StructureSearchProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly EsiClient $esiClient,
        private readonly IndustryStructureConfigRepository $structureConfigRepository,
        private readonly CachedStructureRepository $cachedStructureRepository,
        private readonly MapSolarSystemRepository $solarSystemRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StructureSearchListResource
    {
        $result = new StructureSearchListResource();
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $request = $this->requestStack->getCurrentRequest();
        $query = trim($request?->query->get('q', '') ?? '');

        if (strlen($query) < 3) {
            throw new BadRequestHttpException('Query must be at least 3 characters');
        }

        $mainCharacter = $user->getMainCharacter();
        if ($mainCharacter === null) {
            throw new AccessDeniedHttpException('No main character set');
        }

        $token = $mainCharacter->getEveToken();
        if ($token === null) {
            throw new AccessDeniedHttpException('No token available');
        }

        try {
            $characterId = $mainCharacter->getEveCharacterId();
            $endpoint = sprintf(
                '/characters/%d/search/?categories=structure&search=%s&strict=false',
                $characterId,
                urlencode($query)
            );

            $searchResults = $this->esiClient->get($endpoint, $token);
            $structureIds = $searchResults['structure'] ?? [];

            if (empty($structureIds)) {
                return $result;
            }

            $existingConfigs = $this->structureConfigRepository->findByUser($user);
            $existingLocationIds = [];
            foreach ($existingConfigs as $config) {
                $locId = $config->getLocationId();
                if ($locId !== null) {
                    $existingLocationIds[$locId] = true;
                }
            }

            $structureIds = array_filter($structureIds, fn ($id) => !isset($existingLocationIds[$id]));

            if (empty($structureIds)) {
                return $result;
            }

            $structureIds = array_slice($structureIds, 0, 10);

            $structures = [];
            $userCorporationId = $user->getCorporationId();

            foreach ($structureIds as $structureId) {
                try {
                    $structureEndpoint = sprintf('/universe/structures/%d/', $structureId);
                    $structureInfo = $this->esiClient->get($structureEndpoint, $token);

                    $ownerId = $structureInfo['owner_id'] ?? null;
                    $typeId = $structureInfo['type_id'] ?? null;
                    $solarSystemId = $structureInfo['solar_system_id'] ?? null;
                    $name = $structureInfo['name'] ?? 'Unknown';

                    $this->cacheStructureInfo($structureId, $name, $solarSystemId, $ownerId, $typeId);

                    $solarSystemName = null;
                    if ($solarSystemId !== null) {
                        $solarSystem = $this->solarSystemRepository->findBySolarSystemId($solarSystemId);
                        $solarSystemName = $solarSystem?->getSolarSystemName();
                    }

                    $resource = new StructureSearchResource();
                    $resource->locationId = $structureId;
                    $resource->locationName = $name;
                    $resource->solarSystemId = $solarSystemId;
                    $resource->solarSystemName = $solarSystemName;
                    $resource->structureType = $this->mapTypeIdToStructureType($typeId);
                    $resource->typeId = $typeId;
                    $resource->isCorporationOwned = $ownerId !== null && $ownerId === $userCorporationId;

                    $structures[] = $resource;
                } catch (EsiApiException $e) {
                    $this->logger->debug('Cannot access structure', [
                        'structureId' => $structureId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $result->structures = $structures;
            return $result;
        } catch (EsiApiException $e) {
            $message = $e->getMessage();

            if (str_contains($message, 'Error limited') || str_contains($message, '420')) {
                throw new \Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException(
                    null,
                    'ESI rate limit atteint. Réessayez dans quelques secondes.'
                );
            }

            throw new \Symfony\Component\HttpKernel\Exception\BadGatewayException($message);
        }
    }

    private function mapTypeIdToStructureType(?int $typeId): ?string
    {
        return match ($typeId) {
            35825 => 'raitaru',
            35826 => 'azbel',
            35827 => 'sotiyo',
            35835 => 'athanor',
            35836 => 'tatara',
            default => null,
        };
    }

    private function cacheStructureInfo(int $structureId, string $name, ?int $solarSystemId, ?int $ownerId, ?int $typeId): void
    {
        $cached = $this->cachedStructureRepository->findByStructureId($structureId);

        if ($cached === null) {
            $cached = new CachedStructure();
            $cached->setStructureId($structureId);
            $this->entityManager->persist($cached);
        }

        $cached->setName($name);
        $cached->setSolarSystemId($solarSystemId);
        $cached->setOwnerCorporationId($ownerId);
        $cached->setTypeId($typeId);
        $cached->setResolvedAt(new \DateTimeImmutable());

        $this->entityManager->flush();
    }
}
