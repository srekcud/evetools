<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\ApiResource\Industry\WalletReadingResource;
use App\Entity\Character;
use App\Entity\CharacterWalletReading;
use App\Entity\EveToken;
use App\Entity\User;
use App\Repository\CharacterWalletReadingRepository;
use App\Service\Sync\WalletReadingStateReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(WalletReadingStateReader::class)]
#[Group('issue-916')]
final class WalletReadingStateReaderTest extends TestCase
{
    private const string WALLET_SCOPE = 'esi-wallet.read_character_wallet.v1';

    public function testEachCharacterOfTheUserGetsItsStatusWithTheMainCharacterFirst(): void
    {
        $user = new User();
        $missingScope = $this->characterOf($user, 'Brann Holt', (new EveToken())->setScopes(['esi-skills.read_skills.v1']));
        $withoutToken = $this->characterOf($user, 'Cora Venn', null);
        $neverRead = $this->characterOf($user, 'Dorn Achar', (new EveToken())->setScopes([self::WALLET_SCOPE, 'esi-skills.read_skills.v1']));
        $main = $this->characterOf($user, 'Aria Solberg', (new EveToken())->setScopes([self::WALLET_SCOPE]));
        $user->setMainCharacter($main);
        $readings = [$this->readingOf($main, '2026-11-13T20:04:10+00:00', '2026-11-13T21:04:00+00:00')];

        self::assertSame(
            [
                ['Aria Solberg', 'read', '2026-11-13T20:04:10+00:00', '2026-11-13T21:04:00+00:00'],
                ['Brann Holt', 'missing_scope', null, null],
                ['Cora Venn', 'no_token', null, null],
                ['Dorn Achar', 'never_read', null, null],
            ],
            $this->statesOf($this->reader($readings)->forUser($user)),
        );
    }

    public function testCharacterWhoseOnlyReadFailedIsNeverRead(): void
    {
        $user = new User();
        $main = $this->characterOf($user, 'Elin Marsk', (new EveToken())->setScopes([self::WALLET_SCOPE]));
        $user->setMainCharacter($main);
        $readings = [$this->readingOf($main, null, '2026-11-13T20:19:10+00:00')];

        self::assertSame(
            [['Elin Marsk', 'never_read', null]],
            array_map(
                static fn (array $state): array => [$state[0], $state[1], $state[2]],
                $this->statesOf($this->reader($readings)->forUser($user)),
            ),
        );
    }

    public function testReadingsOfAnotherUserAreNeverRendered(): void
    {
        $user = new User();
        $main = $this->characterOf($user, 'Jara Voss', (new EveToken())->setScopes([self::WALLET_SCOPE]));
        $user->setMainCharacter($main);
        $stranger = $this->characterOf(new User(), 'Kael Dunmar', (new EveToken())->setScopes([self::WALLET_SCOPE]));
        $readings = [
            $this->readingOf($stranger, '2026-11-13T19:00:00+00:00', '2026-11-13T20:00:00+00:00'),
            $this->readingOf($main, '2026-11-13T20:04:10+00:00', '2026-11-13T21:04:00+00:00'),
        ];

        self::assertSame(
            [['Jara Voss', 'read', '2026-11-13T20:04:10+00:00', '2026-11-13T21:04:00+00:00']],
            $this->statesOf($this->reader($readings)->forUser($user)),
        );
    }

    public function testUserWithoutCharacterHasNoState(): void
    {
        self::assertSame([], $this->reader([])->forUser(new User()));
    }

    private function reader(array $storedReadings): WalletReadingStateReader
    {
        $readingRepository = $this->createStub(CharacterWalletReadingRepository::class);
        $readingRepository->method('findByCharacters')->willReturn($storedReadings);
        $readingRepository->method('findOneByCharacter')->willReturnCallback(
            static function (Character $character) use ($storedReadings): ?CharacterWalletReading {
                foreach ($storedReadings as $reading) {
                    if ($reading->getCharacter() === $character) {
                        return $reading;
                    }
                }

                return null;
            },
        );

        return new WalletReadingStateReader($readingRepository);
    }

    private function characterOf(User $user, string $name, ?EveToken $token): Character
    {
        $character = (new Character())->setName($name)->setEveToken($token);
        $user->addCharacter($character);

        return $character;
    }

    private function readingOf(Character $character, ?string $readAt, string $nextReadAt): CharacterWalletReading
    {
        return (new CharacterWalletReading())
            ->setCharacter($character)
            ->setReadAt($readAt === null ? null : new \DateTimeImmutable($readAt))
            ->setNextReadAt(new \DateTimeImmutable($nextReadAt));
    }

    private function statesOf(array $states): array
    {
        return array_map(
            static fn (WalletReadingResource $state): array => [$state->characterName, $state->status, $state->readAt, $state->nextReadAt],
            $states,
        );
    }
}
