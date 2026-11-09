# Audit ménage #44 / #45 / #46 — état au 2026-11-01 (branche main, da562d9c)

Méthode : corps des issues lus via `gh issue view`, chaque élément cherché par `grep` dans `src/`, `config/`, `frontend/src`, racine, `CLAUDE.md`. Commits de ménage déjà dans main : 3f441e03 (Refs #44 #45, 67 fichiers, -2200 lignes), 99d16bce, 412a24f6, c8c11944, b39e3380 (Refs #45). Lecture seule, aucun code ni test modifié.

## #44 — Endpoints API non consommés par le frontend

| Élément | État | Preuve | Décision |
|---|---|---|---|
| `GET /me/corporation` | supprimé | 3f441e03 (`CorporationResource`, `CorporationProvider`) ; aucun `uriTemplate` restant | — |
| `GET /me/settings/available-structures` | supprimé | 3f441e03 | — |
| `GET /me/corporation/assets/status` | supprimé | 3f441e03 | — |
| `POST /industry/projects/{id}/regenerate-steps` | supprimé (API) | 3f441e03 (`RegenerateStepsProcessor`) ; la commande `app:industry:regenerate-steps` reste (`src/Command/RegenerateProjectStepsCommand.php:18`), documentée dans les specs : normal | — |
| `GET /industry/structures/rig-options` | supprimé (API) | `RigOptionsResource` supprimé ; `RigOptionsProvider` survit comme helper injecté dans `StructureConfigCollectionProvider.php:24` | Remarque de nommage : la classe s'appelle « Provider » sans être un provider API Platform (non bloquant) |
| `GET /market/groups/{id}` | supprimé | seul `/market/groups` reste (`src/ApiResource/Market/MarketGroupResource.php:18`) | — |
| `GET /contracts/{contractId}/items` | supprimé | 3f441e03 (`ContractItemsResource`, `ContractItemsProvider`) | — |
| `GET /pve/stats/by-type` | supprimé | 3f441e03 (`StatsByTypeProvider`) ; `/ledger/mining/stats/by-type` est un autre endpoint, consommé (`frontend/src/stores/ledger.ts:253`) | — |
| Ansiblex `GET /me/ansiblex` | **encore présent** | `src/ApiResource/Ansiblex/AnsiblexResource.php:21-22` | **à décider** (Intel Map) |
| Ansiblex `POST /me/ansiblex/refresh` | **encore présent** | `src/ApiResource/Ansiblex/AnsiblexResource.php:26-27` | **à décider** |
| Ansiblex `POST /me/ansiblex/discover` | **encore présent** | `src/ApiResource/Ansiblex/AnsiblexResource.php:40-41` ; cité par `CLAUDE.md:226` | **à décider** |
| Ansiblex `GET /me/ansiblex/graph` | **encore présent** | `src/ApiResource/Ansiblex/AnsiblexGraphResource.php:17-18` | **à décider** (le graphe sert directement le pathfinding prévu, `CLAUDE.md:211`) |

Vérifications : aucun appel frontend à `/me/ansiblex*` (le frontend n'utilise Ansiblex que via l'admin `sync-ansiblex`, `frontend/src/stores/admin.ts:246-247`, et Mercure `frontend/src/stores/sync.ts:61`). Aucun appel frontend vers un endpoint supprimé (grep vide), donc pas de 404 introduit.

Écart de comptage : l'issue annonce 13 opérations, elle en nomme 12 (1+4+1+1+1+1+1+1+1). Le 13e élément est **inconnu** (corps : « cf. liste », rapport d'audit Phase 1 non retrouvé dans le repo).

**Conclusion #44 : reste à faire.** Une seule décision : les 4 opérations Ansiblex (garder pour l'Intel Map, en le notant dans l'issue de l'Intel Map, ou supprimer). Une fois tranché, l'issue est fermable.

## #45 — Code mort backend

