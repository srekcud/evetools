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
