<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\MiningOperation;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\MiningOperation\MiningOperationResource;
use App\Entity\User;
use App\Enum\MiningOperationStatus;
use App\Repository\MiningOperationRepository;
use App\State\Provider\MiningOperation\AvailableMiningOperationsProvider;
use App\State\Provider\MiningOperation\MiningOperationResourceMapper;
use App\Tests\Unit\Service\MiningOperation\BuildsSpodumainOperation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

#[CoversClass(AvailableMiningOperationsProvider::class)]
#[Group('issue-262')]
final class AvailableMiningOperationsProviderTest extends TestCase
{
    use BuildsSpodumainOperation;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
    }

    public function testBrannSeesTheAvailableOperationsOfHisCorporationInTheOrderFoundEachWithItsJoinCode(): void
    {
        $o5 = $this->operationOwnedBy($this->aria, 'O5 Kernite — mercredi', self::C1, '5e5e5e5e5e')->setStatus(MiningOperationStatus::InProgress);
        $o1 = $this->operationOwnedBy($this->aria, 'O1 Spodumain — jeudi', self::C1, '1a1a1a1a1a');
        $repository = $this->createMock(MiningOperationRepository::class);
        $repository->expects($this->once())->method('findAvailableForCorporation')->with(self::C1, $this->brann)->willReturn([$o5, $o1]);

        $available = $this->provider($repository, $this->brann)->provide(new GetCollection());

        self::assertSame(
            [['O5 Kernite — mercredi', 'in_progress', '5e5e5e5e5e', null], ['O1 Spodumain — jeudi', 'planned', '1a1a1a1a1a', null]],
            array_map(static fn (MiningOperationResource $operation): array => [
                $operation->label,
                $operation->status,
                $operation->joinCode,
                $operation->myStatus,
            ], $available),
        );
    }

    public function testPilotWithoutKnownCorporationHasNoAvailableOperation(): void
    {
        $repository = $this->createMock(MiningOperationRepository::class);
        $repository->expects($this->never())->method('findAvailableForCorporation');

        self::assertSame([], $this->provider($repository, $this->dorn)->provide(new GetCollection()));
    }

    private function provider(MiningOperationRepository $repository, User $user): AvailableMiningOperationsProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return new AvailableMiningOperationsProvider($security, $repository, new MiningOperationResourceMapper());
    }
}
