<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MiningOperation;

use App\Entity\User;
use App\Enum\MiningSeasonPeriod;
use App\Service\MiningOperation\MiningSeasonReader;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[Group('issue-364')]
final class MiningSeasonShipsReaderTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use DrivesTheE1MiningOperation;
    use SeedsMiningBarges;

    protected function setUp(): void
    {
        $this->setUpTheE1Pilots();
        $this->seedMiningBarges();
    }

    public function testShipsComeFromTheFrozenValuesOfTheCharactersWithAReadStartSnapshotAndIgnoreTheOperationsWithoutFrozenShares(): void
    {
        $this->closingOperationOfEliaWithoutFrozenShares();
        $this->recordShips(['Aria Solberg' => self::HULK, 'Brann Holt' => self::HULK, 'Brann II' => self::HULK, 'Cyra Lune' => self::ORCA]);
        $this->closingE1(unreadStarts: ['Brann II']);
        $this->enrolmentNamed('Brann II')->correct(self::VELDSPAR, null, 33_337, $this->aria, new \DateTimeImmutable('2026-11-05T21:45:00+00:00'));
        $this->em->flush();
        $this->updateValuation(['valuationSide' => 'sell', 'boosterCutPercent' => 10, 'corporationCutPercent' => 5, 'boosterMemberId' => (string) $this->cyraMembership->getId()]);
        self::mockTime(new \DateTimeImmutable(self::FROZEN_AT));
        $this->as($this->aria);
        $this->post('freeze-shares');
        self::assertResponseStatusCodeSame(200);

        $summary = self::getContainer()->get(MiningSeasonReader::class)->read($this->em->find(User::class, $this->elia->getId()), MiningSeasonPeriod::All);

        self::assertSame(
            [
                'ships' => [
                    [self::HULK, 2, 153, 4_175_721, 1_637_538, false],
                    [self::ORCA, 1, 89, null, null, true],
                ],
                'unknownShipCharactersCount' => 1,
            ],
            [
                'ships' => array_map(
                    static fn (array $ship): array => [$ship['shipTypeId'], $ship['charactersCount'], $ship['minutes'], $ship['minedValue'], $ship['iskPerHour'], $ship['isBooster']],
                    $summary->ships,
                ),
                'unknownShipCharactersCount' => $summary->unknownShipCharactersCount,
            ],
        );
    }

    private function closingOperationOfEliaWithoutFrozenShares(): void
    {
        $closing = $this->persistMiningOperation($this->em, $this->elia, 'Closing without frozen shares', '2026-11-05T18:00:00+00:00', ownerCharacterNames: ['Elia Varr']);
        $closing->start(new \DateTimeImmutable('2026-11-05T18:00:00+00:00'), null);
        $enrolment = $closing->membershipOf($this->elia)->getCharacters()->first();
        $enrolment->scheduleStartSnapshot(new \DateTimeImmutable('2026-11-05T18:00:00+00:00'))->take(new \DateTimeImmutable('2026-11-05T18:00:02+00:00'), $this->reading([]));
        $enrolment->recordShip(self::HULK);
        $closing->end(new \DateTimeImmutable('2026-11-05T19:00:00+00:00'));
        $this->em->flush();
    }
}
