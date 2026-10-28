<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\GroupIndustry\GroupIndustrySaleResource;
use App\Entity\Character;
use App\Entity\GroupIndustrySale;
use App\Entity\User;
use App\Repository\GroupIndustrySaleRepository;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use App\State\Provider\GroupIndustry\GroupSaleCollectionProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

#[CoversClass(GroupSaleCollectionProvider::class)]
class GroupSaleCollectionProviderTest extends TestCase
{
    use GroupProjectAccessProfiles;

    private const int SABRE_TYPE_ID = 22456;
    private const int CAPACITOR_BOOSTER_TYPE_ID = 3554;
    private const int NANITE_PASTE_TYPE_ID = 28668;

    private Security&Stub $security;

    private array $listedSales = [];

    private GroupSaleCollectionProvider $provider;

    protected function setUp(): void
    {
        $this->createSabreBatch();
        $this->security = $this->createStub(Security::class);

        $saleRepository = $this->createStub(GroupIndustrySaleRepository::class);
        $saleRepository->method('findByProjectForListing')->willReturnCallback(fn (): array => $this->listedSales);

        $this->provider = new GroupSaleCollectionProvider(
            $this->security,
            $this->sabreBatchProjectRepository(),
            new GroupProjectAccessChecker($this->sabreBatchMemberRepository()),
            $saleRepository,
        );
    }

    #[DataProvider('readAccessMatrix')]
    public function testSalesAreReadByTheOwnerAcceptedMembersAndTheOwnerCorporation(string $profile, string $expectedOutcome): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile($profile));

        self::assertSame($expectedOutcome, $this->outcomeOf(fn () => $this->salesOf($this->sabreBatch->getId())));
    }

    public function testSalesOfAnUnknownProjectAreNotFound(): void
    {
        $this->security->method('getUser')->willReturn($this->userOfCorporation(self::FOREIGN_CORPORATION_ID));

        self::assertSame(self::PROJECT_NOT_FOUND, $this->outcomeOf(fn () => $this->salesOf(Uuid::v4())));
    }

    public function testSalesWithoutUserAreUnauthorized(): void
    {
        $this->security->method('getUser')->willReturn(null);

        self::assertSame(self::UNAUTHORIZED, $this->outcomeOf(fn () => $this->salesOf($this->sabreBatch->getId())));
    }

    public function testSalesAreListedInTheListingOrderWithTheNameOfTheirRecorder(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_MEMBER));
        $this->listedSales = [
            $this->sale(self::SABRE_TYPE_ID, 'Sabre', 3, 61_250_000.0, '2026-10-24T21:05:00+00:00', $this->pilot('Vexa Mordu')),
            $this->sale(self::CAPACITOR_BOOSTER_TYPE_ID, 'Medium Capacitor Booster II', 14, 1_837_500.0, '2026-10-22T08:40:00+00:00', $this->userOfCorporation(self::FOREIGN_CORPORATION_ID)),
            $this->sale(self::NANITE_PASTE_TYPE_ID, 'Nanite Repair Paste', 260, 23_450.0, '2026-10-19T16:12:00+00:00', null),
        ];

        $resources = $this->salesOf($this->sabreBatch->getId());

        self::assertSame(
            [
                [self::SABRE_TYPE_ID, 'Sabre', 3, 61_250_000.0, 183_750_000.0, 'Jita IV - Moon 4', '2026-10-24T21:05:00+00:00', 'Vexa Mordu'],
                [self::CAPACITOR_BOOSTER_TYPE_ID, 'Medium Capacitor Booster II', 14, 1_837_500.0, 25_725_000.0, 'Jita IV - Moon 4', '2026-10-22T08:40:00+00:00', 'Unknown'],
                [self::NANITE_PASTE_TYPE_ID, 'Nanite Repair Paste', 260, 23_450.0, 6_097_000.0, 'Jita IV - Moon 4', '2026-10-19T16:12:00+00:00', null],
            ],
            array_map(
                static fn (GroupIndustrySaleResource $resource): array => [
                    $resource->typeId,
                    $resource->typeName,
                    $resource->quantity,
                    $resource->unitPrice,
                    $resource->totalPrice,
                    $resource->venue,
                    $resource->soldAt,
                    $resource->recordedByCharacterName,
                ],
                $resources,
            ),
        );
    }

    private function salesOf(Uuid $projectId): array
    {
        return $this->provider->provide(new GetCollection(), ['projectId' => $projectId->toRfc4122()]);
    }

    private function sale(int $typeId, string $typeName, int $quantity, float $unitPrice, string $soldAt, ?User $recordedBy): GroupIndustrySale
    {
        $sale = new GroupIndustrySale();
        $sale->setProject($this->sabreBatch);
        $sale->setTypeId($typeId);
        $sale->setTypeName($typeName);
        $sale->setQuantity($quantity);
        $sale->setUnitPrice($unitPrice);
        $sale->setTotalPrice($quantity * $unitPrice);
        $sale->setVenue('Jita IV - Moon 4');
        $sale->setSoldAt(new \DateTimeImmutable($soldAt));
        if ($recordedBy !== null) {
            $sale->setRecordedBy($recordedBy);
        }
        (new \ReflectionProperty(GroupIndustrySale::class, 'id'))->setValue($sale, Uuid::v4());

        return $sale;
    }

    private function pilot(string $name): User
    {
        $character = $this->createStub(Character::class);
        $character->method('getName')->willReturn($name);
        $user = $this->createStub(User::class);
        $user->method('getMainCharacter')->willReturn($character);

        return $user;
    }
}
