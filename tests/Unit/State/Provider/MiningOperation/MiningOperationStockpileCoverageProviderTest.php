<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Get;
use App\ApiResource\MiningOperation\MiningOperationStockpileCoverageResource;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Service\MiningOperation\MiningOperationAccess;
use App\State\Provider\MiningOperation\MiningOperationStockpileCoverageProvider;
use App\Tests\Unit\Service\Mining\BuildsOreStockpileCoverage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(MiningOperationStockpileCoverageProvider::class)]
#[Group('issue-315')]
final class MiningOperationStockpileCoverageProviderTest extends TestCase
{
    use BuildsOreStockpileCoverage;

    private const string UNKNOWN_OPERATION_ID = '0b1d6c3e-8f42-4a7d-9e15-2c6b8a4f7d90';

    private Security&Stub $security;

    protected function setUp(): void
    {
        $this->setUpOreStockpileCoverage();
        $this->addMembership($this->operation, $this->cyra, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Cyra Lune']);
        $this->identifyEnrolments();
        $this->security = $this->createStub(Security::class);
        $this->minedDuringOperation([
            'Aria Solberg' => [self::VELDSPAR => 90_000],
            'Aria II' => [self::SCORDITE => 12_000],
            'Brann Holt' => [self::VELDSPAR => 6_000],
            'Brann II' => [self::VELDSPAR => 4_000, self::SCORDITE => 5_000],
        ]);
        $this->reprocessingYieldOf($this->aria, 0.9);
        $this->reprocessingYieldOf($this->brann, 0.8);
        $this->stockpileDeficitsOf($this->aria, [self::TRITANIUM => 1_000_000, self::PYERITE => 9_000]);
        $this->stockpileDeficitsOf($this->brann, [self::TRITANIUM => 100_000, self::PYERITE => 3_000]);
    }

    public function testParticipantReadsOnlyTheCoverageOfTheirOwnPartWithTheirOwnYieldAndStockpile(): void
    {
        $this->actAs($this->brann);

        $this->assertCoverage(
            $this->coverage('known', null, true, 0.8, [
                $this->mineral(self::TRITANIUM, 38_000.0, 100_000, true, 38.0, false),
                $this->mineral(self::PYERITE, 3_600.0, 3_000, true, 100.0, true),
            ]),
            get_object_vars($this->stockpileCoverage($this->operation->getId()->toRfc4122())),
        );
    }

    public function testOwnerReadsTheCoverageOfTheirOwnPart(): void
    {
        $this->actAs($this->aria);

        $this->assertCoverage(
            $this->coverage('known', null, true, 0.9, [
                $this->mineral(self::TRITANIUM, 340_200.0, 1_000_000, true, 34.02, false),
                $this->mineral(self::PYERITE, 9_720.0, 9_000, true, 100.0, true),
            ]),
            get_object_vars($this->stockpileCoverage($this->operation->getId()->toRfc4122())),
        );
    }

    public function testPendingMemberIsRefusedTheCoverage(): void
    {
        $this->actAs($this->cyra);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('You do not have access to this operation');

        $this->stockpileCoverage($this->operation->getId()->toRfc4122());
    }

    public function testPilotOutsideTheOperationIsRefusedTheCoverage(): void
    {
        $this->actAs($this->elia);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('You do not have access to this operation');

        $this->stockpileCoverage($this->operation->getId()->toRfc4122());
    }

    public function testUnknownOperationIsNotFound(): void
    {
        $this->actAs($this->brann);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Mining operation not found');

        $this->stockpileCoverage(self::UNKNOWN_OPERATION_ID);
    }

    public function testAnonymousVisitorIsUnauthorized(): void
    {
        $this->expectException(UnauthorizedHttpException::class);

        $this->stockpileCoverage($this->operation->getId()->toRfc4122());
    }

    private function actAs(User $user): void
    {
        $this->security = $this->createStub(Security::class);
        $this->security->method('getUser')->willReturn($user);
    }

    private function stockpileCoverage(string $operationId): MiningOperationStockpileCoverageResource
    {
        $provider = new MiningOperationStockpileCoverageProvider(
            $this->security,
            $this->operationRepositoryReadingOperations(),
            new MiningOperationAccess(),
            $this->coverageService(),
        );

        return $provider->provide(new Get(), ['operationId' => $operationId]);
    }
}
