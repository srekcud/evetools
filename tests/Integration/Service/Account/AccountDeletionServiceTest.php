<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Account;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\Assets\CorpAssetVisibilityResource;
use App\ApiResource\Industry\Project\ProjectSaleResource;
use App\Entity\CachedAsset;
use App\Entity\CachedCharacterSkill;
use App\Entity\CachedIndustryJob;
use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Entity\CorporationWalletDonation;
use App\Entity\CorpAssetVisibility;
use App\Entity\Escalation;
use App\Entity\EveToken;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\IndustryProjectPurchase;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectProduct;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectSale;
use App\Entity\IndustryProjectTemplate;
use App\Entity\IndustryStructureConfig;
use App\Entity\IndustryUserSettings;
use App\Entity\MarketFavorite;
use App\Entity\MarketPriceAlert;
use App\Entity\MiningEntry;
use App\Entity\MiningSite;
use App\Entity\MiningSiteScan;
use App\Entity\MoonComposition;
use App\Entity\MoonTaxStatement;
use App\Entity\Notification;
use App\Entity\PlanetaryColony;
use App\Entity\PushSubscription;
use App\Entity\PveIncome;
use App\Entity\SharedShoppingList;
use App\Entity\User;
use App\Enum\AlertDirection;
use App\Enum\AlertPriceSource;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\PveIncomeType;
use App\Message\SyncCharacterAssets;
use App\MessageHandler\SyncCharacterAssetsHandler;
use App\Repository\CachedAssetRepository;
use App\Repository\CachedWalletJournalEntryRepository;
use App\Repository\CharacterRepository;
use App\Repository\CorpAssetVisibilityRepository;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectSaleRepository;
use App\Repository\UserRepository;
use App\Service\Account\AccountDeletionService;
use App\Service\ESI\CharacterService;
use App\Service\ESI\CorporationService;
use App\Service\ESI\EsiClient;
use App\Service\ESI\EveSsoTokenRevoker;
use App\Service\ESI\TokenManager;
use App\Service\Industry\Project\DerivedContributionService;
use App\Service\Industry\Project\DistributionResult;
use App\Service\Industry\Project\ProjectDistributionService;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Sync\AssetsSyncService;
use App\State\Provider\Assets\CorpAssetVisibilityProvider;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use App\State\Provider\Industry\Project\ProjectSaleCollectionProvider;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Uid\Uuid;

#[Group('issue-53')]
final class AccountDeletionServiceTest extends IntegrationTestCase
{
    private const string REVOCATION_URL = 'https://login.eveonline.com/v2/oauth/revoke';
    private const string CLIENT_ID = 'evetools-client-id';
    private const string CLIENT_SECRET = 'evetools-client-secret';
    private const string CORP_ASSETS_SCOPE = 'esi-assets.read_corporation_assets.v1';
    private const int SHARED_CORPORATION_ID = 98_000_053;
    private const string ESI_BASE_URL = 'https://esi.test/latest';

    private array $ssoResponses = [];

    private array $ssoRequests = [];

