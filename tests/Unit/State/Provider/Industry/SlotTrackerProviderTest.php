<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\Get;
use App\Entity\CachedCharacterSkill;
use App\Entity\Character;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\CharacterRepository;
use App\Repository\IndustryStockpileTargetRepository;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\SlotTrackerService;
use App\Service\TypeNameResolver;
use App\State\Provider\Industry\SlotTrackerProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

#[CoversClass(SlotTrackerProvider::class)]
#[AllowMockObjectsWithoutExpectations]
final class SlotTrackerProviderTest extends TestCase
{
    private const int AURORA_CHARACTER_ID = 3001;
    private const int BOREALIS_CHARACTER_ID = 3002;

    #[Group('issue-218')]
    public function testResourceExposesUnknownSkillsAndStaleJobsOfEachCharacterAndTheUnknownSkillsCharacterCount(): void
    {
        $aurora = $this->character(self::AURORA_CHARACTER_ID, 'Aurora', true, authValid: true);
        $borealis = $this->character(self::BOREALIS_CHARACTER_ID, 'Borealis', false, authValid: false);
        $characterRepository = $this->createMock(CharacterRepository::class);
        $characterRepository->method('findByUser')->willReturn([$aurora, $borealis]);
        $skillRepository = $this->createMock(CachedCharacterSkillRepository::class);
        $skillRepository->method('hasCachedSkills')->willReturnCallback(
            static fn (Character $character): bool => $character->getEveCharacterId() === self::BOREALIS_CHARACTER_ID,
        );
        $massProduction = (new CachedCharacterSkill())->setSkillId(CachedCharacterSkill::SKILL_MASS_PRODUCTION)->setLevel(3);
        $skillRepository->method('findSlotSkillsForCharacter')->willReturnCallback(
            static fn (Character $character): array => $character->getEveCharacterId() === self::BOREALIS_CHARACTER_ID
                ? [CachedCharacterSkill::SKILL_MASS_PRODUCTION => $massProduction]
                : [],
        );
        $jobRepository = $this->createMock(CachedIndustryJobRepository::class);
        $jobRepository->method('findActiveJobsByCharacter')->willReturn([]);
        $typeNameResolver = $this->createMock(TypeNameResolver::class);
        $typeNameResolver->method('findNames')->willReturn([]);
        $stockpileTargetRepository = $this->createMock(IndustryStockpileTargetRepository::class);
        $stockpileTargetRepository->method('findByUser')->willReturn([]);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createStub(User::class));
        $provider = new SlotTrackerProvider($security, new SlotTrackerService(
            $characterRepository,
            $jobRepository,
            $skillRepository,
            $this->createMock(IndustryCalculationService::class),
            $typeNameResolver,
            $stockpileTargetRepository,
            $this->createMock(CachedAssetRepository::class),
        ));

        $resource = $provider->provide(new Get());

        $this->assertSame(1, $resource->unknownSkillsCharacterCount);
        $this->assertTrue($resource->skillsMayBeStale);
        $this->assertSame(['Aurora', 'Borealis'], array_column($resource->characters, 'characterName'));
        $this->assertSame([true, false], array_column($resource->characters, 'skillsUnknown'));
        $this->assertSame([false, true], array_column($resource->characters, 'jobsStale'));
        $this->assertSame(
            [['used' => 0, 'max' => null], ['used' => 0, 'max' => 4]],
            array_map(static fn (array $character): array => $character['slots']['manufacturing'], $resource->characters),
        );
        $this->assertSame(['used' => 0, 'max' => 4, 'percent' => 0.0], $resource->globalKpis['manufacturing']);
    }

    private function character(int $eveCharacterId, string $name, bool $isMain, bool $authValid): Character
    {
        $character = $this->createStub(Character::class);
        $character->method('getEveCharacterId')->willReturn($eveCharacterId);
        $character->method('getName')->willReturn($name);
        $character->method('isMain')->willReturn($isMain);
        $character->method('isAuthValid')->willReturn($authValid);

        return $character;
    }
}
