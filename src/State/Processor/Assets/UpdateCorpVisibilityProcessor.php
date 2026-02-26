<?php

declare(strict_types=1);

namespace App\State\Processor\Assets;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Assets\CorpAssetVisibilityResource;
use App\ApiResource\Input\Assets\UpdateCorpVisibilityInput;
use App\Entity\CorpAssetVisibility;
use App\Entity\User;
use App\Repository\CorpAssetVisibilityRepository;
use App\Service\ESI\CharacterService;
use App\Service\ESI\CorporationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<UpdateCorpVisibilityInput, CorpAssetVisibilityResource>
 */
class UpdateCorpVisibilityProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly CorpAssetVisibilityRepository $visibilityRepository,
        private readonly CharacterService $characterService,
        private readonly CorporationService $corporationService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CorpAssetVisibilityResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $mainCharacter = $user->getMainCharacter();
        if ($mainCharacter === null) {
            throw new AccessDeniedHttpException('No main character set');
        }

        if (!$this->characterService->canReadCorporationAssets($mainCharacter)) {
            throw new AccessDeniedHttpException('Only Directors can configure asset visibility');
        }

        assert($data instanceof UpdateCorpVisibilityInput);

        // Validate each division number is between 1 and 7
        foreach ($data->visibleDivisions as $division) {
            if (!is_int($division) || $division < 1 || $division > 7) {
                throw new BadRequestHttpException('Each division must be an integer between 1 and 7');
            }
        }

        $corporationId = $mainCharacter->getCorporationId();
        $visibility = $this->visibilityRepository->findByCorporationId($corporationId);

        if ($visibility === null) {
            $visibility = new CorpAssetVisibility();
            $visibility->setCorporationId($corporationId);
        }

        $visibility->setVisibleDivisions(array_values(array_unique($data->visibleDivisions)));
        $visibility->setConfiguredBy($user);
        $visibility->setUpdatedAt(new \DateTimeImmutable());

        $this->em->persist($visibility);
        $this->em->flush();

        $allDivisions = $this->corporationService->getDivisions($mainCharacter);

        $resource = new CorpAssetVisibilityResource();
        $resource->visibleDivisions = $visibility->getVisibleDivisions();
        $resource->allDivisions = $allDivisions;
        $resource->isDirector = true;
        $resource->configuredByName = $mainCharacter->getName();
        $resource->updatedAt = $visibility->getUpdatedAt()->format('c');

        return $resource;
    }
}
