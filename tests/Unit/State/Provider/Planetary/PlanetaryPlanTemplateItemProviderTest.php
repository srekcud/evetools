<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Planetary;

use ApiPlatform\Metadata\Get;
use App\Entity\PlanetaryPlanTemplate;
use App\Entity\User;
use App\Repository\PlanetaryPlanTemplateRepository;
use App\State\Provider\Planetary\PlanetaryPlanTemplateItemProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(PlanetaryPlanTemplateItemProvider::class)]
#[Group('issue-1405')]
final class PlanetaryPlanTemplateItemProviderTest extends TestCase
{
    private const string TEMPLATE_ID = '7c2e51d8-90b3-4a16-8f4d-3b6a1e0c9d57';
    private const int BROADCAST_NODE = 2_867;
    private const int ROBOTICS = 9_848;

    private User $user;

    protected function setUp(): void
    {
        $this->user = new User();
    }

    public function testItReturnsTheTemplateOfTheConnectedUserFoundByItsId(): void
    {
        $template = (new PlanetaryPlanTemplate())
            ->setUser($this->user)
            ->setName('Broadcast chain')
            ->setProductions([['typeId' => self::BROADCAST_NODE, 'targetPerDay' => 2.5]])
            ->setChoices([self::ROBOTICS => 'buy'])
            ->setSettings(['priceSource' => 'jita-buy', 'taxPercent' => 10, 'daysBetweenRounds' => 3]);
        (new \ReflectionProperty(PlanetaryPlanTemplate::class, 'id'))->setValue($template, Uuid::fromString(self::TEMPLATE_ID));
        (new \ReflectionProperty(PlanetaryPlanTemplate::class, 'createdAt'))->setValue($template, new \DateTimeImmutable('2026-11-21T07:45:00+00:00'));
        $repository = $this->createMock(PlanetaryPlanTemplateRepository::class);
        $repository->expects($this->once())->method('findOneByUserAndId')
            ->with($this->user, $this->callback(static fn (Uuid $id): bool => $id->toRfc4122() === self::TEMPLATE_ID))
            ->willReturn($template);

        $resource = $this->provider($repository)->provide(new Get(), ['id' => self::TEMPLATE_ID]);

        $this->assertSame(self::TEMPLATE_ID, $resource->id);
        $this->assertSame('Broadcast chain', $resource->name);
        $this->assertSame([['typeId' => self::BROADCAST_NODE, 'targetPerDay' => 2.5]], $resource->productions);
        $this->assertSame([self::ROBOTICS => 'buy'], $resource->choices);
        $this->assertSame(['priceSource' => 'jita-buy', 'taxPercent' => 10, 'daysBetweenRounds' => 3], $resource->settings);
        $this->assertSame('2026-11-21T07:45:00+00:00', $resource->createdAt);
    }

    #[Group('issue-1406')]
    public function testItExposesWhetherTheTemplateIsFollowed(): void
    {
        foreach ([true, false] as $followed) {
            $template = (new PlanetaryPlanTemplate())->setUser($this->user)->setName('Broadcast chain')->setFollowed($followed);
            (new \ReflectionProperty(PlanetaryPlanTemplate::class, 'id'))->setValue($template, Uuid::fromString(self::TEMPLATE_ID));
            $repository = $this->createStub(PlanetaryPlanTemplateRepository::class);
            $repository->method('findOneByUserAndId')->willReturn($template);

            $resource = $this->provider($repository)->provide(new Get(), ['id' => self::TEMPLATE_ID]);

            $this->assertSame($followed, $resource->followed);
        }
    }

    public function testATemplateOfAnotherUserOrAnUnknownOneIsNotFoundNeverForbidden(): void
    {
        $repository = $this->createStub(PlanetaryPlanTemplateRepository::class);
        $repository->method('findOneByUserAndId')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);

        $this->provider($repository)->provide(new Get(), ['id' => self::TEMPLATE_ID]);
    }

    public function testAnIdThatIsNotAUuidIsNotFoundWithoutLookingItUp(): void
    {
        $repository = $this->createMock(PlanetaryPlanTemplateRepository::class);
        $repository->expects($this->never())->method('findOneByUserAndId');

        $this->expectException(NotFoundHttpException::class);

        $this->provider($repository)->provide(new Get(), ['id' => 'not-a-uuid']);
    }

    public function testAnAnonymousCallerIsRefusedWithoutReadingTheRepository(): void
    {
        $repository = $this->createMock(PlanetaryPlanTemplateRepository::class);
        $repository->expects($this->never())->method('findOneByUserAndId');

        $this->expectException(UnauthorizedHttpException::class);

        $this->provider($repository, false)->provide(new Get(), ['id' => self::TEMPLATE_ID]);
    }

    private function provider(PlanetaryPlanTemplateRepository $repository, bool $authenticated = true): PlanetaryPlanTemplateItemProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($authenticated ? $this->user : null);

        return new PlanetaryPlanTemplateItemProvider($security, $repository);
    }
}
