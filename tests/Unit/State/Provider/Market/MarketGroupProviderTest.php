<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Market;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\Market\MarketGroupResource;
use App\Entity\Sde\InvMarketGroup;
use App\Repository\Sde\InvMarketGroupRepository;
use App\State\Provider\Market\MarketGroupProvider;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarketGroupProvider::class)]
class MarketGroupProviderTest extends TestCase
{
    private InvMarketGroupRepository&Stub $marketGroupRepository;
    private MarketGroupProvider $provider;

    protected function setUp(): void
    {
        $this->marketGroupRepository = $this->createStub(InvMarketGroupRepository::class);
        $this->provider = new MarketGroupProvider($this->marketGroupRepository);
    }

    // ===========================================
    // Response structure
    // ===========================================

    public function testReturnsArrayOfMarketGroupResources(): void
    {
        $group = $this->createMarketGroup(2, 'Ships', true, true);
        $this->marketGroupRepository->method('findRootGroups')->willReturn([$group]);

        $result = $this->provider->provide(new GetCollection());

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertInstanceOf(MarketGroupResource::class, $result[0]);
    }

    public function testGroupResourceHasCorrectProperties(): void
    {
        $group = $this->createMarketGroup(2, 'Ships', true, false);
        $this->marketGroupRepository->method('findRootGroups')->willReturn([$group]);

        $result = $this->provider->provide(new GetCollection());

        $this->assertSame(2, $result[0]->id);
        $this->assertSame('Ships', $result[0]->name);
        $this->assertNull($result[0]->parentId);
        $this->assertTrue($result[0]->hasChildren);
        $this->assertFalse($result[0]->hasTypes);
    }

    // ===========================================
    // hasChildren flag
    // ===========================================

    public function testHasChildrenIsTrueWhenGroupHasChildren(): void
    {
        $group = $this->createMarketGroup(2, 'Ships', true, false);
        $this->marketGroupRepository->method('findRootGroups')->willReturn([$group]);

        $result = $this->provider->provide(new GetCollection());

        $this->assertTrue($result[0]->hasChildren);
    }

    public function testHasChildrenIsFalseWhenGroupHasNoChildren(): void
    {
        $group = $this->createMarketGroup(500, 'Minerals', false, true);
        $this->marketGroupRepository->method('findRootGroups')->willReturn([$group]);

        $result = $this->provider->provide(new GetCollection());

        $this->assertFalse($result[0]->hasChildren);
    }

    // ===========================================
    // hasTypes flag
    // ===========================================

    public function testHasTypesReflectsEntityValue(): void
    {
        $groupWithTypes = $this->createMarketGroup(500, 'Minerals', false, true);
        $groupWithoutTypes = $this->createMarketGroup(2, 'Ships', true, false);

        $this->marketGroupRepository->method('findRootGroups')->willReturn([$groupWithTypes, $groupWithoutTypes]);

        $result = $this->provider->provide(new GetCollection());

        $this->assertTrue($result[0]->hasTypes);
        $this->assertFalse($result[1]->hasTypes);
    }

    // ===========================================
    // Empty result
    // ===========================================

    public function testReturnsEmptyArrayWhenNoRootGroups(): void
    {
        $this->marketGroupRepository->method('findRootGroups')->willReturn([]);

        $result = $this->provider->provide(new GetCollection());

        $this->assertSame([], $result);
    }

    // ===========================================
    // Multiple groups
    // ===========================================

    public function testReturnsMultipleGroups(): void
    {
        $groups = [
            $this->createMarketGroup(2, 'Ships', true, false),
            $this->createMarketGroup(9, 'Ship Equipment', true, false),
            $this->createMarketGroup(475, 'Manufacture & Research', true, false),
        ];

        $this->marketGroupRepository->method('findRootGroups')->willReturn($groups);

        $result = $this->provider->provide(new GetCollection());

        $this->assertCount(3, $result);
        $this->assertSame('Ships', $result[0]->name);
        $this->assertSame('Ship Equipment', $result[1]->name);
        $this->assertSame('Manufacture & Research', $result[2]->name);
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function createMarketGroup(int $id, string $name, bool $hasChildren, bool $hasTypes): InvMarketGroup
    {
        $group = new InvMarketGroup();
        $group->setMarketGroupId($id);
        $group->setMarketGroupName($name);
        $group->setHasTypes($hasTypes);

        // Use reflection to set children collection for hasChildren test
        if ($hasChildren) {
            $child = new InvMarketGroup();
            $child->setMarketGroupId($id * 100);
            $child->setMarketGroupName('Child');

            $reflection = new \ReflectionClass(InvMarketGroup::class);
            $childrenProp = $reflection->getProperty('children');
            $childrenProp->setValue($group, new ArrayCollection([$child]));
        }

        return $group;
    }
}
