<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Account;

use ApiPlatform\Metadata\GetCollection;
use App\Entity\CachedAsset;
use App\Entity\CachedCharacterSkill;
use App\Entity\CachedIndustryJob;
use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Entity\CorpAssetVisibility;
use App\Entity\Escalation;
use App\Entity\EveToken;
use App\Entity\GroupIndustryBomItem;
use App\Entity\GroupIndustryContribution;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\GroupIndustrySale;
use App\Entity\IndustryBpcPrice;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryScannerFavorite;
use App\Entity\IndustryStockpileTarget;
use App\Entity\IndustryStructureConfig;
use App\Entity\IndustryUserSettings;
use App\Entity\MarketFavorite;
use App\Entity\MarketPriceAlert;
use App\Entity\MiningEntry;
use App\Entity\Notification;
use App\Entity\PlanetaryColony;
use App\Entity\PushSubscription;
use App\Entity\PveExpense;
use App\Entity\PveIncome;
use App\Entity\SharedShoppingList;
use App\Entity\User;
use App\Entity\UserLedgerSettings;
use App\Entity\UserNotificationPreference;
use App\Entity\UserPveSettings;
use App\Enum\AlertDirection;
use App\Enum\AlertPriceSource;
use App\Enum\AlertStatus;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\EscalationVisibility;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Enum\ProjectStatus;
use App\Enum\PveIncomeType;
use App\Exception\AccountDeletionBlockedException;
use App\Repository\GroupIndustryContributionRepository;
use App\Repository\GroupIndustryProjectRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Service\Account\AccountDeletionService;
use App\State\Provider\GroupIndustry\GroupContributionCollectionProvider;
use App\State\Provider\GroupIndustry\GroupIndustryResourceMapper;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Issue #53: GDPR account erasure (`DELETE /api/me`), promised by the legal page.
 *
 * Kaelen deletes his account. Every personal row (characters, tokens, ESI caches, settings,
 * notifications, personal industry/PVE/mining data, escalations, alerts...) disappears.
 * Rows that belong to other players too are kept but detached from him:
 *   - his membership in a group project where he contributed: kept, user set to null, demoted to member,
 *     so the project accounting (contributions, payouts) of the other members does not change;
 *   - the sales he recorded and the contributions he reviewed in that project: kept, reference set to null;
 *   - his corporation structure configs (shared with the corp): kept, user set to null, still shared;
 *   - the corporation asset visibility he configured as director: kept, configured_by set to null;
 *   - the shopping lists he shared by link: kept, created_by set to null.
 * A group project he owns with other accepted members blocks the deletion (409, see AccountDeletionGuardTest).
 */
final class AccountDeletionServiceTest extends IntegrationTestCase
{
    private const int CORPORATION_ID = 98_000_001;
    private const int ASTRAHUS_LOCATION_ID = 1_035_466_617_946;
    private const int FORTIZAR_LOCATION_ID = 1_035_466_617_947;
    private const int SABRE_TYPE_ID = 22456;
    private const int TRITANIUM_TYPE_ID = 34;
    private const int PYERITE_TYPE_ID = 35;

    /** Personal tables and the column pointing to the deleted user (`user`) or to one of his characters (`character`). */
    private const array PERSONAL_TABLES = [
        'users' => ['id', 'user'],
        'characters' => ['user_id', 'user'],
        'eve_tokens' => ['character_id', 'character'],
        'cached_assets' => ['character_id', 'character'],
        'cached_character_skills' => ['character_id', 'character'],
        'cached_industry_jobs' => ['character_id', 'character'],
        'cached_wallet_transactions' => ['character_id', 'character'],
        'planetary_colonies' => ['character_id', 'character'],
        'user_pve_settings' => ['user_id', 'user'],
        'user_ledger_settings' => ['user_id', 'user'],
        'industry_user_settings' => ['user_id', 'user'],
        'market_favorites' => ['user_id', 'user'],
        'market_price_alerts' => ['user_id', 'user'],
        'industry_scanner_favorites' => ['user_id', 'user'],
        'industry_bpc_prices' => ['user_id', 'user'],
        'industry_stockpile_targets' => ['user_id', 'user'],
        'industry_projects' => ['user_id', 'user'],
        'notifications' => ['user_id', 'user'],
        'user_notification_preferences' => ['user_id', 'user'],
        'push_subscriptions' => ['user_id', 'user'],
        'pve_income' => ['user_id', 'user'],
        'pve_expenses' => ['user_id', 'user'],
        'mining_entries' => ['user_id', 'user'],
        'escalations' => ['user_id', 'user'],
        'industry_structure_configs' => ['user_id', 'user'],
        'group_industry_projects' => ['owner_id', 'user'],
        'group_industry_project_members' => ['user_id', 'user'],
        'group_industry_sales' => ['recorded_by_id', 'user'],
        'group_industry_contributions' => ['reviewed_by_id', 'user'],
        'corp_asset_visibility' => ['configured_by_id', 'user'],
        'shared_shopping_lists' => ['created_by_id', 'user'],
    ];

