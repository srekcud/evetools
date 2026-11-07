<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\BlueprintLibraryResource;
use App\Entity\User;
use App\Service\Industry\Blueprint\BlueprintLibraryReader;
use App\Service\Industry\Blueprint\BlueprintLibraryRow;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<BlueprintLibraryResource>
 */
final readonly class BlueprintLibraryProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private BlueprintLibraryReader $libraryReader,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BlueprintLibraryResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        if ($user->getMainCharacter() === null) {
            throw new AccessDeniedHttpException('No main character set');
        }

        $library = $this->libraryReader->readFor($user);

        $resource = new BlueprintLibraryResource();
        $resource->corporationState = $library->corporationState;
        $resource->blueprints = array_map(
            static fn (BlueprintLibraryRow $row): array => [
                'typeId' => $row->typeId,
                'name' => $row->name,
                'kind' => $row->kind,
                'me' => $row->me,
                'te' => $row->te,
                'runs' => $row->runs,
                'scope' => $row->scope,
                'ownerName' => $row->ownerName,
                'locationId' => $row->locationId,
                'divisionNumber' => $row->divisionNumber,
                'count' => $row->count,
            ],
            $library->rows,
        );

        return $resource;
    }
}
