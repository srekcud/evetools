<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Delete;
use App\Enum\GroupProjectStatus;
use App\Service\Mercure\MercurePublisherService;
use App\State\Processor\GroupIndustry\DeleteSaleProcessor;
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

#[CoversClass(DeleteSaleProcessor::class)]
#[Group('issue-164')]
#[AllowMockObjectsWithoutExpectations]
final class DeleteSaleProcessorTest extends TestCase
{
    use GroupProjectAccessProfiles;
    use SabreBatchSales;

    private Security&Stub $security;
    private GroupSaleItemProvider $provider;
    private DeleteSaleProcessor $processor;
    private int $flushes = 0;

    private array $removedEntities = [];

    private array $publishedUpdates = [];

    protected function setUp(): void
    {
        $this->createSabreBatch();
        $this->sabreBatch->setStatus(GroupProjectStatus::Selling);
        $this->recordSabreSale();
        $this->createHecateBatchOfTheSabreBatchOwner();
        $this->security = $this->createStub(Security::class);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('remove')->willReturnCallback(function (object $entity): void {
            $this->removedEntities[] = $entity;
        });
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
        $this->processor = new DeleteSaleProcessor(
            $this->security,
            $projectRepository,
            $saleRepository,
            $accessChecker,
            $entityManager,
            new MercurePublisherService($hub, new NullLogger()),
        );
    }

    public function testAdminDeletesTheSale(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_ADMIN));

        $this->deleteSabreSale();

        self::assertSame([true, 1], [$this->sabreSaleIsDeletedOnFlush(), $this->flushes]);
    }

    public function testDeletionPublishesTheSaleDeletedEvent(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_ADMIN));
        $sabreSaleId = $this->sabreSale->getId()->toRfc4122();

        $this->deleteSabreSale();

        self::assertSame([['action' => 'sale_deleted', 'data' => ['saleId' => $sabreSaleId]]], $this->actionsAndDataOf($this->publishedUpdates));
    }

    public static function deletionAccessMatrix(): iterable
    {
        yield 'A1 owner' => [self::OWNER, self::GRANTED];
        yield 'A2 accepted admin' => [self::ACCEPTED_ADMIN, self::GRANTED];
        yield 'D2 A3 accepted member' => [self::ACCEPTED_MEMBER, self::ADMIN_OR_OWNER_ONLY];
        yield 'A4 pending member' => [self::PENDING_MEMBER, self::ADMIN_OR_OWNER_ONLY];
        yield 'A6 owner corporation pending member' => [self::OWNER_CORPORATION_PENDING_MEMBER, self::ADMIN_OR_OWNER_ONLY];
        yield 'A7 owner corporation removed former admin' => [self::OWNER_CORPORATION_REMOVED_FORMER_ADMIN, self::ADMIN_OR_OWNER_ONLY];
        yield 'A9 stranger' => [self::STRANGER, self::ADMIN_OR_OWNER_ONLY];
        yield 'A10 user without corporation' => [self::USER_WITHOUT_CORPORATION, self::ADMIN_OR_OWNER_ONLY];
    }

    #[DataProvider('deletionAccessMatrix')]
    public function testOnlyTheOwnerAndAcceptedAdminsDeleteASale(string $profile, string $expectedOutcome): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile($profile));

        $outcome = $this->outcomeOf(fn () => $this->deleteSabreSale());

        $granted = $expectedOutcome === self::GRANTED;
        self::assertSame(
            [$expectedOutcome, $granted, $granted ? 1 : 0, $granted ? 1 : 0],
            [$outcome, $this->sabreSaleIsDeletedOnFlush(), $this->flushes, \count($this->publishedUpdates)],
        );
    }

    public function testDeletionOnACompletedProjectIsRefusedAndKeepsTheSale(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_ADMIN_OF_COMPLETED_PROJECT));

        $refusal = null;
        try {
            $this->deleteSabreSale();
        } catch (BadRequestHttpException $exception) {
            $refusal = $exception;
        }

        self::assertInstanceOf(BadRequestHttpException::class, $refusal);
        self::assertSame([false, 0, []], [$this->sabreSaleIsDeletedOnFlush(), $this->flushes, $this->publishedUpdates]);
    }

    public function testDeletionThroughAnotherProjectOfTheOwnerIsNotFoundAndKeepsTheSale(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::OWNER));

        $status = $this->httpStatusOf(fn () => $this->deleteSabreSale($this->sabreSaleUri(projectId: $this->hecateBatch->getId())));

        self::assertSame([404, false, 0], [$status, $this->sabreSaleIsDeletedOnFlush(), $this->flushes]);
    }

    private function deleteSabreSale(?array $uriVariables = null): void
    {
        $operation = new Delete();
        $uriVariables ??= $this->sabreSaleUri();
        $sale = $this->provider->provide($operation, $uriVariables);

        $this->processor->process($sale, $operation, $uriVariables, ['previous_data' => $sale]);
    }

    private function sabreSaleIsDeletedOnFlush(): bool
    {
        return \in_array($this->sabreSale, $this->removedEntities, true) || !$this->sabreBatch->getSales()->contains($this->sabreSale);
    }
}
