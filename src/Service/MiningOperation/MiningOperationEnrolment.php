<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\ApiResource\Input\MiningOperation\CreateMiningOperationInput;
use App\Entity\Character;
use App\Entity\CorporationMiningExtraction;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Entity\MiningOperationRecurrence;
use App\Entity\User;
use App\Enum\MiningCharacterEligibility;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationRecurrenceKind;
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

    public function create(User $owner, CreateMiningOperationInput $input, ?CorporationMiningExtraction $chunk = null): MiningOperation
    {
        $visibility = MiningOperationVisibility::from($input->visibility);
        $corporationId = $owner->getCorporationId();
        if ($visibility === MiningOperationVisibility::Corporation && $corporationId === null) {
            throw new BadRequestHttpException('Corporation visibility needs a known corporation');
        }

        $plannedStartAt = $input->plannedStartAt ?? throw new BadRequestHttpException('plannedStartAt is required');
        $plannedStartAt = $plannedStartAt->setTimezone(new \DateTimeZone('UTC'));
        $characters = $this->tickedCharactersOf($owner, $input->characterIds);

        $operation = $this->plan(
            $owner,
            trim($input->label),
            $plannedStartAt,
            $visibility,
            MiningSplitMode::from($input->splitMode),
            $corporationId,
            $characters,
        );
        $operation->declareBoostFit($input->declaredBoostShip(), $input->declaredCompressorKinds());
        $operation->setChunk($chunk);

        if ($input->recurrence !== null) {
            $recurrence = $this->recurrenceOf($operation, $input->recurrence->kind, $input->recurrence->structureId);
            $operation->setRecurrence($recurrence);
            $this->entityManager->persist($recurrence);
        }
        $this->entityManager->persist($operation);
        $this->entityManager->flush();

        return $operation;
    }

    /**
     * @param list<Character> $characters
     */
    public function plan(
        User $owner,
        string $label,
        \DateTimeImmutable $plannedStartAt,
        MiningOperationVisibility $visibility,
        MiningSplitMode $splitMode,
        ?int $corporationId,
        array $characters,
    ): MiningOperation {
        $operation = (new MiningOperation())
            ->setOwner($owner)
            ->setLabel($label)
            ->setPlannedStartAt($plannedStartAt)
            ->setVisibility($visibility)
            ->setSplitMode($splitMode)
            ->setCorporationId($corporationId);
        $ownerMembership = (new MiningOperationMember())
            ->setUser($owner)
            ->setRole(MiningOperationMemberRole::Owner)
            ->setStatus(MiningOperationMemberStatus::Accepted);
        foreach ($characters as $character) {
            $ownerMembership->enrol($character);
        }
        $operation->addMember($ownerMembership);

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
     * @return list<Character>
     */
    public function eligibleCharactersOf(User $user): array
    {
        return array_values(array_map(
            static fn (CharacterEligibility $eligibility): Character => $eligibility->character,
            array_filter(
                $this->eligibilityOf($user),
                static fn (CharacterEligibility $eligibility): bool => $eligibility->eligibility === MiningCharacterEligibility::Eligible,
            ),
        ));
    }

    private function recurrenceOf(MiningOperation $operation, string $kindValue, ?int $structureId): MiningOperationRecurrence
    {
        $kind = MiningOperationRecurrenceKind::from($kindValue);
        $corporationId = $operation->getCorporationId();
        $recurrence = (new MiningOperationRecurrence())
            ->setOwner($operation->getOwner())
            ->setKind($kind)
            ->setLabel($operation->getLabel())
            ->setVisibility($operation->getVisibility())
            ->setSplitMode($operation->getSplitMode())
            ->setCorporationId($corporationId)
            ->setAnchorAt($operation->getPlannedStartAt());

        if ($kind === MiningOperationRecurrenceKind::Weekly) {
            return $recurrence;
        }

        if ($structureId === null) {
            throw new BadRequestHttpException('A chunk recurrence needs a refinery');
        }
        if ($corporationId === null) {
            throw new BadRequestHttpException('A chunk recurrence needs a known corporation');
        }
        $extraction = $this->entityManager->getRepository(CorporationMiningExtraction::class)->findOneBy(
            ['corporationId' => $corporationId, 'structureId' => $structureId],
            ['chunkArrivalTime' => 'DESC'],
        ) ?? throw new BadRequestHttpException('This refinery has no extraction for your corporation');

        return $recurrence
            ->setStructureId($structureId)
            ->setStructureName($extraction->getStructureName());
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
