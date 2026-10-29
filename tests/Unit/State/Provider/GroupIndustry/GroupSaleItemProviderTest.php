<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\Patch;
use App\ApiResource\GroupIndustry\GroupIndustrySaleResource;
use App\Entity\Character;
use App\Enum\GroupProjectStatus;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use App\State\Provider\GroupIndustry\GroupSaleItemProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

#[CoversClass(GroupSaleItemProvider::class)]
#[Group('issue-164')]
final class GroupSaleItemProviderTest extends TestCase
{
    use GroupProjectAccessProfiles;
    use SabreBatchSales;

    private Security&Stub $security;
    private GroupSaleItemProvider $provider;

    protected function setUp(): void
    {
        $this->createSabreBatch();
        $this->sabreBatch->setStatus(GroupProjectStatus::Selling);
        $this->recordSabreSale();
        $this->createHecateBatchOfTheSabreBatchOwner();
        $this->security = $this->createStub(Security::class);

        $this->provider = new GroupSaleItemProvider(
            $this->security,
            $this->projectRepositoryOfTheSabreAndHecateBatches(),
            $this->saleRepositoryOfTheSabreSale(),
            new GroupProjectAccessChecker($this->sabreBatchMemberRepository()),
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
        $this->sabreSale->setSeller((new Character())->setEveCharacterId(2120000002)->setName('O-Alt'));
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_ADMIN));

        $sale = $this->provideSale($this->sabreSaleUri());

        self::assertSame([2120000002, 'O-Alt', self::SABRE_SALE_RECORDER_NAME], [$sale->sellerCharacterId, $sale->sellerCharacterName, $sale->recordedByCharacterName]);
    }

    public static function saleAccessMatrix(): iterable
    {
        yield 'A1 owner' => [self::OWNER, self::GRANTED];
        yield 'A2 accepted admin' => [self::ACCEPTED_ADMIN, self::GRANTED];
        yield 'A3 accepted member' => [self::ACCEPTED_MEMBER, self::ADMIN_OR_OWNER_ONLY];
        yield 'A4 pending member' => [self::PENDING_MEMBER, self::ADMIN_OR_OWNER_ONLY];
        yield 'A5 owner corporation without membership' => [self::OWNER_CORPORATION_NON_MEMBER, self::ADMIN_OR_OWNER_ONLY];
        yield 'A6 owner corporation pending member' => [self::OWNER_CORPORATION_PENDING_MEMBER, self::ADMIN_OR_OWNER_ONLY];
        yield 'A7 owner corporation removed former admin' => [self::OWNER_CORPORATION_REMOVED_FORMER_ADMIN, self::ADMIN_OR_OWNER_ONLY];
        yield 'A8 removed member' => [self::REMOVED_MEMBER, self::ADMIN_OR_OWNER_ONLY];
        yield 'A9 stranger' => [self::STRANGER, self::ADMIN_OR_OWNER_ONLY];
        yield 'A10 user without corporation' => [self::USER_WITHOUT_CORPORATION, self::ADMIN_OR_OWNER_ONLY];
        yield 'A13 stranger to a completed project' => [self::STRANGER_TO_COMPLETED_PROJECT, self::ADMIN_OR_OWNER_ONLY];
    }

    #[DataProvider('saleAccessMatrix')]
    public function testOnlyTheOwnerAndAcceptedAdminsReachASaleToCorrectOrDelete(string $profile, string $expectedOutcome): void
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

    private function provideSale(array $uriVariables): GroupIndustrySaleResource
    {
        return $this->provider->provide(new Patch(), $uriVariables);
    }
}
