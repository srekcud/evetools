<?php

declare(strict_types=1);

namespace App\State\Processor\Mining;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Mining\MoonCompositionResource;
use App\Entity\MoonComposition;
use App\Entity\User;
use App\Repository\CorporationMiningExtractionRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\MoonChunk\CorporationRolesUnreadable;
use App\Service\MoonChunk\MoonCompositionRecorder;
use App\Service\MoonChunk\MoonCompositionReplacementPolicy;
use App\Service\MoonChunk\MoonCompositionReplacementRefused;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<MoonCompositionResource, MoonCompositionResource>
 */
final readonly class RecordMoonCompositionProcessor implements ProcessorInterface
{
    public const int MAX_SCAN_LENGTH = 5000;
    public const int MAX_SCAN_LINES = 100;

    private const string MOON_NOT_FOUND_MESSAGE = 'Moon not found';

    public function __construct(
        private Security $security,
        private CorporationMiningExtractionRepository $extractionRepository,
        private InvTypeRepository $invTypeRepository,
        private MoonCompositionRecorder $recorder,
        private MoonCompositionReplacementPolicy $replacementPolicy,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MoonCompositionResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        \assert($data instanceof MoonCompositionResource);

        $this->assertMoonIsExtractedByACorporationOf($user, $data->corporationId, $data->moonId);
        $this->assertScanIsWithinBounds($data->scan);
        $this->assertUserMayRecord($user, $data->corporationId, $data->moonId);

        return $this->resourceOf($this->recorder->record($user, $data->corporationId, $data->moonId, $data->scan));
    }

    private function assertMoonIsExtractedByACorporationOf(User $user, int $corporationId, int $moonId): void
    {
        $isMemberOfCorporation = \in_array($corporationId, $user->getCharacterCorporationIds(), true);
        if (!$isMemberOfCorporation || $this->extractionRepository->findOneBy(['corporationId' => $corporationId, 'moonId' => $moonId]) === null) {
            throw new NotFoundHttpException(self::MOON_NOT_FOUND_MESSAGE);
        }
    }

    private function assertUserMayRecord(User $user, int $corporationId, int $moonId): void
    {
        try {
            $this->replacementPolicy->assertMayRecord($user, $corporationId, $moonId);
        } catch (MoonCompositionReplacementRefused $refusal) {
            throw new AccessDeniedHttpException($refusal->getMessage(), $refusal);
        } catch (CorporationRolesUnreadable $unreadable) {
            throw new ServiceUnavailableHttpException(null, $unreadable->getMessage(), $unreadable);
        }
    }

    private function assertScanIsWithinBounds(string $scan): void
    {
        if (mb_strlen($scan) > self::MAX_SCAN_LENGTH) {
            throw new BadRequestHttpException(sprintf('The scan must not exceed %d characters', self::MAX_SCAN_LENGTH));
        }

        $nonBlankLines = array_filter(preg_split('/\R/u', $scan) ?: [], static fn (string $line): bool => trim($line) !== '');
        if (\count($nonBlankLines) > self::MAX_SCAN_LINES) {
            throw new BadRequestHttpException(sprintf('The scan must not exceed %d lines', self::MAX_SCAN_LINES));
        }
    }

    private function resourceOf(MoonComposition $composition): MoonCompositionResource
    {
        $types = $this->invTypeRepository->findByTypeIds(array_column($composition->getLines(), 'typeId'));

        $resource = new MoonCompositionResource();
        $resource->corporationId = $composition->getCorporationId();
        $resource->moonId = $composition->getMoonId();
        $resource->recordedAt = $composition->getRecordedAt();
        $resource->lines = array_map(
            static fn (array $line): array => ['typeId' => $line['typeId'], 'typeName' => $types[$line['typeId']]->getTypeName(), 'share' => $line['share']],
            $composition->getLines(),
        );

        return $resource;
    }
}
