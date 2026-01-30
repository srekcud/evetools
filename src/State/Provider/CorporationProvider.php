<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\CorporationResource;
use App\Entity\User;
use App\Service\ESI\CorporationService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * @implements ProviderInterface<CorporationResource>
 */
class CorporationProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly CorporationService $corporationService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CorporationResource
    {
        /** @var User $user */
        $user = $this->security->getUser();
        $mainCharacter = $user->getMainCharacter();

        if ($mainCharacter === null) {
            throw new AccessDeniedHttpException('No main character set');
        }

        $data = $this->corporationService->getCorporationWithDivisions($mainCharacter);

        $resource = new CorporationResource();
        $resource->id = $data['id'];
        $resource->name = $data['name'];
        $resource->ticker = $data['ticker'];
        $resource->memberCount = $data['member_count'];
        $resource->allianceId = $data['alliance_id'];
        $resource->divisions = $data['divisions'];

        return $resource;
    }
}
