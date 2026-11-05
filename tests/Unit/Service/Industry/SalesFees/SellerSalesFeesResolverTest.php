<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\SalesFees;

use App\Constant\EveConstants;
use App\Entity\CachedCharacterSkill;
use App\Entity\CachedCharacterStanding;
use App\Entity\Character;
use App\Entity\User;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\CachedCharacterStandingRepository;
use App\Service\Industry\SalesFees\SalesFeeCalculator;
use App\Service\Industry\SalesFees\SalesFees;
use App\Service\Industry\SalesFees\SellerSalesFeesResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(SellerSalesFeesResolver::class)]
#[Group('issue-347')]
final class SellerSalesFeesResolverTest extends TestCase
{
    private const float FRACTION_DELTA = 1e-9;
    private const string NPC_CORPORATION = 'npc_corp';
    private const string FACTION = 'faction';
    private const string AGENT = 'agent';

    private Character $mainCharacter;
    private Character $alternateCharacter;
    private User $user;

    private array $skillsByCharacterName = [];

    private array $standingsByCharacterName = [];

    protected function setUp(): void
    {
        $this->mainCharacter = (new Character())->setName('Main Seller')->setStandingsSyncedAt(new \DateTimeImmutable('2026-11-04T18:00:00+00:00'));
        $this->alternateCharacter = (new Character())->setName('Alternate Seller')->setStandingsSyncedAt(new \DateTimeImmutable('2026-11-04T18:05:00+00:00'));
        $this->user = (new User())->setMainCharacter($this->mainCharacter);
        $this->skillsByCharacterName = [
            'Main Seller' => $this->skillLines($this->mainCharacter, 5, 5),
            'Alternate Seller' => $this->skillLines($this->alternateCharacter, 1, 2),
        ];
        $this->standingsByCharacterName = [
            'Main Seller' => [
                $this->standingLine($this->mainCharacter, EveConstants::JITA_NPC_CORPORATION_ID, self::NPC_CORPORATION, 3.0),
                $this->standingLine($this->mainCharacter, EveConstants::JITA_NPC_FACTION_ID, self::FACTION, 2.0),
            ],
            'Alternate Seller' => [],
        ];
    }

    public function testComputesTheFeesOfTheMainCharacterFromItsSkillsAndStandings(): void
    {
        $fees = $this->forUser();

        $this->assertInstanceOf(SalesFees::class, $fees);
        $this->assertEqualsWithDelta(0.03375, $fees->salesTaxRate, self::FRACTION_DELTA);
        $this->assertEqualsWithDelta(0.0138, $fees->brokerFeeRate, self::FRACTION_DELTA);
    }

    public function testIgnoresTheOtherCharactersOfTheUser(): void
    {
        $this->mainCharacter->setStandingsSyncedAt(null);
        $this->user->addCharacter($this->mainCharacter);
        $this->user->addCharacter($this->alternateCharacter);

        $this->assertNull($this->forUser());
    }

    public function testFeesAreUnknownWithoutMainCharacter(): void
    {
        $this->user->setMainCharacter(null);

        $this->assertNull($this->forUser());
    }

    public function testFeesAreUnknownWithoutAccountingLine(): void
    {
        unset($this->skillsByCharacterName['Main Seller'][CachedCharacterSkill::SKILL_ACCOUNTING]);

        $this->assertNull($this->forUser());
    }

    public function testFeesAreUnknownWithoutBrokerRelationsLine(): void
    {
        unset($this->skillsByCharacterName['Main Seller'][CachedCharacterSkill::SKILL_BROKER_RELATIONS]);

        $this->assertNull($this->forUser());
    }

    public function testFeesAreUnknownWhenTheStandingsWereNeverSynchronized(): void
    {
        $this->mainCharacter->setStandingsSyncedAt(null);

        $this->assertNull($this->forUser());
    }

    public function testTreatsAFactionStandingAbsentFromTheSynchronizedListAsNeutral(): void
    {
        $this->skillsByCharacterName['Main Seller'] = $this->skillLines($this->mainCharacter, 4, 3);
        $this->standingsByCharacterName['Main Seller'] = [
            $this->standingLine($this->mainCharacter, EveConstants::JITA_NPC_CORPORATION_ID, self::NPC_CORPORATION, 3.0),
        ];

        $fees = $this->forUser();

        $this->assertInstanceOf(SalesFees::class, $fees);
        $this->assertEqualsWithDelta(0.042, $fees->salesTaxRate, self::FRACTION_DELTA);
        $this->assertEqualsWithDelta(0.0204, $fees->brokerFeeRate, self::FRACTION_DELTA);
    }

