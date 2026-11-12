<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\Constant\EveConstants;
use App\Dto\BlueprintDto;
use App\Entity\Character;
use App\Entity\OwnedBlueprint;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\CharacterRepository;
use App\Repository\OwnedBlueprintRepository;
use App\Service\ESI\BlueprintsService;
use App\Service\ESI\CorporationRoleReader;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class BlueprintSyncService
{
    private const string SYNC_TYPE = 'blueprints';

    private const string DIVISION_FLAG_PATTERN = '/^CorpSAG([1-7])$/';

    public function __construct(
        private readonly BlueprintsService $blueprintsService,
        private readonly OwnedBlueprintRepository $ownedBlueprintRepository,
        private readonly CachedAssetRepository $cachedAssetRepository,
        private readonly CharacterRepository $characterRepository,
        private readonly CorporationRoleReader $corporationRoleReader,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly MercurePublisherService $mercurePublisher,
    ) {
    }

    public function syncUserBlueprints(User $user): void
    {
        $userId = $user->getId()?->toRfc4122() ?? throw new \LogicException('Cannot sync blueprints of a user without id');

        $this->mercurePublisher->syncStarted($userId, self::SYNC_TYPE, 'Fetching blueprints...');

        try {
            $characters = $user->getCharacters()->getValues();
            $corporationIds = $user->getCharacterCorporationIds();
            $stepCount = count($characters) + count($corporationIds);
            $stepsDone = 0;
            $syncedCharacters = 0;
            $syncedCorporations = 0;
            $failures = [];

            foreach ($characters as $character) {
                $outcome = $this->syncCharacter($character);
                if ($outcome instanceof CharacterSyncFailure) {
                    $failures[] = $outcome->toArray();
                } elseif ($outcome) {
                    $syncedCharacters++;
                }

                $this->publishProgress($userId, ++$stepsDone, $stepCount);
            }

            foreach ($corporationIds as $corporationId) {
                $outcome = $this->syncCorporation($corporationId);
                if ($outcome instanceof CharacterSyncFailure) {
                    $failures[] = $outcome->toArray();
                } elseif ($outcome) {
                    $syncedCorporations++;
                }

                $this->publishProgress($userId, ++$stepsDone, $stepCount);
            }

            $data = [
                'characters' => $syncedCharacters,
                'corporations' => $syncedCorporations,
                'failedCharacters' => $failures,
            ];

            if ($failures !== [] && $syncedCharacters === 0 && $syncedCorporations === 0) {
                $this->mercurePublisher->syncError($userId, self::SYNC_TYPE, 'No blueprints could be synced', $data);

                return;
            }

            $message = sprintf('%d characters and %d corporations synced', $syncedCharacters, $syncedCorporations);
            if ($failures !== []) {
                $message .= sprintf(', %d characters not synced', count($failures));
            }
            $this->mercurePublisher->syncCompleted($userId, self::SYNC_TYPE, $message, $data);
        } catch (\Throwable $e) {
            $this->mercurePublisher->syncError($userId, self::SYNC_TYPE, $e->getMessage());
            throw $e;
        }
    }

    private function publishProgress(string $userId, int $stepsDone, int $stepCount): void
    {
        $this->mercurePublisher->syncProgress(
            $userId,
            self::SYNC_TYPE,
            intdiv(100 * $stepsDone, $stepCount),
            sprintf('%d/%d steps processed', $stepsDone, $stepCount),
        );
    }

    private function syncCharacter(Character $character): bool|CharacterSyncFailure
    {
        $token = $character->getEveToken();
        if ($token === null || !$character->isAuthValid() || !$token->hasScope(EveConstants::CHARACTER_BLUEPRINTS_SCOPE)) {
            return false;
        }

        try {
            $blueprints = $this->blueprintsService->getCharacterBlueprints($character);
        } catch (\Throwable $e) {
            $failure = CharacterSyncFailure::fromThrowable($character, $e);
            $this->logger->warning('Failed to read character blueprints', $failure->logContext($e->getMessage()));

            return $failure;
        }

        $this->ownedBlueprintRepository->deleteByCharacter($character);
        $syncedAt = new \DateTimeImmutable();
        foreach ($blueprints as $blueprint) {
            $this->entityManager->persist(
                $this->ownedBlueprintOf($blueprint, $syncedAt)->setCharacter($character),
            );
        }
        $this->entityManager->flush();

        return true;
    }

    private function syncCorporation(int $corporationId): bool|CharacterSyncFailure
    {
        $reader = $this->corporationReader($corporationId);
        if ($reader === null) {
            return false;
        }

        try {
            $blueprints = $this->blueprintsService->getCorporationBlueprints($reader);
        } catch (\Throwable $e) {
            $failure = CharacterSyncFailure::fromThrowable($reader, $e);
            $this->logger->warning('Failed to read corporation blueprints', $failure->logContext($e->getMessage()));

            return $failure;
        }

        $this->ownedBlueprintRepository->deleteByCorporationId($corporationId);
        $syncedAt = new \DateTimeImmutable();
        foreach ($blueprints as $blueprint) {
            $this->entityManager->persist(
                $this->ownedBlueprintOf($blueprint, $syncedAt)
                    ->setCorporationId($corporationId)
                    ->setDivisionNumber($this->divisionOf($blueprint, $corporationId)),
            );
        }
        $this->entityManager->flush();

        return true;
    }

    private function corporationReader(int $corporationId): ?Character
    {
        return $this->corporationRoleReader->findHolder(
            $corporationId,
            array_values($this->characterRepository->findByCorporationId($corporationId)),
            [],
            [EveConstants::CORPORATION_BLUEPRINTS_SCOPE, EveConstants::CORPORATION_ROLES_SCOPE],
        )->holder;
    }

    private function ownedBlueprintOf(BlueprintDto $blueprint, \DateTimeImmutable $syncedAt): OwnedBlueprint
    {
        return (new OwnedBlueprint())
            ->setItemId($blueprint->itemId)
            ->setTypeId($blueprint->typeId)
            ->setLocationId($blueprint->locationId)
            ->setLocationFlag($blueprint->locationFlag)
            ->setQuantity($blueprint->quantity)
            ->setMaterialEfficiency($blueprint->materialEfficiency)
            ->setTimeEfficiency($blueprint->timeEfficiency)
            ->setRuns($blueprint->runs)
            ->setSyncedAt($syncedAt);
    }

    private function divisionOf(BlueprintDto $blueprint, int $corporationId): ?int
    {
        $divisionNumber = self::divisionNumberOfFlag($blueprint->locationFlag);
        if ($divisionNumber !== null) {
            return $divisionNumber;
        }

        $container = $this->cachedAssetRepository->findOneBy([
            'itemId' => $blueprint->locationId,
            'corporationId' => $corporationId,
        ]);
        $containerFlag = $container?->getLocationFlag();

        return $containerFlag === null ? null : self::divisionNumberOfFlag($containerFlag);
    }

    private static function divisionNumberOfFlag(string $locationFlag): ?int
    {
        return preg_match(self::DIVISION_FLAG_PATTERN, $locationFlag, $matches) === 1 ? (int) $matches[1] : null;
    }
}
