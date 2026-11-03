<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\ApiResource\Input\MiningOperation\CreateMiningOperationInput;
use App\Entity\Character;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Entity\User;
use App\Enum\MiningCharacterEligibility;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationVisibility;
use App\Enum\MiningSplitMode;
use App\Repository\MiningOperationCharacterRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final readonly class MiningOperationEnrolment
{
    private const string MINING_LEDGER_SCOPE = 'esi-industry.read_character_mining.v1';

    public function __construct(
        private MiningOperationCharacterRepository $characterRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function create(User $owner, CreateMiningOperationInput $input): MiningOperation
    {
        $visibility = MiningOperationVisibility::from($input->visibility);
        $corporationId = $owner->getCorporationId();
        if ($visibility === MiningOperationVisibility::Corporation && $corporationId === null) {
            throw new BadRequestHttpException('Corporation visibility needs a known corporation');
        }

        $plannedStartAt = $input->plannedStartAt ?? throw new BadRequestHttpException('plannedStartAt is required');
        $characters = $this->tickedCharactersOf($owner, $input->characterIds);

        $operation = (new MiningOperation())
            ->setOwner($owner)
            ->setLabel(trim($input->label))
            ->setPlannedStartAt($plannedStartAt->setTimezone(new \DateTimeZone('UTC')))
            ->setVisibility($visibility)
            ->setSplitMode(MiningSplitMode::from($input->splitMode))
            ->setCorporationId($corporationId);
        $ownerMembership = (new MiningOperationMember())
            ->setUser($owner)
            ->setRole(MiningOperationMemberRole::Owner)
            ->setStatus(MiningOperationMemberStatus::Accepted);
        foreach ($characters as $character) {
            $ownerMembership->enrol($character);
        }
        $operation->addMember($ownerMembership);

        $this->entityManager->persist($operation);
        $this->entityManager->flush();

        return $operation;
    }

    /**
     * @param list<string> $characterIds
     */
    public function join(User $user, MiningOperation $operation, array $characterIds): MiningOperationMember
    {
        if (!$operation->getStatus()->acceptsParticipants()) {
            throw new BadRequestHttpException('This operation is no longer accepting participants');
        }

        $membership = $operation->membershipOf($user);
        if ($membership !== null && $membership->getStatus() !== MiningOperationMemberStatus::Removed) {
            throw new ConflictHttpException('You are already a member of this operation');
        }

        $characters = $this->tickedCharactersOf($user, $characterIds);

        if ($membership !== null) {
            $membership->requestRejoin($characters);
        } else {
            $membership = (new MiningOperationMember())
                ->setUser($user)
                ->setStatus($this->autoAccepts($user, $operation) ? MiningOperationMemberStatus::Accepted : MiningOperationMemberStatus::Pending);
            foreach ($characters as $character) {
                $membership->enrol($character);
            }
            $operation->addMember($membership);
        }

        $this->entityManager->flush();

        return $membership;
    }

    /**
     * @return list<CharacterEligibility> main character first, then by name
     */
    public function eligibilityOf(User $user): array
    {
        $characters = array_values($user->getCharacters()->toArray());
        usort($characters, static fn (Character $left, Character $right): int => [!$left->isMain(), $left->getName()] <=> [!$right->isMain(), $right->getName()]);
        $heldElsewhere = $this->characterRepository->findOpenEnrolmentsOf($characters);

        return array_map(function (Character $character) use ($heldElsewhere): CharacterEligibility {
            if (!$this->canReadMiningLedger($character)) {
                return new CharacterEligibility($character, MiningCharacterEligibility::ReconnectRequired, null);
            }

            $holder = $heldElsewhere[PersistedUuid::of($character->getId())] ?? null;
            if ($holder !== null) {
                return new CharacterEligibility($character, MiningCharacterEligibility::InAnotherOperation, $holder->getLabel());
            }

            return new CharacterEligibility($character, MiningCharacterEligibility::Eligible, null);
        }, $characters);
    }

    public function autoAccepts(User $user, MiningOperation $operation): bool
    {
        if ($operation->membershipOf($user)?->getStatus() === MiningOperationMemberStatus::Removed) {
            return false;
        }

        $corporationId = $user->getCorporationId();

        return $corporationId !== null && $corporationId === $operation->getCorporationId();
    }

    /**
     * @param list<string> $characterIds
     *
     * @return list<Character>
     */
    private function tickedCharactersOf(User $user, array $characterIds): array
    {
        $eligibilityByCharacterId = [];
        foreach ($this->eligibilityOf($user) as $eligibility) {
            $eligibilityByCharacterId[PersistedUuid::of($eligibility->character->getId())] = $eligibility;
        }

        $characters = [];
        foreach ($characterIds as $characterId) {
            $eligibility = $eligibilityByCharacterId[strtolower($characterId)] ?? null;
            if ($eligibility === null) {
                throw new BadRequestHttpException(sprintf('Character %s is not one of your characters', $characterId));
            }
            $character = $eligibility->character;
            if ($eligibility->eligibility === MiningCharacterEligibility::ReconnectRequired) {
                throw new BadRequestHttpException(sprintf('%s must be reconnected before joining', $character->getName()));
            }
            if ($eligibility->eligibility === MiningCharacterEligibility::InAnotherOperation) {
                throw new ConflictHttpException(sprintf('%s is already in the open operation "%s"', $character->getName(), $eligibility->busyOperationLabel));
            }
            $characters[] = $character;
        }

        return $characters;
    }

    private function canReadMiningLedger(Character $character): bool
    {
        return $character->getEveToken()?->hasScope(self::MINING_LEDGER_SCOPE) === true
            && $character->isAuthValid();
    }
}
