<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MoonChunk;

use App\Entity\Character;
use App\Entity\MoonComposition;
use App\Entity\User;
use App\Exception\EsiApiException;
use App\Repository\MoonCompositionRepository;
use App\Service\ESI\CharacterService;
use App\Service\MoonChunk\CorporationRolesUnreadable;
use App\Service\MoonChunk\MoonCompositionReplacementPolicy;
use App\Service\MoonChunk\MoonCompositionReplacementRefused;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[Group('issue-368')]
final class MoonCompositionReplacementPolicyTest extends TestCase
{
    private const int ILYRA = 98_262_101;
    private const int CYRA = 98_262_102;
    private const int MOON = 40_301_001;

    private int $nextEveCharacterId = 91_000_000;

    /** @var array<int, list<string>|\Throwable> */
    private array $rolesByEveCharacterId = [];

    /** @var list<int> */
    private array $rolesReadFor = [];

    private ?MoonComposition $stored = null;

    public function testTheFirstPasteOfAMoonIsOpenToAnyMemberWithoutReadingRoles(): void
    {
        $member = $this->userWithCharacterIn(self::ILYRA);

        $this->policy()->assertMayRecord($member, self::ILYRA, self::MOON);

        self::assertSame([], $this->rolesReadFor);
    }

    public function testTheAuthorReplacesTheCompositionWithoutReadingRoles(): void
    {
        $author = $this->userWithCharacterIn(self::ILYRA);
        $this->stored = $this->compositionBy($author);

        $this->policy()->assertMayRecord($author, self::ILYRA, self::MOON);

        self::assertSame([], $this->rolesReadFor);
    }

    public function testAnotherMemberWithoutARoleCannotReplaceTheComposition(): void
    {
        $this->stored = $this->compositionBy($this->userWithCharacterIn(self::ILYRA));
        $member = $this->userWithCharacterIn(self::ILYRA);

        $this->expectException(MoonCompositionReplacementRefused::class);

        $this->policy()->assertMayRecord($member, self::ILYRA, self::MOON);
    }

    public static function rolesOfAnotherMember(): iterable
    {
        yield 'Director' => [['Director'], true];
        yield 'Station Manager' => [['Accountant', 'Station_Manager'], true];
        yield 'a role that does not manage the corporation' => [['Accountant'], false];
        yield 'no role' => [[], false];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('rolesOfAnotherMember')]
    public function testAnotherMemberReplacesTheCompositionOnlyWithADirectorOrStationManagerRole(array $roles, bool $mayReplace): void
    {
        $this->stored = $this->compositionBy($this->userWithCharacterIn(self::ILYRA));
        $member = $this->userWithCharacterIn(self::ILYRA, $roles);

        $outcome = $this->outcomeOf($member);

        self::assertSame($mayReplace ? 'allowed' : MoonCompositionReplacementRefused::class, $outcome);
    }

    public function testARoleOfACharacterInAnotherCorporationOpensNothing(): void
    {
        $this->stored = $this->compositionBy($this->userWithCharacterIn(self::ILYRA));
        $member = $this->userWithCharacterIn(self::ILYRA);
        $this->addCharacter($member, self::CYRA, ['Director']);

        $outcome = $this->outcomeOf($member);

        self::assertSame([MoonCompositionReplacementRefused::class, [$member->getCharacters()->first()->getEveCharacterId()]], [$outcome, $this->rolesReadFor]);
    }

    public function testUnreadableRolesAreAnErrorAndNeverARefusalOrAnAuthorisation(): void
    {
        $this->stored = $this->compositionBy($this->userWithCharacterIn(self::ILYRA));
        $member = $this->userWithCharacterIn(self::ILYRA, new EsiApiException('ESI server error', 503));

        self::assertSame(CorporationRolesUnreadable::class, $this->outcomeOf($member));
    }

    public function testADirectorRoleReadOnAnotherCharacterOfTheCorporationAllowsTheReplacementDespiteAnUnreadableOne(): void
    {
        $this->stored = $this->compositionBy($this->userWithCharacterIn(self::ILYRA));
        $member = $this->userWithCharacterIn(self::ILYRA, new EsiApiException('ESI server error', 503));
        $this->addCharacter($member, self::ILYRA, ['Director']);

        self::assertSame('allowed', $this->outcomeOf($member));
    }

    public function testACompositionWithoutAuthorCanOnlyBeReplacedWithARole(): void
    {
        $this->stored = $this->compositionBy(null);
        $withoutRole = $this->userWithCharacterIn(self::ILYRA);
        $director = $this->userWithCharacterIn(self::ILYRA, ['Director']);

        self::assertSame([MoonCompositionReplacementRefused::class, 'allowed'], [$this->outcomeOf($withoutRole), $this->outcomeOf($director)]);
    }

    private function outcomeOf(User $user): string
    {
        try {
            $this->policy()->assertMayRecord($user, self::ILYRA, self::MOON);
        } catch (MoonCompositionReplacementRefused|CorporationRolesUnreadable $refusal) {
            return $refusal::class;
        }

        return 'allowed';
    }

    private function policy(): MoonCompositionReplacementPolicy
    {
        $compositions = $this->createStub(MoonCompositionRepository::class);
        $compositions->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?MoonComposition => $criteria === ['corporationId' => self::ILYRA, 'moonId' => self::MOON] ? $this->stored : null,
        );

        $characters = $this->createStub(CharacterService::class);
        $characters->method('getCharacterRoles')->willReturnCallback(function (Character $character): array {
            $eveCharacterId = $character->getEveCharacterId();
            $this->rolesReadFor[] = $eveCharacterId;
            $roles = $this->rolesByEveCharacterId[$eveCharacterId] ?? [];
            if ($roles instanceof \Throwable) {
                throw $roles;
            }

            return $roles;
        });

        return new MoonCompositionReplacementPolicy($compositions, $characters);
    }

    /**
     * @param list<string>|\Throwable $roles
     */
    private function userWithCharacterIn(int $corporationId, array|\Throwable $roles = []): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, Uuid::v7());
        $this->addCharacter($user, $corporationId, $roles);

        return $user;
    }

    /**
     * @param list<string>|\Throwable $roles
     */
    private function addCharacter(User $user, int $corporationId, array|\Throwable $roles): void
    {
        $eveCharacterId = $this->nextEveCharacterId++;
        $user->addCharacter((new Character())->setEveCharacterId($eveCharacterId)->setName('Pilot '.$eveCharacterId)->setCorporationId($corporationId));
        $this->rolesByEveCharacterId[$eveCharacterId] = $roles;
    }

    private function compositionBy(?User $author): MoonComposition
    {
        return (new MoonComposition())->setCorporationId(self::ILYRA)->setMoonId(self::MOON)->setRecordedBy($author);
    }
}
