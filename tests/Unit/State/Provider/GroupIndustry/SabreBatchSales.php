<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\GroupIndustry;

use App\Entity\Character;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustrySale;
use App\Entity\User;
use App\Enum\GroupProjectStatus;
use App\Repository\GroupIndustryProjectRepository;
use App\Repository\GroupIndustrySaleRepository;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

trait SabreBatchSales
{
    private const int TYPE_ID_TYPED_BY_NAME = 0;
    private const int SABRE_SALE_QUANTITY = 2;
    private const float SABRE_SALE_UNIT_PRICE = 900000.0;
    private const float SABRE_SALE_TOTAL_PRICE = 1800000.0;
    private const string SABRE_SALE_VENUE = 'Amarr VIII (Oris)';
    private const string SABRE_SALE_SOLD_AT = '2026-10-21T16:45:00+00:00';
    private const string SABRE_SALE_CREATED_AT = '2026-10-21T16:50:00+00:00';
    private const string SABRE_SALE_RECORDER_NAME = 'Brann Holt';

    private GroupIndustrySale $sabreSale;
    private GroupIndustryProject $hecateBatch;

    private function recordSabreSale(): void
    {
        $recorder = $this->createStub(User::class);
        $recorder->method('getMainCharacter')->willReturn((new Character())->setName(self::SABRE_SALE_RECORDER_NAME));

        $this->sabreSale = (new GroupIndustrySale())
            ->setTypeId(self::TYPE_ID_TYPED_BY_NAME)
            ->setTypeName('Sabre')
            ->setQuantity(self::SABRE_SALE_QUANTITY)
            ->setUnitPrice(self::SABRE_SALE_UNIT_PRICE)
            ->setTotalPrice(self::SABRE_SALE_TOTAL_PRICE)
            ->setVenue(self::SABRE_SALE_VENUE)
            ->setSoldAt(new \DateTimeImmutable(self::SABRE_SALE_SOLD_AT))
            ->setRecordedBy($recorder);
        (new \ReflectionProperty(GroupIndustrySale::class, 'id'))->setValue($this->sabreSale, Uuid::v4());
        (new \ReflectionProperty(GroupIndustrySale::class, 'createdAt'))->setValue($this->sabreSale, new \DateTimeImmutable(self::SABRE_SALE_CREATED_AT));
        $this->sabreBatch->addSale($this->sabreSale);
    }

    private function createHecateBatchOfTheSabreBatchOwner(): void
    {
        $this->hecateBatch = new GroupIndustryProject();
        $this->hecateBatch->setOwner($this->sabreBatchOwner);
        $this->hecateBatch->setName('Hecate batch');
        $this->hecateBatch->setStatus(GroupProjectStatus::Selling);
        (new \ReflectionProperty(GroupIndustryProject::class, 'id'))->setValue($this->hecateBatch, Uuid::v4());
    }

    private function projectRepositoryOfTheSabreAndHecateBatches(): GroupIndustryProjectRepository&Stub
    {
        $projectRepository = $this->createStub(GroupIndustryProjectRepository::class);
        $projectRepository->method('find')->willReturnCallback(
            fn (Uuid $id): ?GroupIndustryProject => match (true) {
                $id->equals($this->sabreBatch->getId()) => $this->sabreBatch,
                $id->equals($this->hecateBatch->getId()) => $this->hecateBatch,
                default => null,
            },
        );

        return $projectRepository;
    }

    private function saleRepositoryOfTheSabreSale(): GroupIndustrySaleRepository&Stub
    {
        $saleRepository = $this->createStub(GroupIndustrySaleRepository::class);
        $saleRepository->method('find')->willReturnCallback(
            fn (Uuid|string $id): ?GroupIndustrySale => $this->isTheSabreSaleId($id) ? $this->sabreSale : null,
        );
        $saleRepository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?GroupIndustrySale => $this->sabreSaleMeetsEveryCriterion($criteria) ? $this->sabreSale : null,
        );

        return $saleRepository;
    }

    private function sabreSaleMeetsEveryCriterion(array $criteria): bool
    {
        foreach ($criteria as $field => $expected) {
            $met = match ($field) {
                'id' => $this->isTheSabreSaleId($expected),
                'project' => $expected === $this->sabreSale->getProject(),
                default => self::fail("Unexpected criterion '{$field}'"),
            };
            if (!$met) {
                return false;
            }
        }

        return true;
    }

    private function isTheSabreSaleId(Uuid|string $id): bool
    {
        return $this->sabreSale->getId()->equals($id instanceof Uuid ? $id : Uuid::fromString($id));
    }

    private function sabreSaleUri(?Uuid $projectId = null, ?Uuid $saleId = null): array
    {
        return [
            'projectId' => ($projectId ?? $this->sabreBatch->getId())->toRfc4122(),
            'id' => ($saleId ?? $this->sabreSale->getId())->toRfc4122(),
        ];
    }

    private function httpStatusOf(\Closure $request): int|string
    {
        try {
            $request();
        } catch (HttpExceptionInterface $exception) {
            return $exception->getStatusCode();
        } catch (\Throwable $throwable) {
            return $throwable::class . ': ' . $throwable->getMessage();
        }

        return self::GRANTED;
    }

    private function actionsAndDataOf(array $publishedUpdates): array
    {
        return array_map(static function (Update $update): array {
            $payload = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);

            return ['action' => $payload['action'], 'data' => $payload['data']];
        }, $publishedUpdates);
    }
}
