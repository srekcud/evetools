<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Assets;

use ApiPlatform\Metadata\Put;
use App\ApiResource\Assets\CorpAssetVisibilityResource;
use App\ApiResource\Input\Assets\UpdateCorpVisibilityInput;
use App\Entity\Character;
use App\Entity\CorpAssetVisibility;
use App\Entity\User;
use App\Repository\CorpAssetVisibilityRepository;
use App\Service\ESI\CharacterService;
use App\Service\ESI\CorporationService;
use App\State\Processor\Assets\UpdateCorpVisibilityProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(UpdateCorpVisibilityProcessor::class)]
class UpdateCorpVisibilityProcessorTest extends TestCase
{
    private Security&Stub $security;
    private EntityManagerInterface&MockObject $em;
    private CorpAssetVisibilityRepository&Stub $visibilityRepository;
    private CharacterService&Stub $characterService;
    private CorporationService&Stub $corporationService;
    private UpdateCorpVisibilityProcessor $processor;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->visibilityRepository = $this->createStub(CorpAssetVisibilityRepository::class);
        $this->characterService = $this->createStub(CharacterService::class);
        $this->corporationService = $this->createStub(CorporationService::class);

        $this->processor = new UpdateCorpVisibilityProcessor(
            $this->security,
            $this->em,
            $this->visibilityRepository,
            $this->characterService,
            $this->corporationService,
        );
    }

    // ===========================================
    // Successful operations
    // ===========================================

    public function testDirectorCanSaveValidDivisions(): void
    {
        $user = $this->createDirectorUser();
        $this->security->method('getUser')->willReturn($user);
        $this->characterService->method('canReadCorporationAssets')->willReturn(true);
        $this->visibilityRepository->method('findByCorporationId')->willReturn(null);
        $this->corporationService->method('getDivisions')->willReturn([
            1 => 'Materials',
            2 => 'Ships',
            3 => 'Ammo',
        ]);

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $input = new UpdateCorpVisibilityInput();
        $input->visibleDivisions = [1, 3];

        $result = $this->processor->process($input, new Put());

        $this->assertInstanceOf(CorpAssetVisibilityResource::class, $result);
        $this->assertSame([1, 3], $result->visibleDivisions);
        $this->assertTrue($result->isDirector);
        $this->assertSame('TestDirector', $result->configuredByName);
        $this->assertNotNull($result->updatedAt);
    }

    public function testEmptyArrayIsValid(): void
    {
        $user = $this->createDirectorUser();
        $this->security->method('getUser')->willReturn($user);
        $this->characterService->method('canReadCorporationAssets')->willReturn(true);
        $this->visibilityRepository->method('findByCorporationId')->willReturn(null);
        $this->corporationService->method('getDivisions')->willReturn([1 => 'Materials']);

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $input = new UpdateCorpVisibilityInput();
        $input->visibleDivisions = [];

        $result = $this->processor->process($input, new Put());

        $this->assertSame([], $result->visibleDivisions);
    }

    public function testUpsertUpdatesExistingConfig(): void
    {
        $user = $this->createDirectorUser();
        $this->security->method('getUser')->willReturn($user);
        $this->characterService->method('canReadCorporationAssets')->willReturn(true);
        $this->corporationService->method('getDivisions')->willReturn([1 => 'Materials', 2 => 'Ships']);

        $existing = new CorpAssetVisibility();
        $existing->setCorporationId(98000001);
        $existing->setVisibleDivisions([1]);
        $existing->setConfiguredBy($user);
        $this->visibilityRepository->method('findByCorporationId')->willReturn($existing);

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $input = new UpdateCorpVisibilityInput();
        $input->visibleDivisions = [1, 2];

        $result = $this->processor->process($input, new Put());

        $this->assertSame([1, 2], $result->visibleDivisions);
    }

    public function testDuplicateDivisionsAreDeduped(): void
    {
        $user = $this->createDirectorUser();
        $this->security->method('getUser')->willReturn($user);
        $this->characterService->method('canReadCorporationAssets')->willReturn(true);
        $this->visibilityRepository->method('findByCorporationId')->willReturn(null);
        $this->corporationService->method('getDivisions')->willReturn([1 => 'Materials']);

        $this->em->expects($this->once())->method('persist');

        $input = new UpdateCorpVisibilityInput();
        $input->visibleDivisions = [1, 1, 3, 3];

        $result = $this->processor->process($input, new Put());

        $this->assertSame([1, 3], $result->visibleDivisions);
    }

    // ===========================================
    // Authorization errors
    // ===========================================

    public function testThrowsUnauthorizedWhenNoUser(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $input = new UpdateCorpVisibilityInput();
        $input->visibleDivisions = [1];

        $this->expectException(UnauthorizedHttpException::class);

        $this->processor->process($input, new Put());
    }

    public function testThrowsAccessDeniedWhenNoMainCharacter(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getMainCharacter')->willReturn(null);
        $this->security->method('getUser')->willReturn($user);

        $input = new UpdateCorpVisibilityInput();
        $input->visibleDivisions = [1];

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('No main character set');

        $this->processor->process($input, new Put());
    }

    public function testNonDirectorCannotUpdateVisibility(): void
    {
        $user = $this->createDirectorUser();
        $this->security->method('getUser')->willReturn($user);
        $this->characterService->method('canReadCorporationAssets')->willReturn(false);

        $input = new UpdateCorpVisibilityInput();
        $input->visibleDivisions = [1];

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Only Directors can configure asset visibility');

        $this->processor->process($input, new Put());
    }

    // ===========================================
    // Validation errors
    // ===========================================

    public function testThrowsBadRequestForDivisionZero(): void
    {
        $user = $this->createDirectorUser();
        $this->security->method('getUser')->willReturn($user);
        $this->characterService->method('canReadCorporationAssets')->willReturn(true);

        $input = new UpdateCorpVisibilityInput();
        $input->visibleDivisions = [0];

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Each division must be an integer between 1 and 7');

        $this->processor->process($input, new Put());
    }

    public function testThrowsBadRequestForDivisionEight(): void
    {
        $user = $this->createDirectorUser();
        $this->security->method('getUser')->willReturn($user);
        $this->characterService->method('canReadCorporationAssets')->willReturn(true);

        $input = new UpdateCorpVisibilityInput();
        $input->visibleDivisions = [8];

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Each division must be an integer between 1 and 7');

        $this->processor->process($input, new Put());
    }

    public function testThrowsBadRequestForNegativeDivision(): void
    {
        $user = $this->createDirectorUser();
        $this->security->method('getUser')->willReturn($user);
        $this->characterService->method('canReadCorporationAssets')->willReturn(true);

        $input = new UpdateCorpVisibilityInput();
        $input->visibleDivisions = [-1];

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Each division must be an integer between 1 and 7');

        $this->processor->process($input, new Put());
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function createDirectorUser(): User&Stub
    {
        $character = $this->createStub(Character::class);
        $character->method('getCorporationId')->willReturn(98000001);
        $character->method('getName')->willReturn('TestDirector');

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());
        $user->method('getMainCharacter')->willReturn($character);

        return $user;
    }
}
