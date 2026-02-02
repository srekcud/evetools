<?php

declare(strict_types=1);

namespace App\State\Processor\Me;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Me\CharacterResource;
use App\Entity\Character;
use App\Entity\User;
use App\Repository\CharacterRepository;
use App\Service\ESI\AuthenticationService;
use App\Service\ESI\TokenManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<mixed, CharacterResource>
 */
class SetMainCharacterProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly CharacterRepository $characterRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenManager $tokenManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CharacterResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $character = $this->characterRepository->find($uriVariables['id']);

        if ($character === null) {
            throw new NotFoundHttpException('Character not found');
        }

        if ($character->getUser() !== $user) {
            throw new AccessDeniedHttpException('Character does not belong to you');
        }

        $user->setMainCharacter($character);
        $this->entityManager->flush();

        return $this->toResource($character);
    }

    private function toResource(Character $character): CharacterResource
    {
        $token = $character->getEveToken();
        $hasValidToken = $token !== null && !empty($token->getScopes()) && $token->getScopes() !== [''];

        if ($hasValidToken && $token !== null) {
            try {
                $this->tokenManager->decryptRefreshToken($token->getRefreshTokenEncrypted());
            } catch (\RuntimeException) {
                $hasValidToken = false;
            }
        }

        $hasMissingScopes = false;
        if ($hasValidToken && $token !== null) {
            $tokenScopes = $token->getScopes();
            $requiredScopes = AuthenticationService::getRequiredScopes();
            $missingScopes = array_diff($requiredScopes, $tokenScopes);
            $hasMissingScopes = !empty($missingScopes);
        }

        $resource = new CharacterResource();
        $resource->id = $character->getId()?->toRfc4122() ?? '';
        $resource->eveCharacterId = $character->getEveCharacterId();
        $resource->name = $character->getName();
        $resource->corporationId = $character->getCorporationId();
        $resource->corporationName = $character->getCorporationName();
        $resource->allianceId = $character->getAllianceId();
        $resource->allianceName = $character->getAllianceName();
        $resource->isMain = $character->isMain();
        $resource->hasValidToken = $hasValidToken && !$hasMissingScopes;
        $resource->hasMissingScopes = $hasMissingScopes;
        $resource->lastSyncAt = $character->getLastSyncAt()?->format('c');

        return $resource;
    }
}
