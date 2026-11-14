<?php

declare(strict_types=1);

namespace App\State\Provider\Assets;

use App\Dto\Assets\AssetScope;
use App\Entity\User;
use App\Repository\CharacterRepository;
use App\Repository\CorpAssetVisibilityRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

final readonly class AssetBrowseOwnerResolver
{
    public function __construct(
        private Security $security,
        private RequestStack $requestStack,
        private CharacterRepository $characterRepository,
        private CorpAssetVisibilityRepository $visibilityRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    public function resolve(array $uriVariables): AssetBrowseOwner
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        return isset($uriVariables['characterId'])
            ? $this->ofCharacter($user, (string) $uriVariables['characterId'])
            : $this->ofCorporation($user);
    }

    private function ofCharacter(User $user, string $characterId): AssetBrowseOwner
    {
        $character = Uuid::isValid($characterId) ? $this->characterRepository->find(Uuid::fromString($characterId)) : null;
        if ($character === null || $character->getUser() !== $user) {
            throw new NotFoundHttpException('Character not found');
        }

        return new AssetBrowseOwner(AssetScope::forCharacter($character->getId()?->toRfc4122() ?? throw new \LogicException('A loaded character is persisted')), $character, null);
    }

    private function ofCorporation(User $user): AssetBrowseOwner
    {
        $mainCharacter = $user->getMainCharacter();
        if ($mainCharacter === null) {
            throw new AccessDeniedHttpException('No main character set');
        }

        $corporationId = $mainCharacter->getCorporationId();
        $visibility = $this->visibilityRepository->findByCorporationId($corporationId);
        $visibleDivisions = $visibility?->divisionsVisibleTo($user) ?? [];
        $divisionName = $this->requestStack->getCurrentRequest()?->query->get('divisionName');

        return new AssetBrowseOwner(
            AssetScope::forCorporation($corporationId, $visibleDivisions, \is_string($divisionName) ? $divisionName : null),
            null,
            $corporationId,
        );
    }
}
