# Audit Mining / PVE / Wallet — 2026-10-11

Auditeur en lecture seule, main @ 9a16496. Infection : `PveSyncService` MSI 50 % (135 non détectés sur 271), `MiningSyncService` 69 % (44 sur 142).

## État des issues existantes
- #11 pagination : corrigée (minage `MiningSyncService.php:120`, journal PVE `PveSyncService.php:251`, contrats `:414`).
- #12 sync terminée malgré l'échec : corrigée côté service ; la réponse HTTP de la sync minage manuelle ne l'est pas (P2-3).
- #37 total wallet partiel : corrigée (`WalletProvider.php:85-89`, `Dashboard.vue:34-35`).
- **#38 totaux minage incohérents : fermée SANS correction** (`MiningBestValueCalculator.php:147` appelle `getBatchOrePrices($typeIds)` sans paramètres ; `MiningTab.vue:203` sans rendement ni structure ; aucun commit depuis 2026-02-25 hors 3f441e0). **À rouvrir.**
- #79 sync PVE toujours « success » : corrigée pour PVE seulement (P2-3).

## P1
- **P1-1. Minerai compressé valorisé 100× trop bas** — `bug`, `mining`. `OreValueService.php:15-16` (`COMPRESSION_RATIO = 100`), `:132` ; `MiningBestValueCalculator.php:174` (`/ 100`) ; `frontend/src/composables/useMiningPricing.ts:19,42`. SDE : Veldspar / Compressed Veldspar même portion_size 100 et matériaux `34:400` ; Bitumens idem ; Clear Icicle idem. Impact : colonne « Jita compressé » à 1 % de la vraie valeur, le compressé n'est jamais « meilleur prix », totaux sous-évalués. Test : brut + compressé, prix compressé 1000 → `compressedEquivalentPrice == 1000.0` (obtenu 10.0).
- **P1-2. Rouvrir #38** — `bug`, `mining`. Trois implémentations de la valeur d'une entrée avec trois replis différents (backend `MiningBestValueCalculator` cache Jita sans repli ; front `useMiningPricing` `totalValue/quantity` ; front `mainSystem` `entry.totalValue`). Preuve : `MiningBestValueCalculator.php:147`, `MiningEntryCollectionProvider.php:72-77`, `useMiningPricing.ts:22-23`, `MiningTab.vue:177,186-189,203`, `MarketService.php:55` vs `JitaMarketService.php:201-219`.
- **P1-3. Une dépense importée supprimée est réimportée à la sync suivante** — `bug`, `pve`. `DeleteExpenseProcessor.php:39-49` (pas d'`addDeclined*`) ; `PveSyncService.php:527-529,560` ; comparer `DeleteLootSaleProcessor.php:49-55`. Test : import, suppression, re-sync → 0 import attendu, 1 obtenu.

## P2
- **P2-1. Les scans PVE avalent toutes les erreurs** — `bug`, `pve`. `ScanLootSalesProcessor.php:144-146,214-216,264-266,424-426` ; `ScanLootContractsProcessor.php:117-119,161-163` ; `ScanContractsProcessor.php:153-155,201-203,256-258` ; front `usePveExpenseScanning.ts:160-161`. Token expiré → « 0 vente détectée ».
- **P2-2. Contributions aux projets corpo : une seule par (projet, personnage)** — `bug`, `pve`. Identifiant `-abs(crc32(projectId_characterId))` (`ScanLootSalesProcessor.php:393`), `contributed` cumulatif (`:373`), skip `:395-397`, date du jour en repli `:418`. Collision possible avec `-contractId` (`:200`, non mesurée). Unité de `reward_per_contribution` (`:353`) inconnue.
- **P2-3. Sync minage manuelle « partial » quand tous les personnages échouent** — `bug`, `mining`. `SyncMiningProcessor.php:40` vs `SyncPveProcessor.php:48-52` (#79) ; aucun test.
- **P2-4. Après une sync minage manuelle, la liste est rechargée avec les paramètres par défaut** — `bug`, `mining`, `ux`. `stores/ledger.ts:315-319`, `:198-209` ; `Ledger.vue:59-65,182` ; comparer `MiningTab.vue:201-203`.
- **P2-5. `?? 0.0` sur le prix d'un minerai** — `bug`, `mining`. `MiningBestValueCalculator.php:12` (docblock faux), `:46,84,123,182` ; `MiningStatsByTypeProvider.php:65` ; `MiningEntryRepository.php:137,179`.
- **P2-6. Branche « contrats » de `ScanLootSalesProcessor` : code mort coûteux** — `tech-debt`, `pve`, `perf`. `ScanLootSalesProcessor.php:149-267` (appel ESI `:210-213`) ; front `usePveExpenseScanning.ts:164` jette `source === 'contract'` ; dérive avec `ScanLootContractsProcessor.php:54,101` (types, rôles, clé de dédup).
- **P2-7. Sync wallet : un remplissage interrompu n'est jamais repris** — `bug`, `infra`. `WalletTransactionSyncService.php:43` (50 pages max), `:101,103-106,120-131` ; figé par `WalletTransactionSyncServiceTest.php:207`. Impacte les suggestions d'achat industrie.
- **P2-8. Projets corpo comptés selon trois règles différentes** — `bug`, `pve`, `mining`. `UserLedgerSettings.php:41` ; `LedgerDashboardProvider.php:57,70-73` ; `LedgerDailyStatsProvider.php:52,55` ; `MiningStatsProvider.php:86-88`, `MiningStatsByTypeProvider.php:92-94`, `MiningStatsDailyProvider.php:93-95` ; `IncomeProvider.php:69,103` / `PveIncomeRepository.php:239` ; `getExcludeUsages()` copiée 3 fois ; `shouldCountCorpProjectIn*` (`:201-212`) sans appelant.

## P3
- P3-1. Premier jour de la période toujours à 0 en minage (`$from` garde l'heure, colonne `date`) : `MiningStatsDailyProvider.php:45,60-66`, `MiningEntryRepository.php:55-58,117-118`, `MiningEntry.php:40`, même schéma `LedgerDailyStatsProvider.php:47,67`. — `bug`, `mining`
- P3-2. Un nouveau prix réécrit tout l'historique du type (`MiningSyncService.php:296-310`, `MiningEntryRepository.php:196,214`). — `bug`, `mining`
- P3-3. Taxe d'export appliquée au brut et au compressé, pas au retraitement (`OreValueService.php:92-98,125-129` vs `:146-157`). — `bug`, `mining`
- P3-4. Pas de contrainte d'unicité sur `pve_income` / `pve_expenses` (`PveIncome.php:15-17`, `PveExpense.php:14-16`, `PveSyncService.php:236-237,263`) : sync manuelle et planifiée peuvent doubler. — `bug`, `pve`
- P3-5. Montant entier d'un contrat compté dès qu'un item est du loot ; `price` toujours utilisé même côté émetteur (`PveSyncService.php:457,473-478`, `ScanContractsProcessor.php:173-206` vs `ScanLootSalesProcessor.php:186-194`). — `bug`, `pve`
- P3-6. `/wallet/transactions/` lu par cinq chemins (`WalletTransactionSyncService.php:52`, `PveSyncService.php:330,543`, `ScanLootSalesProcessor.php:91`, `ScanContractsProcessor.php:215`), seul le premier pagine. — `tech-debt`, `pve`, `infra`
- P3-7. Code mort : `PveIncomeRepository::getDailyTotals` (`:108-133`, en plus faux), `UserLedgerSettings::shouldCountCorpProjectIn*`, `GET /api/pve/loot-sales`, `suggestedPrice` / `PVE_LOOT_DEFAULT_PRICE_PER_ITEM`, totaux de `/pve/income` non affichés. — `tech-debt`, `pve`, `mining`
- P3-8. Sans ligne de réglages, l'auto-sync PVE ne tourne pas (contrairement au minage) : `PveSyncService.php:32,71-73`, `UserPveSettings.php:94`, `MiningSyncService.php:22,48-50`, `SyncScheduler.php:62,70`. — `bug`, `pve`
- P3-9. Tests de sync qui comptent des appels au lieu des données (mutants `PveSyncService.php:335,548,422,433,457,278-282` ; `MiningSyncService.php:144,167,270-279,306`) ; aucun test pour `OreValueService`, `MiningBestValueCalculator`, providers de stats, scans PVE, `DeleteExpenseProcessor`, `SyncMiningProcessor`. — `tech-debt`, `pve`, `mining`
- P3-10. Unicité globale de `transaction_id` dans le cache wallet (`CachedWalletTransaction.php:14`, `CachedWalletTransactionRepository.php:57-62`) : à vérifier en jeu. — `investigation`, `infra`
- P3-11. Glossaire : aucun terme minage / PVE / wallet. — `docs`, `mining`, `pve`

Escalations : rien de majeur ; nom et sécurité du système fournis par le client (`CreateEscalationProcessor.php:63-65`).

Ordre suggéré : P1-1, rouvrir #38, P1-3, P2-5 + P3-11, P2-8.

## Constat ajouté par la caractérisation (2026-10-11)
- PVE : valeurs par défaut sur les transactions ESI (quantité absente = 1, prix unitaire absent = 0 → ignorée ; `PveSyncService.php:356-357`, `:569-570`), contrat sans `price` ignoré (`:473`), entrée de journal sans `amount` = 0 (`:272`, non testée). Figés dans le groupe `pve-esi-defaults`. — `bug`, `pve`
- **[P1 probable] Les prix du minage ne se mettent jamais à jour quand le prix est fractionnaire** — `bug`, `mining`. `MiningEntryRepository::updatePriceByTypeId` (SQL natif, `:unitPrice` utilisé deux fois dont `quantity * :unitPrice`) : PostgreSQL type le paramètre en bigint, tout prix non entier (ex. 11.75) fait échouer la mise à jour, la sync finit en erreur globale sans enregistrer l'heure de sync. Le commentaire du repository affirme le contraire. Reproduit en intégration (caractérisation DC, groupe `mining-proposal-fractional-price-bigint`). Base de dev : 66 entrées valorisées, 0 prix fractionnaire, 21 sans prix. Prod non vérifiée.
- Sync minage manuelle sans aucun jeton : répond « success » (la sync PVE renvoie 400). Groupe `mining-proposal-sync-without-token`. — `bug`, `mining`
- P3-1 (premier jour à 0) NON reproduit sur PostgreSQL d'après la caractérisation DC (à confirmer par la relecture) : requalifier.
