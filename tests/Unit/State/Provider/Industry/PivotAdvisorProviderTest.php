<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\Get;
use App\Constant\EveConstants;
use App\Entity\IndustryUserSettings;
use App\Entity\User;
use App\Repository\IndustryUserSettingsRepository;
use App\Service\Industry\PivotAdvisorService;
use App\Service\Industry\SalesFees\SalesFees;
use App\Service\Industry\SalesFees\SellerSalesFeesResolver;
use App\State\Provider\Industry\PivotAdvisorProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

#[Group('issue-128')]
#[CoversClass(PivotAdvisorProvider::class)]
final class PivotAdvisorProviderTest extends TestCase
{
    private const int SABRE_TYPE_ID = 22456;

    public function testServiceReceivesTheFavoriteManufacturingAndReactionSystems(): void
    {
        $user = new User();
        $settings = $this->settings(30002510, 30000144);

        $this->assertSame([30002510, 30000144], $this->solarSystemsReceivedByTheService($user, new Request(), $settings));
    }

    public function testServiceReceivesTheRequestedSystemForManufacturingAndReaction(): void
    {
        $user = new User();
        $settings = $this->settings(30002510, 30000144);

        $this->assertSame([30002187, 30002187], $this->solarSystemsReceivedByTheService($user, new Request(['solarSystemId' => '30002187']), $settings));
    }

    public function testServiceReceivesTheManufacturingSystemForReactionWithoutFavoriteReactionSystem(): void
    {
        $user = new User();
        $settings = $this->settings(30002510, null);

        $this->assertSame([30002510, 30002510], $this->solarSystemsReceivedByTheService($user, new Request(), $settings));
    }

    public function testServiceReceivesJitaForManufacturingAndReactionWithoutSettings(): void
    {
        $this->assertSame(
            [EveConstants::JITA_SOLAR_SYSTEM_ID, EveConstants::JITA_SOLAR_SYSTEM_ID],
            $this->solarSystemsReceivedByTheService(new User(), new Request(), null),
        );
    }

    #[Group('issue-347')]
    public function testServiceReceivesTheSalesFeesOfTheSellerResolvedForTheAuthenticatedUserAndNotTheIndustrySettings(): void
    {
        $user = new User();

        $arguments = $this->argumentsReceivedByTheService($user, new Request(), new IndustryUserSettings(), new SalesFees(0.0215, 0.0337));

        $this->assertEquals(new SalesFees(0.0215, 0.0337), $arguments[3]);
    }

    public function testServiceReceivesOneRunWhenTheRunsParameterIsAbsent(): void
    {
        $arguments = $this->argumentsReceivedByTheService(new User(), new Request(), null, new SalesFees(0.036, 0.036));

        $this->assertSame(1, $arguments[1]);
    }

    public function testServiceReceivesTheRequestedRuns(): void
    {
        $arguments = $this->argumentsReceivedByTheService(new User(), new Request(['runs' => '12']), null, new SalesFees(0.036, 0.036));

        $this->assertSame(12, $arguments[1]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidPositiveIntegerParameters(): iterable
    {
        yield 'runs is not a number' => ['runs', 'abc'];
        yield 'runs is zero' => ['runs', '0'];
        yield 'runs is negative' => ['runs', '-2'];
        yield 'runs is a decimal' => ['runs', '1.5'];
        yield 'runs is empty' => ['runs', ''];
        yield 'solarSystemId is not a number' => ['solarSystemId', 'abc'];
        yield 'solarSystemId is zero' => ['solarSystemId', '0'];
        yield 'solarSystemId is negative' => ['solarSystemId', '-30002187'];
    }

    #[Group('issue-331')]
    #[DataProvider('invalidPositiveIntegerParameters')]
    public function testAnInvalidQueryParameterIsRejectedInsteadOfBeingReadAsADefault(string $parameter, string $value): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage(sprintf('%s must be a positive integer.', $parameter));

        $this->argumentsReceivedByTheService(new User(), new Request([$parameter => $value]), null, new SalesFees(0.036, 0.036));
    }

    private function settings(?int $favoriteManufacturingSystemId, ?int $favoriteReactionSystemId): IndustryUserSettings
    {
        return (new IndustryUserSettings())
            ->setFavoriteManufacturingSystemId($favoriteManufacturingSystemId)
            ->setFavoriteReactionSystemId($favoriteReactionSystemId);
    }

    /**
     * @return array{int, int|null}
     */
    private function solarSystemsReceivedByTheService(User $user, Request $request, ?IndustryUserSettings $settings): array
    {
        $receivedArguments = $this->argumentsReceivedByTheService($user, $request, $settings, new SalesFees(0.036, 0.036));

        return [$receivedArguments[2], $receivedArguments[5] ?? null];
    }

    private function argumentsReceivedByTheService(User $user, Request $request, ?IndustryUserSettings $settings, ?SalesFees $salesFees): array
    {
        $receivedArguments = [];
        $pivotAdvisorService = $this->createStub(PivotAdvisorService::class);
        $pivotAdvisorService->method('analyze')->willReturnCallback(
            function (mixed ...$arguments) use (&$receivedArguments): array {
                $receivedArguments = $arguments;

                return ['typeId' => self::SABRE_TYPE_ID, 'sourceProduct' => [], 'matrix' => [], 'candidates' => [], 'matrixProductIds' => []];
            },
        );

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $requestStack = new RequestStack();
        $requestStack->push($request);
        $settingsRepository = $this->createStub(IndustryUserSettingsRepository::class);
        $settingsRepository->method('findOneBy')->willReturn($settings);

        $salesFeesResolver = $this->createStub(SellerSalesFeesResolver::class);
        $salesFeesResolver->method('forUser')->willReturnCallback(
            static fn (User $seller): ?SalesFees => $seller === $user ? $salesFees : null,
        );

        (new PivotAdvisorProvider($security, $requestStack, $pivotAdvisorService, $settingsRepository, $salesFeesResolver))
            ->provide(new Get(), ['typeId' => (string) self::SABRE_TYPE_ID]);

        return $receivedArguments;
    }
}
