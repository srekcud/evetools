<?php

declare(strict_types=1);

namespace App\State\Processor\Me;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Input\DeleteAccountInput;
use App\Entity\User;
use App\Security\JwtBlacklist;
use App\Service\Account\AccountDeletionService;
use Psr\Cache\CacheException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * @implements ProcessorInterface<DeleteAccountInput, void>
 */
final class DeleteAccountProcessor implements ProcessorInterface
{
    private const string CONFIRMATION_PROPERTY_PATH = 'confirmation';
    private const string CONFIRMATION_MISMATCH_MESSAGE = 'The confirmation must be the exact name of the main character.';

    public function __construct(
        private readonly Security $security,
        private readonly AccountDeletionService $accountDeletionService,
        private readonly JwtBlacklist $jwtBlacklist,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $this->assertConfirmationMatchesMainCharacterName($data, $user);

        $this->accountDeletionService->delete($user);

        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return;
        }

        try {
            $this->jwtBlacklist->blockBearerOf($request);
        } catch (CacheException $exception) {
            $this->logger->warning('JWT of the deleted account could not be added to the blocklist: {error}', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function assertConfirmationMatchesMainCharacterName(DeleteAccountInput $input, User $user): void
    {
        $mainCharacter = $user->getMainCharacter();
        if ($mainCharacter !== null && $input->confirmation === $mainCharacter->getName()) {
            return;
        }

        throw new ValidationException(new ConstraintViolationList([
            new ConstraintViolation(self::CONFIRMATION_MISMATCH_MESSAGE, self::CONFIRMATION_MISMATCH_MESSAGE, [], $input, self::CONFIRMATION_PROPERTY_PATH, $input->confirmation),
        ]));
    }
}
