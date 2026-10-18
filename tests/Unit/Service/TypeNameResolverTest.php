<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\TypeNameResolver;
use App\Tests\Unit\Service\Industry\ResolvesTypeNamesFromSde;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(TypeNameResolver::class)]
#[Group('issue-115')]
final class TypeNameResolverTest extends TestCase
{
    use ResolvesTypeNamesFromSde;

    public function testFindNameReturnsTheSdeNameOfATypeInInvTypes(): void
    {
        $resolver = new TypeNameResolver($this->sdeTypeRepositoryKnowingOnlyNitrogenFuelBlock());

        self::assertSame(self::NITROGEN_FUEL_BLOCK_NAME, $resolver->findName(self::NITROGEN_FUEL_BLOCK_TYPE_ID));
    }

    public function testFindNameReturnsAnUnknownTypeNameForATypeMissingFromInvTypes(): void
    {
        $resolver = new TypeNameResolver($this->sdeTypeRepositoryKnowingOnlyNitrogenFuelBlock());

        self::assertNull($resolver->findName(self::ROBOTICS_TYPE_ID));
    }

    public function testFindNamesReturnsOnlyTheSdeNamesOfTypesInInvTypesIndexedByTypeId(): void
    {
        $resolver = new TypeNameResolver($this->sdeTypeRepositoryKnowingOnlyNitrogenFuelBlock());

        self::assertSame(
            [self::NITROGEN_FUEL_BLOCK_TYPE_ID => self::NITROGEN_FUEL_BLOCK_NAME],
            $resolver->findNames([self::NITROGEN_FUEL_BLOCK_TYPE_ID, self::ROBOTICS_TYPE_ID]),
        );
    }

    public function testFindNamesOfNoTypeIsEmpty(): void
    {
        $resolver = new TypeNameResolver($this->sdeTypeRepositoryKnowingOnlyNitrogenFuelBlock());

        self::assertSame([], $resolver->findNames([]));
    }
}
