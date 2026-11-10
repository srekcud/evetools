# Câblage #531 (module Minage : 5 pages, ModuleTabBar, pont chunk ↔ opération)

Spec : `docs/specs/531-mining-module-pages.md`. Références absentes du worktree : `.claude/tasks/ux-architecture-2026-11-09.md` et `docs/design/mockups/navigation.html` (introuvables, `mockups/` n'a que des maquettes d'opération).

## Dépendances de l'implémentation cible

**Front, routage et navigation**
- `frontend/src/router/index.ts` (routeur singleton `createWebHistory`, l.149-171) : 5 pages `/mining/*` (noms R1, dont nouveaux `mining-moons`, `mining-sites`, `mining-ore-value`, `mining-my-mining`), `/mining` → `/mining/operations`, redirections R2 (fonction `redirect` qui lit `to.query`, sur le modèle de `/join/:code` l.143), `beforeEnter` sur `/ledger` pour `tab=mining`. Écriture de la dernière page visitée : `router.afterEach` (recommandé : n'impose rien aux tests de vue qui moquent `useRoute`).
- `src/constants/sidebarNavigation.ts` : groupe `mining` (l.37) avec un enfant `mining-operations` (l.42). Entrée unique `mining`, `activePathPrefixes: ['/mining/']`.
- `src/composables/useNavigationEntries.ts` : `TABS_BY_MODULE['mining-operations']` (l.56) et `ledger.mining` (l.43) ; palette des 5 pages construite comme `projectEntries` (l.103).
- `src/layouts/MainLayout.vue` : lien sidebar `mining` résolu par `useModuleLastPage` ; liste des modules masquables (`settableModules`, l.590).
- Nouveaux : `src/components/layout/ModuleTabBar.vue`, `src/composables/useModuleLastPage.ts`, vues `src/views/Mining/` (Moons, Sites, OreValue, MyMining ; Operations = `MiningOperationList.vue` existant).

**Front, contenus déplacés**
- `components/ledger/MiningTab.vue` : sous-vues `entries|oreValue|moonChunks|moonTax` (l.30), sélecteur de structure et taxe d'export (l.44, l.127) qui alimentent aussi `fetchMiningEntries` (l.200) : Mon minage doit continuer à les **lire**. `views/Ledger.vue` : onglet `mining` (l.22-23, 153, 196).
- `MiningOperationList.vue` : onglets `season`, `sites` (l.21-24, 236-238) et gestion `?chunk=` (l.140-183) à déplacer vers Lunes.
- `MoonChunksTab.vue` : bouton `MoonChunkOperationButton` (l.326, 388), modale (l.412) ; ajout « Opération liée → » / « +N ».
- `MiningSiteDetail.vue:171` (retour → `mining-sites`, lien opération), `MiningSiteHistory.vue` (`COLUMNS` l.12 + `operation`), `MiningStockpileCoverage.vue:11` (→ `{ name: 'mining-ore-value' }`), `MiningCompanionHost.vue:358` (→ `mining-moons` + `chunk`), `views/MiningOperation/miningOperationJoinUrl.ts:2`, `NewMiningOperationModal.vue` (inchangé, émet `open`).
- Types : `composables/useCorporationMiningExtractions.ts:40` (`linkedOperation`, `linkedOperationCount`), `stores/miningOperation.ts` (`chunk`).

**Back**
- `src/Service/MiningOperation/MiningOperationNotifier.php:24` et `src/Service/MoonChunk/MoonChunkAlertSender.php:23` : constantes de route.
- `src/State/Provider/Mining/CorporationMiningExtractionsProvider.php` (`extractionView` l.129) + `src/ApiResource/Mining/CorporationMiningExtractionsResource.php` (PHPDoc des extractions). Nouvelle dépendance : `MiningOperationRepository`, méthode neuve d'une seule requête pour tous les chunks visibles (pas de N+1).
- `src/Repository/MiningOperationRepository.php` : critères à recopier de `findForParticipant` (l.68 : propriétaire ou membre `pending|accepted`) et de la branche membre de `findArchivedReadableBy` (l.92, `accepted`), **sans** sa clause `visibility = corporation` (voir contradictions).
- `src/State/Provider/MiningOperation/MiningOperationResourceMapper.php:38` : `operationToResource` ne connaît pas le lecteur (seulement `?MiningOperationMember`). Recommandé : paramètre `User $viewer` ajouté aux 13 appelants (`grep operationToResource( src`) ; ne pas injecter `Security` au constructeur (≈30 `new MiningOperationResourceMapper(new MiningPresenceResolver(...))` dans `tests/Unit` casseraient). `src/ApiResource/MiningOperation/MiningOperationResource.php:301` : ajouter `?array $chunk`. Relation `MiningOperation::$chunk` (`onDelete: SET NULL`) déjà là ; jointure à prévoir dans les providers de liste.

## Harnais de test

- **Redirections (E1-E6)** : routeur réel `import router from '@/router'` + `router.push` + `currentRoute`, store auth authentifié et `vi.mock('vue-router')` comme `frontend/tests/views/GroupIndustry/industryJoinRoute.spec.ts`. `router.resolve` ne suit pas les redirections : tester par `push`.
- **Dernière page (E7, E8)** : `frontend/tests/layouts/mainLayoutHarness.ts` (routeur mémoire) ; ajouter `/mining/operations`, `/mining/moons` à `navigationRoutes` et une option `storedModuleLastPage` (le harnais vide `localStorage` au montage). E8 : valeur `'{'` dans `evetools_module_last_page`.
- **ModuleTabBar** : `mount` + `createRouter({ history: createMemoryHistory() })` (modèle `notificationMoonChunkCategory.spec.ts`) pour `aria-current`. Les tests de vue existants stubbent `'router-link'` sans routeur : ModuleTabBar doit passer par ce stub ; actif calculé par prop (`activeId`) ou chemin, pas par `router-link-active` (le détail `/mining/operations/:id` doit activer « Opérations »).
- **Lunes / chunks (E9-E11)** : `frontend/tests/components/ledger/moonChunksHarness.ts` (`extraction()` : ajouter `linkedOperation: null`, `linkedOperationCount: 0`, tsconfig inclut `tests/**`), montage de `moonChunkOperation.spec.ts` (sans routeur : passer `RouterLinkStub`, cf. `miningStockpileCoverage.spec.ts:86`). `?chunk=` : reprendre `MiningOperationListCompanion.spec.ts` l.108-145 sur la page Lunes.
- **Détail opération (E12)** : `views/MiningOperation/miningOperationLedgerFixtures.ts` (objet opération complet l.121 : ajouter `chunk: null`). **Sites (E13)** : `views/MiningSite/miningSiteFixtures.ts` (`operationId` l.124), montage `MiningSiteDetail.spec.ts:54`.
- **Back, provider** : `tests/Unit/State/Provider/Mining/CorporationMiningExtractionsProviderTest.php` construit le provider par `autowired()` de `tests/Unit/Service/Sync/CorporationMiningExtractionHarness.php` : ajouter un stub `MiningOperationRepository` à `boundaries()` (l.441), sinon `LogicException` sur les 13 tests.
- **Back, fonctionnel** : `tests/Functional/Api/MiningOperation/MiningOperationChunkApiTest.php` (WebTestCase, `PersistsMiningOperations`, helper `chunk()`, pilotes C1/C2) ; `persistMiningOperation()` + `->setChunk()`, `addMiningMembership()`.

## Tests existants appelés à casser (nouvelle valeur)

Cités par la spec, confirmés : `useNavigationEntries.spec.ts` l.63-69, 150-154 ; `NavigationEntryTabsContract.spec.ts` l.39 (`VIEWS_BY_ROUTE` : `/mining/operations`, `/mining/moons`, `/mining/my-mining`), l.103-106 (nouvelle liste `TAB_ROUTES`) ; `MainLayoutNavigation.spec.ts` l.36, 49, 58 (titre « Mining » si le groupe disparaît), l.59 (réglages : `Mining`) ; `CommandPalette.spec.ts:46` ; `commandPaletteSearch.spec.ts:10` (ligne retirée) ; `miningSiteRoute.spec.ts:6` (`/mining/sites/<id>`) ; `MiningCompanionHostFree.spec.ts:117-118` (`mining-moons`) ; `MoonChunkAlertSenderTest.php:42` (`/mining/moons`) ; `MiningOperationNotificationsApiTest.php:35, 60, 90` (`/mining/operations/<id>`) ; `miningTabMoonChunks.spec.ts` et `MiningOperationList.spec.ts:275-310` (à supprimer ou porter sur Lunes / Mon minage / Sites).
Non cités par la spec :
- `MainLayoutResponsive.spec.ts:8-9` (libellé `Mining operations` → `Mining`).
- `miningOperationJoinUrl.spec.ts:9` (`/mining/operations/join/<code>`), l.13-21 (`resolve` d'anciens chemins).
- `GroupIndustry/industryJoinRoute.spec.ts:77-79` (`resolve('/mining-operations/join/AB12CD')` → `/mining/operations/join/AB12CD`).
- `miningTabMoonTax.spec.ts` (`data-mining-view="moonTax"`, même sort que moonChunks).
- `miningStockpileCoverage.spec.ts:86` (`{ name: 'mining-ore-value' }`).
- `MiningOperationListCompanion.spec.ts:108-145` (`?chunk=` déplacé vers Lunes).
- `LedgerIndustryTab.spec.ts:72-75` (« Industrie entre Minage et Escalations » → entre PVE et Escalations).
- `RecordsMiningOperationNotifications.php:76` (`operationRouteOf`, utilisé par `MiningOperationNotifierTest`).
- `CorporationMiningExtractionsProviderTest.php` (tous : harnais l.441 ; et `corporationEntry` l.211 doit retirer `linkedOperation`/`linkedOperationCount` comme `chunkValue`).
- Si `MiningTab.vue` est supprimé : `miningLedgerHarness.ts` l'importe, et 21 fichiers importent ce harnais (dont `i18nPlugin`) ; garder `MiningTab` comme contenu de Mon minage (onglet `ledger`) évite la casse de `miningTabUnknownStats`, `miningTabMissingPriceTypes`, `miningYieldFromProfile`.
Ne cassent pas (spec à corriger) : `notificationMiningOperationCategory.spec.ts`, `notificationMoonChunkCategory.spec.ts` (route = fixture, routeur `routes: []`), `LedgerPeriodAll.spec.ts` (stub `MiningTab: true` mort). Les chemins `/mining-operations/...` des stores sont des URL d'API, inchangées.
Exhaustifs voisins : listes de navigation ci-dessus ; `COLUMNS` de `MiningSiteHistory` (aucun test ne la fige) ; pas de snapshot OpenAPI ; `MiningOperationSubResourceUriVariablesTest` (URL d'API, intact) ; `NavigationEntryTabsContract` lit `useTabQuery.mock.results[0]` : Lunes et Mon minage doivent appeler `useTabQuery` en premier.

## Découpe rouge (14 front, 4 back)

- Front : redirections `/mining-operations*` (it.each E1, E2, E3, E6, join, site) ; redirections `/ledger?tab=mining*` (E4, E5, oreValue, moonChunks) ; `/mining` ; E7 ; E8 ; ModuleTabBar (role, aria-current sur détail, count, `title`) ; E10 ; E9 + E11 (bouton, modale préremplie) ; chunk explosé avec opération liée ; Lunes `?chunk=` connu / inconnu ; E12 ; carte de liste « Lune liée → » ; E13 (+ retour `mining-sites`) ; colonne Opération de `MiningSiteHistory` ; Mon minage lit/écrit `ledgerSelectedDays`.
- Back : fonctionnel E10 (la plus récente par `plannedStartAt` parmi une active et une archivée, count 2) ; R9 accès (opération d'un collègue, visibilité corpo, archivée comprise : `null`/0) ; R10 accès (même opération lue par le propriétaire C1 : bloc rempli ; par un participant C2 : `null`) ; constantes de route : mise à jour des tests existants, aucun test neuf.
- Sans test : i18n, style HUD, 360 px (contrôle visuel), libellé « Planifier l'opération », `useTabQuery`, vue d'ensemble de Revenus.

## Contradictions et questions ouvertes

1. R10/E12 : la forme `chunk` `{ id, moonName, structureName, chunkArrivalTime }` n'a ni `moonId` ni `structureId` ; E12 exige l'identifiant de la lune. Ajouter `moonId`, `structureId`.
2. R9 « mêmes critères que `findArchivedReadableBy` » : cette méthode inclut les opérations archivées visibles par la corporation (pas seulement participant), ce qui contredit « jamais celles d'un autre membre ». Recommandé : propriétaire ou membre, sans la clause corpo.
3. R5/R6 « id `mining` conservé » : `mining` est l'id du **groupe** ; l'id masquable aujourd'hui est `mining-operations`. Un utilisateur qui l'a masqué le reverrait. Proposé : `LEGACY_MINING_OPERATIONS_MODULE_ID` comme `group-industry`. Et : groupe à un enfant ou entrée à plat ? (change le titre `Mining` des tests).
4. R6 : `?tab=mine` en entrée de palette ferait doublon avec `/mining/operations` ; libellés palette (« Mining › Moons » ?) à fixer avant d'écrire `useNavigationEntries.spec`.
5. R10 « colonne Lune » : la liste `mine`/`available` est une grille de cartes, pas un tableau (seule l'archive est un tableau).
6. R2/R8 : les clés `miningSelectedStructure` et `miningExportTax` sont aussi lues par Mon minage (prix des lignes du ledger, `MiningTab.vue:200`), pas seulement par Valeur du minerai.
