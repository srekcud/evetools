<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use ApiPlatform\Metadata\GetCollection;
use App\Repository\IndustryProjectTemplateRepository;
use App\State\Provider\Industry\Project\ProjectTemplateCollectionProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

#[CoversClass(ProjectTemplateCollectionProvider::class)]
#[Group('issue-355')]
final class ProjectTemplateCollectionProviderTest extends TestCase
{
    use BuildsProjectTemplates;

    public function testVisibleTemplatesKeepTheRepositoryOrderAndTellWhoOwnsThem(): void
    {
        $alice = $this->pilot('Alice Ferrand', 98_000_001);
        $bob = $this->pilot('Bob Lenoir', 98_000_001);
        $aliceSharedTemplate = $this->templateOf($alice, 'Hammerhead line', true);
        $bobOwnTemplate = $this->templateOf($bob, 'Warrior line', false)
            ->setItems([['typeId' => 2446, 'typeName' => 'Warrior II', 'meLevel' => 5, 'teLevel' => 10, 'runs' => 20]])
            ->setBlacklistGroupIds([])
            ->setBlacklistTypeIds([])
            ->setAcceptsOutsideFavoriteSystem(false)
            ->setMinimumOutsideSaving(750_000.0);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($bob);
        $repository = $this->createStub(IndustryProjectTemplateRepository::class);
        $repository->method('findVisibleTo')->willReturn([$aliceSharedTemplate, $bobOwnTemplate]);

        $resources = (new ProjectTemplateCollectionProvider($security, $repository))->provide(new GetCollection());

        self::assertCount(2, $resources);
        [$sharedByAlice, $ownByBob] = $resources;
        self::assertSame((string) $aliceSharedTemplate->getId(), $sharedByAlice->id);
        self::assertSame('Hammerhead line', $sharedByAlice->name);
        self::assertSame('Alice Ferrand', $sharedByAlice->ownerName);
        self::assertFalse($sharedByAlice->isOwn);
        self::assertTrue($sharedByAlice->sharedWithCorporation);
        self::assertCount(2, $sharedByAlice->items);
        self::assertSame([18], $sharedByAlice->blacklistGroupIds);
        self::assertSame([34], $sharedByAlice->blacklistTypeIds);
        self::assertTrue($sharedByAlice->acceptsOutsideFavoriteSystem);
        self::assertSame(5_000_000.0, $sharedByAlice->minimumOutsideSaving);
        self::assertSame($aliceSharedTemplate->getCreatedAt()->format(\DateTimeInterface::ATOM), $sharedByAlice->createdAt);
        self::assertSame((string) $bobOwnTemplate->getId(), $ownByBob->id);
        self::assertSame('Bob Lenoir', $ownByBob->ownerName);
        self::assertTrue($ownByBob->isOwn);
        self::assertFalse($ownByBob->sharedWithCorporation);
        self::assertSame([], $ownByBob->blacklistGroupIds);
        self::assertSame(750_000.0, $ownByBob->minimumOutsideSaving);
    }
}
