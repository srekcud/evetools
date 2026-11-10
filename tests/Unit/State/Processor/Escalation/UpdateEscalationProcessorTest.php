<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Escalation;

use ApiPlatform\Metadata\Patch;
use App\Entity\Escalation;
use App\Entity\PveIncome;
use App\Entity\User;
use App\Enum\EscalationSaleStatus;
use App\Enum\EscalationVisibility;
use App\Enum\PveIncomeType;
use App\Repository\EscalationRepository;
use App\Repository\PveIncomeRepository;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Pve\EscalationIncomeSynchronizer;
use App\State\Processor\Escalation\UpdateEscalationProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(UpdateEscalationProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-533')]
final class UpdateEscalationProcessorTest extends TestCase
{
    private const string ESCALATION_ID = '0198c4e2-7b1a-7c3d-9e5f-3a6b8d2f4c10';
    private const string OWNER_ID = '0198c4e2-1111-7000-8000-000000000001';
    private const string OTHER_MEMBER_ID = '0198c4e2-2222-7000-8000-000000000002';

    private array $entityManagerCalls = [];
    private array $persistedIncomes = [];
    private array $removedIncomes = [];
    private int $publishedMercureUpdates = 0;

    public function testSellingAnEscalationCreatesItsIncomeForTheOwnerBeforeTheFlush(): void
    {
        $owner = $this->userWithId(self::OWNER_ID);
        $escalation = $this->escalationOwnedBy($owner, EscalationSaleStatus::EnVente, 100_000_000);

        $before = new \DateTimeImmutable();
        $this->process($owner, $escalation, '{"saleStatus":"vendu"}');
        $after = new \DateTimeImmutable();

        $this->assertSame(['persist', 'flush'], $this->entityManagerCalls);
        $this->assertCount(1, $this->persistedIncomes);
        $income = $this->persistedIncomes[0];
        $this->assertSame(PveIncomeType::Escalation, $income->getType());
        $this->assertSame(100_000_000.0, $income->getAmount());
        $this->assertSame('Blood Raider Hideaway - Aldranette', $income->getDescription());
        $this->assertSame($owner, $income->getUser());
        $this->assertSame($escalation, $income->getEscalation());
        $this->assertSame($escalation->getSoldAt(), $income->getDate());
        $this->assertGreaterThanOrEqual($before->getTimestamp(), $income->getDate()->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $income->getDate()->getTimestamp());
    }

    public function testPublishingToTheCorpStillHappensOnceWhenAnEscalationIsSold(): void
    {
        $owner = $this->userWithId(self::OWNER_ID);
        $escalation = $this->escalationOwnedBy($owner, EscalationSaleStatus::EnVente, 100_000_000)
            ->setVisibility(EscalationVisibility::Corp);

        $this->process($owner, $escalation, '{"saleStatus":"vendu"}');

        $this->assertSame(1, $this->publishedMercureUpdates);
        $this->assertCount(1, $this->persistedIncomes);
    }

    public function testPuttingASoldEscalationBackOnSaleRemovesItsIncome(): void
    {
        $owner = $this->userWithId(self::OWNER_ID);
        $escalation = $this->escalationOwnedBy($owner, EscalationSaleStatus::Vendu, 100_000_000)
            ->setSoldAt(new \DateTimeImmutable('2026-11-03 14:20:00'));
        $existingIncome = (new PveIncome())
            ->setUser($owner)
            ->setType(PveIncomeType::Escalation)
            ->setDescription('Blood Raider Hideaway - Aldranette')
            ->setAmount(100_000_000.0)
            ->setDate(new \DateTimeImmutable('2026-11-03 14:20:00'))
            ->setEscalation($escalation);

        $this->process($owner, $escalation, '{"saleStatus":"envente"}', $existingIncome);

        $this->assertSame([$existingIncome], $this->removedIncomes);
        $this->assertSame([], $this->persistedIncomes);
        $this->assertSame(['remove', 'flush'], $this->entityManagerCalls);
    }

    public function testAnotherMemberCannotSellAnEscalationAndNoIncomeIsWritten(): void
    {
        $owner = $this->userWithId(self::OWNER_ID);
        $otherMember = $this->userWithId(self::OTHER_MEMBER_ID);
        $escalation = $this->escalationOwnedBy($owner, EscalationSaleStatus::EnVente, 100_000_000)
            ->setVisibility(EscalationVisibility::Corp);

        try {
            $this->process($otherMember, $escalation, '{"saleStatus":"vendu"}');
            $this->fail('A member who does not own the escalation was allowed to update it');
        } catch (AccessDeniedHttpException $exception) {
            $this->assertSame('Access denied', $exception->getMessage());
        }

        $this->assertSame([], $this->entityManagerCalls);
        $this->assertSame([], $this->persistedIncomes);
        $this->assertSame(EscalationSaleStatus::EnVente, $escalation->getSaleStatus());
        $this->assertNull($escalation->getSoldAt());
    }

    private function process(User $caller, Escalation $escalation, string $payload, ?PveIncome $existingIncome = null): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($caller);

        $escalationRepository = $this->createStub(EscalationRepository::class);
        $escalationRepository->method('find')->willReturn($escalation);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->entityManagerCalls[] = 'persist';
            if ($entity instanceof PveIncome) {
                $this->persistedIncomes[] = $entity;
            }
        });
        $entityManager->method('remove')->willReturnCallback(function (object $entity): void {
            $this->entityManagerCalls[] = 'remove';
            if ($entity instanceof PveIncome) {
                $this->removedIncomes[] = $entity;
            }
        });
        $entityManager->method('flush')->willReturnCallback(function (): void {
            $this->entityManagerCalls[] = 'flush';
        });

        $incomeRepository = $this->createStub(PveIncomeRepository::class);
        $incomeRepository->method('findOneBy')->willReturn($existingIncome);

        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (): string {
            $this->publishedMercureUpdates++;

            return 'urn:uuid:published';
        });

        $requestStack = new RequestStack();
        $requestStack->push(new Request(content: $payload));

        $processor = new UpdateEscalationProcessor(
            $security,
            $escalationRepository,
            $entityManager,
            $requestStack,
            new MercurePublisherService($hub, new NullLogger()),
            new EscalationIncomeSynchronizer($incomeRepository, $entityManager),
        );

        $processor->process(null, new Patch(), ['id' => self::ESCALATION_ID]);
    }

    private function userWithId(string $id): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, Uuid::fromString($id));

        return $user;
    }

    private function escalationOwnedBy(User $owner, EscalationSaleStatus $saleStatus, int $price): Escalation
    {
        $escalation = (new Escalation())
            ->setUser($owner)
            ->setCharacterId(2_112_533_001)
            ->setCharacterName('Ilse Varn')
            ->setType('Blood Raider Hideaway')
            ->setSolarSystemId(30_002_813)
            ->setSolarSystemName('Aldranette')
            ->setSecurityStatus(-0.4)
            ->setPrice($price)
            ->setCorporationId(98_533_001)
            ->setExpiresAt(new \DateTimeImmutable('+3 days'));
        if ($saleStatus === EscalationSaleStatus::Vendu) {
            $escalation->setSaleStatus($saleStatus);
        }
        (new \ReflectionProperty(Escalation::class, 'id'))->setValue($escalation, Uuid::fromString(self::ESCALATION_ID));

        return $escalation;
    }
}
