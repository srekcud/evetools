<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry;

use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\Sde\IndustryActivity;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

trait BuildsCarrierProjectWithFuelBlockSteps
{
    private const int CATEGORY_ID = 999_206_006;
    private const int CARRIER_GROUP_ID = 999_206_547;
    private const int FUEL_BLOCK_GROUP_ID = 999_206_136;
    private const int ISOTOPE_GROUP_ID = 999_206_423;
    private const int POLYMER_GROUP_ID = 999_206_428;

    private const int CARRIER_TYPE_ID = 999_206_640;
    private const int CARRIER_BLUEPRINT_TYPE_ID = 999_206_641;
    private const int FUEL_BLOCK_TYPE_ID = 999_206_412;
    private const int FUEL_BLOCK_BLUEPRINT_TYPE_ID = 999_206_413;
    private const int ISOTOPE_TYPE_ID = 999_206_017;
    private const int POLYMER_TYPE_ID = 999_206_901;
    private const int POLYMER_FORMULA_TYPE_ID = 999_206_902;

    private const int FUEL_BLOCKS_PER_CARRIER_RUN = 120;
    private const int FUEL_BLOCKS_PER_FUEL_BLOCK_RUN = 40;
    private const int ISOTOPES_PER_FUEL_BLOCK_RUN = 9;
    private const int POLYMERS_PER_REACTION_RUN = 200;
    private const int ISOTOPES_PER_REACTION_RUN = 100;

    private const int CARRIER_RUNS = 2;
    private const int FUEL_BLOCK_NEED_RUNS = 6;
    private const int FUEL_BLOCK_NEED = 240;

    private static int $nextEveCharacterId = 2_112_206_001;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $owner;
    private User $intruder;
    private IndustryProject $project;
    private IndustryProjectStep $carrierStep;
    private IndustryProjectStep $fuelBlockStep;

    private function setUpCarrierProjectWithFuelBlockStep(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->seedCarrierFuelBlockAndPolymerRecipes();
        $this->owner = $this->persistPilot('Aria Solberg');
        $this->intruder = $this->persistPilot('Brann Holt');
        $this->project = (new IndustryProject())
            ->setUser($this->owner)
            ->setProductTypeId(self::CARRIER_TYPE_ID)
            ->setRuns(self::CARRIER_RUNS)
            ->setMeLevel(0);
        $this->em->persist($this->project);
        $this->carrierStep = $this->step(self::CARRIER_BLUEPRINT_TYPE_ID, self::CARRIER_TYPE_ID, self::CARRIER_RUNS, self::CARRIER_RUNS, 0);
        $this->fuelBlockStep = $this->step(self::FUEL_BLOCK_BLUEPRINT_TYPE_ID, self::FUEL_BLOCK_TYPE_ID, self::FUEL_BLOCK_NEED_RUNS, self::FUEL_BLOCK_NEED, 1);
        $this->em->flush();
    }

    private function step(int $blueprintTypeId, int $productTypeId, int $runs, int $quantity, int $depth): IndustryProjectStep
    {
        $step = (new IndustryProjectStep())
            ->setBlueprintTypeId($blueprintTypeId)
            ->setProductTypeId($productTypeId)
            ->setRuns($runs)
            ->setQuantity($quantity)
            ->setDepth($depth)
            ->setActivityType('manufacturing')
            ->setSortOrder($depth)
            ->setMeLevel(0)
            ->setTeLevel(0);
        $this->project->addStep($step);

        return $step;
    }

    private function persistPilot(string $name): User
    {
        $character = (new Character())
            ->setEveCharacterId(self::$nextEveCharacterId++)
            ->setName($name)
            ->setCorporationId(98_206_001)
            ->setCorporationName('Corporation 98206001');
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($user);
        $this->em->persist($character);

        return $user;
    }

