<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Patch;
use App\ApiResource\GroupIndustry\GroupIndustrySaleResource;
use App\ApiResource\Input\GroupIndustry\UpdateSaleInput;
use App\Entity\GroupIndustrySale;
use App\Enum\GroupProjectStatus;
use App\Service\Mercure\MercurePublisherService;
use App\State\Processor\GroupIndustry\UpdateSaleProcessor;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use App\State\Provider\GroupIndustry\GroupSaleItemProvider;
use App\Tests\Unit\State\Provider\GroupIndustry\GroupProjectAccessProfiles;
use App\Tests\Unit\State\Provider\GroupIndustry\SabreBatchSales;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[CoversClass(UpdateSaleProcessor::class)]
#[Group('issue-164')]
#[AllowMockObjectsWithoutExpectations]
final class UpdateSaleProcessorTest extends TestCase
{
    use GroupProjectAccessProfiles;
    use SabreBatchSales;

    private Security&Stub $security;
    private GroupSaleItemProvider $provider;
    private UpdateSaleProcessor $processor;
    private int $flushes = 0;

    private array $publishedUpdates = [];

    protected function setUp(): void
    {
        $this->createSabreBatch();
        $this->sabreBatch->setStatus(GroupProjectStatus::Selling);
        $this->recordSabreSale();
        $this->createHecateBatchOfTheSabreBatchOwner();
        $this->security = $this->createStub(Security::class);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'published';
        });

        $projectRepository = $this->projectRepositoryOfTheSabreAndHecateBatches();
        $saleRepository = $this->saleRepositoryOfTheSabreSale();
        $accessChecker = new GroupProjectAccessChecker($this->sabreBatchMemberRepository());

        $this->provider = new GroupSaleItemProvider($this->security, $projectRepository, $saleRepository, $accessChecker);
        $this->processor = new UpdateSaleProcessor(
            $this->security,
            $projectRepository,
            $saleRepository,
            $accessChecker,
            $entityManager,
            new MercurePublisherService($hub, new NullLogger()),
        );
    }

    public function testAdminCorrectsTheUnitPriceAndTheTotalFollows(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_ADMIN));

        $sale = $this->correctSabreSale(['unitPrice' => 950000.0]);

        self::assertSame(
            [950000.0, 2, 1900000.0, self::SABRE_SALE_RECORDER_NAME, self::SABRE_SALE_CREATED_AT],
            [$sale->unitPrice, $sale->quantity, $sale->totalPrice, $sale->recordedByCharacterName, $sale->createdAt],
        );
        self::assertSame([950000.0, 2, 1900000.0], $this->persistedSabreSaleAmounts());
        self::assertSame(1, $this->flushes);
    }

    public function testCorrectionPublishesTheSaleUpdatedEventWithTheNewTotal(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_ADMIN));

        $this->correctSabreSale(['unitPrice' => 950000.0]);

        self::assertSame(
            [[
                'action' => 'sale_updated',
                'data' => ['saleId' => $this->sabreSale->getId()->toRfc4122(), 'typeName' => 'Sabre', 'quantity' => 2, 'totalPrice' => 1900000.0],
            ]],
            $this->actionsAndDataOf($this->publishedUpdates),
        );
    }

    public function testOwnerCorrectsTheQuantityAndTheSaleDate(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::OWNER));

        $sale = $this->correctSabreSale(['quantity' => 3, 'soldAt' => '2026-10-24T09:00:00+00:00']);

        self::assertSame(
            [3, 900000.0, 2700000.0, '2026-10-24T09:00:00+00:00'],
            [$sale->quantity, $sale->unitPrice, $sale->totalPrice, $sale->soldAt],
        );
        self::assertSame('2026-10-24T09:00:00+00:00', $this->sabreSale->getSoldAt()->format(\DateTimeInterface::ATOM));
    }

    public function testCorrectingQuantityAndUnitPriceTogetherMultipliesTheNewValues(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::OWNER));

        $sale = $this->correctSabreSale(['quantity' => 4, 'unitPrice' => 875000.0]);

        self::assertSame([4, 875000.0, 3500000.0], [$sale->quantity, $sale->unitPrice, $sale->totalPrice]);
    }

    public function testCorrectionOfTheItemNameAndTypeKeepsTheAmounts(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::OWNER));

        $sale = $this->correctSabreSale(['typeId' => 22456, 'typeName' => 'Sabre Navy Issue']);

        self::assertSame(
            [22456, 'Sabre Navy Issue', 2, 900000.0, 1800000.0],
            [$sale->typeId, $sale->typeName, $sale->quantity, $sale->unitPrice, $sale->totalPrice],
        );
    }

    public function testCorrectionWithANullVenueKeepsTheVenue(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_ADMIN));

        $sale = $this->correctSabreSale(['venue' => null]);

        self::assertSame([self::SABRE_SALE_VENUE, self::SABRE_SALE_VENUE], [$sale->venue, $this->sabreSale->getVenue()]);
    }

    public function testCorrectionWithoutAnyFieldKeepsTheWholeSale(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_ADMIN));

        $sale = $this->correctSabreSale([]);

        self::assertSame(
            [0, 'Sabre', 2, 900000.0, 1800000.0, self::SABRE_SALE_VENUE, self::SABRE_SALE_SOLD_AT],
            [$sale->typeId, $sale->typeName, $sale->quantity, $sale->unitPrice, $sale->totalPrice, $sale->venue, $sale->soldAt],
        );
    }

    public function testSaleDateWrittenZIsStoredAsUtc(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_ADMIN));

        $sale = $this->correctSabreSale(['soldAt' => '2026-10-24T09:00:00Z']);

        self::assertSame('2026-10-24T09:00:00+00:00', $sale->soldAt);
    }

    public function testCorrectionOfASaleWhoseRecorderDeletedTheAccountKeepsNoRecorderName(): void
    {
        (new \ReflectionProperty(GroupIndustrySale::class, 'recordedBy'))->setValue($this->sabreSale, null);
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_ADMIN));

        $sale = $this->correctSabreSale(['unitPrice' => 950000.0]);

        self::assertSame([1900000.0, null], [$sale->totalPrice, $sale->recordedByCharacterName]);
    }

    public static function correctionAccessMatrix(): iterable
    {
        yield 'A1 owner' => [self::OWNER, self::GRANTED];
        yield 'A2 accepted admin' => [self::ACCEPTED_ADMIN, self::GRANTED];
        yield 'C6 A3 accepted member' => [self::ACCEPTED_MEMBER, self::ADMIN_OR_OWNER_ONLY];
        yield 'A4 pending member' => [self::PENDING_MEMBER, self::ADMIN_OR_OWNER_ONLY];
        yield 'A5 owner corporation without membership' => [self::OWNER_CORPORATION_NON_MEMBER, self::ADMIN_OR_OWNER_ONLY];
        yield 'A7 owner corporation removed former admin' => [self::OWNER_CORPORATION_REMOVED_FORMER_ADMIN, self::ADMIN_OR_OWNER_ONLY];
        yield 'A8 removed member' => [self::REMOVED_MEMBER, self::ADMIN_OR_OWNER_ONLY];
        yield 'A9 stranger' => [self::STRANGER, self::ADMIN_OR_OWNER_ONLY];
    }

    #[DataProvider('correctionAccessMatrix')]
    public function testOnlyTheOwnerAndAcceptedAdminsCorrectASale(string $profile, string $expectedOutcome): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile($profile));

        $outcome = $this->outcomeOf(fn () => $this->correctSabreSale(['unitPrice' => 950000.0]));

        $granted = $expectedOutcome === self::GRANTED;
        self::assertSame(
            [$expectedOutcome, $granted ? 950000.0 : self::SABRE_SALE_UNIT_PRICE, $granted ? 1 : 0, $granted ? 1 : 0],
            [$outcome, $this->sabreSale->getUnitPrice(), $this->flushes, \count($this->publishedUpdates)],
        );
    }

    public function testCorrectionThroughAnotherProjectOfTheOwnerIsNotFoundAndKeepsTheSale(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::OWNER));

        $status = $this->httpStatusOf(fn () => $this->correctSabreSale(['unitPrice' => 950000.0], $this->sabreSaleUri(projectId: $this->hecateBatch->getId())));

        self::assertSame([404, [900000.0, 2, 1800000.0], 0], [$status, $this->persistedSabreSaleAmounts(), $this->flushes]);
    }

    public function testCorrectionOfTheVenueMovesTheSale(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_ADMIN));

        $sale = $this->correctSabreSale(['venue' => 'Dodixie IX - Moon 20']);

        self::assertSame(['Dodixie IX - Moon 20', 'Dodixie IX - Moon 20', 1800000.0], [$sale->venue, $this->sabreSale->getVenue(), $sale->totalPrice]);
    }

    #[DataProvider('profilesRefusedTheCorrection')]
    public function testProcessorRefusesTheCorrectionOfANonAdminWithoutRelyingOnTheProvider(string $profile): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile($profile));

        $outcome = $this->outcomeOf(fn () => $this->correctThroughTheProcessorOnly($this->sabreSaleUri()));

        self::assertSame([self::ADMIN_OR_OWNER_ONLY, [900000.0, 2, 1800000.0], 0, []], [$outcome, $this->persistedSabreSaleAmounts(), $this->flushes, $this->publishedUpdates]);
    }

    public static function profilesRefusedTheCorrection(): iterable
    {
        yield 'accepted member' => [self::ACCEPTED_MEMBER];
        yield 'stranger' => [self::STRANGER];
    }

    public function testProcessorRefusesACorrectionWithoutUser(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $outcome = $this->outcomeOf(fn () => $this->correctThroughTheProcessorOnly($this->sabreSaleUri()));

        self::assertSame([self::UNAUTHORIZED, [900000.0, 2, 1800000.0], 0], [$outcome, $this->persistedSabreSaleAmounts(), $this->flushes]);
    }

    #[DataProvider('missingUriVariables')]
    public function testCorrectionWithoutProjectOrSaleIdentifierIsNotFound(string $missingUriVariable): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::OWNER));
        $uriVariables = $this->sabreSaleUri();
        unset($uriVariables[$missingUriVariable]);

        $status = $this->httpStatusOf(fn () => $this->correctThroughTheProcessorOnly($uriVariables));

        self::assertSame([404, [900000.0, 2, 1800000.0], 0], [$status, $this->persistedSabreSaleAmounts(), $this->flushes]);
    }

    public static function missingUriVariables(): iterable
    {
        yield 'project id' => ['projectId'];
        yield 'sale id' => ['id'];
    }

    #[DataProvider('uriVariablesThatAreNotUuids')]
    public function testCorrectionThroughAnIdentifierThatIsNotAUuidIsNotFoundAndKeepsTheSale(string $uriVariable): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::OWNER));
        $input = new UpdateSaleInput();
        $input->unitPrice = 950000.0;

        $status = $this->httpStatusOf(fn () => $this->processor->process($input, new Patch(), [...$this->sabreSaleUri(), $uriVariable => 'abc']));

        self::assertSame([404, [900000.0, 2, 1800000.0], 0], [$status, $this->persistedSabreSaleAmounts(), $this->flushes]);
    }

    public static function uriVariablesThatAreNotUuids(): iterable
    {
        yield 'sale id' => ['id'];
        yield 'project id' => ['projectId'];
    }

    public function testCorrectionOnACompletedProjectIsRefusedAndKeepsTheSale(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_ADMIN_OF_COMPLETED_PROJECT));

        $refusal = null;
        try {
            $this->correctSabreSale(['quantity' => 3, 'unitPrice' => 950000.0]);
        } catch (BadRequestHttpException $exception) {
            $refusal = $exception;
        }

        self::assertInstanceOf(BadRequestHttpException::class, $refusal);
        self::assertSame([[900000.0, 2, 1800000.0], 0, []], [$this->persistedSabreSaleAmounts(), $this->flushes, $this->publishedUpdates]);
    }

    private function correctSabreSale(array $changes, ?array $uriVariables = null): GroupIndustrySaleResource
    {
        $input = new UpdateSaleInput();
        foreach ($changes as $field => $value) {
            $input->{$field} = $value;
        }

        $operation = new Patch();
        $uriVariables ??= $this->sabreSaleUri();
        $previousSale = $this->provider->provide($operation, $uriVariables);

        return $this->processor->process($input, $operation, $uriVariables, ['previous_data' => $previousSale]);
    }

    private function correctThroughTheProcessorOnly(array $uriVariables): GroupIndustrySaleResource
    {
        $input = new UpdateSaleInput();
        $input->unitPrice = 950000.0;

        return $this->processor->process($input, new Patch(), $uriVariables);
    }

    private function persistedSabreSaleAmounts(): array
    {
        return [$this->sabreSale->getUnitPrice(), $this->sabreSale->getQuantity(), $this->sabreSale->getTotalPrice()];
    }
}
