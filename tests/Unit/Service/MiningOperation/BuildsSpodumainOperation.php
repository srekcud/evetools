<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningOperationVisibility;
use App\Enum\MiningSplitMode;
use App\Repository\MiningOperationCharacterRepository;
use App\Repository\MiningOperationMemberRepository;
use App\Repository\MiningOperationRepository;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

trait BuildsSpodumainOperation
{
    private const int C1 = 98_262_101;
    private const int C2 = 98_262_102;
    private const int DORN_CHARACTER_CORPORATION_ID = 98_262_103;
    private const string MINING_LEDGER_SCOPE = 'esi-industry.read_character_mining.v1';
    private const string WALLET_SCOPE = 'esi-wallet.read_character_wallet.v1';
    private const string JOIN_CODE = '7f3a9c2e1b';
    private const string JOIN_CODE_PATTERN = '/^[0-9a-f]{10}$/';
    private const string UNKNOWN_JOIN_CODE = 'ffffffffff';
    private const string NOT_A_UUID = 'abc';
    private const string SPODUMAIN_LABEL = 'Anomalie Spodumain — 20:00';
    private const string ICE_BELT_LABEL = 'Ice belt — dimanche';
    private const string OCHRE_BELT_LABEL = 'Ochre belt — samedi';
    private const string PLANNED_START = '2026-11-05T20:00:00+00:00';

    private static int $nextEveCharacterId = 2_112_262_101;

    private array $charactersByName = [];

    private array $openEnrolments = [];

    private array $operations = [];

    private User $aria;
    private User $brann;
    private User $cyra;
    private User $dorn;
    private User $elia;

    private function setUpSpodumainPilots(): void
    {
        $this->aria = $this->pilot('Aria Solberg', self::C1, ['Aria II']);
        $this->brann = $this->pilot('Brann Holt', self::C1, ['Brann IV', 'Brann II', 'Brann III']);
        $this->character('Brann III')->markAuthInvalid();
        $this->cyra = $this->pilot('Cyra Lune', self::C2, []);
        $this->dorn = $this->pilotWithoutMainCharacter('Dorn Achar');
        $this->elia = $this->pilot('Elia Varr', self::C1, []);
    }

    private function pilot(string $mainName, int $corporationId, array $altNames): User
    {
        $user = $this->userWithId();
        foreach ($altNames as $altName) {
            $this->addCharacterTo($user, $altName, $corporationId);
        }
        $user->setMainCharacter($this->addCharacterTo($user, $mainName, $corporationId));

        return $user;
    }

    private function pilotWithoutMainCharacter(string $characterName): User
    {
        $user = $this->userWithId();
        $this->addCharacterTo($user, $characterName, self::DORN_CHARACTER_CORPORATION_ID);

        return $user;
    }