    public function testTreatsACorporationStandingAbsentFromTheSynchronizedListAsNeutral(): void
    {
        $this->skillsByCharacterName['Main Seller'] = $this->skillLines($this->mainCharacter, 4, 3);
        $this->standingsByCharacterName['Main Seller'] = [
            $this->standingLine($this->mainCharacter, EveConstants::JITA_NPC_FACTION_ID, self::FACTION, 5.0),
        ];

        $fees = $this->forUser();

        $this->assertInstanceOf(SalesFees::class, $fees);
        $this->assertEqualsWithDelta(0.042, $fees->salesTaxRate, self::FRACTION_DELTA);
        $this->assertEqualsWithDelta(0.0195, $fees->brokerFeeRate, self::FRACTION_DELTA);
    }

    public function testTreatsAnEmptySynchronizedListAsNeutralStandings(): void
    {
        $this->skillsByCharacterName['Main Seller'] = $this->skillLines($this->mainCharacter, 4, 3);
        $this->standingsByCharacterName['Main Seller'] = [];

        $fees = $this->forUser();

        $this->assertInstanceOf(SalesFees::class, $fees);
        $this->assertEqualsWithDelta(0.042, $fees->salesTaxRate, self::FRACTION_DELTA);
        $this->assertEqualsWithDelta(0.021, $fees->brokerFeeRate, self::FRACTION_DELTA);
    }

    public function testMatchesTheStandingsOnBothIdAndType(): void
    {
        $this->standingsByCharacterName['Main Seller'] = [
            $this->standingLine($this->mainCharacter, EveConstants::JITA_NPC_CORPORATION_ID, self::AGENT, 9.0),
            $this->standingLine($this->mainCharacter, EveConstants::JITA_NPC_FACTION_ID, self::AGENT, 8.0),
            $this->standingLine($this->mainCharacter, EveConstants::JITA_NPC_CORPORATION_ID, self::FACTION, 7.0),
            $this->standingLine($this->mainCharacter, EveConstants::JITA_NPC_FACTION_ID, self::NPC_CORPORATION, 6.0),
            $this->standingLine($this->mainCharacter, EveConstants::JITA_NPC_CORPORATION_ID, self::NPC_CORPORATION, 3.0),
            $this->standingLine($this->mainCharacter, EveConstants::JITA_NPC_FACTION_ID, self::FACTION, 2.0),
        ];

        $fees = $this->forUser();

        $this->assertInstanceOf(SalesFees::class, $fees);
        $this->assertEqualsWithDelta(0.0138, $fees->brokerFeeRate, self::FRACTION_DELTA);
    }

    private function forUser(): ?SalesFees
    {
        $skillRepository = $this->createStub(CachedCharacterSkillRepository::class);
        $skillRepository->method('findAllSkillsForCharacter')->willReturnCallback(
            fn (Character $character): array => $this->skillsByCharacterName[$character->getName()],
        );
        $skillRepository->method('findBy')->willReturnCallback(
            fn (array $criteria): array => array_values(array_filter(
                $this->skillsByCharacterName[$criteria['character']->getName()],
                static fn (CachedCharacterSkill $skill): bool => !isset($criteria['skillId']) || $skill->getSkillId() === $criteria['skillId'],
            )),
        );
        $skillRepository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?CachedCharacterSkill => $this->skillsByCharacterName[$criteria['character']->getName()][$criteria['skillId']] ?? null,
        );

        $standingRepository = $this->createStub(CachedCharacterStandingRepository::class);
        $standingRepository->method('findBy')->willReturnCallback(
            fn (array $criteria): array => $this->standingsByCharacterName[$criteria['character']->getName()],
        );

        return (new SellerSalesFeesResolver($skillRepository, $standingRepository, new SalesFeeCalculator()))->forUser($this->user);
    }

    private function skillLines(Character $character, int $accountingLevel, int $brokerRelationsLevel): array
    {
        return [
            CachedCharacterSkill::SKILL_ACCOUNTING => $this->skillLine($character, CachedCharacterSkill::SKILL_ACCOUNTING, $accountingLevel),
            CachedCharacterSkill::SKILL_BROKER_RELATIONS => $this->skillLine($character, CachedCharacterSkill::SKILL_BROKER_RELATIONS, $brokerRelationsLevel),
        ];
    }

    private function skillLine(Character $character, int $skillId, int $level): CachedCharacterSkill
    {
        return (new CachedCharacterSkill())->setCharacter($character)->setSkillId($skillId)->setLevel($level);
    }

    private function standingLine(Character $character, int $fromId, string $fromType, float $standing): CachedCharacterStanding
    {
        return (new CachedCharacterStanding())
            ->setCharacter($character)
            ->setFromId($fromId)
            ->setFromType($fromType)
            ->setStanding($standing);
    }
}
