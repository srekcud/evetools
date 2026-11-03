<?php

declare(strict_types=1);

namespace App\Tests\Integration\MiningOperation;

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
use Doctrine\ORM\EntityManagerInterface;

trait PersistsMiningOperations
{
    private const int C1 = 98_262_101;
    private const int C2 = 98_262_102;
    private const string MINING_LEDGER_SCOPE = 'esi-industry.read_character_mining.v1';

    private static int $nextMiningEveCharacterId = 2_112_262_501;

    private array $miningCharacters = [];

    private function persistMiningPilot(EntityManagerInterface $em, string $mainName, ?int $corporationId, array $altNames = [], bool $withToken = true): User
    {
        $user = new User();
        $em->persist($user);
        $main = $this->persistMiningCharacter($em, $user, $mainName, $corporationId ?? 98_262_103, $withToken);
        if ($corporationId !== null) {
            $user->setMainCharacter($main);
        }
        foreach ($altNames as $altName) {
            $this->persistMiningCharacter($em, $user, $altName, $corporationId ?? 98_262_103, $withToken);
        }

        return $user;
    }

    private function persistMiningCharacter(EntityManagerInterface $em, User $user, string $name, int $corporationId, bool $withToken): Character
    {
        $character = (new Character())
            ->setEveCharacterId(self::$nextMiningEveCharacterId++)
            ->setName($name)
            ->setCorporationId($corporationId)
            ->setCorporationName(sprintf('Corporation %d', $corporationId));
        if ($withToken) {
            $character->setEveToken(
                (new EveToken())
                    ->setAccessToken('access-token-of-' . $name)
                    ->setRefreshTokenEncrypted('never-decrypted-refresh-token')
                    ->setAccessTokenExpiresAt(new \DateTimeImmutable('2026-11-05T21:00:00+00:00'))
                    ->setScopes([self::MINING_LEDGER_SCOPE]),
            );
        }
        $user->addCharacter($character);
        $em->persist($character);
        $this->miningCharacters[$name] = $character;

        return $character;
    }

    private function persistMiningOperation(
        EntityManagerInterface $em,
        User $owner,
        string $label,
        string $plannedStartAt,
        MiningOperationStatus $status = MiningOperationStatus::Planned,
        MiningOperationVisibility $visibility = MiningOperationVisibility::Corporation,
        ?int $corporationId = self::C1,
        array $ownerCharacterNames = [],
    ): MiningOperation {
        $operation = (new MiningOperation())
            ->setOwner($owner)
            ->setLabel($label)
            ->setPlannedStartAt(new \DateTimeImmutable($plannedStartAt))
            ->setStatus($status)
            ->setVisibility($visibility)
            ->setSplitMode(MiningSplitMode::ValueProrata)
            ->setCorporationId($corporationId);
        $this->addMiningMembership($operation, $owner, MiningOperationMemberRole::Owner, MiningOperationMemberStatus::Accepted, $ownerCharacterNames);
        $em->persist($operation);

        return $operation;
    }

    private function addMiningMembership(
        MiningOperation $operation,
        User $user,
        MiningOperationMemberRole $role,
        MiningOperationMemberStatus $status,
        array $characterNames,
    ): MiningOperationMember {
        $member = (new MiningOperationMember())->setUser($user)->setRole($role)->setStatus($status);
        foreach ($characterNames as $characterName) {
            $member->enrol($this->miningCharacters[$characterName]);
        }
        $operation->addMember($member);

        return $member;
    }
}