    private string $kaelenId;
    private string $ariaId;
    private string $brannId;
    /** @var string[] */
    private array $kaelenCharacterIds = [];

    private string $soloProjectId;
    private string $ariaProjectId;
    private string $kaelenMemberId;
    private string $kaelenContributionId;
    private string $brannContributionId;
    private string $kaelenSaleId;
    private string $kaelenCorpStructureId;
    private string $ariaCorpStructureId;
    private string $ariaStepId;
    private string $corpAssetVisibilityId;
    private string $corpAssetId;
    private string $sharedShoppingListId;

    protected function setUp(): void
    {
        parent::setUp();

        $kaelen = $this->createUserWithMainCharacter('Kaelen Voss');
        $kaelenAlt = $this->createCharacter($kaelen, 'Kaelen Alt');
        $aria = $this->createUserWithMainCharacter('Aria Solberg');
        $brann = $this->createUserWithMainCharacter('Brann Holt');
        $kaelenMain = $kaelen->getMainCharacter();
        \assert($kaelenMain instanceof Character);
        $this->em->flush();

        $this->addKaelenPersonalData($kaelen, $kaelenMain, $kaelenAlt);
        $this->addKaelenSharedData($kaelen, $aria, $brann);

        $this->em->flush();
        $this->kaelenId = (string) $kaelen->getId();
        $this->ariaId = (string) $aria->getId();
        $this->brannId = (string) $brann->getId();
        $this->kaelenCharacterIds = [(string) $kaelenMain->getId(), (string) $kaelenAlt->getId()];
        $this->em->clear();
    }

    public function testFixtureGivesKaelenRowsInEveryRelatedTable(): void
    {
        self::assertSame([
            'users' => 1,
            'characters' => 2,
            'eve_tokens' => 1,
            'cached_assets' => 1,
            'cached_character_skills' => 1,
            'cached_industry_jobs' => 1,
            'cached_wallet_transactions' => 1,
            'planetary_colonies' => 1,
            'user_pve_settings' => 1,
            'user_ledger_settings' => 1,
            'industry_user_settings' => 1,
            'market_favorites' => 1,
            'market_price_alerts' => 1,
            'industry_scanner_favorites' => 1,
            'industry_bpc_prices' => 1,
            'industry_stockpile_targets' => 1,
            'industry_projects' => 1,
            'notifications' => 1,
            'user_notification_preferences' => 1,
            'push_subscriptions' => 1,
            'pve_income' => 1,
            'pve_expenses' => 1,
            'mining_entries' => 1,
            'escalations' => 2,
            'industry_structure_configs' => 2,
            'group_industry_projects' => 1,
            'group_industry_project_members' => 3,
            'group_industry_sales' => 1,
            'group_industry_contributions' => 1,
            'corp_asset_visibility' => 1,
            'shared_shopping_lists' => 1,
        ], $this->rowsReferencingKaelen());
    }

    public function testDeletingAccountLeavesNoRowReferencingTheUserOrHisCharacters(): void
    {
        $this->deleteKaelenAccount();

        self::assertSame(
            array_fill_keys(array_keys(self::PERSONAL_TABLES), 0),
            $this->rowsReferencingKaelen(),
        );
    }

