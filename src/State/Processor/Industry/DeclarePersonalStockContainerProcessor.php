<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\PersonalStockContainerResource;
use App\ApiResource\Input\Industry\DeclarePersonalStockContainerInput;
use App\Entity\User;
use App\Exception\ContainerDeclaredByAnotherUserException;
use App\Exception\ContainerNamedLikeProjectContainerException;
use App\Exception\ContainerNotVisibleException;
use App\Exception\NotAContainerException;
use App\Service\Industry\Stock\PersonalStockContainerDeclarer;
use App\Service\Industry\Stock\PersonalStockContainerReading;
use App\Service\Industry\Stock\PersonalStockReader;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @implements ProcessorInterface<DeclarePersonalStockContainerInput, PersonalStockContainerResource>
 */
final readonly class DeclarePersonalStockContainerProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private PersonalStockContainerDeclarer $declarer,
        private PersonalStockReader $personalStockReader,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PersonalStockContainerResource
    {
        \assert($data instanceof DeclarePersonalStockContainerInput);

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $itemId = $data->itemId;
        if (!\is_int($itemId)) {
            throw new \LogicException('The validated input carries an integer item id.');
        }

        try {
            $declaration = $this->declarer->declare($user, $itemId);
        } catch (ContainerNotVisibleException $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        } catch (NotAContainerException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        } catch (ContainerDeclaredByAnotherUserException|ContainerNamedLikeProjectContainerException $exception) {
            throw new ConflictHttpException($exception->getMessage(), $exception);
        }

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            throw new ConflictHttpException('The container was declared concurrently, retry', $exception);
        }

        $declarationId = $declaration->getId()->toRfc4122();
        foreach ($this->personalStockReader->containersOf($user) as $reading) {
            if ($reading->id === $declarationId) {
                return self::resourceOf($reading);
            }
        }

        throw new \LogicException('A declared container is read back.');
    }

    private static function resourceOf(PersonalStockContainerReading $reading): PersonalStockContainerResource
    {
        $resource = new PersonalStockContainerResource();
        $resource->id = $reading->id;
        $resource->itemId = $reading->itemId;
        $resource->name = $reading->name;
        $resource->divisionName = $reading->divisionName;
        $resource->stationId = $reading->stationId;
        $resource->stationName = $reading->stationName;
        $resource->status = $reading->status;
        $resource->unknownReason = $reading->unknownReason;
        $resource->itemCount = $reading->itemCount;

        return $resource;
    }
}
