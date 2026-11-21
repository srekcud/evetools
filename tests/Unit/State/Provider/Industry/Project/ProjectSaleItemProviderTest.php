<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Patch;
use App\ApiResource\Industry\Project\ProjectSaleResource;
use App\Entity\Character;
use App\Entity\IndustryProjectSale;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use App\State\Provider\Industry\Project\ProjectSaleItemProvider;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectSaleItemProvider::class)]
#[Group('issue-164')]
final class ProjectSaleItemProviderTest extends TestCase
{
    use GroupProjectAccessProfiles;
    use SabreBatchSales;

    private Security&Stub $security;
    private ProjectSaleItemProvider $provider;

    protected function setUp(): void
    {
        $this->createSabreBatch();
        $this->sabreBatch->setStatus(ProjectStatus::Selling);
        $this->recordSabreSale();
        $this->createHecateBatchOfTheSabreBatchOwner();
        $this->security = $this->createStub(Security::class);

        $this->provider = new ProjectSaleItemProvider(
            $this->security,
            $this->projectRepositoryOfTheSabreAndHecateBatches(),
            $this->saleRepositoryOfTheSabreSale(),
            new ProjectAccessChecker($this->sabreBatchMemberRepository()),
        );
    }

    public function testAdminLoadsTheSaleToCorrect(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_ADMIN));

        $sale = $this->provideSale($this->sabreSaleUri());

        self::assertSame(
            [$this->sabreSale->getId()->toRfc4122(), 'Sabre', 2, 900000.0, 1800000.0, self::SABRE_SALE_VENUE, self::SABRE_SALE_SOLD_AT, self::SABRE_SALE_RECORDER_NAME],
            [$sale->id, $sale->typeName, $sale->quantity, $sale->unitPrice, $sale->totalPrice, $sale->venue, $sale->soldAt, $sale->recordedByCharacterName],
        );
    }

    #[Group('issue-241')]
    public function testAdminLoadsTheSaleToCorrectWithItsSeller(): void
    {
        $admin = $this->userWithProfile(self::ACCEPTED_ADMIN);
        $seller = (new Character())->setEveCharacterId(2120000002)->setName('O-Alt');
        $admin->method('getCharacters')->willReturn(new ArrayCollection([$seller]));
        $this->sabreSale->setSeller($seller);
        $this->security->method('getUser')->willReturn($admin);

        $sale = $this->provideSale($this->sabreSaleUri());

        self::assertSame([2120000002, 'O-Alt', self::SABRE_SALE_RECORDER_NAME], [$sale->sellerCharacterId, $sale->sellerCharacterName, $sale->recordedByCharacterName]);
    }

    #[Group('issue-339')]
    public function testAdminLoadsTheSaleToCorrectSoldByTheAltOfAnotherPlayerWithTheirMainAsSeller(): void
    {
        $otherMain = (new Character())->setEveCharacterId(2120000001)->setName('O-Main');
        $otherAlt = (new Character())->setEveCharacterId(2120000002)->setName('O-Alt');
        (new User())->addCharacter($otherMain)->addCharacter($otherAlt)->setMainCharacter($otherMain);
        $this->sabreSale->setSeller($otherAlt);
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_ADMIN));

        $sale = $this->provideSale($this->sabreSaleUri());

        self::assertSame([2120000001, 'O-Main'], [$sale->sellerCharacterId, $sale->sellerCharacterName]);
    }

    #[Group('issue-1212')]
    public function testSaleIsHeldByTheViewerWhenOneOfHisCharactersSoldIt(): void
    {
        $member = $this->userWithProfile(self::ACCEPTED_MEMBER);
        $seller = (new Character())->setEveCharacterId(2120000021)->setName('M-Main');
        $member->method('getCharacters')->willReturn(new ArrayCollection([$seller]));
        $this->sabreSale->setSeller($seller);
        $this->security->method('getUser')->willReturn($member);

        self::assertTrue($this->provideSale($this->sabreSaleUri())->heldByViewer);
    }

    #[Group('issue-1212')]
    public function testSaleIsNotHeldByTheViewerWhenAnotherParticipantSoldIt(): void
    {
        $this->sabreSale->setSeller((new Character())->setEveCharacterId(2120000001)->setName('O-Main'));
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_ADMIN));

        self::assertFalse($this->provideSale($this->sabreSaleUri())->heldByViewer);
    }

    #[Group('issue-1212')]
    public function testSaleWithoutSellerIsHeldByTheViewerWhoRecordedIt(): void
    {
        $member = $this->userWithProfile(self::ACCEPTED_MEMBER);
        (new \ReflectionProperty(IndustryProjectSale::class, 'recordedBy'))->setValue($this->sabreSale, $member);
        $this->security->method('getUser')->willReturn($member);

        self::assertTrue($this->provideSale($this->sabreSaleUri())->heldByViewer);
    }

    public static function saleAccessMatrix(): iterable
    {
        yield 'A1 owner' => [self::OWNER, self::GRANTED];
        yield 'A2 accepted admin' => [self::ACCEPTED_ADMIN, self::GRANTED];
        yield 'A3 accepted member who did not sell it' => [self::ACCEPTED_MEMBER, self::OWN_SALES_ONLY];
        yield 'A4 pending member' => [self::PENDING_MEMBER, self::NO_ACCESS];
        yield 'A5 owner corporation without membership' => [self::OWNER_CORPORATION_NON_MEMBER, self::NO_ACCESS];
        yield 'A6 owner corporation pending member' => [self::OWNER_CORPORATION_PENDING_MEMBER, self::NO_ACCESS];
        yield 'A7 owner corporation removed former admin' => [self::OWNER_CORPORATION_REMOVED_FORMER_ADMIN, self::NO_ACCESS];
        yield 'A8 removed member' => [self::REMOVED_MEMBER, self::NO_ACCESS];
        yield 'A9 stranger' => [self::STRANGER, self::NO_ACCESS];
        yield 'A10 user without corporation' => [self::USER_WITHOUT_CORPORATION, self::NO_ACCESS];
        yield 'A13 stranger to a completed project' => [self::STRANGER_TO_COMPLETED_PROJECT, self::NO_ACCESS];
    }

    #[DataProvider('saleAccessMatrix')]
    public function testOnlyTheManagersAndTheSellerReachASaleToCorrectOrDelete(string $profile, string $expectedOutcome): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile($profile));

        self::assertSame($expectedOutcome, $this->outcomeOf(fn () => $this->provideSale($this->sabreSaleUri())));
    }

    public function testSaleOfAnUnknownProjectIsNotFound(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::OWNER));

        self::assertSame(404, $this->httpStatusOf(fn () => $this->provideSale($this->sabreSaleUri(projectId: Uuid::v4()))));
    }

    public function testUnknownSaleIsNotFound(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::OWNER));

        self::assertSame(404, $this->httpStatusOf(fn () => $this->provideSale($this->sabreSaleUri(saleId: Uuid::v4()))));
    }

    public function testSaleReachedThroughAnotherProjectOfTheSameOwnerIsNotFound(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::OWNER));

        self::assertSame(404, $this->httpStatusOf(fn () => $this->provideSale($this->sabreSaleUri(projectId: $this->hecateBatch->getId()))));
    }

    #[DataProvider('uriVariablesThatAreNotUuids')]
    public function testSaleReachedThroughAnIdentifierThatIsNotAUuidIsNotFound(string $uriVariable): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::OWNER));

        self::assertSame(404, $this->httpStatusOf(fn () => $this->provideSale([...$this->sabreSaleUri(), $uriVariable => 'abc'])));
    }

    public static function uriVariablesThatAreNotUuids(): iterable
    {
        yield 'sale id' => ['id'];
        yield 'project id' => ['projectId'];
    }

    public function testSaleWithoutUserIsUnauthorized(): void
    {
        $this->security->method('getUser')->willReturn(null);

        self::assertSame(self::UNAUTHORIZED, $this->outcomeOf(fn () => $this->provideSale($this->sabreSaleUri())));
    }

    private function provideSale(array $uriVariables): ProjectSaleResource
    {
        return $this->provider->provide(new Patch(), $uriVariables);
    }
}