| Élément | État | Preuve / chemin | Décision |
|---|---|---|---|
| `CharacterVoter` | supprimé | 3f441e03 (+ son test) | — |
| `Pve\MessageResource` | supprimé | 3f441e03 | — |
| message `CheckMarketAlerts` (+ handler, routage messenger) | supprimé | 3f441e03 | — |
| ~40 méthodes de repository sans appelant | supprimées (48) | 3f441e03 | Reste 2 nouvelles, voir constat A |
| `CharacterService::refreshCharacterInfo`, `canReadCorporationContracts` | supprimés | grep vide | — |
| `PveSyncService::syncExpensesFromContracts` | supprimé | grep vide | — |
| `OreValueService::getOrePrices` | supprimé | grep vide | — |
| `JitaMarketService::getBuyPrice` / `getWeightedBuyPrice` | supprimés | grep vide ; `getWeightedBuyPrices` (pluriel, `src/Service/JitaMarketService.php:618`) est vivant | — |
| `IndustryBonusService::getDefaultIntermediateTE` | supprimé | grep vide | — |
| paramètre `$isRoot` (`IndustryTreeService::buildNode`) | supprimé | 3f441e03. Le `$isRoot` de `src/Service/Industry/ProductionStepNeedsResolver.php:65` est une variable locale utilisée, pas le même | — |
| paramètre `$te` (`StockpileService::previewImport/importFromBlueprint`) | **supprimé du service, mais toujours accepté et saisi** | `src/ApiResource/Input/Industry/StockpileImportInput.php:20-21` ; envoyé par `frontend/src/stores/industry/stockpile.ts:58,67,82,90` ; champ affiché `frontend/src/components/industry/StockpileImportPanel.vue:20,131` | supprimer le champ de l'input, du store et du panneau (constat B) |
| `CachedStructure::$resolvedAt` | **encore présent, écrit jamais lu** | `src/Entity/CachedStructure.php:39` ; écrit `src/Command/ResolveAllStructuresCommand.php:233`, `src/State/Provider/Industry/StructureSearchProvider.php:194`, `src/Service/ESI/AssetsService.php:446` ; `getResolvedAt` sans appelant | à décider : lire (TTL de re-résolution) ou supprimer → **migration** (`cached_structure.resolved_at`) |
| `IndustryStepJobMatch::$plannedStructureName` | **encore présent, écrit jamais lu** | `src/Entity/IndustryStepJobMatch.php:53` ; écrit `src/Service/Industry/IndustryJobMatcher.php:258` (valeur par défaut `'Aucune structure'`) | supprimer → **migration** (`industry_step_job_matches.planned_structure_name`) |
| `IndustryStepJobMatch::$plannedMaterialBonus` | **encore présent, écrit jamais lu** | `src/Entity/IndustryStepJobMatch.php:56` ; écrit `src/Service/Industry/IndustryJobMatcher.php:260` | supprimer → **migration** (`planned_material_bonus`) |
| `CachedIndustryJob::$completedDate` | **encore présent, écrit jamais lu** | `src/Entity/CachedIndustryJob.php:68` ; écrit `src/Service/Sync/IndustryJobSyncService.php:220,240,264` | à décider → **migration** si suppression (`cached_industry_jobs.completed_date`) |
| `SharedShoppingList::$createdBy` | **encore présent, écrit jamais lu** | `src/Entity/SharedShoppingList.php:36` ; écrit `src/State/Processor/ShoppingList/ShareListProcessor.php:45` | à décider (utile pour un contrôle d'auteur) → **migration** si suppression (FK `shared_shopping_lists.created_by_id`) |
| `CachedWalletTransaction::$clientId` | **encore présent, écrit jamais lu** | `src/Entity/CachedWalletTransaction.php:49` ; écrit `src/Service/Sync/WalletTransactionSyncService.php:94` | à décider → **migration** si suppression (`cached_wallet_transactions.client_id`) |
| Barèmes de location de ligne | **encore présents, stockés et réaffichés, jamais utilisés dans un calcul** | `src/Entity/User.php:56`, `src/Entity/GroupIndustryProject.php:48` ; la contribution `LineRental` exige une valeur saisie : `src/Service/GroupIndustry/GroupIndustryContributionService.php:42` ; saisis via `frontend/src/components/group-industry/LineRentalModal.vue:47-50` et `NewProjectModal.vue:260-261` | **à décider** : brancher (pré-remplir la valeur d'une contribution `LineRental` depuis le barème, override projet prioritaire, comme le décrit `docs/glossary.md:135`) ou supprimer → **migration** (`users.line_rental_rates`, `group_industry_projects.line_rental_rates_override`) |
| `UserLedgerSettings::shouldCountCorpProject*` | supprimés | b39e3380 (Refs #138 #45). Le réglage `corpProjectAccounting` a bien un effet : `src/State/Provider/Ledger/MiningStatsByTypeProvider.php:101`, `MiningStatsDailyProvider.php:97`, saisi `frontend/src/views/Ledger.vue:76` | — |

### Constat A — 2 méthodes de repository sans appelant, maintenues vivantes par des mocks
- `src/Repository/GroupIndustryContributionRepository.php:96` `countByMember` : aucun appelant dans `src/`. `KickGroupMemberProcessor` ne l'injecte plus (constructeur à 6 paramètres, `src/State/Processor/GroupIndustry/KickGroupMemberProcessor.php:29-36`), mais le test passe un 7e argument (`tests/Unit/State/Processor/GroupIndustry/KickGroupMemberProcessorTest.php:68`, PHP l'ignore silencieusement) et le stubbe à 0, 1 ou 3 (`:215,243,262,291`). Les tests « membre avec contributions » ne testent donc pas ce qu'annonce leur nom : la valeur du stub n'a aucun effet.
- `src/Repository/CachedCharacterSkillRepository.php:27` `findIndustrySkillsForCharacter` : aucun appelant dans `src/` ; stub inutile dans `tests/Unit/Service/Industry/IndustryProjectFactoryTest.php:221`.
- Impact : faux signal de couverture. Un agent qui lit le test croit que le retrait d'un membre dépend du nombre de contributions.
- Test qui le démontre : PHPStan (règle « argument en trop ») ou un test qui vérifie que `countByMember` n'est jamais appelé (`expects($this->never())`) : il passe aujourd'hui.

### Constat B — Champ TE de l'import de stockpile saisi mais sans effet
- Preuve : ci-dessus (`StockpileImportInput.php:21`, `StockpileImportPanel.vue:20,131`, valeur par défaut 20 côté UI, 0 côté API).
- Impact : l'utilisateur règle un TE qui ne change rien à l'aperçu ni à l'import.
- Test : un test fonctionnel `POST` de l'aperçu d'import avec `te: 0` puis `te: 20` montre des réponses identiques.

**Conclusion #45 : reste à faire.**
1. Retirer `te` de `StockpileImportInput`, du store `stockpile.ts` et de `StockpileImportPanel.vue` (sans migration : aucune colonne).
2. Supprimer `countByMember`, `findIndustrySkillsForCharacter`, l'argument en trop et les stubs morts des deux tests.
3. Décider pour les 6 champs écrits jamais lus : chaque suppression est une **migration** de colonne.
4. Décider pour les barèmes de location de ligne (brancher ou supprimer ; la suppression est une **migration** sur 2 colonnes).

## #46 — Ménage frontend, commandes, fichiers racine, CLAUDE.md

| Élément | État | Preuve / chemin | Décision |
|---|---|---|---|
| `PriceChart.vue` | supprimé | 412a24f6 | — |
| `VolumeChart.vue` | supprimé | 412a24f6 | — |
| `useShoppingList.ts` | supprimé | 412a24f6 | — |
| `design-charte-graphique.html`, `design-proposals.html`, `design-proposals-layout.html`, `design-systems-v2.html` | **encore présents**, versionnés, aucune référence | racine | supprimer (ou déplacer sous `docs/design/` si on veut les garder) |
| `design-proposals/` | **encore présent** (3 fichiers versionnés), aucune référence | racine | idem |
| `docs/industry-pipeline-plan.md` | **encore présent**, aucune référence entrante, dernier commit 0f829506 (2026-02-25, V0.12, déjà livré) | `docs/industry-pipeline-plan.md` (il référence `mockups/*.html`) | supprimer ou archiver, en même temps que `mockups/` |
| Hors liste, même nature : `corp-industry-plan.md` (racine, chemins absolus `file:///home/...` aux lignes 80-83), `mockups/` (23 fichiers) | encore présents | racine | même décision que ci-dessus |
| `CLAUDE.md` : Profit Tracker | corrigé | plus de mention ; seule « Profit Margins » (`CLAUDE.md:204`), feature réelle | — |
| `CLAUDE.md` : TODO Ansiblex | corrigé | `CLAUDE.md:226` décrit l'état actuel, exact : `syncFromCharacter` au scheduler (`src/MessageHandler/SyncAnsiblexGatesHandler.php:46`), `syncViaSearch` à la demande (`src/State/Processor/Ansiblex/DiscoverAnsiblexProcessor.php:52`) | à revoir si #44 supprime `/me/ansiblex/discover` |
| `CLAUDE.md` : table Scheduler (#30, fermée) | corrigée, sauf un oubli | `src/Scheduler/SyncScheduler.php` planifie aussi `PurgeExpiredSharedLists` chaque jour (`src/Scheduler/SyncScheduler.php:106`), absent de la ligne « Purge » de `CLAUDE.md:190` | ajouter « listes partagées expirées » à la ligne Purge |
| Commandes one-shot / debug / doublons du scheduler | **non trié** (aucune commande supprimée depuis le 2026-10-01, `git log --diff-filter=D -- src/Command` vide) | voir ci-dessous | à trier |

Commandes présentes (27 `#[AsCommand]`) :
- Debug, aucune référence doc/Makefile : `app:test-ansiblex-discover` (`src/Command/TestAnsiblexDiscoverCommand.php`), `app:test:corp-assets` (`TestCorpAssetsCommand.php`), `app:test:structure` (`TestStructureCommand.php`) → supprimer.
- One-shot probable, aucune référence : `app:fix-token-scopes` (`FixTokenScopesCommand.php`) → à décider (migration de données déjà passée en prod ? **inconnu**).
- Structures, aucune référence : `app:search-structures`, `app:resolve-structure-names`, `app:structures:resolve-all`, `app:clear-structure-market-cache` → à trier (recouvrement probable entre les deux commandes de résolution, non vérifié en détail).
- Doublons manuels du scheduler : `app:ansiblex:sync`, `app:sync-adjusted-prices`, `app:sync-cost-indices`, `app:sync-jita-market`, `app:sync-public-contracts`, `app:sync-structure-market`, `app:sync-wallet-transactions`, `app:purge-market-history`, `app:purge-notifications` → décision de principe : garder comme déclencheurs manuels ou s'appuyer sur les actions admin.
- Backfill documentés, à garder : `app:industry:regenerate-steps`, `app:industry:regenerate-multi-unit-steps`, `app:industry:recalculate-reaction-sell-prices` (specs 278-284, `docs/modules/industry.md`).

Hors liste, détectés en vérifiant le frontend :
- `frontend/src/components/common/DataFreshness.vue` : jamais importé. Créé le 2026-10-28 (c337b723, avec ses tests), donc probablement en attente de câblage : **inconnu** s'il existe une issue de câblage.
- `frontend/src/components/pve/index.ts` : barrel jamais importé (`PveTab.vue:10-12` importe les composants directement) → supprimer.

**Conclusion #46 : reste à faire.**
1. Fichiers racine : 4 `design-*.html`, `design-proposals/`, `docs/industry-pipeline-plan.md` (+ `corp-industry-plan.md`, `mockups/` à trancher ensemble).
2. Tri des commandes : au minimum les 3 commandes `test*` ; décision pour `fix-token-scopes`, les 4 commandes de structures et les 9 doublons du scheduler.
3. CLAUDE.md : ajouter `PurgeExpiredSharedLists` à la table Scheduler.
4. Frontend : `components/pve/index.ts` ; `DataFreshness.vue` à câbler ou suivre.

## Propositions d'issues (si on préfère découper plutôt que garder les 3 issues ouvertes)

1. **Stockpile import: drop the TE field that has no effect** — labels `bug`, `frontend`, `backend`, `industry`. Corps : « The stockpile import panel asks for a TE value and sends it, but neither the preview nor the import reads it since the dead parameter was removed from the service. Remove `te` from the input DTO, the store and the panel. Test: the preview response is identical for te=0 and te=20. »
2. **Remove repository methods kept alive only by test stubs** — labels `tech-debt`, `tests`. Corps : « `GroupIndustryContributionRepository::countByMember` and `CachedCharacterSkillRepository::findIndustrySkillsForCharacter` have no caller. The kick member test passes an extra constructor argument and stubs `countByMember`, so its contribution scenarios do not exercise what their names claim. Remove the methods, the extra argument and the stubs. »
3. **Decide on write-only columns (migration)** — labels `tech-debt`, `migration`, `needs-decision`. Corps : liste des 6 colonnes ci-dessus, avec pour chacune : lire ou supprimer.
4. **Line rental rates: apply them or remove them** — labels `group-industry`, `needs-decision`. Corps : « Rates are stored per user and per project override and shown in two modals, but a line rental contribution still requires a manually entered value. Either pre-fill the contribution from the rates (project override first) or remove the rates (migration on 2 columns). »
5. **Ansiblex endpoints: keep for the Intel Map or remove** — labels `needs-decision`, `api`. Corps : 4 opérations listées, aucun consommateur frontend.
