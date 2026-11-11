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
use App\Repository\UserRepository;
use App\Service\Assets\CorpVisibilityPlayers;
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
        private readonly UserRepository $userRepository,
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
        $players = $this->userRepository->findPlayersOfCorporation($corporationId);
        $restrictedDivisions = $this->validatedRestrictedDivisions($data, $players);

        // Fetched before saving: an ESI or auth failure must leave the visibility config untouched
        $allDivisions = $this->corporationService->getDivisions($mainCharacter);

        $visibility = $this->visibilityRepository->findByCorporationId($corporationId);

        if ($visibility === null) {
            $visibility = new CorpAssetVisibility();
            $visibility->setCorporationId($corporationId);
        }

        $visibility->setVisibleDivisions(array_values(array_unique($data->visibleDivisions)));
        $visibility->setRestrictedDivisions($restrictedDivisions);
        $visibility->setShareBlueprintsOfHiddenDivisions($data->shareBlueprintsOfHiddenDivisions);
        $visibility->setConfiguredBy($user);
        $visibility->setUpdatedAt(new \DateTimeImmutable());

        $this->em->persist($visibility);
        $this->em->flush();

        $resource = new CorpAssetVisibilityResource();
        $resource->visibleDivisions = $visibility->getVisibleDivisions();
        $resource->restrictedDivisions = CorpVisibilityPlayers::namedByDivision($restrictedDivisions, $players);
        $resource->shareBlueprintsOfHiddenDivisions = $visibility->sharesBlueprintsOfHiddenDivisions();
        $resource->allDivisions = $allDivisions;
        $resource->isDirector = true;
        $resource->configuredByName = $mainCharacter->getName();
        $resource->updatedAt = $visibility->getUpdatedAt()->format('c');

        return $resource;
    }

    /**
     * @param list<User> $players
     *
     * @return array<int, list<string>>
     */
    private function validatedRestrictedDivisions(UpdateCorpVisibilityInput $data, array $players): array
    {
        $playerIds = array_map(CorpVisibilityPlayers::idOf(...), $players);
        $restrictedDivisions = [];

        foreach ($data->restrictedDivisions as $division => $requestedPlayerIds) {
            if (!is_int($division) || $division < 1 || $division > 7) {
                throw new BadRequestHttpException('Each division must be an integer between 1 and 7');
            }
            if (!is_array($requestedPlayerIds)) {
                throw new BadRequestHttpException('Each restricted division must list player ids');
            }
            if (in_array($division, $data->visibleDivisions, true)) {
                throw new BadRequestHttpException('A division cannot be visible to all members and restricted to a list');
            }
            foreach ($requestedPlayerIds as $requestedPlayerId) {
                if (!is_string($requestedPlayerId) || !in_array($requestedPlayerId, $playerIds, true)) {
                    throw new BadRequestHttpException('Each listed player must be a player of the corporation');
                }
            }

            $uniquePlayerIds = array_values(array_unique($requestedPlayerIds));
            if ($uniquePlayerIds !== []) {
                $restrictedDivisions[$division] = $uniquePlayerIds;
            }
        }

        ksort($restrictedDivisions);

        return $restrictedDivisions;
    }
}
