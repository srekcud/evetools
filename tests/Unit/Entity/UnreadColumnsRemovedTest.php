<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\CachedIndustryJob;
use App\Entity\CachedStructure;
use App\Entity\CachedWalletTransaction;
use App\Entity\SharedShoppingList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('issue-299')]
class UnreadColumnsRemovedTest extends TestCase
{
    /** @return iterable<string, array{class-string, string}> */
    public static function propertiesNeverRead(): iterable
    {
        yield 'structure resolution date' => [CachedStructure::class, 'resolvedAt'];
        yield 'industry job completion date' => [CachedIndustryJob::class, 'completedDate'];
        yield 'wallet transaction client' => [CachedWalletTransaction::class, 'clientId'];
    }

    /** @param class-string $entityClass */
    #[DataProvider('propertiesNeverRead')]
    public function testPropertyNeverReadIsNoLongerMapped(string $entityClass, string $property): void
    {
        $this->assertFalse(property_exists($entityClass, $property));
    }

    public function testSharedShoppingListKeepsItsCreatorWhichAccountDeletionCascadesOn(): void
    {
        $this->assertTrue(property_exists(SharedShoppingList::class, 'createdBy'));
    }
}
