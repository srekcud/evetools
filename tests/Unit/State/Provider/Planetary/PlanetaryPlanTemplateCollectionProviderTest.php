<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Planetary;

use ApiPlatform\Metadata\GetCollection;
use App\Entity\PlanetaryPlanTemplate;
use App\Entity\User;
use App\Repository\PlanetaryPlanTemplateRepository;
use App\State\Provider\Planetary\PlanetaryPlanTemplateCollectionProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(PlanetaryPlanTemplateCollectionProvider::class)]
#[Group('issue-1405')]
final class PlanetaryPlanTemplateCollectionProviderTest extends TestCase
{
    private const string COOLANT_TEMPLATE_ID = '5d3a9c1e-7b24-4f60-8e1a-2c9b4d7f0a13';
    private const string ROBOTICS_TEMPLATE_ID = 'a81f20c6-3e5b-4d97-b6c4-91d0e7a35f28';
    private const int COOLANT = 9_832;
    private const int ROBOTICS = 9_848;
    private const int PLASMOIDS = 2_389;

    private User $user;

    protected function setUp(): void
    {
        $this->user = new User();
    }

    public function testItListsTheTemplatesOfTheConnectedUserInTheOrderOfTheRepositoryWithEveryStoredField(): void
    {
        $coolant = $this->template(self::COOLANT_TEMPLATE_ID, 'Coolant line', [['typeId' => self::COOLANT, 'targetPerDay' => 120]], [self::PLASMOIDS => 'buy'], ['priceSource' => 'jita-buy', 'taxPercent' => 7.5, 'daysBetweenRounds' => 6], '2026-11-22T18:30:00+00:00');
        $robotics = $this->template(self::ROBOTICS_TEMPLATE_ID, 'Robotics', [['typeId' => self::ROBOTICS, 'targetPerDay' => 36.5], ['typeId' => self::COOLANT, 'targetPerDay' => 9]], [], ['priceSource' => 'jita-sell', 'taxPercent' => 0, 'daysBetweenRounds' => 0.5], '2026-11-20T09:00:00+00:00');
        $repository = $this->createMock(PlanetaryPlanTemplateRepository::class);
        $repository->expects($this->once())->method('findByUser')->with($this->user)->willReturn([$coolant, $robotics]);

        $resources = $this->provider($repository)->provide(new GetCollection());

        $this->assertCount(2, $resources);
        $this->assertSame(self::COOLANT_TEMPLATE_ID, $resources[0]->id);
        $this->assertSame('Coolant line', $resources[0]->name);
        $this->assertSame([['typeId' => self::COOLANT, 'targetPerDay' => 120]], $resources[0]->productions);
        $this->assertSame([self::PLASMOIDS => 'buy'], $resources[0]->choices);
        $this->assertSame(['priceSource' => 'jita-buy', 'taxPercent' => 7.5, 'daysBetweenRounds' => 6], $resources[0]->settings);
        $this->assertSame('2026-11-22T18:30:00+00:00', $resources[0]->createdAt);
        $this->assertSame(self::ROBOTICS_TEMPLATE_ID, $resources[1]->id);
        $this->assertSame('Robotics', $resources[1]->name);
        $this->assertSame([['typeId' => self::ROBOTICS, 'targetPerDay' => 36.5], ['typeId' => self::COOLANT, 'targetPerDay' => 9]], $resources[1]->productions);
        $this->assertSame([], $resources[1]->choices);
        $this->assertSame(['priceSource' => 'jita-sell', 'taxPercent' => 0, 'daysBetweenRounds' => 0.5], $resources[1]->settings);
        $this->assertSame('2026-11-20T09:00:00+00:00', $resources[1]->createdAt);
    }

    public function testAUserWithoutTemplateGetsAnEmptyList(): void
    {
        $repository = $this->createMock(PlanetaryPlanTemplateRepository::class);
        $repository->expects($this->once())->method('findByUser')->with($this->user)->willReturn([]);

        $this->assertSame([], $this->provider($repository)->provide(new GetCollection()));
    }

    public function testAnAnonymousCallerIsRefusedWithoutReadingTheRepository(): void
    {
        $repository = $this->createMock(PlanetaryPlanTemplateRepository::class);
        $repository->expects($this->never())->method('findByUser');

        $this->expectException(UnauthorizedHttpException::class);

        $this->provider($repository, false)->provide(new GetCollection());
    }

    private function provider(PlanetaryPlanTemplateRepository $repository, bool $authenticated = true): PlanetaryPlanTemplateCollectionProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($authenticated ? $this->user : null);

        return new PlanetaryPlanTemplateCollectionProvider($security, $repository);
    }

    /**
     * @param list<array{typeId: int, targetPerDay: int|float}> $productions
     * @param array<int, string> $choices
     * @param array{priceSource: string, taxPercent: int|float, daysBetweenRounds: int|float} $settings
     */
    private function template(string $id, string $name, array $productions, array $choices, array $settings, string $createdAt): PlanetaryPlanTemplate
    {
        $template = (new PlanetaryPlanTemplate())->setUser($this->user)->setName($name)->setProductions($productions)->setChoices($choices)->setSettings($settings);
        (new \ReflectionProperty(PlanetaryPlanTemplate::class, 'id'))->setValue($template, Uuid::fromString($id));
        (new \ReflectionProperty(PlanetaryPlanTemplate::class, 'createdAt'))->setValue($template, new \DateTimeImmutable($createdAt));

        return $template;
    }
}
