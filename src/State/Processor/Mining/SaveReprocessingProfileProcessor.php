<?php

declare(strict_types=1);

namespace App\State\Processor\Mining;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Mining\ReprocessingProfileInput;
use App\ApiResource\Mining\ReprocessingProfileResource;
use App\Entity\Character;
use App\Entity\ReprocessingProfile;
use App\Entity\User;
use App\Enum\ReprocessingLocation;
use App\Enum\ReprocessingRigTier;
use App\Enum\ReprocessingSecurity;
use App\Repository\ReprocessingProfileRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Mining\ReprocessingProfileRecalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<ReprocessingProfileInput, ReprocessingProfileResource>
 */
final readonly class SaveReprocessingProfileProcessor implements ProcessorInterface
{
    private const array IMPLANT_PERCENTS = [0, 1, 2, 4];

    public function __construct(
        private Security $security,
        private ReprocessingProfileRepository $profileRepository,
        private InvTypeRepository $invTypeRepository,
        private ReprocessingProfileRecalculator $recalculator,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ReprocessingProfileResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $oreProcessingSkillTypeId = $this->oreProcessingSkillTypeIdOf($data);
        $location = $this->locationOf($data);
        $implantPercent = $this->implantPercentOf($data);
        $isStructure = $location->isStructure();
        $rigTier = $isStructure ? $this->rigTierOf($data) : null;
        $security = $isStructure ? $this->securityOf($data) : null;
        $character = $this->characterOf($user, $data);

        $existingProfile = $this->profileRepository->findOneBy(['user' => $user]);
        $profile = $existingProfile ?? (new ReprocessingProfile())->setUser($user);
        $profile
            ->setCharacter($character)
            ->setOreProcessingSkillTypeId($oreProcessingSkillTypeId)
            ->setLocation($location)
            ->setRigTier($rigTier)
            ->setSecurity($security)
            ->setImplantPercent($implantPercent);

        $this->recalculator->recalculate($profile);

        if ($existingProfile === null) {
            $this->entityManager->persist($profile);
        }
        $this->entityManager->flush();

        return ReprocessingProfileResource::fromProfile($profile, $this->invTypeRepository->findOreProcessingSkills());
    }

    private function oreProcessingSkillTypeIdOf(ReprocessingProfileInput $input): int
    {
        $skillTypeId = $input->oreProcessingSkillTypeId;
        $choosableSkillTypeIds = array_column($this->invTypeRepository->findOreProcessingSkills(), 'typeId');

        if ($skillTypeId === null || !in_array($skillTypeId, $choosableSkillTypeIds, true)) {
            throw new BadRequestHttpException('oreProcessingSkillTypeId must be one of the skills that process an ore of the table.');
        }

        return $skillTypeId;
    }

    private function locationOf(ReprocessingProfileInput $input): ReprocessingLocation
    {
        return ($input->location === null ? null : ReprocessingLocation::tryFrom($input->location))
            ?? throw new BadRequestHttpException($this->mustBeOneOf('location', ReprocessingLocation::cases()) . '.');
    }

    private function rigTierOf(ReprocessingProfileInput $input): ReprocessingRigTier
    {
        return ($input->rigTier === null ? null : ReprocessingRigTier::tryFrom($input->rigTier))
            ?? throw new BadRequestHttpException($this->mustBeOneOf('rigTier', ReprocessingRigTier::cases()) . ' for a structure.');
    }

    private function securityOf(ReprocessingProfileInput $input): ReprocessingSecurity
    {
        return ($input->security === null ? null : ReprocessingSecurity::tryFrom($input->security))
            ?? throw new BadRequestHttpException($this->mustBeOneOf('security', ReprocessingSecurity::cases()) . ' for a structure.');
    }

    private function implantPercentOf(ReprocessingProfileInput $input): int
    {
        if ($input->implantPercent === null || !in_array($input->implantPercent, self::IMPLANT_PERCENTS, true)) {
            throw new BadRequestHttpException(sprintf('implantPercent must be one of: %s.', implode(', ', self::IMPLANT_PERCENTS)));
        }

        return $input->implantPercent;
    }

    private function characterOf(User $user, ReprocessingProfileInput $input): Character
    {
        if ($input->characterId === null) {
            throw new BadRequestHttpException('characterId is required.');
        }

        foreach ($user->getCharacters() as $character) {
            if ($character->getEveCharacterId() === $input->characterId) {
                return $character;
            }
        }

        throw new NotFoundHttpException('Character not found.');
    }

    /**
     * @param list<\BackedEnum> $cases
     */
    private function mustBeOneOf(string $field, array $cases): string
    {
        return sprintf('%s must be one of: %s', $field, implode(', ', array_map(static fn (\BackedEnum $case): string => (string) $case->value, $cases)));
    }
}