    private BufferingLogger $ssoLogger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ssoLogger = new BufferingLogger();
    }

    public function testDeletedAccountLeavesNoUserCharacterNorEveTokenWhileTheOtherAccountKeepsThem(): void
    {
        $kaelen = $this->createAccount('Kaelen Voss', 'refresh-token-kaelen-main');
        $kaelenAlt = $this->addCharacterWithToken($kaelen, 'Kaelen Alt', 'refresh-token-kaelen-alt');
        $brann = $this->createAccount('Brann Holt', 'refresh-token-brann-main');
        $this->em->flush();
        $kaelenId = (string) $kaelen->getId();
        $kaelenCharacterIds = [(string) $kaelen->getMainCharacter()?->getId(), (string) $kaelenAlt->getId()];
        $brannId = (string) $brann->getId();
        $brannCharacterIds = [(string) $brann->getMainCharacter()?->getId()];

        $this->deleteAccount($kaelenId);

        self::assertSame(0, $this->countWhereIn('users', 'id', [$kaelenId]));
        self::assertSame(0, $this->countWhereIn('characters', 'user_id', [$kaelenId]));
        self::assertSame(0, $this->countWhereIn('eve_tokens', 'character_id', $kaelenCharacterIds));
        self::assertSame(1, $this->countWhereIn('users', 'id', [$brannId]));
        self::assertSame(1, $this->countWhereIn('characters', 'user_id', [$brannId]));
        self::assertSame(1, $this->countWhereIn('eve_tokens', 'character_id', $brannCharacterIds));
    }

    public function testNoForeignKeyColumnReferencesTheDeletedAccountAndTheOtherAccountKeepsAllItsRows(): void
    {
        $kaelen = $this->createAccount('Kaelen Voss', 'refresh-token-kaelen-main');
        $kaelenAlt = $this->addCharacterWithToken($kaelen, 'Kaelen Alt', 'refresh-token-kaelen-alt');
        $brann = $this->createAccount('Brann Holt', 'refresh-token-brann-main');
        $this->seedPersonalData($kaelen, $kaelenAlt, 1);
        $this->seedPersonalData($brann, $this->mainCharacterOf($brann), 2);
        $this->em->flush();
        $kaelenIds = [(string) $kaelen->getId(), (string) $kaelen->getMainCharacter()?->getId(), (string) $kaelenAlt->getId()];
        $brannIds = [(string) $brann->getId(), (string) $brann->getMainCharacter()?->getId()];
        $kaelenRowsBefore = $this->rowsReferencing($kaelenIds);
        $brannRowsBefore = $this->rowsReferencing($brannIds);

        $this->deleteAccount((string) $kaelen->getId());

        $seededColumns = [
            'cached_assets.character_id' => 1,
            'cached_character_skills.character_id' => 1,
            'cached_industry_jobs.character_id' => 1,
            'cached_wallet_transactions.character_id' => 1,
            'characters.user_id' => 2,
            'corporation_wallet_donations.attributed_by_user_id' => 1,
            'escalations.user_id' => 1,
            'eve_tokens.character_id' => 2,
            'industry_project_sales.seller_character_id' => 1,
            'industry_project_templates.owner_id' => 1,
            'industry_projects.owner_id' => 2,
            'industry_structure_configs.user_id' => 1,
            'industry_user_settings.user_id' => 1,
            'market_favorites.user_id' => 1,
            'market_price_alerts.user_id' => 1,
            'mining_entries.user_id' => 1,
            'mining_site_scans.author_id' => 1,
            'mining_sites.owner_id' => 1,
            'moon_compositions.recorded_by_id' => 1,
            'moon_tax_statements.acted_by_user_id' => 1,
            'notifications.user_id' => 1,
            'planetary_colonies.character_id' => 1,
            'push_subscriptions.user_id' => 1,
            'pve_income.user_id' => 1,
            'shared_shopping_lists.created_by_id' => 1,
            'users.main_character_id' => 1,
        ];
        self::assertSame($seededColumns, array_intersect_key($kaelenRowsBefore, $seededColumns));
        self::assertSame(array_fill_keys(array_keys($kaelenRowsBefore), 0), $this->rowsReferencing($kaelenIds));
        self::assertSame(
            array_replace($seededColumns, ['characters.user_id' => 1, 'eve_tokens.character_id' => 1]),
            array_intersect_key($brannRowsBefore, $seededColumns),
        );
        self::assertSame($brannRowsBefore, $this->rowsReferencing($brannIds));
    }

    #[Group('issue-273')]
    public function testMoonTaxStatementAndDonationHandledByTheDeletedAccountAreKeptWithoutTheAuthor(): void
    {
        $kaelen = $this->createAccount('Kaelen Voss', 'refresh-token-kaelen-main');
        $this->seedPersonalData($kaelen, $this->mainCharacterOf($kaelen), 1);
        $this->em->flush();

        $this->deleteAccount((string) $kaelen->getId());

        $connection = $this->connection();
        self::assertSame(
            [[640_000.0, null], [4_000_000.0, null]],
            [
                [(float) $connection->fetchOne('SELECT tax FROM moon_tax_statements'), $connection->fetchOne('SELECT acted_by_user_id FROM moon_tax_statements') ?: null],
                [(float) $connection->fetchOne('SELECT amount FROM corporation_wallet_donations'), $connection->fetchOne('SELECT attributed_by_user_id FROM corporation_wallet_donations') ?: null],
            ],
        );
    }

    public function testOwnershipGoesToTheOldestAcceptedAdmin(): void
    {
        $kaelen = $this->createAccount('Kaelen Voss', 'refresh-token-kaelen-main');
        $brann = $this->createAccount('Brann Holt', 'refresh-token-brann-main');
        $cyra = $this->createAccount('Cyra Lune', 'refresh-token-cyra-main');
        $dorn = $this->createAccount('Dorn Ashby', 'refresh-token-dorn-main');
        $project = $this->createProject($kaelen, 'Rorqual for the corp');
        $kaelenMember = $this->addMember($project, $kaelen, ParticipantRole::Owner, ParticipantStatus::Accepted, '2025-12-01 09:00:00');
        $this->addMember($project, $brann, ParticipantRole::Admin, ParticipantStatus::Accepted, '2026-01-03 09:00:00');
        $this->addMember($project, $cyra, ParticipantRole::Admin, ParticipantStatus::Accepted, '2026-01-02 09:00:00');
        $this->addMember($project, $dorn, ParticipantRole::Member, ParticipantStatus::Accepted, '2026-01-01 09:00:00');
        $this->em->flush();
        $projectId = (string) $project->getId();
        $kaelenMemberId = (string) $kaelenMember->getId();

        $this->deleteAccount((string) $kaelen->getId());

        $project = $this->project($projectId);
        self::assertSame((string) $cyra->getId(), (string) $project->getOwner()->getId());
        self::assertSame(
            [
                'Brann Holt' => ['admin', 'accepted'],
                'Cyra Lune' => ['owner', 'accepted'],
                'Dorn Ashby' => ['member', 'accepted'],
                'deleted account' => ['member', 'removed'],
            ],
            $this->rolesAndStatusesByMemberName($project),
        );
        self::assertNull($this->member($kaelenMemberId)->getUser());
    }

    public function testJobMatchesAndPurchasesOfTheDeletedOwnerAreRemovedWhenTheProjectGoesToASuccessor(): void
    {
        $kaelen = $this->createAccount('Kaelen Voss', 'refresh-token-kaelen-main');
        $brann = $this->createAccount('Brann Holt', 'refresh-token-brann-main');
        $project = $this->createProject($kaelen, 'Rorqual for the corp');
        $this->addMember($project, $kaelen, ParticipantRole::Owner, ParticipantStatus::Accepted, '2025-12-01 09:00:00');
        $this->addMember($project, $brann, ParticipantRole::Admin, ParticipantStatus::Accepted, '2026-01-03 09:00:00');
        $line = (new IndustryProjectBomLine())->setTypeId(11_399)->setTypeName('Morphite')->setRequiredQuantity(675)->setEstimatedPrice(9_800.0);
        $project->addBomItem($line);
        $line->addJobMatch((new IndustryProjectJobMatch())->setEsiJobId(900_001)->setStatus('active')->setRuns(3)->setCharacterName('Kaelen Voss')->setMatchedAt(new \DateTimeImmutable('2026-02-01 10:00:00')));
        $line->addPurchase((new IndustryProjectPurchase())->setTypeId(11_399)->setQuantity(50)->setUnitPrice(9_800.0)->setTotalPrice(490_000.0)->setSource('manual'));
        $this->em->flush();
        $projectId = (string) $project->getId();

        $this->deleteAccount((string) $kaelen->getId());

        self::assertSame((string) $brann->getId(), (string) $this->project($projectId)->getOwner()->getId());
        self::assertSame(1, $this->countWhereIn('industry_project_bom_lines', 'project_id', [$projectId]));
        self::assertSame(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM industry_project_job_matches'));
        self::assertSame(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM industry_project_purchases'));
    }

    #[Group('issue-363')]
    public function testDerivedContributionsOfTheDeletedOwnerAreRemovedWithTheirLinksAndTheFulfilledQuantityIsCorrectedWhileManualOnesAreKept(): void
    {
        $kaelen = $this->createAccount('Kaelen Voss', 'refresh-token-kaelen-main');
        $brann = $this->createAccount('Brann Holt', 'refresh-token-brann-main');
        $project = $this->createProject($kaelen, 'Ishtar batch');
        $kaelenMember = $this->addMember($project, $kaelen, ParticipantRole::Owner, ParticipantStatus::Accepted, '2025-12-01 09:00:00');
        $brannMember = $this->addMember($project, $brann, ParticipantRole::Admin, ParticipantStatus::Accepted, '2026-01-03 09:00:00');
        $tritaniumLine = (new IndustryProjectBomLine())->setTypeId(34)->setTypeName('Tritanium')->setRequiredQuantity(1_000)->setEstimatedPrice(5.5)->setFulfilledQuantity(1_100);
        $ishtarLine = (new IndustryProjectBomLine())->setTypeId(12_005)->setTypeName('Ishtar')->setRequiredQuantity(5)->setIsJob(true)->setJobGroup('final')->setActivityType('manufacturing')->setRuns(5);
        $project->addBomItem($tritaniumLine);
        $project->addBomItem($ishtarLine);
        $tritaniumLine->addPurchase((new IndustryProjectPurchase())->setTypeId(34)->setQuantity(400)->setUnitPrice(6.5)->setTotalPrice(2_600.0)->setSource('manual'));
        $tritaniumLine->addPurchase((new IndustryProjectPurchase())->setTypeId(34)->setQuantity(600)->setUnitPrice(6.0)->setTotalPrice(3_600.0)->setSource('manual'));
        $ishtarLine->addJobMatch((new IndustryProjectJobMatch())->setEsiJobId(900_363)->setStatus('active')->setRuns(3)->setCost(214_000.0)->setMatchedAt(new \DateTimeImmutable('2026-02-01 10:00:00')));
        $derivedMaterial = $this->addContribution($project, $kaelenMember, 6_200.0, ContributionStatus::Approved, ContributionType::Material, 1_000)
            ->setBomItem($tritaniumLine)->setIsAutoDetected(true)->setIsVerified(true);
        $derivedJobInstall = $this->addContribution($project, $kaelenMember, 214_000.0, ContributionStatus::Approved, ContributionType::JobInstall, 3)
            ->setBomItem($ishtarLine)->setIsAutoDetected(true)->setIsVerified(true);
        $keptBpc = $this->addContribution($project, $kaelenMember, 50_000.0, ContributionStatus::Approved, ContributionType::Bpc, 1)->setBomItem($ishtarLine);
        $brannMaterial = $this->addContribution($project, $brannMember, 700.0, ContributionStatus::Approved, ContributionType::Material, 100)->setBomItem($tritaniumLine);
        $this->em->flush();
        $projectId = (string) $project->getId();
        $tritaniumLineId = (string) $tritaniumLine->getId();
        $ishtarLineId = (string) $ishtarLine->getId();
        $keptBpcId = (string) $keptBpc->getId();
        $brannMaterialId = (string) $brannMaterial->getId();
        $derivedIds = [(string) $derivedMaterial->getId(), (string) $derivedJobInstall->getId()];

        $this->deleteAccount((string) $kaelen->getId());

        self::assertSame((string) $brann->getId(), (string) $this->project($projectId)->getOwner()->getId());
        self::assertSame(0, $this->countWhereIn('industry_project_contributions', 'id', $derivedIds));
        self::assertSame(2, $this->countWhereIn('industry_project_contributions', 'id', [$keptBpcId, $brannMaterialId]));
        $tritaniumLine = $this->em->find(IndustryProjectBomLine::class, $tritaniumLineId);
        $ishtarLine = $this->em->find(IndustryProjectBomLine::class, $ishtarLineId);
        self::assertSame([100, 0], [$tritaniumLine->getFulfilledQuantity(), $ishtarLine->getFulfilledQuantity()]);
    }

    public function testOwnershipGoesToTheOldestAcceptedMemberWhenNoAdminIsAccepted(): void
    {
        $kaelen = $this->createAccount('Kaelen Voss', 'refresh-token-kaelen-main');
        $brann = $this->createAccount('Brann Holt', 'refresh-token-brann-main');
        $cyra = $this->createAccount('Cyra Lune', 'refresh-token-cyra-main');
        $dorn = $this->createAccount('Dorn Ashby', 'refresh-token-dorn-main');
        $project = $this->createProject($kaelen, 'Orca for the corp');
        $this->addMember($project, $kaelen, ParticipantRole::Owner, ParticipantStatus::Accepted, '2025-12-01 09:00:00');
        $this->addMember($project, $brann, ParticipantRole::Member, ParticipantStatus::Accepted, '2026-01-02 09:00:00');
        $this->addMember($project, $cyra, ParticipantRole::Member, ParticipantStatus::Accepted, '2026-01-01 09:00:00');
        $this->addMember($project, $dorn, ParticipantRole::Member, ParticipantStatus::Pending, '2025-12-31 09:00:00');
        $this->em->flush();
        $projectId = (string) $project->getId();

        $this->deleteAccount((string) $kaelen->getId());

        $project = $this->project($projectId);
        self::assertSame((string) $cyra->getId(), (string) $project->getOwner()->getId());
        self::assertSame(
            [
                'Brann Holt' => ['member', 'accepted'],
                'Cyra Lune' => ['owner', 'accepted'],
                'Dorn Ashby' => ['member', 'pending'],
                'deleted account' => ['member', 'removed'],
            ],
            $this->rolesAndStatusesByMemberName($project),
        );
    }

    public function testProjectWithoutAnyOtherAcceptedMemberIsDeletedWithItsItemsMembersContributionsAndSales(): void
    {
        $kaelen = $this->createAccount('Kaelen Voss', 'refresh-token-kaelen-main');
        $dorn = $this->createAccount('Dorn Ashby', 'refresh-token-dorn-main');
        $project = $this->createProject($kaelen, 'Hulk for the corp');
        $kaelenMember = $this->addMember($project, $kaelen, ParticipantRole::Owner, ParticipantStatus::Accepted, '2025-12-01 09:00:00');
        $this->addMember($project, $dorn, ParticipantRole::Member, ParticipantStatus::Pending, '2026-01-01 09:00:00');
        $project->addItem((new IndustryProjectProduct())->setTypeId(22_544)->setTypeName('Hulk')->setRuns(3));
        $project->addBomItem((new IndustryProjectBomLine())->setTypeId(11_399)->setTypeName('Morphite')->setRequiredQuantity(675)->setEstimatedPrice(9_800.0));
        $this->addContribution($project, $kaelenMember, 2_400_000.0, ContributionStatus::Approved, ContributionType::Material, 40_000);
        $this->addSale($project, $kaelen, 22_544, 'Hulk', 3, 390_000_000.0);
        $this->em->flush();
        $projectId = (string) $project->getId();

        $this->deleteAccount((string) $kaelen->getId());

        self::assertSame(0, $this->countWhereIn('industry_projects', 'id', [$projectId]));
        self::assertSame(0, $this->countWhereIn('industry_project_products', 'project_id', [$projectId]));
        self::assertSame(0, $this->countWhereIn('industry_project_bom_lines', 'project_id', [$projectId]));
        self::assertSame(0, $this->countWhereIn('industry_project_participants', 'project_id', [$projectId]));
        self::assertSame(0, $this->countWhereIn('industry_project_contributions', 'project_id', [$projectId]));
        self::assertSame(0, $this->countWhereIn('industry_project_sales', 'project_id', [$projectId]));
        self::assertSame(1, $this->countWhereIn('users', 'id', [(string) $dorn->getId()]));
    }

    #[Group('issue-362')]
    public function testProjectWhoseOnlyParticipantIsTheOwnerIsDeletedWithItsProductsAndItsParticipant(): void
    {
        $kaelen = $this->createAccount('Kaelen Voss', 'refresh-token-kaelen-main');
        $soloProject = $this->createProject($kaelen, 'Rifter by steps');
        $this->addMember($soloProject, $kaelen, ParticipantRole::Owner, ParticipantStatus::Accepted, '2025-12-01 09:00:00');
        $soloProject->addItem((new IndustryProjectProduct())->setTypeId(587)->setTypeName('Rifter')->setRuns(10));
        $soloProject->addItem((new IndustryProjectProduct())->setTypeId(34)->setTypeName('Tritanium')->setRuns(1));
        $this->em->flush();
        $soloProjectId = (string) $soloProject->getId();
        self::assertSame(2, $this->countWhereIn('industry_project_products', 'project_id', [$soloProjectId]));

        $this->deleteAccount((string) $kaelen->getId());

        self::assertSame(0, $this->countWhereIn('industry_projects', 'id', [$soloProjectId]));
        self::assertSame(0, $this->countWhereIn('industry_project_products', 'project_id', [$soloProjectId]));
        self::assertSame(0, $this->countWhereIn('industry_project_participants', 'project_id', [$soloProjectId]));
    }

    public function testAcceptedMembershipIsAnonymizedAndKeepsOnlyItsApprovedContribution(): void
    {
        $kaelen = $this->createAccount('Kaelen Voss', 'refresh-token-kaelen-main');
        $brann = $this->createAccount('Brann Holt', 'refresh-token-brann-main');
        $project = $this->createProject($brann, 'Sabre doctrine');
        $this->addMember($project, $brann, ParticipantRole::Owner, ParticipantStatus::Accepted, '2025-12-01 09:00:00');
        $kaelenMember = $this->addMember($project, $kaelen, ParticipantRole::Admin, ParticipantStatus::Accepted, '2026-01-02 09:00:00');
        $approved = $this->addContribution($project, $kaelenMember, 1_000_000.0, ContributionStatus::Approved, ContributionType::Material, 250_000);
        $this->addContribution($project, $kaelenMember, 400_000.0, ContributionStatus::Pending, ContributionType::JobInstall, 1);
        $this->addContribution($project, $kaelenMember, 250_000.0, ContributionStatus::Rejected, ContributionType::Bpc, 10);
        $this->em->flush();
        $kaelenMemberId = (string) $kaelenMember->getId();
        $approvedId = (string) $approved->getId();

        $this->deleteAccount((string) $kaelen->getId());

        $member = $this->member($kaelenMemberId);
        self::assertNull($member->getUser());
        self::assertSame(ParticipantStatus::Removed, $member->getStatus());
        self::assertSame(ParticipantRole::Member, $member->getRole());
        self::assertSame(
            [$approvedId => ['approved', 1_000_000.0, 250_000]],
            $this->contributionsOf($kaelenMemberId),
        );
    }

    public function testKeptApprovedContributionOfTheDeletedAccountLosesItsNoteWhileOtherNotesAreKept(): void
    {
        $kaelen = $this->createAccount('Kaelen Voss', 'refresh-token-kaelen-main');
        $brann = $this->createAccount('Brann Holt', 'refresh-token-brann-main');
        $project = $this->createProject($brann, 'Sabre doctrine');
        $brannMember = $this->addMember($project, $brann, ParticipantRole::Owner, ParticipantStatus::Accepted, '2025-12-01 09:00:00');
        $kaelenMember = $this->addMember($project, $kaelen, ParticipantRole::Member, ParticipantStatus::Accepted, '2026-01-02 09:00:00');
        $kaelenContribution = $this->addContribution($project, $kaelenMember, 1_000_000.0, ContributionStatus::Approved, ContributionType::Material, 250_000)
            ->setNote('Hauled by Kaelen Voss from his Amarr hangar');
        $brannContribution = $this->addContribution($project, $brannMember, 3_000_000.0, ContributionStatus::Approved, ContributionType::LineRental, 4)
            ->setNote('Two Raitaru lines of Brann Holt');
        $this->em->flush();
        $kaelenContributionId = (string) $kaelenContribution->getId();
        $brannContributionId = (string) $brannContribution->getId();

        $this->deleteAccount((string) $kaelen->getId());

        self::assertSame(
            ['note' => null, 'estimated_value' => 1_000_000.0],
            $this->noteAndValueOf($kaelenContributionId),
        );
        self::assertSame(
            ['note' => 'Two Raitaru lines of Brann Holt', 'estimated_value' => 3_000_000.0],
            $this->noteAndValueOf($brannContributionId),
        );
    }

    public function testDistributionIsUnchangedAndTheDeletedAccountHasNoCharacterName(): void
    {
        $kaelen = $this->createAccount('Kaelen Voss', 'refresh-token-kaelen-main');
        $brann = $this->createAccount('Brann Holt', 'refresh-token-brann-main');
        $project = $this->createProject($brann, 'Sabre doctrine')->setBrokerFeePercent(0.0)->setSalesTaxPercent(0.0);
        $brannMember = $this->addMember($project, $brann, ParticipantRole::Owner, ParticipantStatus::Accepted, '2025-12-01 09:00:00');
        $kaelenMember = $this->addMember($project, $kaelen, ParticipantRole::Member, ParticipantStatus::Accepted, '2026-01-02 09:00:00');
        $this->addContribution($project, $kaelenMember, 1_000_000.0, ContributionStatus::Approved, ContributionType::Material, 250_000);
        $this->addContribution($project, $kaelenMember, 400_000.0, ContributionStatus::Pending, ContributionType::JobInstall, 1);
        $this->addContribution($project, $kaelenMember, 250_000.0, ContributionStatus::Rejected, ContributionType::Bpc, 10);
        $this->addContribution($project, $brannMember, 3_000_000.0, ContributionStatus::Approved, ContributionType::LineRental, 4);
        $this->addSale($project, $brann, 22_456, 'Sabre', 80, 55_000.0);
        $this->em->flush();
        $projectId = (string) $project->getId();
        $kaelenMemberId = (string) $kaelenMember->getId();
        $brannMemberId = (string) $brannMember->getId();
        $this->em->clear();
        $before = $this->distributionOf($projectId);

        $this->deleteAccount((string) $kaelen->getId());

        $after = $this->distributionOf($projectId);
        $expectedPayouts = [$kaelenMemberId => 1_100_000.0, $brannMemberId => 3_300_000.0];
        ksort($expectedPayouts);
        $namesBefore = [$kaelenMemberId => 'Kaelen Voss', $brannMemberId => 'Brann Holt'];
        ksort($namesBefore);
        $namesAfter = [$kaelenMemberId => null, $brannMemberId => 'Brann Holt'];
        ksort($namesAfter);
        self::assertSame($expectedPayouts, $this->payoutsByMemberId($before));
        self::assertSame($expectedPayouts, $this->payoutsByMemberId($after));
        self::assertSame(4_400_000.0, $after->totalRevenue);
        self::assertSame(4_000_000.0, $after->totalProjectCost);
        self::assertSame(10.0, $after->marginPercent);
        self::assertSame($namesBefore, $this->characterNamesByMemberId($before));
        self::assertSame($namesAfter, $this->characterNamesByMemberId($after));
    }

    public function testPendingMembershipIsDeleted(): void
    {
        $kaelen = $this->createAccount('Kaelen Voss', 'refresh-token-kaelen-main');
        $brann = $this->createAccount('Brann Holt', 'refresh-token-brann-main');
        $project = $this->createProject($brann, 'Sabre doctrine');
        $brannMember = $this->addMember($project, $brann, ParticipantRole::Owner, ParticipantStatus::Accepted, '2025-12-01 09:00:00');
        $kaelenMember = $this->addMember($project, $kaelen, ParticipantRole::Member, ParticipantStatus::Pending, '2026-01-02 09:00:00');
        $this->em->flush();
        $projectId = (string) $project->getId();
        $kaelenMemberId = (string) $kaelenMember->getId();
        $brannMemberId = (string) $brannMember->getId();

        $this->deleteAccount((string) $kaelen->getId());

        self::assertSame(0, $this->countWhereIn('industry_project_participants', 'id', [$kaelenMemberId]));
        self::assertSame(1, $this->countWhereIn('industry_project_participants', 'id', [$brannMemberId]));
        self::assertSame(1, $this->countWhereIn('industry_projects', 'id', [$projectId]));
    }

    public function testSaleRecordedByTheDeletedAccountIsKeptWithoutAuthor(): void
    {
        $kaelen = $this->createAccount('Kaelen Voss', 'refresh-token-kaelen-main');
        $brann = $this->createAccount('Brann Holt', 'refresh-token-brann-main');
        $project = $this->createProject($brann, 'Sabre doctrine');
        $project->addItem((new IndustryProjectProduct())->setTypeId(587)->setTypeName('Sabre')->setRuns(1));
        $this->addMember($project, $brann, ParticipantRole::Owner, ParticipantStatus::Accepted, '2025-12-01 09:00:00');
        $this->addMember($project, $kaelen, ParticipantRole::Admin, ParticipantStatus::Accepted, '2026-01-02 09:00:00');
        $sale = $this->addSale($project, $kaelen, 22_456, 'Sabre', 100, 50_000.0);
        $this->em->flush();
        $projectId = (string) $project->getId();
        $saleId = (string) $sale->getId();
        $brannId = (string) $brann->getId();

        $this->deleteAccount((string) $kaelen->getId());

        $sale = $this->em->find(IndustryProjectSale::class, $saleId);
        self::assertInstanceOf(IndustryProjectSale::class, $sale);
        self::assertNull($sale->getRecordedBy());
        self::assertSame(5_000_000.0, $sale->getTotalPrice());
        self::assertSame(5_000_000.0, $this->distributionOf($projectId)->totalRevenue);
        $listedSales = $this->listSalesOf($projectId, $brannId);
        self::assertCount(1, $listedSales);
        self::assertNull($listedSales[0]->recordedByCharacterName);
        self::assertSame(5_000_000.0, $listedSales[0]->totalPrice);
    }

    #[Group('issue-241')]
    public function testSaleSoldByACharacterOfTheDeletedAccountIsKeptWithoutSellerNorAuthor(): void
    {
        $kaelen = $this->createAccount('Kaelen Voss', 'refresh-token-kaelen-main');
        $brann = $this->createAccount('Brann Holt', 'refresh-token-brann-main');
        $project = $this->createProject($brann, 'Sabre');
        $project->addItem((new IndustryProjectProduct())->setTypeId(587)->setTypeName('Sabre')->setRuns(1));
        $this->addMember($project, $brann, ParticipantRole::Owner, ParticipantStatus::Accepted, '2025-12-01 09:00:00');
        $this->addMember($project, $kaelen, ParticipantRole::Admin, ParticipantStatus::Accepted, '2026-01-02 09:00:00');
        $sale = $this->addSale($project, $kaelen, 0, 'Sabre', 3, 61_250_000.0)->setSeller($this->mainCharacterOf($kaelen));
        $this->em->flush();
        $projectId = (string) $project->getId();
        $saleId = (string) $sale->getId();
        $brannId = (string) $brann->getId();

        $this->deleteAccount((string) $kaelen->getId());

        $sale = $this->em->find(IndustryProjectSale::class, $saleId);
        self::assertInstanceOf(IndustryProjectSale::class, $sale);
        self::assertSame([null, null, 183_750_000.0], [$sale->getSeller(), $sale->getRecordedBy(), $sale->getTotalPrice()]);
        self::assertSame(183_750_000.0, $this->distributionOf($projectId)->totalRevenue);
        $listedSales = $this->listSalesOf($projectId, $brannId);
        self::assertSame(
            [[null, null, null, 183_750_000.0]],
            array_map(
                static fn (ProjectSaleResource $listedSale): array => [
                    $listedSale->sellerCharacterId,
                    $listedSale->sellerCharacterName,
                    $listedSale->recordedByCharacterName,
                    $listedSale->totalPrice,
                ],
                $listedSales,
            ),
        );
    }

    #[Group('issue-241')]
    public function testX1SaleLinkedToAWalletTransactionOfTheDeletedAccountIsKeptUnlinkedWithoutSellerNorAuthor(): void
    {
        $kaelen = $this->createAccount('Kaelen Voss', 'refresh-token-kaelen-main');
        $brann = $this->createAccount('Brann Holt', 'refresh-token-brann-main');
        $project = $this->createProject($brann, 'Sabre');
        $project->addItem((new IndustryProjectProduct())->setTypeId(587)->setTypeName('Sabre')->setRuns(1));
        $this->addMember($project, $brann, ParticipantRole::Owner, ParticipantStatus::Accepted, '2025-12-01 09:00:00');
        $this->addMember($project, $kaelen, ParticipantRole::Admin, ParticipantStatus::Accepted, '2026-01-02 09:00:00');
        $t5 = (new CachedWalletTransaction())
            ->setCharacter($this->mainCharacterOf($kaelen))
            ->setTransactionId(6_100_000_005)
            ->setTypeId(22_456)
            ->setQuantity(2)
            ->setUnitPrice(61_000_000.0)
            ->setIsBuy(false)
            ->setLocationId(1_035_466_617_946)
            ->setClientId(2_119_000_005)
            ->setDate(new \DateTimeImmutable('2026-10-25 18:00:00'));
        $this->em->persist($t5);
        $sale = $this->addSale($project, $kaelen, 22_456, 'Sabre', 2, 61_000_000.0)
            ->setSeller($this->mainCharacterOf($kaelen))
            ->setWalletTransaction($t5);
        $this->em->flush();
        $projectId = (string) $project->getId();
        $saleId = (string) $sale->getId();
        $transactionId = (string) $t5->getId();
        $brannId = (string) $brann->getId();

        $this->deleteAccount((string) $kaelen->getId());

        $sale = $this->em->find(IndustryProjectSale::class, $saleId);
        self::assertInstanceOf(IndustryProjectSale::class, $sale);
        self::assertSame(
            [null, null, null, 2, 122_000_000.0],
            [$sale->getWalletTransaction(), $sale->getSeller(), $sale->getRecordedBy(), $sale->getQuantity(), $sale->getTotalPrice()],
        );
        self::assertSame(0, $this->countWhereIn('cached_wallet_transactions', 'id', [$transactionId]));
        self::assertSame(122_000_000.0, $this->distributionOf($projectId)->totalRevenue);
        self::assertSame(
            [[false, null, null, 122_000_000.0]],
            array_map(
                static fn (ProjectSaleResource $listedSale): array => [
                    $listedSale->linkedToWalletTransaction,
                    $listedSale->sellerCharacterName,
                    $listedSale->recordedByCharacterName,
                    $listedSale->totalPrice,
                ],
                $this->listSalesOf($projectId, $brannId),
            ),
        );
    }

    public function testCorporationAssetVisibilityIsKeptWithoutAuthorAndCorporationAssetsSyncFallsBackOnAnotherCharacter(): void
    {
        $kaelen = $this->createAccount('Kaelen Voss', 'refresh-token-kaelen-main', self::SHARED_CORPORATION_ID, [self::CORP_ASSETS_SCOPE]);
        $brann = $this->createAccount('Brann Holt', 'refresh-token-brann-main', self::SHARED_CORPORATION_ID, [self::CORP_ASSETS_SCOPE]);
        $visibility = (new CorpAssetVisibility())
            ->setCorporationId(self::SHARED_CORPORATION_ID)
            ->setVisibleDivisions([1, 3])
            ->setConfiguredBy($kaelen);
        $this->em->persist($visibility);
        $this->em->flush();
        $visibilityId = (string) $visibility->getId();
        $brannId = (string) $brann->getId();
        $brannCharacterId = (string) $brann->getMainCharacter()?->getId();
        $kaelenCharacterId = (string) $kaelen->getMainCharacter()?->getId();
        self::assertSame($kaelenCharacterId, (string) $this->assetsSyncService()->getCorpAssetsCharacter(self::SHARED_CORPORATION_ID)?->getId());
        $this->em->clear();

        $this->deleteAccount((string) $kaelen->getId());

        $visibility = $this->em->find(CorpAssetVisibility::class, $visibilityId);
        self::assertInstanceOf(CorpAssetVisibility::class, $visibility);
        self::assertSame([1, 3], $visibility->getVisibleDivisions());
        self::assertNull($visibility->getConfiguredBy());
        $resource = $this->corpAssetVisibilityFor($brannId);
        self::assertNull($resource->configuredByName);
        self::assertSame([1, 3], $resource->visibleDivisions);
        self::assertSame($brannCharacterId, (string) $this->assetsSyncService()->getCorpAssetsCharacter(self::SHARED_CORPORATION_ID)?->getId());
    }

    public function testDeletedAccountIsRemovedFromTheUsersWhoHidASharedStructure(): void
    {
        $kaelen = $this->createAccount('Kaelen Voss', 'refresh-token-kaelen-main');
        $brann = $this->createAccount('Brann Holt', 'refresh-token-brann-main');
        $cyra = $this->createAccount('Cyra Lune', 'refresh-token-cyra-main');
        $this->em->flush();
        $kaelenId = (string) $kaelen->getId();
        $cyraId = (string) $cyra->getId();
        $brannStructure = $this->createStructure($brann, 'Brann Sotiyo', 'sotiyo')->hideForUser($kaelenId)->hideForUser($cyraId);
        $cyraStructure = $this->createStructure($cyra, 'Cyra Tatara', 'tatara')->hideForUser($kaelenId);
        $this->em->flush();
        $brannStructureId = (string) $brannStructure->getId();
        $cyraStructureId = (string) $cyraStructure->getId();

        $this->deleteAccount($kaelenId);

        self::assertSame([$cyraId], $this->structure($brannStructureId)->getHiddenByUsers());
        self::assertSame([], $this->structure($cyraStructureId)->getHiddenByUsers());
    }

    public function testSharedShoppingListOfTheDeletedAccountIsDeletedAndTheOtherOneIsKept(): void
    {
        $kaelen = $this->createAccount('Kaelen Voss', 'refresh-token-kaelen-main');
        $brann = $this->createAccount('Brann Holt', 'refresh-token-brann-main');
        $kaelenList = $this->createSharedShoppingList($kaelen, 34, 'Tritanium', 120_000);
        $brannList = $this->createSharedShoppingList($brann, 11_399, 'Morphite', 675);
        $this->em->flush();
        $kaelenListId = (string) $kaelenList->getId();
        $brannListId = (string) $brannList->getId();

        $this->deleteAccount((string) $kaelen->getId());

        self::assertSame(0, $this->countWhereIn('shared_shopping_lists', 'id', [$kaelenListId]));
        $brannList = $this->em->find(SharedShoppingList::class, $brannListId);
        self::assertInstanceOf(SharedShoppingList::class, $brannList);
        self::assertSame((string) $brann->getId(), (string) $brannList->getCreatedBy()?->getId());
        self::assertSame([['typeId' => 11_399, 'typeName' => 'Morphite', 'quantity' => 675]], $brannList->getData());
    }

    public function testFailureAfterTheAnonymizationLeavesTheDatabaseUnchangedAndRevokesNothing(): void
    {
        $kaelen = $this->createAccount('Kaelen Voss', 'refresh-token-kaelen-main');
        $brann = $this->createAccount('Brann Holt', 'refresh-token-brann-main');
        $cyra = $this->createAccount('Cyra Lune', 'refresh-token-cyra-main');
        $ownedProject = $this->createProject($kaelen, 'Rorqual for the corp');
        $this->addMember($ownedProject, $kaelen, ParticipantRole::Owner, ParticipantStatus::Accepted, '2025-12-01 09:00:00');
        $brannAdmin = $this->addMember($ownedProject, $brann, ParticipantRole::Admin, ParticipantStatus::Accepted, '2026-01-02 09:00:00');
        $joinedProject = $this->createProject($cyra, 'Sabre doctrine');
        $this->addMember($joinedProject, $cyra, ParticipantRole::Owner, ParticipantStatus::Accepted, '2025-12-01 09:00:00');
        $kaelenMember = $this->addMember($joinedProject, $kaelen, ParticipantRole::Admin, ParticipantStatus::Accepted, '2026-01-03 09:00:00');
        $pending = $this->addContribution($joinedProject, $kaelenMember, 400_000.0, ContributionStatus::Pending, ContributionType::JobInstall, 1);
        $this->em->flush();
        $kaelenId = (string) $kaelen->getId();
        $kaelenCharacterId = (string) $kaelen->getMainCharacter()?->getId();
        $ownedProjectId = (string) $ownedProject->getId();
        $brannAdminId = (string) $brannAdmin->getId();
        $kaelenMemberId = (string) $kaelenMember->getId();
        $pendingId = (string) $pending->getId();
        $this->failOnTheAnonymizationOfAMembership();

        try {
            $this->deleteAccount($kaelenId);
            self::fail('The simulated failure must reach the caller');
        } catch (\Throwable $failure) {
            self::assertStringContainsString('simulated failure after the anonymization', $failure->getMessage() . ($failure->getPrevious()?->getMessage() ?? ''));
        }

        $connection = $this->connection();
        self::assertSame(1, $this->countWhereIn('users', 'id', [$kaelenId]));
        self::assertSame(1, $this->countWhereIn('characters', 'id', [$kaelenCharacterId]));
        self::assertSame(1, $this->countWhereIn('eve_tokens', 'character_id', [$kaelenCharacterId]));
        self::assertSame($kaelenId, $connection->fetchOne('SELECT owner_id::text FROM industry_projects WHERE id = ?', [$ownedProjectId]));
        self::assertSame(['role' => 'admin', 'status' => 'accepted'], $connection->fetchAssociative('SELECT role, status FROM industry_project_participants WHERE id = ?', [$brannAdminId]));
        self::assertSame(
            ['user_id' => $kaelenId, 'role' => 'admin', 'status' => 'accepted'],
            $connection->fetchAssociative('SELECT user_id::text AS user_id, role, status FROM industry_project_participants WHERE id = ?', [$kaelenMemberId]),
        );
        self::assertSame(1, $this->countWhereIn('industry_project_contributions', 'id', [$pendingId]));
        self::assertSame([], $this->ssoRequests);
    }

    public function testEveSsoRevocationFailureDoesNotPreventTheDeletionNorTheOtherRevocation(): void
    {
        $kaelen = $this->createAccount('Kaelen Voss', 'refresh-token-kaelen-main');
        $this->addCharacterWithToken($kaelen, 'Kaelen Alt', 'refresh-token-kaelen-alt');
        $this->em->flush();
        $kaelenId = (string) $kaelen->getId();
        $this->ssoResponses = [
            new MockResponse('{"error":"server_error"}', ['http_code' => 500]),
            new MockResponse('', ['http_code' => 200]),
        ];

        $this->deleteAccount($kaelenId);

        self::assertSame(0, $this->countWhereIn('users', 'id', [$kaelenId]));
        self::assertSame([self::REVOCATION_URL, self::REVOCATION_URL], array_column($this->ssoRequests, 'url'));
        self::assertEqualsCanonicalizing(
            ['refresh-token-kaelen-main', 'refresh-token-kaelen-alt'],
            array_column($this->ssoRequests, 'token'),
        );
        self::assertCount(1, $this->ssoWarnings());
    }

    public function testEveryRefreshTokenOfTheAccountIsRevokedWithoutWarning(): void
    {
        $kaelen = $this->createAccount('Kaelen Voss', 'refresh-token-kaelen-main');
        $this->addCharacterWithToken($kaelen, 'Kaelen Alt', 'refresh-token-kaelen-alt');
        $this->createAccount('Brann Holt', 'refresh-token-brann-main');
        $this->em->flush();

        $this->deleteAccount((string) $kaelen->getId());

        self::assertEqualsCanonicalizing(
            ['refresh-token-kaelen-main', 'refresh-token-kaelen-alt'],
            array_column($this->ssoRequests, 'token'),
        );
        self::assertSame([], $this->ssoWarnings());
    }

    public function testAccountWithoutEveTokenIsDeletedWithoutRevocation(): void
    {
        $kaelen = $this->createUser();
        $kaelen->setMainCharacter($this->createCharacter($kaelen, 'Kaelen Voss'));
        $this->em->flush();
        $kaelenId = (string) $kaelen->getId();

        $this->deleteAccount($kaelenId);

        self::assertSame(0, $this->countWhereIn('users', 'id', [$kaelenId]));
        self::assertSame([], $this->ssoRequests);
    }

    public function testCachedEsiResponsesOfTheDeletedAccountAreForgottenAndThoseOfTheOtherAccountAreKept(): void
    {
        $kaelen = $this->createAccount('Kaelen Voss', 'refresh-token-kaelen-main');
        $brann = $this->createAccount('Brann Holt', 'refresh-token-brann-main');
        $this->em->flush();
        $kaelenCharacter = $this->mainCharacterOf($kaelen);
        $brannCharacter = $this->mainCharacterOf($brann);
        $esiRequests = [];
        $esiClient = $this->esiClient($esiRequests, [
            $this->esiJsonResponse([['type_id' => 34, 'quantity' => 1_000]], '"etag-kaelen-assets"'),
            $this->esiJsonResponse([['type_id' => 35, 'quantity' => 2]], '"etag-brann-assets"'),
            $this->esiJsonResponse([['type_id' => 36, 'quantity' => 70]], null),
        ]);
        $esiClient->getWithCache('/characters/2112000053/assets/', $this->esiTokenFor($kaelenCharacter));
        $esiClient->getWithCache('/characters/2112000054/assets/', $this->esiTokenFor($brannCharacter));

        $this->deleteAccount((string) $kaelen->getId());

        $kaelenAssets = $esiClient->getWithCache('/characters/2112000053/assets/', $this->esiTokenFor($kaelenCharacter));
        $brannAssets = $esiClient->getWithCache('/characters/2112000054/assets/', $this->esiTokenFor($brannCharacter));
        self::assertSame([['type_id' => 36, 'quantity' => 70]], $kaelenAssets);
        self::assertSame([['type_id' => 35, 'quantity' => 2]], $brannAssets);
        self::assertCount(3, $esiRequests);
        self::assertSame(self::ESI_BASE_URL . '/characters/2112000053/assets/', $esiRequests[2]['url']);
        self::assertArrayNotHasKey('if-none-match', $esiRequests[2]['headers']);
    }

    public function testCharacterAssetsSyncOfADeletedCharacterHasNoEffect(): void
    {
        $kaelen = $this->createAccount('Kaelen Voss', 'refresh-token-kaelen-main');
        $this->em->flush();
        $kaelenCharacterId = (string) $kaelen->getMainCharacter()?->getId();
        $this->deleteAccount((string) $kaelen->getId());

        $handler = self::getContainer()->get(SyncCharacterAssetsHandler::class);
        \assert($handler instanceof SyncCharacterAssetsHandler);
        $handler(new SyncCharacterAssets($kaelenCharacterId));

        self::assertSame(0, $this->countWhereIn('characters', 'id', [$kaelenCharacterId]));
        self::assertSame(0, $this->countWhereIn('cached_assets', 'character_id', [$kaelenCharacterId]));
    }

    private function deleteAccount(string $userId): void
    {
        $this->em->flush();
        $this->em->clear();
        $user = $this->em->find(User::class, $userId);
        \assert($user instanceof User);

        $this->accountDeletionService()->delete($user);

        if ($this->em->isOpen()) {
            $this->em->clear();
        }
    }

    private function accountDeletionService(): AccountDeletionService
    {
        $ssoHttpClient = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            parse_str(is_string($options['body'] ?? null) ? $options['body'] : '', $form);
            $this->ssoRequests[] = ['method' => $method, 'url' => $url, 'token' => $form['token'] ?? null];

            return array_shift($this->ssoResponses) ?? new MockResponse('', ['http_code' => 200]);
        });

        return new AccountDeletionService(
            entityManager: $this->em,
            tokenRevoker: new EveSsoTokenRevoker($ssoHttpClient, $this->ssoLogger, self::CLIENT_ID, self::CLIENT_SECRET),
            tokenManager: $this->tokenManager(),
            esiCache: $this->esiCache(),
            derivedContributionService: new DerivedContributionService(
                $this->em,
                new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
            ),
        );
    }

    private function ssoWarnings(): array
    {
        return array_values(array_filter(
            $this->ssoLogger->cleanLogs(),
            static fn (array $record): bool => $record[0] === LogLevel::WARNING,
        ));
    }

    private function tokenManager(): TokenManager
    {
        $tokenManager = self::getContainer()->get(TokenManager::class);
        \assert($tokenManager instanceof TokenManager);

        return $tokenManager;
    }

    private function esiCache(): TagAwareAdapterInterface
    {
        $esiCache = self::getContainer()->get('esi.cache');
        \assert($esiCache instanceof TagAwareAdapterInterface);

        return $esiCache;
    }

    private function connection(): Connection
    {
        return $this->em->getConnection();
    }

    private function createAccount(string $mainCharacterName, string $refreshToken, ?int $corporationId = null, array $scopes = []): User
    {
        $user = $this->createUser();
        $mainCharacter = $this->addCharacterWithToken($user, $mainCharacterName, $refreshToken, $scopes);
        if ($corporationId !== null) {
            $mainCharacter->setCorporationId($corporationId)->setCorporationName('Shared Corp');
        }
        $user->setMainCharacter($mainCharacter);

        return $user;
    }

    private function addCharacterWithToken(User $user, string $name, string $refreshToken, array $scopes = []): Character
    {
        $character = $this->createCharacter($user, $name);
        $character->setEveToken(
            (new EveToken())
                ->setAccessToken('access-token-of-' . $refreshToken)
                ->setRefreshTokenEncrypted($this->tokenManager()->encryptRefreshToken($refreshToken))
                ->setAccessTokenExpiresAt(new \DateTimeImmutable('+1 hour'))
                ->setScopes($scopes),
        );

        return $character;
    }

    private function mainCharacterOf(User $user): Character
    {
        $mainCharacter = $user->getMainCharacter();
        \assert($mainCharacter instanceof Character);

        return $mainCharacter;
    }

    private function createProject(User $owner, string $name): IndustryProject
    {
        $project = (new IndustryProject())->setOwner($owner)->setName($name);
        $this->em->persist($project);

        return $project;
    }

    private function addMember(IndustryProject $project, User $user, ParticipantRole $role, ParticipantStatus $status, string $joinedAt): IndustryProjectParticipant
    {
        $member = (new IndustryProjectParticipant())->setUser($user)->setRole($role)->setStatus($status);
        (new \ReflectionProperty(IndustryProjectParticipant::class, 'joinedAt'))->setValue($member, new \DateTimeImmutable($joinedAt));
        $project->addMember($member);

        return $member;
    }

    private function addContribution(
        IndustryProject $project,
        IndustryProjectParticipant $member,
        float $estimatedValue,
        ContributionStatus $status,
        ContributionType $type,
        int $quantity,
    ): IndustryProjectContribution {
        $contribution = (new IndustryProjectContribution())
            ->setMember($member)
            ->setType($type)
            ->setQuantity($quantity)
            ->setEstimatedValue($estimatedValue)
            ->setStatus($status);
        $project->addContribution($contribution);

        return $contribution;
    }

    private function addSale(IndustryProject $project, User $recordedBy, int $typeId, string $typeName, int $quantity, float $unitPrice): IndustryProjectSale
    {
        $sale = (new IndustryProjectSale())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setTotalPrice($quantity * $unitPrice)
            ->setVenue('Jita 4-4')
            ->setSoldAt(new \DateTimeImmutable('2026-02-10 20:00:00'))
            ->setRecordedBy($recordedBy);
        $project->addSale($sale);

        return $sale;
    }

    private function project(string $projectId): IndustryProject
    {
        $project = $this->em->find(IndustryProject::class, $projectId);
        \assert($project instanceof IndustryProject);

        return $project;
    }

    private function member(string $memberId): IndustryProjectParticipant
    {
        $member = $this->em->find(IndustryProjectParticipant::class, $memberId);
        \assert($member instanceof IndustryProjectParticipant);

        return $member;
    }

    private function rolesAndStatusesByMemberName(IndustryProject $project): array
    {
        $byName = [];
        foreach ($project->getMembers() as $member) {
            $name = $member->getUser()?->getMainCharacter()?->getName() ?? 'deleted account';
            $byName[$name] = [$member->getRole()->value, $member->getStatus()->value];
        }
        ksort($byName);

        return $byName;
    }

    private function contributionsOf(string $memberId): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT id::text AS id, status, estimated_value, quantity FROM industry_project_contributions WHERE member_id = ? ORDER BY id',
            [$memberId],
        );

        $contributions = [];
        foreach ($rows as $row) {
            $contributions[$row['id']] = [$row['status'], (float) $row['estimated_value'], (int) $row['quantity']];
        }

        return $contributions;
    }

    private function noteAndValueOf(string $contributionId): array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT note, estimated_value FROM industry_project_contributions WHERE id = ?',
            [$contributionId],
        );
        \assert(\is_array($row));

        return ['note' => $row['note'], 'estimated_value' => (float) $row['estimated_value']];
    }

    private function distributionOf(string $projectId): DistributionResult
    {
        $distributionService = self::getContainer()->get(ProjectDistributionService::class);
        \assert($distributionService instanceof ProjectDistributionService);

        return $distributionService->calculateDistribution($this->project($projectId));
    }

    private function payoutsByMemberId(DistributionResult $distribution): array
    {
        $payouts = [];
        foreach ($distribution->members as $member) {
            $payouts[$member->memberId] = $member->payoutTotal;
        }
        ksort($payouts);

        return $payouts;
    }

    private function characterNamesByMemberId(DistributionResult $distribution): array
    {
        $names = [];
        foreach ($distribution->members as $member) {
            $names[$member->memberId] = $member->characterName;
        }
        ksort($names);

        return $names;
    }

    private function listSalesOf(string $projectId, string $viewerId): array
    {
        $viewer = $this->em->find(User::class, $viewerId);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($viewer);

        $provider = new ProjectSaleCollectionProvider(
            $security,
            self::getContainer()->get(IndustryProjectRepository::class),
            self::getContainer()->get(ProjectAccessChecker::class),
            self::getContainer()->get(IndustryProjectSaleRepository::class),
            self::getContainer()->get(CachedWalletJournalEntryRepository::class),
        );

        return $provider->provide(new GetCollection(), ['projectId' => $projectId]);
    }

    private function corpAssetVisibilityFor(string $viewerId): CorpAssetVisibilityResource
    {
        $viewer = $this->em->find(User::class, $viewerId);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($viewer);

        $provider = new CorpAssetVisibilityProvider(
            $security,
            self::getContainer()->get(CorpAssetVisibilityRepository::class),
            self::getContainer()->get(CachedAssetRepository::class),
            $this->createStub(CorporationService::class),
            $this->createStub(CharacterService::class),
            self::getContainer()->get(CharacterRepository::class),
            self::getContainer()->get(UserRepository::class),
        );

        return $provider->provide(new Get());
    }

    private function assetsSyncService(): AssetsSyncService
    {
        $assetsSyncService = self::getContainer()->get(AssetsSyncService::class);
        \assert($assetsSyncService instanceof AssetsSyncService);

        return $assetsSyncService;
    }

    private function createStructure(User $owner, string $name, string $structureType): IndustryStructureConfig
    {
        $structure = (new IndustryStructureConfig())
            ->setUser($owner)
            ->setName($name)
            ->setSecurityType('nullsec')
            ->setStructureType($structureType)
            ->setIsCorporationStructure(true)
            ->setCorporationId(98_000_001);
        $this->em->persist($structure);

        return $structure;
    }

    private function structure(string $structureId): IndustryStructureConfig
    {
        $structure = $this->em->find(IndustryStructureConfig::class, $structureId);
        \assert($structure instanceof IndustryStructureConfig);

        return $structure;
    }

    private function createSharedShoppingList(User $createdBy, int $typeId, string $typeName, int $quantity): SharedShoppingList
    {
        $list = (new SharedShoppingList())
            ->setData([['typeId' => $typeId, 'typeName' => $typeName, 'quantity' => $quantity]])
            ->setCreatedBy($createdBy);
        $this->em->persist($list);

        return $list;
    }

    private function failOnTheAnonymizationOfAMembership(): void
    {
        $connection = $this->connection();
        $connection->executeStatement(<<<'SQL'
            CREATE FUNCTION fail_on_membership_anonymization_53() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'simulated failure after the anonymization';
            END
            $$
            SQL);
        $connection->executeStatement(<<<'SQL'
            CREATE TRIGGER fail_on_membership_anonymization_53
            AFTER UPDATE ON industry_project_participants
            FOR EACH ROW WHEN (OLD.user_id IS NOT NULL AND NEW.user_id IS NULL)
            EXECUTE FUNCTION fail_on_membership_anonymization_53()
            SQL);
    }

    private function countWhereIn(string $table, string $column, array $ids): int
    {
        $connection = $this->connection();

        return (int) $connection->fetchOne(
            sprintf(
                'SELECT COUNT(*) FROM %s WHERE %s::text IN (?)',
                $connection->quoteSingleIdentifier($table),
                $connection->quoteSingleIdentifier($column),
            ),
            [$ids],
            [ArrayParameterType::STRING],
        );
    }

    private function rowsReferencing(array $ids): array
    {
        $foreignKeyColumns = $this->connection()->fetchAllAssociative(<<<'SQL'
            SELECT DISTINCT kcu.table_name, kcu.column_name
            FROM information_schema.referential_constraints rc
            JOIN information_schema.key_column_usage kcu
                ON kcu.constraint_name = rc.constraint_name AND kcu.constraint_schema = rc.constraint_schema
            JOIN information_schema.constraint_column_usage ccu
                ON ccu.constraint_name = rc.constraint_name AND ccu.constraint_schema = rc.constraint_schema
            WHERE rc.constraint_schema = current_schema()
                AND ccu.table_name IN ('users', 'characters')
                AND ccu.column_name = 'id'
            ORDER BY kcu.table_name, kcu.column_name
            SQL);
        \assert($foreignKeyColumns !== []);

        $rows = [];
        foreach ($foreignKeyColumns as $foreignKeyColumn) {
            $rows[$foreignKeyColumn['table_name'] . '.' . $foreignKeyColumn['column_name']] = $this->countWhereIn(
                $foreignKeyColumn['table_name'],
                $foreignKeyColumn['column_name'],
                $ids,
            );
        }
        ksort($rows);

        return $rows;
    }

    private function seedPersonalData(User $user, Character $character, int $seed): void
    {
        $this->em->persist((new CachedAsset())
            ->setCharacter($character)
            ->setItemId(1_060_000_000_000 + $seed)
            ->setTypeId(34 + $seed)
            ->setTypeName(sprintf('Asset %d', $seed))
            ->setQuantity(1_000 * $seed)
            ->setLocationId(60_003_760)
            ->setLocationName('Jita IV - Moon 4 - Caldari Navy Assembly Plant')
            ->setLocationType('station'));
        $this->em->persist((new CachedCharacterSkill())
            ->setCharacter($character)
            ->setSkillId(3_380 + $seed)
            ->setLevel(3 + $seed));
        $start = new \DateTimeImmutable(sprintf('2026-03-0%d 12:00:00', $seed));
        $this->em->persist((new CachedIndustryJob())
            ->setCharacter($character)
            ->setJobId(530_000_000 + $seed)
            ->setActivityId(1)
            ->setBlueprintTypeId(22_545 + $seed)
            ->setProductTypeId(22_544 + $seed)
            ->setRuns(5 * $seed)
            ->setCost(125_000.0 * $seed)
            ->setStatus('active')
            ->setStartDate($start)
            ->setEndDate($start->modify('+1 day')));
        $this->em->persist((new CachedWalletTransaction())
            ->setCharacter($character)
            ->setTransactionId(5_300_000_000 + $seed)
            ->setTypeId(11_399 + $seed)
            ->setQuantity(675 * $seed)
            ->setUnitPrice(9_800.0 * $seed)
            ->setIsBuy($seed === 1)
            ->setLocationId(60_003_760)
            ->setClientId(2_112_100_000 + $seed)
            ->setDate($start));
        $this->em->persist((new IndustryProjectTemplate())
            ->setOwner($user)
            ->setName(sprintf('Template %d', $seed))
            ->setItems([['typeId' => 2_185 + $seed, 'typeName' => sprintf('Hull %d', $seed), 'meLevel' => 5 + $seed, 'teLevel' => 10 * $seed, 'runs' => 20 * $seed]])
            ->setSharedWithCorporation($seed === 1));
        $this->em->persist((new PlanetaryColony())
            ->setCharacter($character)
            ->setPlanetId(40_009_000 + $seed)
            ->setPlanetType($seed === 1 ? 'barren' : 'gas')
            ->setSolarSystemId(30_000_142)
            ->setUpgradeLevel(3 + $seed)
            ->setNumPins(8 + $seed)
            ->setLastUpdate($start));
        $this->em->persist((new IndustryProject())->setOwner($user)
            ->addItem((new IndustryProjectProduct())->setTypeId(22_444 + $seed)->setTypeName(sprintf('Seeded product %d', $seed))->setRuns(10 * $seed)));
        $this->em->persist((new IndustryUserSettings())
            ->setUser($user)
            ->setExportCostPerM3(100.0 * $seed));
        $this->em->persist((new MarketFavorite())
            ->setUser($user)
            ->setTypeId(16_670 + $seed));
        $this->em->persist((new MarketPriceAlert())
            ->setUser($user)
            ->setTypeId(16_680 + $seed)
            ->setTypeName(sprintf('Alerted type %d', $seed))
            ->setDirection(AlertDirection::Below)
            ->setThreshold(1_500.0 * $seed)
            ->setPriceSource(AlertPriceSource::JitaSell));
        $this->em->persist((new MiningEntry())
            ->setUser($user)
            ->setCharacterId($character->getEveCharacterId())
            ->setCharacterName($character->getName())
            ->setDate($start)
            ->setTypeId(1_230 + $seed)
            ->setTypeName(sprintf('Ore %d', $seed))
            ->setSolarSystemId(30_003_504)
            ->setSolarSystemName('Aldranette')
            ->setQuantity(12_000 * $seed)
            ->setUnitPrice(18.0 * $seed));
        $site = (new MiningSite())->setOwner($user)->setLabel(sprintf('Belt %d', $seed))->setSolarSystemId(30_003_504);
        $this->em->persist($site);
        $this->em->persist((new MiningSiteScan())
            ->setSite($site)
            ->setAuthor($user)
            ->setScannedAt($start)
            ->setTotalUnits(30_000 * $seed)
            ->setTotalVolumeM3(3_000.0 * $seed)
            ->setRemainingValue(450_000.0 * $seed));
        $this->em->persist((new MoonComposition())
            ->setCorporationId(98_262_100 + $seed)
            ->setMoonId(40_301_000 + $seed)
            ->setRecordedBy($user)
            ->setRecordedAt($start)
            ->setLines([['typeId' => 45_490 + $seed, 'share' => 1.0]]));
        $this->em->persist((new MoonTaxStatement())
            ->setCorporationId(98_000_273)
            ->setStructureId(1_035_000_000_100 + $seed)
            ->setCharacterId(2_112_000_100 + $seed)
            ->setPeriodStart($start)
            ->setPeriodEnd($start->modify('+7 days'))
            ->setReference(sprintf('TAX-2026-W10-%04d', $seed))
            ->setTax(640_000.0 * $seed)
            ->setActedBy($user)
            ->setActedAt($start));
        $this->em->persist((new CorporationWalletDonation())
            ->setCorporationId(98_000_273)
            ->setJournalId(7_000 + $seed)
            ->setFirstPartyId(2_112_000_100 + $seed)
            ->setAmount(4_000_000.0 * $seed)
            ->setDate($start)
            ->setAttributedCharacterId(2_112_000_100 + $seed)
            ->setAttributedBy($user)
            ->setAttributedAt($start));
        $this->em->persist((new PveIncome())
            ->setUser($user)
            ->setType(PveIncomeType::Bounty)
            ->setDescription(sprintf('Bounty %d', $seed))
            ->setAmount(3_500_000.0 * $seed)
            ->setDate($start));
        $this->em->persist((new Notification())
            ->setUser($user)
            ->setCategory(Notification::CATEGORY_PLANETARY)
            ->setLevel(Notification::LEVEL_INFO)
            ->setTitle(sprintf('Extractor %d expired', $seed))
            ->setMessage(sprintf('Extractor %d expired message', $seed)));
        $this->em->persist((new PushSubscription())
            ->setUser($user)
            ->setEndpoint(sprintf('https://push.example.test/subscription-%d', $seed))
            ->setPublicKey(sprintf('public-key-%d', $seed))
            ->setAuthToken(sprintf('auth-token-%d', $seed)));
        $this->em->persist((new Escalation())
            ->setUser($user)
            ->setCharacterId($character->getEveCharacterId())
            ->setCharacterName($character->getName())
            ->setType(sprintf('Escalation %d', $seed))
            ->setSolarSystemId(30_002_000 + $seed)
            ->setSolarSystemName(sprintf('System %d', $seed))
            ->setSecurityStatus(-0.1 * $seed)
            ->setPrice(40_000_000 * $seed)
            ->setCorporationId($character->getCorporationId())
            ->setExpiresAt(new \DateTimeImmutable('+3 days')));
        $this->createStructure($user, sprintf('Structure %d', $seed), $seed === 1 ? 'raitaru' : 'azbel');
        $this->createSharedShoppingList($user, 30 + $seed, sprintf('Listed type %d', $seed), 100 * $seed);
        $this->addSale($this->createProject($user, sprintf('Sold project %d', $seed)), $user, 22_456, 'Sabre', $seed, 60_000_000.0 + 1_250_000.0 * $seed)
            ->setSeller($character);
    }

    private function esiClient(array &$esiRequests, array $responses): EsiClient
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$esiRequests, &$responses): MockResponse {
            $esiRequests[] = ['method' => $method, 'url' => $url, 'headers' => $options['normalized_headers'] ?? []];
            $response = array_shift($responses);
            if ($response === null) {
                self::fail(sprintf('Unexpected extra ESI request: %s %s', $method, $url));
            }

            return $response;
        });
        $tokenManager = $this->createStub(TokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturn('esi-access-token');

        return new EsiClient(
            httpClient: $httpClient,
            esiCache: $this->esiCache(),
            tokenManager: $tokenManager,
            baseUrl: self::ESI_BASE_URL,
            logger: new NullLogger(),
        );
    }

    private function esiJsonResponse(array $body, ?string $etag): MockResponse
    {
        $headers = ['Content-Type' => 'application/json', 'X-Esi-Error-Limit-Remain' => '100', 'X-Esi-Error-Limit-Reset' => '60'];
        if ($etag !== null) {
            $headers['ETag'] = $etag;
        }

        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), ['http_code' => 200, 'response_headers' => $headers]);
    }

    private function esiTokenFor(Character $character): EveToken
    {
        $detachedCharacter = new Character();
        (new \ReflectionProperty(Character::class, 'id'))->setValue($detachedCharacter, Uuid::fromString((string) $character->getId()));

        return (new EveToken())
            ->setAccessToken('esi-access-token')
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('+1 hour'))
            ->setCharacter($detachedCharacter);
    }
}
