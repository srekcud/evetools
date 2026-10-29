<?php

declare(strict_types=1);

namespace App\Tests\Integration\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\GetCollection;
use App\Entity\CachedWalletTransaction;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectItem;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\GroupIndustrySale;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Enum\GroupProjectStatus;
use App\Repository\CachedWalletTransactionRepository;
use App\Repository\GroupIndustryProjectRepository;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use App\State\Provider\GroupIndustry\GroupSaleSuggestionProvider;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\RecordsSqlQueries;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\SecurityBundle\Security;

#[Group('issue-241')]
final class GroupSaleSuggestionProviderTest extends IntegrationTestCase
{
    use RecordsSqlQueries;

    private const int MAX_QUERIES = 6;
    private const int SABRE_TYPE_ID = 22456;
    private const int TRANSACTIONS_PER_CHARACTER = 2;

    private static int $lastTransactionId = 6_241_100_000;

    public function testSuggestionsIssueTheSameQueriesForTwoOrFourCharactersOfTheUser(): void
    {
        [$twoCharactersOwnerId, $twoCharactersProjectId] = $this->createOwnerSellingWithCharacters('Rhea Tallis', charactersCount: 2);
        [$fourCharactersOwnerId, $fourCharactersProjectId] = $this->createOwnerSellingWithCharacters('Joss Varn', charactersCount: 4);

        [$twoCharactersQueries, $twoCharactersSuggestions] = $this->queriesSuggestingTheSales($twoCharactersOwnerId, $twoCharactersProjectId);
        [$fourCharactersQueries, $fourCharactersSuggestions] = $this->queriesSuggestingTheSales($fourCharactersOwnerId, $fourCharactersProjectId);

        self::assertSame([4, 8], [$twoCharactersSuggestions, $fourCharactersSuggestions]);
        self::assertCount(
            \count($twoCharactersQueries),
            $fourCharactersQueries,
            sprintf(
                "2 characters: %d queries; 4 characters: %d queries:\n%s",
                \count($twoCharactersQueries),
                \count($fourCharactersQueries),
                implode("\n", $fourCharactersQueries),
            ),
        );
    }

    public function testSuggestionsOfFourCharactersIssueABoundedNumberOfQueries(): void
    {
        [$ownerId, $projectId] = $this->createOwnerSellingWithCharacters('Nadia Prell', charactersCount: 4);

        [$queries] = $this->queriesSuggestingTheSales($ownerId, $projectId);

        self::assertLessThanOrEqual(
            self::MAX_QUERIES,
            \count($queries),
            sprintf("expected at most %d queries, got %d:\n%s", self::MAX_QUERIES, \count($queries), implode("\n", $queries)),
        );
    }

    private function createOwnerSellingWithCharacters(string $prefix, int $charactersCount): array
    {
        $owner = $this->createUser();
        $characters = [];
        for ($characterIndex = 1; $characterIndex <= $charactersCount; ++$characterIndex) {
            $characters[] = $this->createCharacter($owner, sprintf('Seller %d of %s', $characterIndex, $prefix));
        }
        $owner->setMainCharacter($characters[0]);

        $project = (new GroupIndustryProject())->setOwner($owner)->setName(sprintf('%s project', $prefix))->setStatus(GroupProjectStatus::Selling);
        (new \ReflectionProperty(GroupIndustryProject::class, 'createdAt'))->setValue($project, new \DateTimeImmutable('2026-10-20T10:00:00+00:00'));
        $this->em->persist($project);
        $project->addMember((new GroupIndustryProjectMember())->setUser($owner)->setRole(GroupMemberRole::Owner)->setStatus(GroupMemberStatus::Accepted));
        $project->addItem((new GroupIndustryProjectItem())->setTypeId(self::SABRE_TYPE_ID)->setTypeName('Sabre')->setMeLevel(10)->setRuns(1));
        $project->addSale($this->manualSale($owner, 3, 61_250_000.0));
        $project->addSale($this->manualSale($owner, 2, 60_000_000.0));

        foreach ($characters as $characterIndex => $character) {
            for ($transactionIndex = 1; $transactionIndex <= self::TRANSACTIONS_PER_CHARACTER; ++$transactionIndex) {
                $this->em->persist((new CachedWalletTransaction())
                    ->setCharacter($character)
                    ->setTransactionId(++self::$lastTransactionId)
                    ->setIsBuy(false)
                    ->setTypeId(self::SABRE_TYPE_ID)
                    ->setQuantity($transactionIndex + 1)
                    ->setUnitPrice(60_000_000.0 + $characterIndex * 250_000.0)
                    ->setDate(new \DateTimeImmutable(sprintf('2026-10-%02dT12:00:00+00:00', 21 + $characterIndex)))
                    ->setLocationId(60003760)
                    ->setClientId(2_119_000_241));
            }
        }

        $this->em->flush();

        return [(string) $owner->getId(), (string) $project->getId()];
    }

    private function queriesSuggestingTheSales(string $ownerId, string $projectId): array
    {
        $this->em->clear();
        $owner = $this->em->find(User::class, $ownerId);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($owner);
        $provider = new GroupSaleSuggestionProvider(
            $security,
            self::getContainer()->get(GroupIndustryProjectRepository::class),
            self::getContainer()->get(GroupProjectAccessChecker::class),
            self::getContainer()->get(CachedWalletTransactionRepository::class),
        );

        $suggestions = [];
        $queries = $this->sqlExecutedDuring(function () use ($provider, $projectId, &$suggestions): void {
            $suggestions = $provider->provide(new GetCollection(), ['projectId' => $projectId]);
        });

        return [$queries, \count($suggestions)];
    }

    private function manualSale(User $recordedBy, int $quantity, float $unitPrice): GroupIndustrySale
    {
        return (new GroupIndustrySale())
            ->setTypeId(0)
            ->setTypeName('Sabre')
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->recalculateTotalPrice()
            ->setSoldAt(new \DateTimeImmutable('2026-10-24T22:00:00+00:00'))
            ->setRecordedBy($recordedBy);
    }
}
