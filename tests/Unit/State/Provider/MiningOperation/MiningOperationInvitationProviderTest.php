<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Get;
use App\ApiResource\MiningOperation\MiningOperationInvitationResource;
use App\Entity\User;
use App\Enum\MarketSide;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningOperationVisibility;
use App\Service\MiningOperation\MiningOperationEnrolment;
use App\State\Provider\MiningOperation\MiningOperationInvitationProvider;
use App\State\Provider\MiningOperation\MiningOperationResourceMapper;
use App\Tests\Unit\Service\MiningOperation\BuildsSpodumainOperation;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(MiningOperationInvitationProvider::class)]
#[Group('issue-262')]
#[AllowMockObjectsWithoutExpectations]
final class MiningOperationInvitationProviderTest extends TestCase
{
    use BuildsSpodumainOperation;

    private Security&Stub $security;
    private MiningOperationInvitationProvider $provider;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->holdInOpenOperation('Brann IV', $this->iceBeltOperation());
        $this->security = $this->createStub(Security::class);
        $this->provider = new MiningOperationInvitationProvider(
            $this->security,
            $this->operationRepositoryReadingOperations(),
            new MiningOperationEnrolment($this->characterRepositoryReadingOpenEnrolments(), $this->createStub(EntityManagerInterface::class)),
            new MiningOperationResourceMapper(),
        );
    }

    public function testBrannPreviewsTheSpodumainOperationWithTheEligibilityOfEachOfHisCharacters(): void
    {
        $operation = $this->spodumainOperation();
        $this->actAs($this->brann);

        $invitation = $this->preview(self::JOIN_CODE);

        self::assertSame(self::JOIN_CODE, $invitation->code);
        self::assertSame($operation->getId()->toRfc4122(), $invitation->operationId);
        self::assertSame(self::SPODUMAIN_LABEL, $invitation->label);
        self::assertSame('Aria Solberg', $invitation->ownerCharacterName);
        self::assertSame(self::PLANNED_START, $invitation->plannedStartAt?->format(\DateTimeInterface::ATOM));
        self::assertSame('planned', $invitation->status);
        self::assertSame('corporation', $invitation->visibility);
        self::assertSame('value_prorata', $invitation->splitMode);
        self::assertSame(1, $invitation->participantsCount);
        self::assertTrue($invitation->autoAccepted);
        self::assertNull($invitation->myRole);
        self::assertNull($invitation->myStatus);
        self::assertSame(
            [
                [$this->idOf('Brann Holt'), $this->character('Brann Holt')->getEveCharacterId(), 'Brann Holt', true, 'eligible', null],
                [$this->idOf('Brann II'), $this->character('Brann II')->getEveCharacterId(), 'Brann II', false, 'eligible', null],
                [$this->idOf('Brann III'), $this->character('Brann III')->getEveCharacterId(), 'Brann III', false, 'reconnect_required', null],
                [$this->idOf('Brann IV'), $this->character('Brann IV')->getEveCharacterId(), 'Brann IV', false, 'in_another_operation', self::ICE_BELT_LABEL],
            ],
            array_map(static fn (array $character): array => array_values($character), $invitation->characters),
        );
        self::assertSame(
            ['id', 'eveCharacterId', 'name', 'isMain', 'eligibility', 'busyOperationLabel'],
            array_keys($invitation->characters[0]),
        );
    }

    #[Group('issue-264')]
    public function testInvitationShowsTheValuationRulesWithoutTheBoosterMembership(): void
    {
        $operation = $this->spodumainOperation();
        $ariaMembership = $this->membershipOf($operation, $this->aria);
        $operation->updateValuationSettings(valuationSide: MarketSide::Sell, boosterCutPercent: 10, boosterMember: $ariaMembership);
        $this->actAs($this->brann);

        $invitation = $this->preview(self::JOIN_CODE);

        self::assertSame(
            [
                'valuationForm' => 'compressed',
                'valuationMarket' => 'jita',
                'valuationSide' => 'sell',
                'structureId' => null,
                'structureName' => null,
                'reprocessYield' => null,
                'boosterCutPercent' => 10,
                'corporationCutPercent' => 0,
                'boosterCharacterName' => 'Aria Solberg',
            ],
            $invitation->valuationSettings,
        );
    }

    public function testCyraOfAnotherCorporationIsToldHerRequestWillNeedApproval(): void
    {
        $this->spodumainOperation();
        $this->actAs($this->cyra);

        $invitation = $this->preview(self::JOIN_CODE);

        self::assertFalse($invitation->autoAccepted);
        self::assertSame([['Cyra Lune', 'eligible']], array_map(
            static fn (array $character): array => [$character['name'], $character['eligibility']],
            $invitation->characters,
        ));
    }

    public function testAnyoneWithTheCodeOfAPrivateOperationPreviewsIt(): void
    {
        $this->spodumainOperation(visibility: MiningOperationVisibility::Private);
        $this->actAs($this->cyra);

        self::assertSame(self::SPODUMAIN_LABEL, $this->preview(self::JOIN_CODE)->label);
    }

    public function testUnknownCodeIsNotFound(): void
    {
        $this->spodumainOperation();
        $this->actAs($this->brann);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Mining operation not found');

        $this->preview(self::UNKNOWN_JOIN_CODE);
    }

    public function testCompletedOperationIsStillPreviewed(): void
    {
        $this->spodumainOperation(MiningOperationStatus::Completed);
        $this->actAs($this->brann);

        self::assertSame('completed', $this->preview(self::JOIN_CODE)->status);
    }

    public function testRemovedMemberSeesHisRemovedMembershipAndNoAutomaticAcceptance(): void
    {
        $operation = $this->spodumainOperation();
        $this->addMembership($operation, $this->brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Removed, []);
        $this->actAs($this->brann);

        $invitation = $this->preview(self::JOIN_CODE);

        self::assertSame('removed', $invitation->myStatus);
        self::assertFalse($invitation->autoAccepted);
    }

    public function testOwnerPreviewingHerOwnOperationSeesHerOwnerMembership(): void
    {
        $this->spodumainOperation();
        $this->actAs($this->aria);

        $invitation = $this->preview(self::JOIN_CODE);

        self::assertSame('owner', $invitation->myRole);
        self::assertSame('accepted', $invitation->myStatus);
    }

    private function actAs(User $user): void
    {
        $this->security->method('getUser')->willReturn($user);
    }

    private function preview(string $code): MiningOperationInvitationResource
    {
        return $this->provider->provide(new Get(), ['code' => $code]);
    }
}
