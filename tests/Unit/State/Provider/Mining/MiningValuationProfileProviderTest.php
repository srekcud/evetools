<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Mining;

use ApiPlatform\Metadata\Get;
use App\Entity\MiningValuationProfile;
use App\Entity\User;
use App\Enum\MarketSide;
use App\Enum\MiningValuationForm;
use App\Enum\MiningValuationMarket;
use App\Repository\MiningValuationProfileRepository;
use App\Service\MiningValuation\MiningValuationProfileReader;
use App\State\Provider\Mining\MiningValuationProfileProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(MiningValuationProfileProvider::class)]
#[Group('issue-532')]
final class MiningValuationProfileProviderTest extends TestCase
{
    public function testAUserWithoutProfileGetsTheDefaultsFlaggedAsNotPersistedAndNothingIsWritten(): void
    {
        $repository = $this->createStub(MiningValuationProfileRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $resource = $this->providerFor(new User(), $repository)->provide(new Get());

        $this->assertSame(
            ['market' => 'jita', 'side' => 'buy', 'form' => 'compressed', 'structureId' => null, 'structureName' => null, 'exportTax' => 0.0, 'persisted' => false],
            get_object_vars($resource),
        );
    }

    public function testAStoredProfileIsReturnedFlaggedAsPersisted(): void
    {
        $profile = (new MiningValuationProfile())
            ->setMarket(MiningValuationMarket::Structure)
            ->setSide(MarketSide::Sell)
            ->setForm(MiningValuationForm::Raw)
            ->setStructureId(60003760)
            ->setStructureName('Refinery B-1')
            ->setExportTax(1250.0);
        $repository = $this->createStub(MiningValuationProfileRepository::class);
        $repository->method('findOneBy')->willReturn($profile);

        $resource = $this->providerFor(new User(), $repository)->provide(new Get());

        $this->assertSame(
            ['market' => 'structure', 'side' => 'sell', 'form' => 'raw', 'structureId' => 60003760, 'structureName' => 'Refinery B-1', 'exportTax' => 1250.0, 'persisted' => true],
            get_object_vars($resource),
        );
    }

    public function testAnAnonymousVisitorIsUnauthorized(): void
    {
        $this->expectException(UnauthorizedHttpException::class);

        $this->providerFor(null, $this->createStub(MiningValuationProfileRepository::class))->provide(new Get());
    }

    private function providerFor(?User $user, MiningValuationProfileRepository $repository): MiningValuationProfileProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return new MiningValuationProfileProvider($security, new MiningValuationProfileReader($repository));
    }
}