    private function userWithId(): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, Uuid::v4());

        return $user;
    }

    private function addCharacterTo(User $user, string $name, int $corporationId): Character
    {
        $character = (new Character())
            ->setEveCharacterId(self::$nextEveCharacterId++)
            ->setName($name)
            ->setCorporationId($corporationId)
            ->setCorporationName(sprintf('Corporation %d', $corporationId));
        (new \ReflectionProperty(Character::class, 'id'))->setValue($character, Uuid::v4());
        $character->setEveToken((new EveToken())->setScopes([self::WALLET_SCOPE, self::MINING_LEDGER_SCOPE]));
        $user->addCharacter($character);
        $this->charactersByName[$name] = $character;

        return $character;
    }

    private function character(string $name): Character
    {
        return $this->charactersByName[$name];
    }

    private function idOf(string $characterName): string
    {
        return $this->character($characterName)->getId()->toRfc4122();
    }

    private function idsOf(array $characterNames): array
    {
        return array_map(fn (string $name): string => $this->idOf($name), $characterNames);
    }

    private function spodumainOperation(
        MiningOperationStatus $status = MiningOperationStatus::Planned,
        MiningOperationVisibility $visibility = MiningOperationVisibility::Corporation,
    ): MiningOperation {
        $operation = $this->operationOwnedBy($this->aria, self::SPODUMAIN_LABEL, self::C1, self::JOIN_CODE)
            ->setStatus($status)
            ->setVisibility($visibility);
        $this->addMembership($operation, $this->aria, MiningOperationMemberRole::Owner, MiningOperationMemberStatus::Accepted, ['Aria Solberg', 'Aria II']);

        return $operation;
    }

    private function operationOwnedBy(User $owner, string $label, ?int $corporationId, string $joinCode): MiningOperation
    {
        $operation = (new MiningOperation())
            ->setOwner($owner)
            ->setLabel($label)
            ->setPlannedStartAt(new \DateTimeImmutable(self::PLANNED_START))
            ->setVisibility(MiningOperationVisibility::Corporation)
            ->setSplitMode(MiningSplitMode::ValueProrata)
            ->setCorporationId($corporationId)
            ->setJoinCode($joinCode);
        (new \ReflectionProperty(MiningOperation::class, 'id'))->setValue($operation, Uuid::v4());
        $this->operations[] = $operation;

        return $operation;
    }

    private function addMembership(
        MiningOperation $operation,
        User $user,
        MiningOperationMemberRole $role,
        MiningOperationMemberStatus $status,
        array $characterNames,
    ): MiningOperationMember {
        $member = (new MiningOperationMember())->setUser($user)->setRole($role)->setStatus($status);
        (new \ReflectionProperty(MiningOperationMember::class, 'id'))->setValue($member, Uuid::v4());
        foreach ($characterNames as $characterName) {
            $member->enrol($this->character($characterName));
        }
        $operation->addMember($member);

        return $member;
    }

    private function holdInOpenOperation(string $characterName, MiningOperation $operation): void
    {
        $this->openEnrolments[$this->idOf($characterName)] = $operation;
    }

    private function iceBeltOperation(): MiningOperation
    {
        return $this->operationOwnedBy($this->cyra, self::ICE_BELT_LABEL, self::C2, '1c4e7a9b2d');
    }

    private function characterRepositoryReadingOpenEnrolments(): MiningOperationCharacterRepository
    {
        $repository = $this->createStub(MiningOperationCharacterRepository::class);
        $repository->method('findOpenEnrolmentsOf')->willReturnCallback(function (array $characters): array {
            $held = [];
            foreach ($characters as $character) {
                $characterId = $character->getId()->toRfc4122();
                if (isset($this->openEnrolments[$characterId])) {
                    $held[$characterId] = $this->openEnrolments[$characterId];
                }
            }

            return $held;
        });

        return $repository;
    }

    private function operationRepositoryReadingOperations(): MiningOperationRepository
    {
        $repository = $this->createStub(MiningOperationRepository::class);
        $repository->method('find')->willReturnCallback(function (mixed $id): ?MiningOperation {
            foreach ($this->operations as $operation) {
                if ((string) $operation->getId() === (string) $id) {
                    return $operation;
                }
            }

            return null;
        });
        $repository->method('findOneByJoinCode')->willReturnCallback(function (string $joinCode): ?MiningOperation {
            foreach ($this->operations as $operation) {
                if ($operation->getJoinCode() === $joinCode) {
                    return $operation;
                }
            }

            return null;
        });

        return $repository;
    }

    private function memberRepositoryReadingOperations(): MiningOperationMemberRepository
    {
        $repository = $this->createStub(MiningOperationMemberRepository::class);
        $repository->method('find')->willReturnCallback(function (mixed $id): ?MiningOperationMember {
            foreach ($this->operations as $operation) {
                foreach ($operation->getMembers() as $member) {
                    if ((string) $member->getId() === (string) $id) {
                        return $member;
                    }
                }
            }

            return null;
        });

        return $repository;
    }

    private function enrolledNamesOf(MiningOperationMember $member): array
    {
        $names = array_map(
            static fn ($enrolment): string => $enrolment->getCharacter()->getName(),
            array_values($member->getCharacters()->toArray()),
        );
        sort($names);

        return $names;
    }

    private function acceptedMembersOf(MiningOperation $operation): array
    {
        return array_values(array_filter(
            $operation->getMembers()->toArray(),
            static fn (MiningOperationMember $member): bool => $member->getStatus() === MiningOperationMemberStatus::Accepted,
        ));
    }

    private function enrolledCharactersCountOfAcceptedMembers(MiningOperation $operation): int
    {
        return array_sum(array_map(
            static fn (MiningOperationMember $member): int => $member->getCharacters()->count(),
            $this->acceptedMembersOf($operation),
        ));
    }

    private function membershipOf(MiningOperation $operation, User $user): ?MiningOperationMember
    {
        foreach ($operation->getMembers() as $member) {
            if ($member->getUser() === $user) {
                return $member;
            }
        }

        return null;
    }

    private function topicOf(User $user): string
    {
        return sprintf('/user/%s/mining-operations', $user->getId()->toRfc4122());
    }

    private function capturePublishedUpdates(HubInterface&MockObject $hub): \ArrayObject
    {
        $publishedUpdates = new \ArrayObject();
        $hub->method('publish')->willReturnCallback(static function (Update $update) use ($publishedUpdates): string {
            $publishedUpdates->append($update);

            return 'urn:uuid:published';
        });

        return $publishedUpdates;
    }

    private function publishedMessages(\ArrayObject $publishedUpdates): array
    {
        $messages = [];
        foreach ($publishedUpdates as $update) {
            $payload = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);
            $messages[] = [
                'topics' => $update->getTopics(),
                'private' => $update->isPrivate(),
                'action' => $payload['action'],
                'operationId' => $payload['operationId'],
                'data' => $payload['data'],
            ];
        }

        return $this->sortedByTopic($messages);
    }

    private function expectedMessages(array $recipients, MiningOperation $operation, string $action, array $data): array
    {
        return $this->sortedByTopic(array_map(fn (User $recipient): array => [
            'topics' => [$this->topicOf($recipient)],
            'private' => true,
            'action' => $action,
            'operationId' => $operation->getId()->toRfc4122(),
            'data' => $data,
        ], $recipients));
    }

    private function sortedByTopic(array $messages): array
    {
        usort($messages, static fn (array $left, array $right): int => strcmp(implode(',', $left['topics']), implode(',', $right['topics'])));

        return $messages;
    }
}