    public function testDeletingAccountRemovesOwnedProjectWithoutOtherAcceptedMembersAndItsJoinRequests(): void
    {
        $this->deleteKaelenAccount();

        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM group_industry_projects WHERE id = ?', [$this->soloProjectId]));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM group_industry_project_members WHERE project_id = ?', [$this->soloProjectId]));
    }

    public function testContributingMembershipIsKeptAnonymizedAndDemotedToMember(): void
    {
        $this->deleteKaelenAccount();

        self::assertSame(
            ['project_id' => $this->ariaProjectId, 'user_id' => null, 'role' => 'member', 'status' => 'accepted'],
            $this->row('SELECT project_id, user_id, role, status FROM group_industry_project_members WHERE id = ?', [$this->kaelenMemberId]),
        );
    }

    public function testMembershipWithoutContributionInAnotherProjectIsRemoved(): void
    {
        $this->deleteKaelenAccount();

        self::assertSame(
            [['user_id' => $this->brannId, 'role' => 'owner']],
            $this->rows(
                'SELECT m.user_id, m.role FROM group_industry_project_members m JOIN group_industry_projects p ON p.id = m.project_id WHERE p.name = ?',
                ['Jackdaw batch'],
            ),
        );
    }

    public function testContributionsOfTheSharedProjectAreKeptWithTheirQuantitiesAndReviewerDetached(): void
    {
        $this->deleteKaelenAccount();

        self::assertSame(
            ['member_id' => $this->kaelenMemberId, 'quantity' => 400_000, 'estimated_value' => 1_600_000.0, 'status' => 'approved'],
            $this->row('SELECT member_id, quantity, estimated_value, status FROM group_industry_contributions WHERE id = ?', [$this->kaelenContributionId]),
        );
        self::assertSame(
            ['quantity' => 250_000, 'status' => 'approved', 'reviewed_by_id' => null],
            $this->row('SELECT quantity, status, reviewed_by_id FROM group_industry_contributions WHERE id = ?', [$this->brannContributionId]),
        );
    }

    public function testOwnerStillListsTheContributionsOfTheDeletedMemberAsUnknown(): void
    {
        $this->deleteKaelenAccount();

        $listed = [];
        foreach ($this->listAriaProjectContributionsAs($this->ariaId) as $contribution) {
            $listed[$contribution->id] = [$contribution->memberCharacterName, $contribution->quantity, $contribution->reviewedByCharacterName];
        }

        self::assertSame([
            $this->kaelenContributionId => ['Unknown', 400_000, 'Aria Solberg'],
            $this->brannContributionId => ['Brann Holt', 250_000, null],
        ], array_intersect_key($listed, [$this->kaelenContributionId => true, $this->brannContributionId => true]));
        self::assertCount(2, $listed);
    }

    public function testRecordedSaleIsKeptWithRecorderDetached(): void
    {
        $this->deleteKaelenAccount();

        self::assertSame(
            ['project_id' => $this->ariaProjectId, 'quantity' => 10, 'total_price' => 650_000_000.0, 'recorded_by_id' => null],
            $this->row('SELECT project_id, quantity, total_price, recorded_by_id FROM group_industry_sales WHERE id = ?', [$this->kaelenSaleId]),
        );
    }

    public function testCorporationStructureStaysSharedWithTheCorporationWithoutOwner(): void
    {
        $this->deleteKaelenAccount();

        self::assertSame(
            ['name' => 'Kaelen Astrahus', 'user_id' => null, 'is_corporation_structure' => true],
            $this->row('SELECT name, user_id, is_corporation_structure FROM industry_structure_configs WHERE id = ?', [$this->kaelenCorpStructureId]),
        );

        $aria = $this->em->find(User::class, $this->ariaId);
        \assert($aria instanceof User);
        $shared = self::getContainer()->get(IndustryStructureConfigRepository::class)
            ->findCorporationSharedStructures(self::CORPORATION_ID, $aria);

        self::assertSame([self::ASTRAHUS_LOCATION_ID], array_keys($shared));
        self::assertSame($this->kaelenCorpStructureId, (string) $shared[self::ASTRAHUS_LOCATION_ID]->getId());
    }

    public function testDeletedUserIsRemovedFromStructuresHiddenByOtherUsers(): void
    {
        $this->deleteKaelenAccount();

        self::assertSame(
            [$this->brannId],
            json_decode((string) $this->em->getConnection()->fetchOne('SELECT hidden_by_users FROM industry_structure_configs WHERE id = ?', [$this->ariaCorpStructureId]), true),
        );
    }

    public function testPersonalStructureUsedByAnotherUserProjectStepIsDetachedFromTheStep(): void
    {
        $this->deleteKaelenAccount();

        self::assertSame(
            ['runs' => 10, 'structure_config_id' => null],
            $this->row('SELECT runs, structure_config_id FROM industry_project_steps WHERE id = ?', [$this->ariaStepId]),
        );
    }

    public function testCorporationAssetVisibilityAndCorporationAssetsAreKept(): void
    {
        $this->deleteKaelenAccount();

        $visibility = $this->row('SELECT corporation_id, visible_divisions, configured_by_id FROM corp_asset_visibility WHERE id = ?', [$this->corpAssetVisibilityId]);
        self::assertSame(self::CORPORATION_ID, $visibility['corporation_id']);
        self::assertSame([1, 3], json_decode((string) $visibility['visible_divisions'], true));
        self::assertNull($visibility['configured_by_id']);

        self::assertSame(
            ['quantity' => 2_000_000, 'corporation_id' => self::CORPORATION_ID],
            $this->row('SELECT quantity, corporation_id FROM cached_assets WHERE id = ?', [$this->corpAssetId]),
        );
    }

    public function testSharedShoppingListLinkKeepsWorkingWithoutCreator(): void
    {
        $this->deleteKaelenAccount();

        self::assertSame(
            ['created_by_id' => null],
            $this->row('SELECT created_by_id FROM shared_shopping_lists WHERE id = ?', [$this->sharedShoppingListId]),
        );
    }

    public function testOtherUsersAndTheirCharactersAreUntouched(): void
    {
        $this->deleteKaelenAccount();

        self::assertSame(
            [['name' => 'Aria Solberg'], ['name' => 'Brann Holt']],
            $this->rows('SELECT c.name FROM characters c WHERE c.user_id IN (?, ?) ORDER BY c.name', [$this->ariaId, $this->brannId]),
        );
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM group_industry_projects WHERE id = ?', [$this->ariaProjectId]));
    }

    public function testDeletedUserNoLongerAuthenticates(): void
    {
        $this->deleteKaelenAccount();

        /** @var UserProviderInterface<User> $userProvider */
        $userProvider = self::getContainer()->get('security.user.provider.concrete.app_user_provider');

        $this->expectException(UserNotFoundException::class);

        $userProvider->loadUserByIdentifier($this->kaelenId);
    }

    public function testOwnerOfProjectWithOtherAcceptedMembersIsRefusedAndNothingIsDeleted(): void
    {
        $kaelen = $this->em->find(User::class, $this->kaelenId);
        $aria = $this->em->find(User::class, $this->ariaId);
        \assert($kaelen instanceof User && $aria instanceof User);
        $sharedOwnedProject = (new GroupIndustryProject())->setOwner($kaelen)->setName('Sabre doctrine');
        $this->em->persist($sharedOwnedProject);
        $this->addMember($sharedOwnedProject, $kaelen, GroupMemberRole::Owner, GroupMemberStatus::Accepted);
        $this->addMember($sharedOwnedProject, $aria, GroupMemberRole::Member, GroupMemberStatus::Accepted);
        $this->flushAndClear();
        $rowsBefore = $this->rowsReferencingKaelen();

        try {
            $this->deleteKaelenAccount();
            self::fail('Account deletion should have been refused');
        } catch (AccountDeletionBlockedException $refusal) {
            self::assertSame(409, $refusal->getStatusCode());
            self::assertSame(
                'Cannot delete your account while you own group projects with other members: Sabre doctrine. Delete these projects first.',
                $refusal->getMessage(),
            );
        }

        $this->em->clear();
        self::assertSame($rowsBefore, $this->rowsReferencingKaelen());
    }

    private function deleteKaelenAccount(): void
    {
        $kaelen = $this->em->find(User::class, $this->kaelenId);
        \assert($kaelen instanceof User);

        self::getContainer()->get(AccountDeletionService::class)->deleteAccount($kaelen);

        $this->em->clear();
    }

    /** @return array<string, int> */
    private function rowsReferencingKaelen(): array
    {
        $counts = [];
        foreach (self::PERSONAL_TABLES as $table => [$column, $target]) {
            $counts[$table] = $target === 'user'
                ? $this->countRows("SELECT COUNT(*) FROM {$table} WHERE {$column} = ?", [$this->kaelenId])
                : $this->countRows("SELECT COUNT(*) FROM {$table} WHERE {$column} IN (?, ?)", $this->kaelenCharacterIds);
        }

        return $counts;
    }

    /** @param list<string> $params */
    private function countRows(string $sql, array $params): int
    {
        return (int) $this->em->getConnection()->fetchOne($sql, $params);
    }

    /**
     * @param list<string> $params
     *
     * @return array<string, mixed>
     */
    private function row(string $sql, array $params): array
    {
        $row = $this->em->getConnection()->fetchAssociative($sql, $params);
        self::assertIsArray($row, 'Row should still exist');

        return $row;
    }

    /**
     * @param list<string> $params
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        return $this->em->getConnection()->fetchAllAssociative($sql, $params);
    }

    /** @return \App\ApiResource\GroupIndustry\GroupIndustryContributionResource[] */
    private function listAriaProjectContributionsAs(string $userId): array
    {
        $user = $this->em->find(User::class, $userId);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $provider = new GroupContributionCollectionProvider(
            $security,
            self::getContainer()->get(GroupIndustryProjectRepository::class),
            self::getContainer()->get(GroupIndustryContributionRepository::class),
            self::getContainer()->get(GroupProjectAccessChecker::class),
            self::getContainer()->get(GroupIndustryResourceMapper::class),
        );

        return $provider->provide(new GetCollection(), ['projectId' => $this->ariaProjectId]);
    }

    private function addKaelenPersonalData(User $kaelen, Character $kaelenMain, Character $kaelenAlt): void
    {
        $now = new \DateTimeImmutable('2026-10-01 12:00:00');

        $this->em->persist((new EveToken())->setCharacter($kaelenMain)->setAccessToken('access')->setRefreshTokenEncrypted('refresh')
            ->setAccessTokenExpiresAt($now)->setScopes(['esi-assets.read_assets.v1']));
        $this->em->persist((new CachedAsset())->setCharacter($kaelenMain)->setItemId(1_000_000_000_001)->setTypeId(self::SABRE_TYPE_ID)
            ->setTypeName('Sabre')->setQuantity(1)->setLocationId(60_003_760)->setLocationName('Jita IV - Moon 4')->setLocationType('station'));
        $this->em->persist((new CachedCharacterSkill())->setCharacter($kaelenAlt)->setSkillId(3380)->setLevel(5)->setIsManual(false)->setCachedAt($now));
        $this->em->persist((new CachedIndustryJob())->setCharacter($kaelenMain)->setJobId(500_000_001)->setActivityId(1)
            ->setBlueprintTypeId(22457)->setProductTypeId(self::SABRE_TYPE_ID)->setRuns(10)->setCost(1_250_000.0)->setStatus('active')
            ->setStartDate($now)->setEndDate($now->modify('+2 days'))->setCachedAt($now));
        $this->em->persist((new CachedWalletTransaction())->setCharacter($kaelenMain)->setTransactionId(6_000_000_001)->setTypeId(self::TRITANIUM_TYPE_ID)
            ->setQuantity(400_000)->setUnitPrice(4.0)->setIsBuy(true)->setLocationId(60_003_760)->setClientId(2_112_000_001)->setDate($now));
        $this->em->persist((new PlanetaryColony())->setCharacter($kaelenAlt)->setPlanetId(40_000_001)->setPlanetType('barren')
            ->setSolarSystemId(30_000_142)->setUpgradeLevel(4)->setNumPins(12)->setLastUpdate($now)->setCachedAt($now));

        $this->em->persist((new UserPveSettings())->setUser($kaelen));
        $this->em->persist((new UserLedgerSettings())->setUser($kaelen));
        $this->em->persist((new IndustryUserSettings())->setUser($kaelen));
        $this->em->persist((new MarketFavorite())->setUser($kaelen)->setTypeId(self::SABRE_TYPE_ID));
        $this->em->persist((new MarketPriceAlert())->setUser($kaelen)->setTypeId(self::SABRE_TYPE_ID)->setTypeName('Sabre')
            ->setDirection(AlertDirection::Below)->setThreshold(60_000_000.0)->setPriceSource(AlertPriceSource::JitaSell)->setStatus(AlertStatus::Active));
        $this->em->persist((new IndustryScannerFavorite())->setUser($kaelen)->setTypeId(self::SABRE_TYPE_ID));
        $this->em->persist((new IndustryBpcPrice())->setUser($kaelen)->setBlueprintTypeId(22457)->setPricePerRun(150_000.0));
        $this->em->persist((new IndustryStockpileTarget())->setUser($kaelen)->setTypeId(self::TRITANIUM_TYPE_ID)->setTypeName('Tritanium')
            ->setTargetQuantity(1_000_000)->setStage('raw_material'));

        $kaelenProject = (new IndustryProject())->setUser($kaelen)->setProductTypeId(self::SABRE_TYPE_ID)->setName('Sabre x10')
            ->setRuns(10)->setMeLevel(10)->setTeLevel(20)->setStatus(ProjectStatus::Active);
        $this->em->persist($kaelenProject);
        $this->em->persist($this->step($kaelenProject, null));

        $this->em->persist((new Notification())->setUser($kaelen)->setCategory('industry')->setLevel('info')
            ->setTitle('Job completed')->setMessage('Sabre x10 is ready'));
        $this->em->persist((new UserNotificationPreference())->setUser($kaelen)->setCategory('industry')->setEnabled(true)->setPushEnabled(true));
        $this->em->persist((new PushSubscription())->setUser($kaelen)->setEndpoint('https://push.example/kaelen')
            ->setPublicKey('public-key')->setAuthToken('auth-token'));
        $this->em->persist((new PveIncome())->setUser($kaelen)->setType(PveIncomeType::Bounty)->setDescription('Bounty prizes')
            ->setAmount(25_000_000.0)->setDate($now));
        $this->em->persist((new PveExpense())->setUser($kaelen)->setType('ammo')->setDescription('Hail L')
            ->setAmount(3_000_000.0)->setDate($now));
        $this->em->persist((new MiningEntry())->setUser($kaelen)->setCharacterId($kaelenMain->getEveCharacterId())->setCharacterName('Kaelen Voss')
            ->setDate($now)->setTypeId(1230)->setTypeName('Veldspar')->setSolarSystemId(30_000_142)->setSolarSystemName('Jita')
            ->setQuantity(50_000)->setUsage('sold')->setSyncedAt($now));

        // Escalations carry the character identity; even when shared with the corp they are deleted.
        $this->em->persist($this->escalation($kaelen, $kaelenMain, EscalationVisibility::Perso));
        $this->em->persist($this->escalation($kaelen, $kaelenMain, EscalationVisibility::Corp));
    }

    private function addKaelenSharedData(User $kaelen, User $aria, User $brann): void
    {
        // Personal structure: deleted, but a step of Aria's project points to it.
        $kaelenPersonalStructure = (new IndustryStructureConfig())->setUser($kaelen)->setName('Kaelen Raitaru')
            ->setSecurityType('nullsec')->setStructureType('engineering_complex');
        $this->em->persist($kaelenPersonalStructure);
        $ariaProject = (new IndustryProject())->setUser($aria)->setProductTypeId(self::SABRE_TYPE_ID)->setName('Aria Sabres')
            ->setRuns(10)->setMeLevel(10)->setTeLevel(20)->setStatus(ProjectStatus::Active);
        $this->em->persist($ariaProject);
        $ariaStep = $this->step($ariaProject, $kaelenPersonalStructure);
        $this->em->persist($ariaStep);

        $kaelenCorpStructure = (new IndustryStructureConfig())->setUser($kaelen)->setName('Kaelen Astrahus')
            ->setSecurityType('nullsec')->setStructureType('engineering_complex')->setLocationId(self::ASTRAHUS_LOCATION_ID)
            ->setCorporationId(self::CORPORATION_ID)->setIsCorporationStructure(true);
        $this->em->persist($kaelenCorpStructure);
        $ariaCorpStructure = (new IndustryStructureConfig())->setUser($aria)->setName('Aria Fortizar')
            ->setSecurityType('nullsec')->setStructureType('engineering_complex')->setLocationId(self::FORTIZAR_LOCATION_ID)
            ->setCorporationId(self::CORPORATION_ID)->setIsCorporationStructure(true);
        $this->em->persist($ariaCorpStructure);

        $corpAssetVisibility = (new CorpAssetVisibility())->setCorporationId(self::CORPORATION_ID)->setVisibleDivisions([1, 3])->setConfiguredBy($kaelen);
        $this->em->persist($corpAssetVisibility);
        $corpAsset = (new CachedAsset())->setCorporationId(self::CORPORATION_ID)->setIsCorporationAsset(true)->setItemId(1_000_000_000_002)
            ->setTypeId(self::TRITANIUM_TYPE_ID)->setTypeName('Tritanium')->setQuantity(2_000_000)->setLocationId(self::ASTRAHUS_LOCATION_ID)
            ->setLocationName('Kaelen Astrahus')->setLocationType('structure')->setLocationFlag('CorpSAG1');
        $this->em->persist($corpAsset);

        $sharedShoppingList = (new SharedShoppingList())->setData(['items' => [['typeId' => self::TRITANIUM_TYPE_ID, 'quantity' => 400_000]]])
            ->setCreatedBy($kaelen);
        $this->em->persist($sharedShoppingList);

        // Group project owned by Kaelen alone, with a pending join request from Brann: deleted.
        $soloProject = (new GroupIndustryProject())->setOwner($kaelen)->setName('Solo Sabre run');
        $this->em->persist($soloProject);
        $this->addMember($soloProject, $kaelen, GroupMemberRole::Owner, GroupMemberStatus::Accepted);
        $this->addMember($soloProject, $brann, GroupMemberRole::Member, GroupMemberStatus::Pending);

        // Aria's project where Kaelen (admin) contributed, recorded a sale and reviewed Brann's contribution: kept.
        $ariaGroupProject = (new GroupIndustryProject())->setOwner($aria)->setName('Ishtar fleet');
        $this->em->persist($ariaGroupProject);
        $this->addMember($ariaGroupProject, $aria, GroupMemberRole::Owner, GroupMemberStatus::Accepted);
        $kaelenMember = $this->addMember($ariaGroupProject, $kaelen, GroupMemberRole::Admin, GroupMemberStatus::Accepted);
        $brannMember = $this->addMember($ariaGroupProject, $brann, GroupMemberRole::Member, GroupMemberStatus::Accepted);
        $tritanium = (new GroupIndustryBomItem())->setTypeId(self::TRITANIUM_TYPE_ID)->setTypeName('Tritanium')->setRequiredQuantity(1_000_000);
        $pyerite = (new GroupIndustryBomItem())->setTypeId(self::PYERITE_TYPE_ID)->setTypeName('Pyerite')->setRequiredQuantity(500_000);
        $ariaGroupProject->addBomItem($tritanium);
        $ariaGroupProject->addBomItem($pyerite);
        $kaelenContribution = $this->contribution($ariaGroupProject, $kaelenMember, $tritanium, 400_000, 1_600_000.0, $aria);
        $brannContribution = $this->contribution($ariaGroupProject, $brannMember, $pyerite, 250_000, 2_500_000.0, $kaelen);
        $kaelenSale = (new GroupIndustrySale())->setTypeId(self::SABRE_TYPE_ID)->setTypeName('Sabre')->setQuantity(10)
            ->setUnitPrice(65_000_000.0)->setTotalPrice(650_000_000.0)->setSoldAt(new \DateTimeImmutable('2026-09-20 18:00:00'))
            ->setRecordedBy($kaelen);
        $ariaGroupProject->addSale($kaelenSale);

        // Brann's project where Kaelen is an accepted member without contribution: membership deleted.
        $brannGroupProject = (new GroupIndustryProject())->setOwner($brann)->setName('Jackdaw batch');
        $this->em->persist($brannGroupProject);
        $this->addMember($brannGroupProject, $brann, GroupMemberRole::Owner, GroupMemberStatus::Accepted);
        $this->addMember($brannGroupProject, $kaelen, GroupMemberRole::Member, GroupMemberStatus::Accepted);

        $this->em->flush();
        $ariaCorpStructure->hideForUser((string) $kaelen->getId())->hideForUser((string) $brann->getId());
        $this->em->flush();

        $this->soloProjectId = (string) $soloProject->getId();
        $this->ariaProjectId = (string) $ariaGroupProject->getId();
        $this->kaelenMemberId = (string) $kaelenMember->getId();
        $this->kaelenContributionId = (string) $kaelenContribution->getId();
        $this->brannContributionId = (string) $brannContribution->getId();
        $this->kaelenSaleId = (string) $kaelenSale->getId();
        $this->kaelenCorpStructureId = (string) $kaelenCorpStructure->getId();
        $this->ariaCorpStructureId = (string) $ariaCorpStructure->getId();
        $this->ariaStepId = (string) $ariaStep->getId();
        $this->corpAssetVisibilityId = (string) $corpAssetVisibility->getId();
        $this->corpAssetId = (string) $corpAsset->getId();
        $this->sharedShoppingListId = (string) $sharedShoppingList->getId();
    }

    private function createUserWithMainCharacter(string $characterName): User
    {
        $user = $this->createUser();
        $user->setMainCharacter($this->createCharacter($user, $characterName));

        return $user;
    }

    private function addMember(GroupIndustryProject $project, User $user, GroupMemberRole $role, GroupMemberStatus $status): GroupIndustryProjectMember
    {
        $member = (new GroupIndustryProjectMember())->setUser($user)->setRole($role)->setStatus($status);
        $project->addMember($member);

        return $member;
    }

    private function contribution(
        GroupIndustryProject $project,
        GroupIndustryProjectMember $member,
        GroupIndustryBomItem $bomItem,
        int $quantity,
        float $estimatedValue,
        User $reviewedBy,
    ): GroupIndustryContribution {
        $contribution = (new GroupIndustryContribution())->setMember($member)->setBomItem($bomItem)->setType(ContributionType::Material)
            ->setQuantity($quantity)->setEstimatedValue($estimatedValue)->setStatus(ContributionStatus::Approved)
            ->setReviewedBy($reviewedBy)->setReviewedAt(new \DateTimeImmutable('2026-09-01 12:00:00'));
        $project->addContribution($contribution);

        return $contribution;
    }

    private function step(IndustryProject $project, ?IndustryStructureConfig $structureConfig): IndustryProjectStep
    {
        return (new IndustryProjectStep())->setProject($project)->setBlueprintTypeId(22457)->setProductTypeId(self::SABRE_TYPE_ID)
            ->setQuantity(10)->setRuns(10)->setDepth(0)->setMeLevel(10)->setTeLevel(20)->setActivityType('manufacturing')
            ->setStructureConfig($structureConfig);
    }

    private function escalation(User $user, Character $character, EscalationVisibility $visibility): Escalation
    {
        return (new Escalation())->setUser($user)->setCharacterId($character->getEveCharacterId())->setCharacterName((string) $character->getName())
            ->setType('Angel Military Complex')->setSolarSystemId(30_000_142)->setSolarSystemName('Jita')->setSecurityStatus(0.9)
            ->setPrice(150_000_000)->setVisibility($visibility)->setCorporationId(self::CORPORATION_ID)
            ->setExpiresAt(new \DateTimeImmutable('2026-10-04 12:00:00'));
    }
}
