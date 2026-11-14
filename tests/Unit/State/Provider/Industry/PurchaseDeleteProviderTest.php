<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\Delete;
use App\ApiResource\Industry\ProjectPurchaseResource;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectPurchase;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Repository\IndustryProjectPurchaseRepository;
use App\State\Provider\Industry\PurchaseDeleteProvider;
use App\Tests\Unit\State\Processor\Industry\TracksJobsAndPurchasesOnIshtarBom;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(PurchaseDeleteProvider::class)]
#[Group('issue-362')]
#[AllowMockObjectsWithoutExpectations]
final class PurchaseDeleteProviderTest extends TestCase
{
    use TracksJobsAndPurchasesOnIshtarBom;

    private const string ONLY_BUYER_OR_OWNER = '403 Only the buyer or the project owner can delete this purchase';
    private const string NO_ACCESS = '403 You do not have access to this project';

    private IndustryProjectPurchase $ramPurchase;
    private IndustryProjectPurchase $memberTritaniumPurchase;
    private IndustryProjectPurchase $hecateTritaniumPurchase;
    private User $member;
    private User $outsider;

    protected function setUp(): void
    {
        $this->setUpIshtarProject();
        $this->member = new User();
        $this->characterOf($this->member, 'Cora Vance');
        $this->participate($this->ishtarProject, $this->member, ParticipantRole::Member);
        $this->outsider = new User();
        $this->characterOf($this->outsider, 'Elia Varr');
        $this->ramPurchase = $this->purchaseOn($this->ramLine, self::RAM_TYPE_ID, 50, 152_000.0);
        $this->memberTritaniumPurchase = $this->purchaseOn($this->tritaniumLine, self::TRITANIUM_TYPE_ID, 2_500, 4.2)->setBuyer($this->participantOf($this->member));
        $this->hecateTritaniumPurchase = $this->purchaseOn($this->hecateTritaniumLine, self::TRITANIUM_TYPE_ID, 1_000, 4.4);
    }

    public function testOwnerGetsThePurchaseOfHisProjectAsItsResourceAndItStaysOnItsLine(): void
    {
        $resource = $this->provide($this->owner, $this->ishtarProject, $this->ramPurchase->getId()->toRfc4122());

        self::assertInstanceOf(ProjectPurchaseResource::class, $resource);
        self::assertSame($this->ramPurchase->getId()->toRfc4122(), $resource->id);
        self::assertSame(50, $this->ramLine->getPurchasedQuantity());
    }

    #[Group('issue-981')]
    public function testOwnerGetsThePurchaseBoughtByAMember(): void
    {
        $resource = $this->provide($this->owner, $this->ishtarProject, $this->memberTritaniumPurchase->getId()->toRfc4122());

        self::assertSame($this->memberTritaniumPurchase->getId()->toRfc4122(), $resource->id);
    }

    #[Group('issue-981')]
    public function testMemberGetsThePurchaseHeBoughtHimself(): void
    {
        $resource = null;

        self::assertSame(
            self::GRANTED,
            $this->outcomeOf(function () use (&$resource): void {
                $resource = $this->provide($this->member, $this->ishtarProject, $this->memberTritaniumPurchase->getId()->toRfc4122());
            }),
        );
        self::assertSame($this->memberTritaniumPurchase->getId()->toRfc4122(), $resource->id);
    }

    #[Group('issue-981')]
    public function testMemberIsDeniedThePurchaseOfTheOwnerWhateverItsMissingBuyer(): void
    {
        self::assertSame(
            self::ONLY_BUYER_OR_OWNER,
            $this->outcomeOf(fn () => $this->provide($this->member, $this->ishtarProject, $this->ramPurchase->getId()->toRfc4122())),
        );
    }

    #[Group('issue-981')]
    public function testAcceptedAdminIsDeniedThePurchaseBoughtByAnotherParticipant(): void
    {
        self::assertSame(
            self::ONLY_BUYER_OR_OWNER,
            $this->outcomeOf(fn () => $this->provide($this->admin, $this->ishtarProject, $this->memberTritaniumPurchase->getId()->toRfc4122())),
        );
    }

    #[Group('issue-981')]
    public function testUserWhoIsNotAParticipantIsDenied(): void
    {
        self::assertSame(
            self::NO_ACCESS,
            $this->outcomeOf(fn () => $this->provide($this->outsider, $this->ishtarProject, $this->memberTritaniumPurchase->getId()->toRfc4122())),
        );
    }

    public function testPurchaseOfAnotherProjectIsNotFound(): void
    {
        self::assertSame(
            '404 Purchase not found',
            $this->outcomeOf(fn () => $this->provide($this->owner, $this->ishtarProject, $this->hecateTritaniumPurchase->getId()->toRfc4122())),
        );
    }

    public function testPurchaseIdThatIsNotAUuidIsNotFound(): void
    {
        self::assertSame(
            '404 Purchase not found',
            $this->outcomeOf(fn () => $this->provide($this->owner, $this->ishtarProject, 'p-77')),
        );
    }

    private function provide(User $user, IndustryProject $project, string $purchaseId): mixed
    {
        $purchaseRepository = $this->createStub(IndustryProjectPurchaseRepository::class);
        $purchaseRepository->method('find')->willReturnCallback(
            fn (Uuid $id): ?IndustryProjectPurchase => array_find(
                [$this->ramPurchase, $this->memberTritaniumPurchase, $this->hecateTritaniumPurchase],
                static fn (IndustryProjectPurchase $purchase): bool => $purchase->getId()->equals($id),
            ),
        );

        return (new PurchaseDeleteProvider($this->securityOf($user), $this->projectRepository(), $purchaseRepository, $this->accessChecker()))
            ->provide(new Delete(), ['projectId' => $project->getId()->toRfc4122(), 'id' => $purchaseId]);
    }

    private function participantOf(User $user): IndustryProjectParticipant
    {
        return array_find(
            $this->ishtarProject->getMembers()->toArray(),
            static fn (IndustryProjectParticipant $participant): bool => $participant->getUser() === $user,
        );
    }

    private function purchaseOn(IndustryProjectBomLine $line, int $typeId, int $quantity, float $unitPrice): IndustryProjectPurchase
    {
        $purchase = (new IndustryProjectPurchase())
            ->setTypeId($typeId)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setTotalPrice($quantity * $unitPrice)
            ->setSource('manual');
        (new \ReflectionProperty(IndustryProjectPurchase::class, 'id'))->setValue($purchase, Uuid::v4());
        $line->addPurchase($purchase);

        return $purchase;
    }
}
