<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MiningOperation;

use App\Entity\CachedAsset;
use App\Entity\CorpAssetVisibility;
use App\Entity\User;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningSplitMode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Uuid;

#[Group('issue-266')]
final class MiningOperationFleetStorageApiTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use DrivesTheE1MiningOperation;

    private const string CONTAINER_NAME = 'OPS-HAUL';
    private const string ASSETS_READ_AT = '2026-11-05T21:41:00+00:00';
    private const string PAID_AT = '2026-11-05T22:24:00+00:00';
    private const int STATION_ID = 60_008_494;
    private const int STATION_CONTAINER = 17366;
    private const int OPS_HAUL_ITEM_ID = 1_051_000_001;

    protected function setUp(): void
    {
        $this->setUpTheE1Pilots();
    }

    public function testOwnerChoosesTheRecipientAndTheContainer(): void
    {
        $this->closingE1();
        $this->as($this->aria);

        $this->request('PATCH', $this->fleetStorageUri(), ['recipientMemberId' => $this->memberIdOf('Brann Holt'), 'containerName' => self::CONTAINER_NAME]);

        self::assertSame(
            [200, 'Brann Holt', self::CONTAINER_NAME, $this->memberIdOf('Brann Holt')],
            [$this->client->getResponse()->getStatusCode(), ...$this->fields($this->responseJson(), ['recipientName', 'containerName', 'recipientMemberId'])],
        );
    }

    public function testPlainMemberCannotChooseTheRecipient(): void
    {
        $this->closingE1();
        $this->as($this->brann);

        $this->request('PATCH', $this->fleetStorageUri(), ['recipientMemberId' => $this->memberIdOf('Brann Holt'), 'containerName' => self::CONTAINER_NAME]);

        self::assertSame([403, 'Only the operation owner or an admin can perform this action'], $this->errorDetail());
    }

    public function testRecipientMustBeAnAcceptedParticipantOfThisOperation(): void
    {
        $this->closingE1();
        $this->membershipNamed('Cyra Lune')->setStatus(MiningOperationMemberStatus::Pending);
        $iceBelt = $this->persistMiningOperation($this->em, $this->elia, 'Ice belt — dimanche', self::STARTED_AT, ownerCharacterNames: ['Elia Varr']);
        $this->em->flush();
        $eliaMembership = $iceBelt->membershipOf($this->elia);
        $outcomes = [];

        foreach ([$this->memberIdOf('Cyra Lune'), (string) $eliaMembership?->getId()] as $recipientMemberId) {
            $this->as($this->aria);
            $this->request('PATCH', $this->fleetStorageUri(), ['recipientMemberId' => $recipientMemberId]);
            $outcomes[] = $this->errorDetail();
        }

        self::assertSame(
            [[400, 'The fleet storage recipient must be an accepted participant'], [400, 'The fleet storage recipient must be an accepted participant']],
            $outcomes,
        );
    }

    public function testRecipientAndContainerAreValidatedField(): void
    {
        $this->closingE1();
        $outcomes = [];

        foreach ([['containerName' => ''], ['containerName' => str_repeat('H', 101)], ['recipientMemberId' => 'abc']] as $body) {
            $this->as($this->aria);
            $this->request('PATCH', $this->fleetStorageUri(), $body);
            $outcomes[] = [$this->client->getResponse()->getStatusCode(), $this->violatedPaths()];
        }

        self::assertSame([[422, ['containerName']], [422, ['containerName']], [422, ['recipientMemberId']]], $outcomes);
    }

    public function testParticipantWhoBecomesTheRecipientNoLongerDelivers(): void
    {
        $this->closingE1();
        $this->chooseRecipient('Brann Holt');
        $this->declareAs($this->aria, 'Aria Solberg', ['delivery' => 'partial', 'quantities' => [['typeId' => self::VELDSPAR, 'quantity' => 100_000]]]);
        $declared = $this->declarationIn($this->responseJson(), 'Aria Solberg');

        $this->chooseRecipient('Aria Solberg');
        $aria = $this->reloadedOperation()->membershipOf($this->em->find(User::class, $this->aria->getId()));

        self::assertSame(
            ['declared' => ['partial', [['typeId' => self::VELDSPAR, 'typeName' => 'Veldspar', 'quantity' => 100_000]]], 'afterBecomingRecipient' => ['none', null]],
            ['declared' => $declared, 'afterBecomingRecipient' => [$aria?->getFleetStorageDelivery()->value, $aria?->getFleetStorageQuantities()]],
        );
    }

    public function testEachForThemselvesOperationHasNoFleetStorage(): void
    {
        $this->closingE1(splitMode: MiningSplitMode::EachForThemselves);

        $this->as($this->aria);
        $this->request('PATCH', $this->fleetStorageUri(), ['containerName' => self::CONTAINER_NAME]);
        $update = $this->errorDetail();
        $this->as($this->aria);
        $this->request('PATCH', $this->declarationUri('Aria Solberg'), ['delivery' => 'none']);

        self::assertSame(
            [[400, 'An each-for-themselves operation has no fleet storage'], [400, 'An each-for-themselves operation has no fleet storage']],
            [$update, $this->errorDetail()],
        );
    }

    public function testFleetStorageOfFrozenSharesCannotChange(): void
    {
        $this->fs2Frozen();

        $this->as($this->aria);
        $this->request('PATCH', $this->fleetStorageUri(), ['containerName' => 'OPS-HAUL-2']);
        $update = $this->errorDetail();
        $this->as($this->aria);
        $this->request('PATCH', $this->declarationUri('Aria Solberg'), ['delivery' => 'all']);

        self::assertSame(
            [[400, 'The shares of this operation are frozen'], [400, 'The shares of this operation are frozen']],
            [$update, $this->errorDetail()],
        );
    }

    public function testParticipantDeclaresThePartOfHerOreSheDelivers(): void
    {
        $this->closingE1();
        $this->chooseRecipient('Brann Holt');

        $this->declareAs($this->aria, 'Aria Solberg', ['delivery' => 'partial', 'quantities' => [['typeId' => self::VELDSPAR, 'quantity' => 100_000]]]);

        self::assertSame(
            [200, ['partial', [['typeId' => self::VELDSPAR, 'typeName' => 'Veldspar', 'quantity' => 100_000]]]],
            [$this->client->getResponse()->getStatusCode(), $this->declarationIn($this->responseJson(), 'Aria Solberg')],
        );
    }

    public function testPlainMemberDeclaresForHerselfOnly(): void
    {
        $this->closingE1();
        $this->chooseRecipient('Brann Holt');

        $this->declareAs($this->cyra, 'Cyra Lune', ['delivery' => 'all']);
        $own = $this->client->getResponse()->getStatusCode();
        $this->declareAs($this->cyra, 'Aria Solberg', ['delivery' => 'all']);

        self::assertSame(
            [200, [403, 'Only the participant, the operation owner or an admin can declare this fleet storage']],
            [$own, $this->errorDetail()],
        );
    }

    public function testOwnerDeclaresForAnotherParticipant(): void
    {
        $this->closingE1();
        $this->chooseRecipient('Brann Holt');

        $this->declareAs($this->aria, 'Cyra Lune', ['delivery' => 'all']);

        self::assertSame([200, ['all', []]], [$this->client->getResponse()->getStatusCode(), $this->declarationIn($this->responseJson(), 'Cyra Lune')]);
    }

    public function testRecipientCannotDeliverToHimself(): void
    {
        $this->closingE1();
        $this->chooseRecipient('Brann Holt');

        $this->declareAs($this->aria, 'Brann Holt', ['delivery' => 'all']);

        self::assertSame([400, 'The fleet storage recipient keeps the ore delivered to them'], $this->errorDetail());
    }

    public function testDeliveringNeedsARecipientButKeepingEverythingDoesNot(): void
    {
        $this->closingE1();

        $this->declareAs($this->aria, 'Aria Solberg', ['delivery' => 'all']);
        $delivering = $this->errorDetail();
        $this->declareAs($this->aria, 'Aria Solberg', ['delivery' => 'none']);

        self::assertSame(
            [[400, 'Choose the fleet storage recipient first'], 200],
            [$delivering, $this->client->getResponse()->getStatusCode()],
        );
    }

    public function testDeclarationIsValidatedField(): void
    {
        $this->closingE1();
        $this->chooseRecipient('Brann Holt');
        $outcomes = [];

        foreach ([
            ['delivery' => 'half'],
            ['delivery' => 'partial', 'quantities' => []],
            ['delivery' => 'all', 'quantities' => [['typeId' => self::VELDSPAR, 'quantity' => 1]]],
            ['delivery' => 'partial', 'quantities' => [['typeId' => self::VELDSPAR, 'quantity' => 0]]],
            ['delivery' => 'partial', 'quantities' => [['typeId' => self::VELDSPAR, 'quantity' => 10], ['typeId' => self::VELDSPAR, 'quantity' => 20]]],
            ['delivery' => 'partial', 'quantities' => [['typeId' => 0, 'quantity' => 10]]],
        ] as $body) {
            $this->declareAs($this->aria, 'Aria Solberg', $body);
            $outcomes[] = [$this->client->getResponse()->getStatusCode(), $this->violatedFields()];
        }

        self::assertSame(
            [[422, ['delivery']], [422, ['quantities']], [422, ['quantities']], [422, ['quantities']], [422, ['quantities']], [422, ['quantities']]],
            $outcomes,
        );
    }

    public function testPendingPilotDeclaresNothingAndAnUnknownMemberIsNotFound(): void
    {
        $this->closingE1();
        $this->chooseRecipient('Brann Holt');
        $this->spodumain = $this->reloadedOperation();
        $this->membershipNamed('Cyra Lune')->setStatus(MiningOperationMemberStatus::Pending);
        $this->em->flush();
        $outcomes = [];

        foreach ([$this->memberIdOf('Cyra Lune'), Uuid::v4()->toRfc4122(), 'abc'] as $memberId) {
            $this->as($this->aria);
            $this->request('PATCH', sprintf('/api/mining-operations/%s/fleet-storage/declarations/%s', $this->spodumain->getId(), $memberId), ['delivery' => 'all']);
            $outcomes[] = $this->errorDetail();
        }

        self::assertSame(
            [[400, 'Only an accepted participant declares a fleet storage'], [404, 'Member not found'], [404, 'Member not found']],
            $outcomes,
        );
    }

    public function testPilotWithoutAccessCannotReadTheFleetStorageNorItsVerification(): void
    {
        $this->closingE1();

        $this->as($this->dorn);
        $this->request('GET', $this->fleetStorageUri());
        $fleetStorage = $this->errorDetail();
        $this->as($this->dorn);
        $this->request('GET', $this->fleetStorageUri().'/verification');

        self::assertSame(
            [[403, 'You do not have access to this operation'], [403, 'You do not have access to this operation']],
            [$fleetStorage, $this->errorDetail()],
        );
    }

    public function testParticipantReadsEveryDeclarationInLedgerOrder(): void
    {
        $this->closingE1();
        $this->chooseRecipient('Brann Holt');
        $this->declareAs($this->aria, 'Aria Solberg', ['delivery' => 'partial', 'quantities' => [['typeId' => self::VELDSPAR, 'quantity' => 100_000]]]);
        $this->as($this->brann);

        $this->request('GET', $this->fleetStorageUri());

        self::assertSame(
            [
                'editable' => true,
                'declarations' => [
                    ['Aria Solberg', 'partial', [['typeId' => self::VELDSPAR, 'typeName' => 'Veldspar', 'quantity' => 100_000]], false],
                    ['Brann Holt', 'none', [], false],
                    ['Cyra Lune', 'none', [], false],
                ],
            ],
            [
                'editable' => $this->responseJson()['editable'] ?? null,
                'declarations' => array_map(
                    fn (array $declaration): array => $this->fields($declaration, ['characterName', 'delivery', 'quantities', 'canDeclare']),
                    $this->responseJson()['declarations'] ?? [],
                ),
            ],
        );
    }

    public function testOperationAndInvitationNameTheRecipient(): void
    {
        $this->closingE1();
        $this->chooseRecipient('Brann Holt');

        $this->as($this->brann);
        $this->request('GET', sprintf('/api/mining-operations/%s', $this->spodumain->getId()));
        $operation = $this->responseJson()['fleetStorageRecipientName'] ?? null;
        $this->as($this->brann);
        $this->request('GET', sprintf('/api/mining-operations/join/%s', $this->spodumain->getJoinCode()));

        self::assertSame(['Brann Holt', 'Brann Holt'], [$operation, $this->responseJson()['fleetStorageRecipientName'] ?? null]);
    }

    public function testFreezingTheSharesOwesThePartialDepositorHerShareMinusWhatSheKept(): void
    {
        $this->fs2Frozen();
        $this->as($this->aria);

        $this->request('GET', sprintf('/api/mining-operations/%s/payments', $this->spodumain->getId()));

        self::assertSame(
            [
                [0, 'booster_cut', 'Brann Holt', 'Cyra Lune', 556_755],
                [1, 'fleet_storage_share', 'Brann Holt', 'Aria Solberg', 1_691_091],
            ],
            array_map(fn (array $payment): array => $this->fields($payment, ['position', 'kind', 'payerName', 'beneficiaryName', 'amount']), $this->responseJson()),
        );
    }

    public function testFrozenValuationKeepsTheKeptValueAndTheDebtOfTheDepositor(): void
    {
        $this->fs2Frozen();
        $this->as($this->aria);

        $this->request('GET', sprintf('/api/mining-operations/%s/valuation', $this->spodumain->getId()));

        self::assertSame(
            ['Brann Holt', ['partial', 1_961_000, 738_093, 1_691_091]],
            [
                $this->responseJson()['fleetStorageRecipientName'] ?? null,
                $this->fields($this->participantNamed('Aria Solberg'), ['fleetStorageDelivery', 'deliveredValue', 'keptValue', 'fleetStorageDebt']),
            ],
        );
    }

    public function testFleetStorageLineIsMarkedByTheRecipientOrTheOwnerButNotByAnotherMember(): void
    {
        $this->fs2Frozen();
        $paymentId = $this->paymentIdAt(1);
        $outcomes = [];

        foreach ([$this->brann, $this->aria, $this->cyra] as $marker) {
            $this->as($marker);
            $this->request('PATCH', sprintf('/api/mining-operations/%s/payments/%s', $this->spodumain->getId(), $paymentId), ['paid' => true]);
            $outcomes[] = $this->client->getResponse()->getStatusCode();
        }

        self::assertSame([200, 200, 403], $outcomes);
    }

    public function testDeliveredQuantityAboveWhatWasMinedRefusesTheFreeze(): void
    {
        $this->closingE1();
        $this->updateValuation(['valuationSide' => 'sell', 'boosterCutPercent' => 10, 'boosterMemberId' => (string) $this->cyraMembership->getId()]);
        $this->chooseRecipient('Brann Holt');
        $this->declareAs($this->aria, 'Aria Solberg', ['delivery' => 'partial', 'quantities' => [['typeId' => self::VELDSPAR, 'quantity' => 100_001]]]);
        $this->as($this->aria);

        $this->post('freeze-shares');

        self::assertSame(
            [[400, 'Some delivered quantities exceed what was mined: Aria Solberg'], 'closing'],
            [$this->errorDetail(), $this->reloadedOperation()->getStatus()->value],
        );
    }

    public function testDeliveredVeldsparFoundInTheNamedCorporationContainerIsVerified(): void
    {
        $this->closingE1();
        $this->chooseRecipient('Brann Holt', self::CONTAINER_NAME);
        $this->declareAs($this->aria, 'Aria Solberg', ['delivery' => 'partial', 'quantities' => [['typeId' => self::VELDSPAR, 'quantity' => 100_000]]]);
        $this->persistOpsHaulHolding([self::COMPRESSED_VELDSPAR => 60_000, self::VELDSPAR => 40_000]);
        $this->as($this->aria);

        $this->request('GET', $this->fleetStorageUri().'/verification');

        self::assertSame(
            [
                200,
                ['verified', null, self::CONTAINER_NAME, self::ASSETS_READ_AT],
                [['typeId' => self::VELDSPAR, 'typeName' => 'Veldspar', 'expectedQuantity' => 100_000, 'foundQuantity' => 100_000, 'missingQuantity' => 0, 'status' => 'verified']],
            ],
            [
                $this->client->getResponse()->getStatusCode(),
                $this->fields($this->responseJson(), ['status', 'unknownReason', 'containerName', 'assetsReadAt']),
                $this->responseJson()['lines'] ?? null,
            ],
        );
    }

    private function fs2Frozen(): void
    {
        $this->closingE1();
        $this->updateValuation(['valuationSide' => 'sell', 'boosterCutPercent' => 10, 'boosterMemberId' => (string) $this->cyraMembership->getId()]);
        $this->chooseRecipient('Brann Holt', self::CONTAINER_NAME);
        $this->declareAs($this->aria, 'Aria Solberg', ['delivery' => 'partial', 'quantities' => [['typeId' => self::VELDSPAR, 'quantity' => 100_000]]]);
        self::mockTime(new \DateTimeImmutable(self::FROZEN_AT));
        $this->as($this->aria);
        $this->post('freeze-shares');
        self::assertResponseStatusCodeSame(200);
    }

    private function chooseRecipient(string $mainCharacterName, ?string $containerName = null): void
    {
        $this->as($this->aria);
        $this->request('PATCH', $this->fleetStorageUri(), ['recipientMemberId' => $this->memberIdOf($mainCharacterName)] + ($containerName === null ? [] : ['containerName' => $containerName]));
    }

    private function declareAs(User $user, string $mainCharacterName, array $declaration): void
    {
        $this->as($user);
        $this->request('PATCH', $this->declarationUri($mainCharacterName), $declaration);
    }

    private function persistOpsHaulHolding(array $quantitiesByTypeId): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist((new CorpAssetVisibility())->setCorporationId(self::C1)->setVisibleDivisions([1, 2]));
        $em->persist($this->corporationAsset(self::OPS_HAUL_ITEM_ID, self::STATION_CONTAINER, 1, self::STATION_ID, 'station')->setItemName(self::CONTAINER_NAME)->setLocationFlag('CorpSAG1'));
        $itemId = self::OPS_HAUL_ITEM_ID;
        foreach ($quantitiesByTypeId as $typeId => $quantity) {
            $em->persist($this->corporationAsset(++$itemId, $typeId, $quantity, self::OPS_HAUL_ITEM_ID, 'item'));
        }
        $em->flush();
    }

    private function corporationAsset(int $itemId, int $typeId, int $quantity, int $locationId, string $locationType): CachedAsset
    {
        return (new CachedAsset())
            ->setItemId($itemId)
            ->setTypeId($typeId)
            ->setQuantity($quantity)
            ->setLocationId($locationId)
            ->setLocationType($locationType)
            ->setLocationName('Aldranette VI - Moon 1 - Federal Navy Academy')
            ->setCorporationId(self::C1)
            ->setIsCorporationAsset(true)
            ->setCachedAt(new \DateTimeImmutable(self::ASSETS_READ_AT));
    }

    private function memberIdOf(string $mainCharacterName): string
    {
        return (string) $this->membershipNamed($mainCharacterName)->getId();
    }

    private function fleetStorageUri(): string
    {
        return sprintf('/api/mining-operations/%s/fleet-storage', $this->spodumain->getId());
    }

    private function declarationUri(string $mainCharacterName): string
    {
        return sprintf('%s/declarations/%s', $this->fleetStorageUri(), $this->memberIdOf($mainCharacterName));
    }

    private function declarationIn(array $fleetStorage, string $characterName): ?array
    {
        foreach ($fleetStorage['declarations'] ?? [] as $declaration) {
            if (($declaration['characterName'] ?? null) === $characterName) {
                return [$declaration['delivery'] ?? null, $declaration['quantities'] ?? null];
            }
        }

        return null;
    }

    private function participantNamed(string $characterName): array
    {
        foreach ($this->responseJson()['participants'] ?? [] as $participant) {
            if (($participant['characterName'] ?? null) === $characterName) {
                return $participant;
            }
        }

        return [];
    }

    private function paymentIdAt(int $position): string
    {
        $this->as($this->aria);
        $this->request('GET', sprintf('/api/mining-operations/%s/payments', $this->spodumain->getId()));
        foreach ($this->responseJson() as $payment) {
            if (($payment['position'] ?? null) === $position) {
                return (string) $payment['id'];
            }
        }

        return Uuid::v4()->toRfc4122();
    }

    private function violatedFields(): array
    {
        return array_values(array_unique(array_map(
            static fn (string $path): string => (string) preg_replace('/[\[.].*$/', '', $path),
            array_column($this->responseJson()['violations'] ?? [], 'propertyPath'),
        )));
    }
}
