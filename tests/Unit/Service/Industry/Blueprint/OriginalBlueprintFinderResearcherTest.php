<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Blueprint;

use App\Entity\Character;
use App\Entity\User;
use App\Service\Industry\Blueprint\OriginalBlueprintFinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(OriginalBlueprintFinder::class)]
#[Group('issue-475')]
final class OriginalBlueprintFinderResearcherTest extends TestCase
{
    use BuildsBlueprintLibrary;

    private Character $main;
    private Character $alt;
    private User $user;

    protected function setUp(): void
    {
        $this->main = $this->pilot('Aria Solberg', self::MAIN_CORPORATION_ID, self::MAIN_CORPORATION_NAME, [self::PERSONAL_SCOPE, self::CORPORATION_SCOPE]);
        $this->alt = $this->pilot('Brann Holt', self::ALT_CORPORATION_ID, 'Holt Haulage', [self::PERSONAL_SCOPE]);
        $this->user = $this->userOf(self::MAIN_USER_ID, $this->main, $this->alt);
    }

    public function testResearcherOfAPersonalOriginalIsTheEveCharacterIdOfItsOwner(): void
    {
        $finder = new OriginalBlueprintFinder(
            $this->repositoryHolding([$this->personalBlueprint($this->alt, 8_201, self::DRAKE_BLUEPRINT, -1, -1, 6, 12)]),
            $this->visibilityOf([]),
        );

        $uses = $finder->bestUsesOwnedBy($this->user);

        self::assertSame($this->alt->getEveCharacterId(), $uses[self::DRAKE_BLUEPRINT]->researcherEveCharacterId);
        self::assertNotSame($this->main->getEveCharacterId(), $uses[self::DRAKE_BLUEPRINT]->researcherEveCharacterId);
    }

    public function testCorporationOriginalHasNoResearcher(): void
    {
        $finder = new OriginalBlueprintFinder(
            $this->repositoryHolding([$this->corporationBlueprint(self::MAIN_CORPORATION_ID, 8_211, self::ATRON_BLUEPRINT, -1, -1, 9, 18, 3)]),
            $this->visibilityOf([self::MAIN_CORPORATION_ID => [3]]),
        );

        $uses = $finder->bestUsesOwnedBy($this->user);

        self::assertNull($uses[self::ATRON_BLUEPRINT]->researcherEveCharacterId);
    }

    public function testResearcherIsTheOwnerOfTheBestOriginalAmongTwoCharacters(): void
    {
        $finder = new OriginalBlueprintFinder(
            $this->repositoryHolding([
                $this->personalBlueprint($this->main, 8_221, self::DRAKE_BLUEPRINT, -1, -1, 3, 6),
                $this->personalBlueprint($this->alt, 8_222, self::DRAKE_BLUEPRINT, -1, -1, 7, 14),
            ]),
            $this->visibilityOf([]),
        );

        $uses = $finder->bestUsesOwnedBy($this->user);

        self::assertSame($this->alt->getEveCharacterId(), $uses[self::DRAKE_BLUEPRINT]->researcherEveCharacterId);
        self::assertSame([7, 14], [$uses[self::DRAKE_BLUEPRINT]->efficiency->materialEfficiency->value, $uses[self::DRAKE_BLUEPRINT]->efficiency->timeEfficiency->value]);
    }

    public function testResearcherIsTheOwnerOfTheFirstReadOriginalAtEqualEfficiencies(): void
    {
        $finder = new OriginalBlueprintFinder(
            $this->repositoryHolding([
                $this->personalBlueprint($this->main, 8_231, self::DRAKE_BLUEPRINT, -1, -1, 5, 10),
                $this->personalBlueprint($this->alt, 8_232, self::DRAKE_BLUEPRINT, -1, -1, 5, 10),
                $this->corporationBlueprint(self::MAIN_CORPORATION_ID, 8_233, self::DRAKE_BLUEPRINT, -1, -1, 5, 10, 3),
            ]),
            $this->visibilityOf([self::MAIN_CORPORATION_ID => [3]]),
        );

        $uses = $finder->bestUsesOwnedBy($this->user);

        self::assertSame($this->main->getEveCharacterId(), $uses[self::DRAKE_BLUEPRINT]->researcherEveCharacterId);
    }
}