    private function seedCarrierFuelBlockAndPolymerRecipes(): void
    {
        $category = (new InvCategory())->setCategoryId(self::CATEGORY_ID)->setCategoryName('Test Category 206');
        $this->em->persist($category);
        $this->type(self::CARRIER_TYPE_ID, 'Test Carrier', $this->group(self::CARRIER_GROUP_ID, 'Test Carrier', $category));
        $this->type(self::FUEL_BLOCK_TYPE_ID, 'Test Fuel Block', $this->group(self::FUEL_BLOCK_GROUP_ID, 'Test Fuel Block', $category));
        $this->type(self::ISOTOPE_TYPE_ID, 'Test Isotope', $this->group(self::ISOTOPE_GROUP_ID, 'Test Isotope', $category));
        $this->type(self::POLYMER_TYPE_ID, 'Test Polymer', $this->group(self::POLYMER_GROUP_ID, 'Test Polymer', $category));

        $this->recipe(IndustryActivityType::Manufacturing, self::CARRIER_BLUEPRINT_TYPE_ID, self::CARRIER_TYPE_ID, 1, self::FUEL_BLOCK_TYPE_ID, self::FUEL_BLOCKS_PER_CARRIER_RUN);
        $this->recipe(IndustryActivityType::Manufacturing, self::FUEL_BLOCK_BLUEPRINT_TYPE_ID, self::FUEL_BLOCK_TYPE_ID, self::FUEL_BLOCKS_PER_FUEL_BLOCK_RUN, self::ISOTOPE_TYPE_ID, self::ISOTOPES_PER_FUEL_BLOCK_RUN);
        $this->recipe(IndustryActivityType::Reaction, self::POLYMER_FORMULA_TYPE_ID, self::POLYMER_TYPE_ID, self::POLYMERS_PER_REACTION_RUN, self::ISOTOPE_TYPE_ID, self::ISOTOPES_PER_REACTION_RUN);
    }

    private function recipe(IndustryActivityType $activity, int $blueprintTypeId, int $productTypeId, int $outputPerRun, int $materialTypeId, int $baseQuantityPerRun): void
    {
        $this->em->persist((new IndustryActivityProduct())->setTypeId($blueprintTypeId)->setActivityId($activity->value)->setProductTypeId($productTypeId)->setQuantity($outputPerRun));
        $this->em->persist((new IndustryActivityMaterial())->setTypeId($blueprintTypeId)->setActivityId($activity->value)->setMaterialTypeId($materialTypeId)->setQuantity($baseQuantityPerRun));
        $this->em->persist((new IndustryActivity())->setTypeId($blueprintTypeId)->setActivityId($activity->value)->setTime(600));
    }

    private function group(int $groupId, string $name, InvCategory $category): InvGroup
    {
        $group = (new InvGroup())->setGroupId($groupId)->setGroupName($name)->setCategory($category);
        $this->em->persist($group);

        return $group;
    }

    private function type(int $typeId, string $name, InvGroup $group): void
    {
        $this->em->persist((new InvType())->setTypeId($typeId)->setTypeName($name)->setGroup($group));
    }

    private function projectUri(): string
    {
        return '/api/industry/projects/'.$this->project->getId()?->toRfc4122();
    }

    private function stepUri(IndustryProjectStep $step): string
    {
        return $this->projectUri().'/steps/'.$step->getId()?->toRfc4122();
    }

    private function request(string $method, string $uri, ?array $body = null): void
    {
        $this->client->request(
            $method,
            $uri,
            server: [
                'CONTENT_TYPE' => $method === 'PATCH' ? 'application/merge-patch+json' : 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private function responseJson(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function errorDetail(): array
    {
        $error = $this->responseJson();

        return [$error['status'] ?? null, $error['detail'] ?? null];
    }

    private function storedJobsOfProduct(int $productTypeId): array
    {
        $this->em->clear();
        $steps = $this->em->getRepository(IndustryProjectStep::class)->findBy(
            ['project' => $this->project->getId(), 'productTypeId' => $productTypeId],
            ['sortOrder' => 'ASC', 'splitIndex' => 'ASC'],
        );

        return array_map(
            static fn (IndustryProjectStep $job): array => [$job->getRuns(), $job->getQuantity(), $job->getSplitIndex(), $job->getTotalGroupRuns(), $job->isRunsFixed()],
            $steps,
        );
    }

    private function idOf(IndustryProjectStep $step): string
    {
        return (string) $step->getId()?->toRfc4122();
    }
}
