<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Industry\StructureListImportResource;
use App\ApiResource\Input\Industry\CreateStructureInput;
use App\ApiResource\Input\Industry\ImportStructureListInput;
use App\Entity\User;
use App\Repository\IndustryStructureConfigRepository;
use App\Service\Industry\ProductionStructureRigsValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @implements ProcessorInterface<ImportStructureListInput, StructureListImportResource>
 */
class ImportStructureListProcessor implements ProcessorInterface
{
    private const int MAX_ENTRIES = 500;

    public function __construct(
        private readonly Security $security,
        private readonly IndustryStructureConfigRepository $structureConfigRepository,
        private readonly CreateStructureProcessor $createStructureProcessor,
        private readonly ProductionStructureRigsValidator $rigsValidator,
        private readonly ValidatorInterface $validator,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StructureListImportResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof ImportStructureListInput);

        if (count($data->entries) > self::MAX_ENTRIES) {
            throw new BadRequestHttpException(sprintf('A structure list holds at most %d entries.', self::MAX_ENTRIES));
        }

        $existingByIdentity = [];
        foreach ($this->structureConfigRepository->findByUser($user) as $structure) {
            $existingByIdentity[$this->identityOf($structure->getLocationId(), $structure->getName())] = $structure;
        }

        $seenIdentities = [];
        $lines = [];
        $updates = [];
        foreach (array_values($data->entries) as $index => $entry) {
            [$input, $errors] = $this->parseEntry($entry);
            $name = $this->rawName($entry);
            $locationId = $input?->locationId;

            if ($input === null || $errors !== []) {
                $lines[] = $this->line($index, $name, $locationId, StructureListImportResource::STATUS_INVALID, $errors);
                continue;
            }

            $identity = $this->identityOf($input->locationId, $input->name);
            $existing = $existingByIdentity[$identity] ?? null;

            if (isset($seenIdentities[$identity]) || ($existing !== null && !$data->overwriteExisting)) {
                $seenIdentities[$identity] = true;
                $lines[] = $this->line($index, $name, $locationId, StructureListImportResource::STATUS_SKIP, []);
                continue;
            }
            $seenIdentities[$identity] = true;

            if ($existing !== null) {
                $rigErrors = $this->rigErrors($existing->getStructureType(), $input->rigs);
                if ($rigErrors !== []) {
                    $lines[] = $this->line($index, $name, $locationId, StructureListImportResource::STATUS_INVALID, $rigErrors);
                    continue;
                }
                $updates[] = [$existing, $input];
                $lines[] = $this->line($index, $name, $locationId, StructureListImportResource::STATUS_UPDATE, []);
                continue;
            }

            if (!$data->dryRun) {
                $this->createStructureProcessor->process($input, $operation);
            }
            $lines[] = $this->line($index, $name, $locationId, StructureListImportResource::STATUS_CREATE, []);
        }

        if (!$data->dryRun) {
            foreach ($updates as [$structure, $input]) {
                $structure->setRigs($input->rigs);
                if ($input->facilityTaxRate !== null) {
                    $structure->setFacilityTaxPercent($input->facilityTaxRate);
                }
            }
            $this->entityManager->flush();
        }

        $resource = new StructureListImportResource();
        $resource->applied = !$data->dryRun;
        $resource->entries = $lines;

        return $resource;
    }

    /**
     * @return array{0: ?CreateStructureInput, 1: list<string>}
     */
    private function parseEntry(mixed $entry): array
    {
        if (!is_array($entry)) {
            return [null, ['The entry is not an object.']];
        }

        $errors = [];
        $input = new CreateStructureInput();

        $name = $entry['name'] ?? null;
        if (is_string($name)) {
            $input->name = trim($name);
        } else {
            $errors[] = 'The name is missing.';
        }

        $securityType = $entry['securityType'] ?? null;
        if (is_string($securityType)) {
            $input->securityType = $securityType;
        } else {
            $errors[] = 'The security type is missing.';
        }

        $structureType = $entry['structureType'] ?? null;
        if (is_string($structureType)) {
            $input->structureType = $structureType;
        } else {
            $errors[] = 'The structure type is missing.';
        }

        $rigs = $entry['rigs'] ?? null;
        if (is_array($rigs) && array_filter($rigs, 'is_string') === $rigs) {
            $input->rigs = array_values($rigs);
        } else {
            $errors[] = 'The rigs are missing or not a list of names.';
        }

        $locationId = $entry['locationId'] ?? null;
        if ($locationId === null || is_int($locationId)) {
            $input->locationId = $locationId;
        } else {
            $errors[] = 'The location id is not a whole number.';
        }

        $solarSystemId = $entry['solarSystemId'] ?? null;
        if ($solarSystemId === null || is_int($solarSystemId)) {
            $input->solarSystemId = $solarSystemId;
        } else {
            $errors[] = 'The solar system id is not a whole number.';
        }

        $facilityTaxRate = $entry['facilityTaxRate'] ?? null;
        if ($facilityTaxRate === null || is_int($facilityTaxRate) || is_float($facilityTaxRate)) {
            $input->facilityTaxRate = $facilityTaxRate === null ? null : (float) $facilityTaxRate;
        } else {
            $errors[] = 'The facility tax is not a number.';
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        foreach ($this->validator->validate($input) as $violation) {
            $errors[] = sprintf('%s: %s', $violation->getPropertyPath(), $violation->getMessage());
        }

        if ($errors === []) {
            $errors = $this->rigErrors($input->structureType, $input->rigs);
        }

        return [$input, $errors];
    }

    /**
     * @param string[] $rigs
     *
     * @return list<string>
     */
    private function rigErrors(string $structureType, array $rigs): array
    {
        try {
            $this->rigsValidator->validate($structureType, $rigs);
        } catch (ValidationException $exception) {
            $messages = [];
            foreach ($exception->getConstraintViolationList() as $violation) {
                $messages[] = (string) $violation->getMessage();
            }

            return $messages;
        }

        return [];
    }

    private function identityOf(?int $locationId, string $name): string
    {
        return $locationId !== null && $locationId > 0 ? 'location:'.$locationId : 'name:'.$name;
    }

    private function rawName(mixed $entry): string
    {
        return is_array($entry) && is_string($entry['name'] ?? null) ? $entry['name'] : '';
    }

    /**
     * @param list<string> $errors
     *
     * @return array{index: int, name: string, locationId: ?int, status: string, errors: list<string>}
     */
    private function line(int $index, string $name, ?int $locationId, string $status, array $errors): array
    {
        return ['index' => $index, 'name' => $name, 'locationId' => $locationId, 'status' => $status, 'errors' => $errors];
    }
}
