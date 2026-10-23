<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\Get;
use App\Constant\EveConstants;
use App\Entity\IndustryUserSettings;
use App\Entity\User;
use App\Repository\IndustryUserSettingsRepository;
use App\Service\Industry\PivotAdvisorService;
use App\State\Provider\Industry\PivotAdvisorProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

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

        (new PivotAdvisorProvider($security, $requestStack, $pivotAdvisorService, $settingsRepository))
            ->provide(new Get(), ['typeId' => (string) self::SABRE_TYPE_ID]);

        return [$receivedArguments[2], $receivedArguments[6] ?? null];
    }
}
