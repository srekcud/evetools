<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Character;
use App\Entity\CharacterWalletReading;
use App\Repository\CharacterWalletReadingRepository;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\RecordsSqlQueries;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-916')]
final class CharacterWalletReadingRepositoryTest extends IntegrationTestCase
{
    use RecordsSqlQueries;

    private CharacterWalletReadingRepository $repository;
    private Character $ariaMain;
    private Character $ariaAlt;
    private Character $brannMain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(CharacterWalletReadingRepository::class);

        $aria = $this->createUser();
        $this->ariaMain = $this->createCharacter($aria, 'Aria Solberg');
        $this->ariaAlt = $this->createCharacter($aria, 'Aria Alt');
        $this->brannMain = $this->createCharacter($this->createUser(), 'Brann Holt');
    }

    public function testReadingsOfTheGivenCharactersAreReturnedWithoutThoseOfOtherCharacters(): void
    {
        $this->persistReading($this->ariaMain, '2026-11-13 20:04:10', '2026-11-13 21:04:00');
        $this->persistReading($this->brannMain, '2026-11-13 19:00:00', '2026-11-13 20:00:00');
        $this->flushAndClear();

        $readings = $this->repository->findByCharacters([$this->em->find(Character::class, $this->ariaMain->getId())]);

        self::assertSame(
            [['Aria Solberg', '2026-11-13 20:04:10', '2026-11-13 21:04:00']],
            array_map(
                static fn (CharacterWalletReading $reading): array => [
                    $reading->getCharacter()->getName(),
                    $reading->getReadAt()?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                    $reading->getNextReadAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                ],
                $readings,
            ),
        );
    }

    public function testReadingsOfSeveralCharactersAreFetchedWithASingleQuery(): void
    {
        $this->persistReading($this->ariaMain, '2026-11-13 20:04:10', '2026-11-13 21:04:00');
        $this->persistReading($this->ariaAlt, '2026-11-13 20:10:00', '2026-11-13 21:10:00');
        $this->persistReading($this->brannMain, '2026-11-13 19:00:00', '2026-11-13 20:00:00');
        $this->flushAndClear();
        $characters = [
            $this->em->find(Character::class, $this->ariaMain->getId()),
            $this->em->find(Character::class, $this->ariaAlt->getId()),
            $this->em->find(Character::class, $this->brannMain->getId()),
        ];

        $readings = [];
        $queries = $this->sqlExecutedDuring(function () use ($characters, &$readings): void {
            $readings = $this->repository->findByCharacters($characters);
        });

        self::assertCount(3, $readings);
        self::assertCount(1, $queries, implode("\n", $queries));
    }

    public function testReadingOfACharacterIsFoundByItsCharacterAndAbsentWhenNeverRecorded(): void
    {
        $this->persistReading($this->ariaMain, null, '2026-11-13 20:19:10');
        $this->flushAndClear();

        $found = $this->repository->findOneByCharacter($this->em->find(Character::class, $this->ariaMain->getId()));
        $absent = $this->repository->findOneByCharacter($this->em->find(Character::class, $this->ariaAlt->getId()));

        self::assertSame([null, '2026-11-13 20:19:10'], [
            $found?->getReadAt(),
            $found?->getNextReadAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]);
        self::assertNull($absent);
    }

    public function testASecondReadingOfTheSameCharacterIsRejectedByTheDatabase(): void
    {
        $this->persistReading($this->ariaMain, '2026-11-13 20:04:10', '2026-11-13 21:04:00');
        $this->em->flush();

        $this->persistReading($this->ariaMain, '2026-11-13 20:30:00', '2026-11-13 21:30:00');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testRemovingACharacterRemovesItsReading(): void
    {
        $this->persistReading($this->ariaMain, '2026-11-13 20:04:10', '2026-11-13 21:04:00');
        $this->persistReading($this->brannMain, '2026-11-13 19:00:00', '2026-11-13 20:00:00');
        $this->flushAndClear();
        $ariaMainId = $this->ariaMain->getId();
        $brannMainId = $this->brannMain->getId();

        $this->em->remove($this->em->find(Character::class, $ariaMainId));
        $this->em->flush();
        $this->em->clear();

        $readings = $this->em->getRepository(CharacterWalletReading::class);
        self::assertSame([0, 1], [
            \count($readings->findBy(['character' => $ariaMainId])),
            \count($readings->findBy(['character' => $brannMainId])),
        ]);
    }

    public function testRecordingTheReadingOfANeverReadCharacterThenAnotherOneKeepsASingleUpdatedRow(): void
    {
        $this->flushAndClear();
        $aria = $this->em->find(Character::class, $this->ariaMain->getId());
        $brann = $this->em->find(Character::class, $this->brannMain->getId());

        $this->repository->record($brann, $this->utc('2026-11-13 19:00:00'), $this->utc('2026-11-13 20:00:00'));
        $this->repository->record($aria, $this->utc('2026-11-13 20:04:10'), $this->utc('2026-11-13 21:04:00'));
        $this->repository->record($aria, $this->utc('2026-11-13 20:30:00'), $this->utc('2026-11-13 21:30:00'));
        $this->em->clear();

        self::assertSame(
            [
                'aria' => [1, ['2026-11-13 20:30:00', '2026-11-13 21:30:00']],
                'brann' => [1, ['2026-11-13 19:00:00', '2026-11-13 20:00:00']],
            ],
            [
                'aria' => [$this->rowCountOf($this->ariaMain), $this->datesStoredFor($this->ariaMain)],
                'brann' => [$this->rowCountOf($this->brannMain), $this->datesStoredFor($this->brannMain)],
            ],
        );
    }

    public function testFirstRecordWithoutReadAtThenASuccessfulOneSetsReadAt(): void
    {
        $this->flushAndClear();
        $aria = $this->em->find(Character::class, $this->ariaMain->getId());

        $this->repository->record($aria, null, $this->utc('2026-11-13 20:19:10'));
        $afterFailure = $this->datesStoredFor($this->ariaMain);
        $this->repository->record($aria, $this->utc('2026-11-13 20:30:00'), $this->utc('2026-11-13 21:30:00'));
        $this->em->clear();

        self::assertSame(
            [[null, '2026-11-13 20:19:10'], ['2026-11-13 20:30:00', '2026-11-13 21:30:00'], 1],
            [$afterFailure, $this->datesStoredFor($this->ariaMain), $this->rowCountOf($this->ariaMain)],
        );
    }

    public function testRecordingAFailureKeepsTheExistingReadAt(): void
    {
        $this->flushAndClear();
        $aria = $this->em->find(Character::class, $this->ariaMain->getId());
        $this->repository->record($aria, $this->utc('2026-11-13 19:00:00'), $this->utc('2026-11-13 20:00:00'));

        $this->repository->record($aria, null, $this->utc('2026-11-13 20:19:10'));
        $this->em->clear();

        self::assertSame(
            [['2026-11-13 19:00:00', '2026-11-13 20:19:10'], 1],
            [$this->datesStoredFor($this->ariaMain), $this->rowCountOf($this->ariaMain)],
        );
    }

    public function testRecordingTheSameDatesTwiceIsAccepted(): void
    {
        $this->flushAndClear();
        $aria = $this->em->find(Character::class, $this->ariaMain->getId());

        $this->repository->record($aria, $this->utc('2026-11-13 20:04:10'), $this->utc('2026-11-13 21:04:00'));
        $this->repository->record($aria, $this->utc('2026-11-13 20:04:10'), $this->utc('2026-11-13 21:04:00'));
        $this->em->clear();

        self::assertSame(
            [['2026-11-13 20:04:10', '2026-11-13 21:04:00'], 1],
            [$this->datesStoredFor($this->ariaMain), $this->rowCountOf($this->ariaMain)],
        );
    }

    public function testRecordingIsASingleAtomicUpsertStatement(): void
    {
        $this->flushAndClear();
        $aria = $this->em->find(Character::class, $this->ariaMain->getId());
        $this->repository->record($aria, $this->utc('2026-11-13 19:00:00'), $this->utc('2026-11-13 20:00:00'));

        $queries = $this->sqlExecutedDuring(function () use ($aria): void {
            $this->repository->record($aria, $this->utc('2026-11-13 20:04:10'), $this->utc('2026-11-13 21:04:00'));
        });
        $readingQueries = $this->queriesMatching($queries, '/character_wallet_reading/i');

        self::assertCount(1, $readingQueries, implode("\n", $queries));
        self::assertMatchesRegularExpression('/^\s*INSERT\s+INTO\s+character_wallet_reading.*ON\s+CONFLICT\s*\(\s*character_id\s*\)\s+DO\s+UPDATE/is', $readingQueries[0]);
    }

    private function utc(string $dateTime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($dateTime . ' UTC');
    }

    private function rowCountOf(Character $character): int
    {
        return \count($this->em->getRepository(CharacterWalletReading::class)->findBy(['character' => $character->getId()]));
    }

    /**
     * @return array{?string, string}
     */
    private function datesStoredFor(Character $character): array
    {
        $this->em->clear();
        $reading = $this->em->getRepository(CharacterWalletReading::class)->findOneBy(['character' => $character->getId()]);
        self::assertNotNull($reading);
        $utc = new \DateTimeZone('UTC');

        return [
            $reading->getReadAt()?->setTimezone($utc)->format('Y-m-d H:i:s'),
            $reading->getNextReadAt()->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    private function persistReading(Character $character, ?string $readAtUtc, string $nextReadAtUtc): void
    {
        $this->em->persist(
            (new CharacterWalletReading())
                ->setCharacter($character)
                ->setReadAt($readAtUtc === null ? null : new \DateTimeImmutable($readAtUtc . ' UTC'))
                ->setNextReadAt(new \DateTimeImmutable($nextReadAtUtc . ' UTC')),
        );
    }
}
