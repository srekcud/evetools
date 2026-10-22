<?php

declare(strict_types=1);

namespace App\State\Processor\Me;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Security\JwtBlacklist;
use App\Service\Account\AccountDeletionService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<mixed, void>
 */
final class DeleteAccountProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly AccountDeletionService $accountDeletionService,
        private readonly JwtBlacklist $jwtBlacklist,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $this->accountDeletionService->delete($user);

        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null) {
            $this->jwtBlacklist->blockBearerOf($request);
        }
    }
}
