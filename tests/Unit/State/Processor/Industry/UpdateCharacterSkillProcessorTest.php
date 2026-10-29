<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Patch;
use App\ApiResource\Input\Industry\UpdateCharacterSkillInput;
use App\Entity\User;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\CharacterRepository;
use App\State\Processor\Industry\UpdateCharacterSkillProcessor;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(UpdateCharacterSkillProcessor::class)]
#[Group('issue-200')]
final class UpdateCharacterSkillProcessorTest extends TestCase
{
    public function testCharacterIdThatIsNotAUuidIsNotFoundWithoutReadingTheCharactersNorFlushing(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());
        $characterRepository = $this->createMock(CharacterRepository::class);
        $characterRepository->expects($this->never())->method('find');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');
        $processor = new UpdateCharacterSkillProcessor(
            $security,
            $characterRepository,
            $this->createStub(CachedCharacterSkillRepository::class),
            $this->createStub(IndustryResourceMapper::class),
            $entityManager,
        );
        $input = new UpdateCharacterSkillInput();
        $input->industry = 5;

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Character not found');

        $processor->process($input, new Patch(), ['characterId' => 'pilot-one']);
    }
}
