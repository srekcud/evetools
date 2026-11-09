<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\IndustryProjectBomLine;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Service\JitaMarketService;
use App\Service\Mercure\MercurePublisherService;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[Group('issue-320')]
final class ProjectLineReservationApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const int TRITANIUM_REQUIRED = 12_000;

    private array $publishedUpdates = [];

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'urn:uuid:published';
        });
        self::getContainer()->set(MercurePublisherService::class, new MercurePublisherService($hub, new NullLogger()));
    }

    public function testAcceptedMemberReservesTheWholeFreeQuantityOfALineWhenHeGivesNoQuantity(): void
    {
        $this->actAs($this->member);
        $this->request('POST', $this->reservationUri($this->tritaniumLine), []);

        self::assertResponseStatusCodeSame(201);
        $reservation = $this->responseJson();
        self::assertSame(
            [$this->idOf($this->tritaniumLine), $this->idOf($this->memberMembership), 'Brann Holt', self::TRITANIUM_REQUIRED],
            [$reservation['bomItemId'], $reservation['participantId'], $reservation['characterName'], $reservation['quantity']],
        );
        self::assertSame([self::TRITANIUM_REQUIRED], $this->storedReservationQuantitiesOf($this->tritaniumLine));
    }

    public function testBomShowsTheReservationToEveryParticipantWithTheMainNameAndTheFreeQuantity(): void
    {
        $this->reserve($this->member, $this->tritaniumLine, 5_000);

        $this->actAs($this->owner);
        $this->request('GET', $this->projectUri($this->ishtarBatch).'/bom');
        $line = $this->bomLineOf($this->tritaniumLine);
        self::assertSame(
            [5_000, 7_000, [['Brann Holt', 5_000, false]]],
            [$line['reservedQuantity'], $line['freeQuantity'], $this->reservationRows($line)],
        );

        $this->actAs($this->member);
        $this->request('GET', $this->projectUri($this->ishtarBatch).'/bom');
        self::assertSame([['Brann Holt', 5_000, true]], $this->reservationRows($this->bomLineOf($this->tritaniumLine)));
    }

    public function testLineWithoutReservationExposesAnEmptyListAndAllItsRemainingQuantityAsFree(): void
    {
        $this->actAs($this->owner);
        $this->request('GET', $this->projectUri($this->ishtarBatch).'/bom');
        $line = $this->bomLineOf($this->morphiteLine);

        self::assertSame([0, 147, []], [$line['reservedQuantity'], $line['freeQuantity'], $line['reservations']]);
    }

    public function testReservingAgainReplacesTheQuantityOfTheSameParticipant(): void
    {
        $this->reserve($this->member, $this->tritaniumLine, 5_000);
        $this->reserve($this->member, $this->tritaniumLine, 2_000);

        self::assertSame([2_000], $this->storedReservationQuantitiesOf($this->tritaniumLine));
    }

    public function testSeveralParticipantsReserveAPartEachUpToTheFreeQuantity(): void
    {
        $this->reserve($this->member, $this->tritaniumLine, 5_000);
        $this->reserve($this->owner, $this->tritaniumLine, 7_000);

        self::assertSame([5_000, 7_000], $this->storedReservationQuantitiesOf($this->tritaniumLine));
    }

    public function testReservationAboveTheQuantityLeftFreeByTheOthersIsRefused(): void
    {
        $this->reserve($this->member, $this->tritaniumLine, 5_000);

        $this->actAs($this->owner);
        $this->request('POST', $this->reservationUri($this->tritaniumLine), ['quantity' => 7_001]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([5_000], $this->storedReservationQuantitiesOf($this->tritaniumLine));
    }

    public function testReservationWithoutQuantityTakesWhatTheOthersLeaveFree(): void
    {
        $this->reserve($this->member, $this->tritaniumLine, 5_000);
        $this->reserve($this->owner, $this->tritaniumLine, null);

        self::assertSame([5_000, 7_000], $this->storedReservationQuantitiesOf($this->tritaniumLine));
    }

    public function testReservationOfAFullyReservedLineIsRefused(): void
    {
        $this->reserve($this->member, $this->tritaniumLine, null);

        $this->actAs($this->owner);
        $this->request('POST', $this->reservationUri($this->tritaniumLine), []);

        self::assertResponseStatusCodeSame(400);
    }

    public function testReservationOfZeroOrNegativeQuantityIsRefused(): void
    {
        $this->actAs($this->member);
        $this->request('POST', $this->reservationUri($this->tritaniumLine), ['quantity' => 0]);
        self::assertResponseStatusCodeSame(400);
        $this->actAs($this->member);
        $this->request('POST', $this->reservationUri($this->tritaniumLine), ['quantity' => -3]);
        self::assertResponseStatusCodeSame(400);

        self::assertSame([], $this->storedReservationQuantitiesOf($this->tritaniumLine));
    }

    public function testReservationOfACompleteLineIsRefused(): void
    {
        $this->actAs($this->member);
        $this->request('POST', $this->reservationUri($this->ishtarJobLine), []);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([400, 'The line is already complete'], $this->errorDetail());
    }

    public function testJobLineIsReservedInTheSameWayAsAMaterialLine(): void
    {
        $this->reserve($this->member, $this->ramJobLine, 100);

        self::assertSame([100], $this->storedReservationQuantitiesOf($this->ramJobLine));
    }

    public function testReservationOfACompletedProjectIsRefused(): void
    {
        $this->ishtarBatch->setStatus(ProjectStatus::Completed);
        $this->em->flush();

        $this->actAs($this->member);
        $this->request('POST', $this->reservationUri($this->tritaniumLine), []);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([400, 'Cannot reserve a line of a completed project'], $this->errorDetail());
    }

    public function testOutsiderAndPendingMemberCannotReserve(): void
    {
        foreach ([$this->outsider, $this->pendingMember] as $user) {
            $this->actAs($user);
            $this->request('POST', $this->reservationUri($this->tritaniumLine), []);

            self::assertResponseStatusCodeSame(403);
            self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
        }
        self::assertSame([], $this->storedReservationQuantitiesOf($this->tritaniumLine));
    }

    public function testReservationOnABomLineOfAnotherProjectIsNotFound(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', $this->projectUri($this->ishtarBatch).'/bom/'.$this->idOf($this->hecateTritaniumLine).'/reservation', []);

        self::assertResponseStatusCodeSame(404);
    }

    public function testParticipantReleasesHisOwnReservationWithoutTouchingTheOthers(): void
    {
        $this->reserve($this->member, $this->tritaniumLine, 5_000);
        $this->reserve($this->owner, $this->tritaniumLine, 7_000);

        $this->actAs($this->member);
        $this->request('DELETE', $this->reservationUri($this->tritaniumLine));

        self::assertResponseStatusCodeSame(204);
        self::assertSame([7_000], $this->storedReservationQuantitiesOf($this->tritaniumLine));
    }

    public function testReleaseWithoutOwnReservationIsNotFoundEvenWhenAnotherParticipantHoldsOne(): void
    {
        $this->reserve($this->owner, $this->tritaniumLine, 7_000);

        $this->actAs($this->member);
        $this->request('DELETE', $this->reservationUri($this->tritaniumLine));

        self::assertResponseStatusCodeSame(404);
        self::assertSame([7_000], $this->storedReservationQuantitiesOf($this->tritaniumLine));
    }

    public function testReservationDoesNotStopAnotherParticipantFromContributingOnTheLine(): void
    {
        $this->givenJitaPercentilePrices([34 => 7.0]);
        $this->reserve($this->member, $this->tritaniumLine, null);

        $this->actAs($this->owner);
        $this->request('POST', $this->projectUri($this->ishtarBatch).'/contributions', ['type' => 'material', 'bomItemId' => $this->idOf($this->tritaniumLine), 'quantity' => 100]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testContributionAboveTheRemainingQuantityOfTheLineIsRefused(): void
    {
        $this->givenJitaPercentilePrices([11399 => 18_500.0]);
        $this->actAs($this->owner);
        $this->request('POST', $this->projectUri($this->ishtarBatch).'/contributions', ['type' => 'material', 'bomItemId' => $this->idOf($this->morphiteLine), 'quantity' => 148]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([400, 'The contribution exceeds the remaining quantity of the line'], $this->errorDetail());
    }

    public function testContributionOfExactlyTheRemainingQuantityOfTheLineIsAccepted(): void
    {
        $this->givenJitaPercentilePrices([11399 => 18_500.0]);
        $this->actAs($this->owner);
        $this->request('POST', $this->projectUri($this->ishtarBatch).'/contributions', ['type' => 'material', 'bomItemId' => $this->idOf($this->morphiteLine), 'quantity' => 147]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testApprovedContributionOfTheHolderLowersHisReservationByItsQuantity(): void
    {
        $this->givenJitaPercentilePrices([34 => 7.0]);
        $this->reserve($this->member, $this->tritaniumLine, 5_000);
        $this->actAs($this->member);
        $this->request('POST', $this->projectUri($this->ishtarBatch).'/contributions', ['type' => 'material', 'bomItemId' => $this->idOf($this->tritaniumLine), 'quantity' => 2_000]);
        $contributionId = $this->responseJson()['id'];

        $this->actAs($this->owner);
        $this->request('PATCH', $this->projectUri($this->ishtarBatch).'/contributions/'.$contributionId, ['status' => 'approved']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([3_000], $this->storedReservationQuantitiesOf($this->tritaniumLine));
    }

    public function testApprovedContributionOfTheWholeReservationRemovesIt(): void
    {
        $this->givenJitaPercentilePrices([34 => 7.0]);
        $this->reserve($this->member, $this->tritaniumLine, 2_000);
        $this->actAs($this->member);
        $this->request('POST', $this->projectUri($this->ishtarBatch).'/contributions', ['type' => 'material', 'bomItemId' => $this->idOf($this->tritaniumLine), 'quantity' => 2_000]);
        $contributionId = $this->responseJson()['id'];

        $this->actAs($this->owner);
        $this->request('PATCH', $this->projectUri($this->ishtarBatch).'/contributions/'.$contributionId, ['status' => 'approved']);

        self::assertSame([], $this->storedReservationQuantitiesOf($this->tritaniumLine));
    }

    public function testReservationsOfALineAreRemovedWhenAnApprovedContributionCompletesIt(): void
    {
        $this->reserve($this->owner, $this->ramJobLine, 300);

        $this->actAs($this->owner);
        $this->request('PATCH', $this->projectUri($this->ishtarBatch).'/contributions/'.$this->idOf($this->memberRamJobInstall), ['status' => 'approved']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->storedReservationQuantitiesOf($this->ramJobLine));
    }

    public function testMemberWhoLeavesTheProjectLosesHisReservations(): void
    {
        $this->reserve($this->member, $this->tritaniumLine, 5_000);
        $this->reserve($this->member, $this->nocxiumLine, 100);
        $this->reserve($this->owner, $this->tritaniumLine, 1_000);

        $this->actAs($this->member);
        $this->request('POST', $this->projectUri($this->ishtarBatch).'/leave', []);

        self::assertSame([[1_000], []], [$this->storedReservationQuantitiesOf($this->tritaniumLine), $this->storedReservationQuantitiesOf($this->nocxiumLine)]);
    }

    public function testMemberWhoIsKickedLosesHisReservations(): void
    {
        $this->reserve($this->member, $this->tritaniumLine, 5_000);

        $this->actAs($this->owner);
        $this->request('DELETE', $this->projectUri($this->ishtarBatch).'/members/'.$this->idOf($this->memberMembership));

        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->storedReservationQuantitiesOf($this->tritaniumLine));
    }

    public function testProjectThatCompletesLosesEveryReservation(): void
    {
        $this->ishtarBatch->setStatus(ProjectStatus::Selling);
        $this->em->flush();
        $this->reserve($this->member, $this->tritaniumLine, 5_000);
        $this->reserve($this->owner, $this->nocxiumLine, 100);

        $this->actAs($this->owner);
        $this->request('PATCH', $this->projectUri($this->ishtarBatch), ['status' => 'completed']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([[], []], [$this->storedReservationQuantitiesOf($this->tritaniumLine), $this->storedReservationQuantitiesOf($this->nocxiumLine)]);
    }

    public function testReservationOfAnotherProjectIsKeptWhenThisProjectLosesAMember(): void
    {
        $this->reserve($this->owner, $this->hecateTritaniumLine, 1_000);

        $this->reserve($this->member, $this->tritaniumLine, 5_000);
        $this->actAs($this->member);
        $this->request('POST', $this->projectUri($this->ishtarBatch).'/leave', []);

        self::assertSame([1_000], $this->storedReservationQuantitiesOf($this->hecateTritaniumLine));
    }

    public function testReservationAndReleaseEachPublishALineReservedEventOnTheProjectTopic(): void
    {
        $this->reserve($this->member, $this->tritaniumLine, 5_000);
        $this->actAs($this->member);
        $this->request('DELETE', $this->reservationUri($this->tritaniumLine));

        $projectId = $this->ishtarBatch->getId()->toRfc4122();
        $events = array_values(array_filter(array_map(
            static fn (Update $update): array => ['topic' => $update->getTopics()[0], 'payload' => json_decode($update->getData(), true)],
            $this->publishedUpdates,
        ), static fn (array $event): bool => $event['payload']['action'] === 'line_reserved'));

        self::assertSame(
            [
                ['/group-project/'.$projectId.'/events', 'Tritanium', 'Brann Holt', 5_000, false],
                ['/group-project/'.$projectId.'/events', 'Tritanium', 'Brann Holt', 0, true],
            ],
            array_map(static fn (array $event): array => [
                $event['topic'],
                $event['payload']['data']['bomItemName'],
                $event['payload']['data']['characterName'],
                $event['payload']['data']['quantity'],
                $event['payload']['data']['released'],
            ], $events),
        );
        self::assertSame($this->idOf($this->tritaniumLine), $events[0]['payload']['data']['bomItemId']);
    }

    private function reserve(User $user, IndustryProjectBomLine $line, ?int $quantity): void
    {
        $this->actAs($user);
        $this->request('POST', $this->projectUri($line->getProject()).'/bom/'.$this->idOf($line).'/reservation', $quantity === null ? [] : ['quantity' => $quantity]);

        self::assertResponseStatusCodeSame(201);
    }

    private function reservationUri(IndustryProjectBomLine $line): string
    {
        return $this->projectUri($this->ishtarBatch).'/bom/'.$this->idOf($line).'/reservation';
    }

    private function bomLineOf(IndustryProjectBomLine $line): array
    {
        foreach ($this->responseJson() as $row) {
            if ($row['id'] === $this->idOf($line)) {
                return $row;
            }
        }

        self::fail('BOM line not in the response');
    }

    private function reservationRows(array $line): array
    {
        return array_map(
            static fn (array $reservation): array => [$reservation['characterName'], $reservation['quantity'], $reservation['isMine']],
            $line['reservations'],
        );
    }

    private function storedReservationQuantitiesOf(IndustryProjectBomLine $line): array
    {
        $this->em->clear();

        return array_map('intval', $this->em->getConnection()->fetchFirstColumn(
            'SELECT quantity FROM industry_project_line_reservations WHERE bom_line_id = :line ORDER BY quantity',
            ['line' => $line->getId()->toRfc4122()],
        ));
    }

    private function givenJitaPercentilePrices(array $pricesByTypeId): void
    {
        $jitaMarket = $this->createStub(JitaMarketService::class);
        $jitaMarket->method('getCheapestPercentilePrices')->willReturn($pricesByTypeId);
        self::getContainer()->set(JitaMarketService::class, $jitaMarket);
    }
}
