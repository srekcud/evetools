<?php

declare(strict_types=1);

namespace App\State\Provider\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Admin\AdminUserSheetCharacterDto;
use App\ApiResource\Admin\AdminUserSheetResource;
use App\ApiResource\Admin\AdminUserSheetSyncDto;
use App\Repository\UserRepository;
use App\Security\AdminAccessGuard;
use App\Service\Admin\UserSupportCharacter;
use App\Service\Admin\UserSupportSheetBuilder;
use App\Service\Admin\UserSupportSyncLine;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProviderInterface<AdminUserSheetResource>
 */
class AdminUserSheetProvider implements ProviderInterface
{
    public function __construct(
        private readonly AdminAccessGuard $accessGuard,
        private readonly UserRepository $users,
        private readonly UserSupportSheetBuilder $sheetBuilder,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AdminUserSheetResource
    {
        $admin = $this->accessGuard->requireAdmin();

        $userId = (string) ($uriVariables['userId'] ?? '');
        $user = Uuid::isValid($userId) ? $this->users->find(Uuid::fromString($userId)) : null;
        if ($user === null) {
            throw new NotFoundHttpException('User not found');
        }

        $this->logger->info('Admin viewed a user support sheet', [
            'adminId' => $admin->getId()?->toRfc4122(),
            'userId' => $userId,
        ]);

        $sheet = $this->sheetBuilder->build($user);
        $resource = new AdminUserSheetResource();
        $resource->userId = $sheet->userId;
        $resource->createdAt = $sheet->createdAt;
        $resource->lastLoginAt = $sheet->lastLoginAt;
        $resource->characters = array_map(
            static fn (UserSupportCharacter $character): AdminUserSheetCharacterDto => new AdminUserSheetCharacterDto(
                $character->eveCharacterId,
                $character->name,
                $character->isMain,
                $character->tokenState->value,
                $character->accessTokenExpiresAt,
                $character->missingScopes,
                $character->lastSyncAt,
            ),
            $sheet->characters,
        );
        $resource->syncs = array_map(
            static fn (UserSupportSyncLine $line): AdminUserSheetSyncDto => new AdminUserSheetSyncDto($line->type, $line->lastDataAt, $line->triggerable),
            $sheet->syncs,
        );

        return $resource;
    }
}
