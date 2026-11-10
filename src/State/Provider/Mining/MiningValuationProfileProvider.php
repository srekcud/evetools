<?php

declare(strict_types=1);

namespace App\State\Provider\Mining;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Mining\MiningValuationProfileResource;
use App\Entity\User;
use App\Service\MiningValuation\MiningValuationProfileReader;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<MiningValuationProfileResource>
 */
final readonly class MiningValuationProfileProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private MiningValuationProfileReader $profileReader,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MiningValuationProfileResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $settings = $this->profileReader->read($user);

        $resource = new MiningValuationProfileResource();
        $resource->market = $settings->market->value;
        $resource->side = $settings->side->value;
        $resource->form = $settings->form->value;
        $resource->structureId = $settings->structureId;
        $resource->structureName = $settings->structureName;
        $resource->exportTax = $settings->exportTax;
        $resource->persisted = $settings->persisted;

        return $resource;
    }
}
