<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\UserSettings;

use ApiPlatform\Metadata\Patch;
use App\ApiResource\UserSettings\UserSettingsResource;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\CachedStructureRepository;
use App\State\Processor\UserSettings\UpdateUserSettingsProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

#[CoversClass(UpdateUserSettingsProcessor::class)]
#[Group('issue-880')]
final class UpdateUserSettingsProcessorMarketStructureTest extends TestCase
{
    private const int DEFAULT_STRUCTURE_ID = 1049588174021;
    private const string DEFAULT_STRUCTURE_NAME = 'C-J6MT - 1st Taj Mahgoon (Keepstar)';
    private const int LISTED_STRUCTURE_ID = 1028858195912;
    private const int OTHER_LISTED_STRUCTURE_ID = 1022734985679;

    public function testEffectiveMarketStructureIsTheFirstEntryOfTheSentListWhenNoPreferredStructureIsSent(): void
    {
        $data = new UserSettingsResource();
        $data->marketStructures = [
            ['id' => self::LISTED_STRUCTURE_ID, 'name' => 'Ashab - Market Fortizar'],
            ['id' => self::OTHER_LISTED_STRUCTURE_ID, 'name' => 'Amarr - Trade Hub'],
        ];

        $resource = $this->process(new User(), $data);

        self::assertSame(self::LISTED_STRUCTURE_ID, $resource->effectiveMarketStructureId);
        self::assertSame('Ashab - Market Fortizar', $resource->effectiveMarketStructureName);
        self::assertNull($resource->preferredMarketStructureId);
    }

    public function testEffectiveMarketStructureIsTheDefaultStructureWhenNeitherPreferredNorListIsSent(): void
    {
        $resource = $this->process(new User(), new UserSettingsResource());

        self::assertSame(self::DEFAULT_STRUCTURE_ID, $resource->effectiveMarketStructureId);
        self::assertSame(self::DEFAULT_STRUCTURE_NAME, $resource->effectiveMarketStructureName);
    }

    private function process(User $user, UserSettingsResource $data): UserSettingsResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $processor = new UpdateUserSettingsProcessor(
            $security,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(CachedStructureRepository::class),
            $this->createStub(CachedAssetRepository::class),
            self::DEFAULT_STRUCTURE_ID,
            self::DEFAULT_STRUCTURE_NAME,
        );

        return $processor->process($data, new Patch());
    }
}
