<?php

declare(strict_types=1);

namespace App\Service\Help;

use App\Message\CheckAlertPrices;
use App\Message\CheckCourierContracts;
use App\Message\CheckMiningSiteDepletionAlerts;
use App\Message\CheckMoonChunkAlerts;
use App\Message\CheckSdeBuild;
use App\Message\PurgeExpiredSharedLists;
use App\Message\PurgeOldMarketHistory;
use App\Message\PurgeOldNotifications;
use App\Message\SyncAdjustedPrices;
use App\Message\SyncBlueprints;
use App\Message\SyncCharacterOrders;
use App\Message\SyncCostIndices;
use App\Message\SyncIndustryJobs;
use App\Message\SyncPackagedVolumes;
use App\Message\SyncPublicContracts;
use App\Message\SyncRegionalMarketVolumes;
use App\Message\SyncWalletTransactions;
use App\Message\TriggerAnsiblexSync;
use App\Message\TriggerCorporationMiningExtractionsSync;
use App\Message\TriggerJitaMarketSync;
use App\Message\TriggerMiningOperationRecurrences;
use App\Message\TriggerMiningSync;
use App\Message\TriggerMoonTaxSync;
use App\Message\TriggerPlanetarySync;
use App\Message\TriggerPveSync;
use App\Message\TriggerStructureMarketSync;

