<?php

declare(strict_types=1);

namespace App\Service\MoonChunk;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Entity\MoonComposition;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Repository\MoonCompositionRepository;
use App\Repository\Sde\InvTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

final readonly class MoonCompositionRecorder
{
    private const int ASTEROID_CATEGORY_ID = 25;
    private const string SCAN_PROPERTY = 'scan';
    private const string LOCK_QUERY = 'SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))';
    private const string LOCK_KEY_FORMAT = 'moon-composition:%d:%d';
    private const string UNKNOWN_ORE_MESSAGE = 'Scan rejected at line %d: %s is not a published ore of the SDE';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private MoonCompositionRepository $compositionRepository,
        private InvTypeRepository $invTypeRepository,
        private MoonScanParser $parser,
        private ClockInterface $clock,
    ) {
    }

    public function record(User $user, int $corporationId, int $moonId, string $scan): MoonComposition
    {
        $scanLines = $this->parsed($scan);
        $lines = $this->resolved($scanLines);

        return $this->entityManager->wrapInTransaction(fn (): MoonComposition => $this->recordInTransaction($user, $corporationId, $moonId, $lines));
    }

    /**
     * @param list<MoonScanLine> $scanLines
     */
    public function recordImported(int $corporationId, int $moonId, array $scanLines): bool
    {
        $lines = $this->resolved($scanLines);

        return $this->entityManager->wrapInTransaction(function () use ($corporationId, $moonId, $lines): bool {
            $this->entityManager->getConnection()->executeQuery(self::LOCK_QUERY, ['key' => sprintf(self::LOCK_KEY_FORMAT, $corporationId, $moonId)]);
            if ($this->compositionRepository->findOneBy(['corporationId' => $corporationId, 'moonId' => $moonId]) !== null) {
                return false;
            }

            $this->entityManager->persist(
                (new MoonComposition())
                    ->setCorporationId($corporationId)
                    ->setMoonId($moonId)
                    ->setRecordedAt($this->clock->now())
                    ->setLines($lines),
            );
            $this->entityManager->flush();

            return true;
        });
    }

    /**
     * @param list<array{typeId: int, share: float}> $lines
     */
    private function recordInTransaction(User $user, int $corporationId, int $moonId, array $lines): MoonComposition
    {
        $this->entityManager->getConnection()->executeQuery(self::LOCK_QUERY, ['key' => sprintf(self::LOCK_KEY_FORMAT, $corporationId, $moonId)]);

        $composition = $this->compositionRepository->findOneBy(['corporationId' => $corporationId, 'moonId' => $moonId])
            ?? (new MoonComposition())->setCorporationId($corporationId)->setMoonId($moonId);
        $composition
            ->setRecordedBy($user)
            ->setRecordedAt($this->clock->now())
            ->setLines($lines);
        $this->entityManager->persist($composition);
        $this->entityManager->flush();

        return $composition;
    }

    /**
     * @return list<MoonScanLine>
     */
    private function parsed(string $scan): array
    {
        try {
            return $this->parser->parse($scan);
        } catch (MoonScanException $rejection) {
            throw $this->refusal([[$rejection->getMessage(), $rejection->lineNumber]]);
        }
    }

    /**
     * @param list<MoonScanLine> $scanLines
     *
     * @return list<array{typeId: int, share: float}>
     */
    private function resolved(array $scanLines): array
    {
        $typesByName = $this->invTypeRepository->findByNames(array_map(static fn (MoonScanLine $line): string => $line->name, $scanLines));

        $lines = [];
        $unknownOres = [];
        foreach ($scanLines as $line) {
            $type = $typesByName[$line->name] ?? null;
            if ($type === null || !$this->isPublishedOre($type)) {
                $unknownOres[] = [sprintf(self::UNKNOWN_ORE_MESSAGE, $line->lineNumber, $line->name), $line->lineNumber];
                continue;
            }
            $lines[] = ['typeId' => $type->getTypeId(), 'share' => $line->share];
        }

        if ($unknownOres !== []) {
            throw $this->refusal($unknownOres);
        }

        return $lines;
    }

    private function isPublishedOre(InvType $type): bool
    {
        return $type->isPublished() && $type->getGroup()->getCategory()->getCategoryId() === self::ASTEROID_CATEGORY_ID;
    }

    /**
     * @param non-empty-list<array{0: string, 1: ?int}> $faults
     */
    private function refusal(array $faults): ValidationException
    {
        return new ValidationException(new ConstraintViolationList(array_map(
            static fn (array $fault): ConstraintViolation => new ConstraintViolation(
                $fault[0],
                $fault[0],
                [],
                null,
                $fault[1] === null ? self::SCAN_PROPERTY : sprintf('%s[%d]', self::SCAN_PROPERTY, $fault[1]),
                null,
            ),
            $faults,
        )));
    }
}
