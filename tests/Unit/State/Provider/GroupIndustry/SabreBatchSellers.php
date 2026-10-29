<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\GroupIndustry;

use App\Entity\Character;
use App\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\MockObject\Stub;

trait SabreBatchSellers
{
    private const int O_MAIN_EVE_CHARACTER_ID = 2120000001;
    private const int O_ALT_EVE_CHARACTER_ID = 2120000002;
    private const int A_MAIN_EVE_CHARACTER_ID = 2120000011;
    private const int M_MAIN_EVE_CHARACTER_ID = 2120000021;
    private const int UNKNOWN_EVE_CHARACTER_ID = 99999999;
    private const string SELLER_OF_ANOTHER_USER = '400 The seller must be one of your characters.';

    private Character $oMain;
    private Character $oAlt;
    private Character $aMain;
    private Character $mMain;

    private function createSabreBatchSellers(): void
    {
        $this->oMain = $this->sellerCharacter(self::O_MAIN_EVE_CHARACTER_ID, 'O-Main');
        $this->oAlt = $this->sellerCharacter(self::O_ALT_EVE_CHARACTER_ID, 'O-Alt');
        $this->aMain = $this->sellerCharacter(self::A_MAIN_EVE_CHARACTER_ID, 'A-Main');
        $this->mMain = $this->sellerCharacter(self::M_MAIN_EVE_CHARACTER_ID, 'M-Main');
    }

    private function sellerCharacter(int $eveCharacterId, string $name): Character
    {
        return (new Character())->setEveCharacterId($eveCharacterId)->setName($name);
    }

    private function withCharacters(User $user, Character $mainCharacter, Character ...$otherCharacters): User
    {
        \assert($user instanceof Stub);
        $user->method('getCharacters')->willReturn(new ArrayCollection([$mainCharacter, ...$otherCharacters]));
        $user->method('getMainCharacter')->willReturn($mainCharacter);

        return $user;
    }
}