final readonly class HelpDescriptions
{
    public const array LANGUAGES = ['fr', 'en'];

    private const array SCOPE_USAGES = [
        'esi-assets.read_assets.v1' => ['fr' => 'Inventaire de vos personnages (page Inventaire, valeur des assets, matériaux disponibles pour les projets).', 'en' => 'Inventory of your characters (Inventory page, asset value, materials available to projects).'],
        'esi-assets.read_corporation_assets.v1' => ['fr' => 'Inventaire de la corporation dans les divisions de hangar que vous avez le droit de voir.', 'en' => 'Corporation inventory in the hangar divisions you are allowed to see.'],
        'esi-characters.read_corporation_roles.v1' => ['fr' => 'Lit vos rôles en corporation (Director, Station_Manager…) pour savoir quelles données de corporation vous pouvez lire.', 'en' => 'Reads your corporation roles (Director, Station_Manager…) to know which corporation data you may read.'],
        'esi-corporations.read_divisions.v1' => ['fr' => 'Noms des divisions de hangar et de wallet de la corporation.', 'en' => 'Names of the corporation hangar and wallet divisions.'],
        'esi-corporations.read_structures.v1' => ['fr' => 'Structures de la corporation : jumpgates Ansiblex, raffineries lunaires, santé des structures d\'un projet.', 'en' => 'Corporation structures: Ansiblex jump gates, moon refineries, health of the structures a project relies on.'],
        'esi-wallet.read_character_wallet.v1' => ['fr' => 'Transactions du wallet d\'un personnage, pour rattacher les achats aux projets Industrie.', 'en' => 'Wallet transactions of a character, to match purchases with Industry projects.'],
        'esi-wallet.read_corporation_wallets.v1' => ['fr' => 'Journal du wallet de la corporation, pour la taxe lunaire.', 'en' => 'Corporation wallet journal, for the moon tax.'],
        'esi-contracts.read_character_contracts.v1' => ['fr' => 'Contrats d\'un personnage : ventes et contrats de courrier liés aux projets, données PVE.', 'en' => 'Contracts of a character: sales and courier contracts linked to projects, PVE data.'],
        'esi-industry.read_character_jobs.v1' => ['fr' => 'Jobs d\'industrie d\'un personnage (Slot Tracker, suivi des projets).', 'en' => 'Industry jobs of a character (Slot Tracker, project tracking).'],
        'esi-industry.read_corporation_jobs.v1' => ['fr' => 'Jobs d\'industrie de la corporation, pour les jobs lancés pour son compte.', 'en' => 'Corporation industry jobs, for jobs started on its behalf.'],
        'esi-industry.read_character_mining.v1' => ['fr' => 'Registre de minage d\'un personnage et opérations de minage.', 'en' => 'Mining ledger of a character and mining operations.'],
        'esi-industry.read_corporation_mining.v1' => ['fr' => 'Extractions lunaires et registre des observateurs de la corporation.', 'en' => 'Moon extractions and observer ledger of the corporation.'],
        'esi-characters.read_blueprints.v1' => ['fr' => 'Blueprints personnels, pour la bibliothèque de blueprints et le ME/TE des projets.', 'en' => 'Personal blueprints, for the blueprint library and project ME/TE.'],
        'esi-corporations.read_blueprints.v1' => ['fr' => 'Blueprints de la corporation, pour la bibliothèque de blueprints.', 'en' => 'Corporation blueprints, for the blueprint library.'],
        'esi-skills.read_skills.v1' => ['fr' => 'Compétences d\'un personnage : durées d\'entraînement, niveau de retraitement.', 'en' => 'Skills of a character: training times, reprocessing level.'],
        'esi-skills.read_skillqueue.v1' => ['fr' => 'Demandé à l\'avance : aucune fonction ne lit encore la file d\'entraînement.', 'en' => 'Requested in advance: no feature reads the skill queue yet.'],
        'esi-location.read_location.v1' => ['fr' => 'Demandé à l\'avance : aucune fonction ne lit encore la position du personnage.', 'en' => 'Requested in advance: no feature reads the character location yet.'],
        'esi-location.read_ship_type.v1' => ['fr' => 'Vaisseau actuel d\'un personnage, pour les opérations de minage.', 'en' => 'Current ship of a character, for mining operations.'],
        'esi-location.read_online.v1' => ['fr' => 'Demandé à l\'avance : aucune fonction ne lit encore l\'état en ligne du personnage.', 'en' => 'Requested in advance: no feature reads the character online status yet.'],
        'esi-fleets.read_fleet.v1' => ['fr' => 'Demandé à l\'avance : aucune fonction ne lit encore la flotte.', 'en' => 'Requested in advance: no feature reads the fleet yet.'],
        'esi-universe.read_structures.v1' => ['fr' => 'Noms et emplacements des structures de joueurs.', 'en' => 'Names and locations of player structures.'],
        'esi-search.search_structures.v1' => ['fr' => 'Recherche de structures, pour retrouver les jumpgates Ansiblex.', 'en' => 'Structure search, to discover Ansiblex jump gates.'],
        'esi-characters.read_notifications.v1' => ['fr' => 'Demandé à l\'avance : aucune fonction ne lit encore les notifications du jeu.', 'en' => 'Requested in advance: no feature reads in-game notifications yet.'],
        'esi-killmails.read_killmails.v1' => ['fr' => 'Demandé à l\'avance : aucune fonction ne lit encore les killmails.', 'en' => 'Requested in advance: no feature reads killmails yet.'],
        'esi-markets.structure_markets.v1' => ['fr' => 'Prix du marché d\'une structure, pour comparer avec Jita.', 'en' => 'Market prices of a structure, to compare with Jita.'],
        'esi-markets.read_character_orders.v1' => ['fr' => 'Ordres de vente de vos personnages, comparés au meilleur prix du marché.', 'en' => 'Sell orders of your characters, compared with the best market price.'],
        'esi-ui.open_window.v1' => ['fr' => 'Ouvre une fenêtre du jeu (infos, marché, contrat) depuis l\'application.', 'en' => 'Opens an in-game window (info, market, contract) from the app.'],
        'esi-ui.write_waypoint.v1' => ['fr' => 'Définit la destination dans le jeu depuis l\'application.', 'en' => 'Sets the in-game destination from the app.'],
        'esi-corporations.read_projects.v1' => ['fr' => 'Projets de corporation, pour les contributions PVE.', 'en' => 'Corporation projects, for PVE contributions.'],
        'esi-planets.manage_planets.v1' => ['fr' => 'Colonies planétaires : extracteurs, alertes d\'arrêt.', 'en' => 'Planetary colonies: extractors, expiry alerts.'],
        'esi-characters.read_standings.v1' => ['fr' => 'Standings du personnage, pour les frais de vente et de courtage.', 'en' => 'Character standings, for sales tax and brokerage fees.'],
    ];

    private const array SYNC_LABELS = [
        TriggerAnsiblexSync::class => ['fr' => 'Jumpgates Ansiblex', 'en' => 'Ansiblex jump gates'],
        TriggerStructureMarketSync::class => ['fr' => 'Prix du marché de la structure', 'en' => 'Structure market prices'],
        TriggerJitaMarketSync::class => ['fr' => 'Prix du marché de Jita', 'en' => 'Jita market prices'],
        TriggerPveSync::class => ['fr' => 'Données PVE', 'en' => 'PVE data'],
        SyncIndustryJobs::class => ['fr' => 'Jobs d\'industrie', 'en' => 'Industry jobs'],
        TriggerMiningSync::class => ['fr' => 'Registre de minage', 'en' => 'Mining ledger'],
        SyncWalletTransactions::class => ['fr' => 'Transactions du wallet et frais de contrat du journal', 'en' => 'Wallet transactions and contract fee journal lines'],
        TriggerMoonTaxSync::class => ['fr' => 'Taxe lunaire (registre des observateurs et journal du wallet corporation)', 'en' => 'Moon tax (observer ledger and corporation wallet journal)'],
        SyncBlueprints::class => ['fr' => 'Blueprints personnels et de la corporation', 'en' => 'Personal and corporation blueprints'],
        SyncCharacterOrders::class => ['fr' => 'Ordres de vente des personnages comparés au meilleur prix', 'en' => 'Character sell orders compared with the best price'],
        TriggerPlanetarySync::class => ['fr' => 'Colonies planétaires', 'en' => 'Planetary colonies'],
        CheckAlertPrices::class => ['fr' => 'Prix des alertes de marché', 'en' => 'Market alert prices'],
        SyncAdjustedPrices::class => ['fr' => 'Prix ajustés', 'en' => 'Adjusted prices'],
        SyncCostIndices::class => ['fr' => 'Indices de coût des systèmes', 'en' => 'System cost indices'],
        SyncPublicContracts::class => ['fr' => 'Contrats publics (The Forge)', 'en' => 'Public contracts (The Forge)'],
        TriggerCorporationMiningExtractionsSync::class => ['fr' => 'Extractions lunaires de la corporation', 'en' => 'Corporation moon extractions'],
        TriggerMiningOperationRecurrences::class => ['fr' => 'Opérations de minage récurrentes', 'en' => 'Recurring mining operations'],
        CheckCourierContracts::class => ['fr' => 'Alerte d\'un contrat de courrier de projet qui échoue', 'en' => 'Alert for a failing project courier contract'],
        CheckMoonChunkAlerts::class => ['fr' => 'Alertes de chunk lunaire', 'en' => 'Moon chunk alerts'],
        CheckMiningSiteDepletionAlerts::class => ['fr' => 'Alertes d\'épuisement de site de minage', 'en' => 'Mining site depletion alerts'],
        SyncRegionalMarketVolumes::class => ['fr' => 'Volumes de marché journaliers par région', 'en' => 'Daily market volumes by region'],
        PurgeOldNotifications::class => ['fr' => 'Purge des notifications anciennes', 'en' => 'Purge of old notifications'],
        PurgeOldMarketHistory::class => ['fr' => 'Purge de l\'historique de marché', 'en' => 'Purge of market history'],
        PurgeExpiredSharedLists::class => ['fr' => 'Purge des listes d\'achats partagées expirées', 'en' => 'Purge of expired shared shopping lists'],
        CheckSdeBuild::class => ['fr' => 'Version du SDE (alerte aux admins, aucun import automatique)', 'en' => 'SDE build (admin alert, no automatic import)'],
        SyncPackagedVolumes::class => ['fr' => 'Volumes reconditionnés des types présents dans les inventaires', 'en' => 'Packaged volumes of the types found in inventories'],
    ];

    private const array ON_DEMAND_SYNC_LABELS = [
        ['fr' => 'Assets des personnages et de la corporation', 'en' => 'Character and corporation assets'],
    ];

    /**
     * @param array<string, array<string, string>>        $scopeUsages
     * @param array<string, array<string, string>>        $syncLabels
     * @param list<array<string, string>>                 $onDemandSyncLabels
     */
    public function __construct(
        private array $scopeUsages = self::SCOPE_USAGES,
        private array $syncLabels = self::SYNC_LABELS,
        private array $onDemandSyncLabels = self::ON_DEMAND_SYNC_LABELS,
    ) {
    }

    public function scopeUsage(string $scope, string $language): string
    {
        return $this->nonEmpty($this->scopeUsages[$scope][$language] ?? null, $scope, $language);
    }

    public function syncLabel(string $messageClass, string $language): string
    {
        return $this->nonEmpty($this->syncLabels[$messageClass][$language] ?? null, $messageClass, $language);
    }

    /**
     * @return list<string>
     */
    public function describedScopes(): array
    {
        return array_keys($this->scopeUsages);
    }

    /**
     * @return list<string>
     */
    public function describedSyncMessages(): array
    {
        return array_keys($this->syncLabels);
    }

    /**
     * @return list<string>
     */
    public function onDemandSyncLabels(string $language): array
    {
        return array_map(
            fn (array $labels): string => $this->nonEmpty($labels[$language] ?? null, 'on-demand sync', $language),
            $this->onDemandSyncLabels,
        );
    }

    private function nonEmpty(?string $description, string $key, string $language): string
    {
        if (null === $description || '' === trim($description)) {
            throw HelpDescriptionMissing::forKey($key, $language);
        }

        return $description;
    }
}
