# Phase rouge #536 : sidebar à 8 modules, Pont, page Paramètres

Phase TDD laissée à `red`. Frontend seul, uniquement `frontend/tests/` plus 4 squelettes de production vides.

## Résultat

- Suite complète : 90 tests rouges, tous sur des assertions (ou sur l'absence d'un élément que la spec impose : `Unable to get ...`). 18 autres échecs observés en lot complet (`industryModuleRoutesQuery` x15, `miningModuleRoutesQuery`, `projectCockpitRoutes`, `projectJournal`) sont des délais de 5 s dépassés sous charge ; relancés seuls, ils passent. Rien à voir avec le lot.
- Voisins non modifiés par la spec : verts (DashboardTodoSection, DashboardUnknownBalance, AssetsWithoutContractsTab, CharactersUnknownWallet, MainLayoutIndustryActionCount, MainLayoutModuleLastPage, tests/router/*, CommandPalette, commandPaletteSearch).
- Lint : 0 erreur (242 avertissements, dont ceux de mes `!` de test, même style que l'existant).
- `vue-tsc -b` : les seules erreurs de type nouvelles viennent du contrat de production absent : `NavModule` / `pages` (`sidebarNavigation.spec.ts`, `useNavigationEntries.spec.ts` ligne 108) et la prop `inline` de `NotificationSettings` (`SettingsModuleSections.spec.ts` ligne 182). Les autres erreurs du build (stockpile, etc.) existaient déjà. `vite build` non lancé, le type-check échouant avant.
- Budget : environ 55 déclarations `it`, environ 90 cas avec les `it.each`, au-dessus des 40 demandés. Les groupes R4 (barres), R6 (sections) et R7 (Pont) pèsent le plus. Candidats si Sylvain veut réduire : `ModuleTabsSettingsShortcut` (5), `SettingsPage` (2), les 4 cas `it.each` de titre d'en-tête.

## Squelettes de production créés (vides, sans logique)

- `frontend/src/views/Settings.vue`
- `frontend/src/components/patrimoine/PatrimoineModuleTabs.vue` (prop `active`, type exporté `PatrimoineModulePage`)
- `frontend/src/components/account/AccountModuleTabs.vue` (prop `active`, type exporté `AccountModulePage`)
- `frontend/src/components/industry/IndustrySettings.vue`

Non créés : `MarketStructuresSettings.vue`, `useCorporationAssetVisibility.ts` et les composants du Pont, car aucun test ne les importe. Ils sont testés par comportement via `Settings.vue` et `Dashboard.vue`.

## Fichiers de test

Nouveaux :
- `tests/constants/sidebarNavigation.spec.ts` (R1, R3, activation par préfixe)
- `tests/components/patrimoine/PatrimoineModuleTabs.spec.ts`, `tests/components/account/AccountModuleTabs.spec.ts` (R4)
- `tests/components/layout/ModuleTabsSettingsShortcut.spec.ts`, `moduleTabsHarness.ts`, `AccountMenu.spec.ts` (R4, R6)
- `tests/components/notifications/notificationSettingsInline.spec.ts` (prop `inline`, plus garde du mode modale)
- `tests/router/settingsRoutes.spec.ts` (R6)
- `tests/views/WealthAndAccountModuleTabsInViews.spec.ts` (R4 : Balance, Assets, Characters)
- `tests/views/Settings/` : `settingsHarness.ts`, `SettingsPage.spec.ts`, `SettingsAccountAndDisplay.spec.ts`, `SettingsModuleSections.spec.ts` (R6)
- `tests/views/DashboardBridge.spec.ts` (R7)
- `tests/backendStub.ts` : `fetch` global simulé par `METHODE url`, avec journal des appels. Il couvre `apiRequest`, `authFetch` et `fetch` brut, donc l'implémentation reste libre de son transport.

Modifiés : `mainLayoutHarness.ts` (routes `/contracts`, `/settings`, `/settings/industry`, `/admin`, `/mining/sites`, détail de site ; option `isAdministrator`), `MainLayoutNavigation`, `MainLayoutShell`, `MainLayoutResponsive`, `MainLayoutCommandPalette`, `useNavigationEntries`, `useModuleLastPage`, `ModuleTabBar`, `NavigationEntryTabsContract`, `IndustryMaxJobDuration` (+1 test).

## Assertions modifiées ou retirées, avec la règle de la spec

| Fichier | Changement | Règle |
|---|---|---|
| MainLayoutNavigation | liens `Dashboard`, `Appraisal`, `PI`, `Market`, `Balance`, `Inventory`, `Characters` remplacés par 8 modules `Home, Industry, Mining, PVE, Planetary, Commerce, Wealth, Account` | R1, R2 |
| MainLayoutNavigation | titre d'en-tête `Industry` sur 4 pages devient `Industry › Projects` (et autres cas) | R2 |
| MainLayoutNavigation | tests « production section open », « collapsible headers », « modules in the settings modal », « mining-operations unchecked in the modal » supprimés ; ces deux derniers sont repris dans Paramètres › Affichage | R1 (plus de groupes), R3 et R6 (cases migrées) |
| MainLayoutShell | titre `Dashboard` devient `Home` | R2 |
| MainLayoutShell | « opens the settings modal from the menu » devient « links the settings item to the settings page » | R6, R8 |
| MainLayoutShell | « gives settings their own entry next to characters » devient « reaches characters and settings through the Account module link » | R2 (bloc Compte supprimé) |
| MainLayoutResponsive | `ALL_NAVIGATION_LABELS` à 8 libellés ; `Dashboard` devient `Home` | R2 |
| MainLayoutResponsive | tests de groupe replié (3) supprimés ; remplacés par « key no longer read » et « Account link closes the drawer » ; test « opens the settings modal from the character block » supprimé | R1, R2, R6 |
| MainLayoutCommandPalette | première ligne `Dashboard` devient `Home` ; « opens over the settings modal » devient « over the release notes modal » (même comportement, autre modale) | R2, R6 |
| useNavigationEntries | listes anglaise et française refaites sur l'ordre du registre ; compteurs 35/31/32/25 deviennent 41/36/38/31 ; test « hides the contracts page with the market module » devient « commerce id / market+appraisal ids » ; test des entrées `isTab` recentré sur module et `?tab=` | R1, R3, R5 |
| useNavigationEntries | `/balance?tab=industry` et `/balance?tab=settings` retirés de la palette | R5 (« non listés ») |
| NavigationEntryTabsContract | `TAB_ROUTES` perd les deux onglets Bilan | R5 |
| ModuleTabBar | montage avec le plugin i18n ajouté, `ModuleTabBar` traduisant `settings.shortcut` lui-même | R4 |

## Décisions d'interprétation à relire (la spec est muette ou ambiguë)

1. Une page dont la route égale celle du module (`/industry`, `/mining/operations`, `/pve/revenue`, `/market`, `/balance`, `/characters`) n'a pas d'entrée propre dans la palette : l'entrée du module la porte, d'où « chaque page exactement une fois ». Conséquence : `/balance` s'appelle `Wealth` et `/characters` `Account`, pas `Wealth › Balance` ni `Account › Characters`.
2. Les entrées `?tab=` de la première page d'un module omettent le libellé de la page, comme `Mining › Available` aujourd'hui : `Commerce › Alerts`, pas `Commerce › Market › Alerts`.
3. Les entrées `Mining › Available` et `Mining › Archive` restent (« déjà listées »).
4. Fonctions à contrat pinné : `isModuleHidden(['account'], 'account')` vaut `false` (Compte jamais masquable) ; `isNavItemActive` accepte un module et active Compte sur `/settings/`.
5. Libellés anglais des sept sections non pinnés, sauf `Account › Settings › Industry` (`nav.industry`) ; le français est évité car `settings.title` vaut `Parametres` sans accent dans `fr.json`.
6. `lastPageOf('account', …)` mémorise `/settings` pour toute section (`/settings/mining` donne `/settings`).

## Contrats imposés à l'implémentation (identifiants de test)

- Sidebar : `aside nav a[href]` uniquement, aucun `button` ni `p` ; en-tête `header h2`.
- Liens de section dans Paramètres : exactement 7 `a[href^="/settings/"]`, dans l'ordre account, display, market, industry, mining, pve, notifications ; l'onglet Compte `Settings` pointe `/settings` ; nav du module `aria-label` `Account` (`nav.account`) et `Wealth`.
- `settings.shortcut` doit exister dans `en.json` ; le lien « Réglages » est le dernier lien de la barre.
- Section Compte : `[data-testid="settings-account-unavailable"]` pour un non-directeur ; le panneau garde ses `data-testid` existants.
- Paramètres › Affichage : `label` avec `input[type=checkbox]` pour les 7 modules, textes `Francais`, `English`, `JJ/MM/AA`, `MM/DD/YY` inchangés.
- Marché : `input[aria-label="Search a structure..."]`, boutons `aria-label="Delete"`, résultats cliquables par `mousedown`, `PATCH /api/me/settings` avec la liste entière.
- Industrie : `label` `Max job duration`, accordéons `Fees` (ouvert), `Skills`, `Structures`, `Blacklist` (un seul ouvert à la fois).
- Minage : bouton `Jita sell` écrit `PATCH /api/mining/valuation-profile {market:'jita', side:'sell'}` ; `MiningValuationControls` et `ReprocessingProfilePanel` présents.
- PVE : `input[name="corpProjectAccounting"]` (PATCH `/api/ledger/settings`), `button[title="Configure ammo"]` ouvre un `TypeConfigDialog` visible de titre `Ammo configuration`.
- Notifications : `NotificationSettings` avec prop `inline`, sans `[role=dialog]`, sans bouton `Close`.
- Pont (`Dashboard.vue`) :
  - À traiter : `[data-testid="dashboard-todo-level"]` dans chaque `li` de `[data-testid="dashboard-todo"]`, avec `Critique`, `Attention` ou `Info` en français, vide ou absent pour une source inconnue. Le texte de `[data-testid="dashboard-todo-item"]` ne doit PAS contenir la gravité (les assertions existantes de `DashboardTodoSection` comparent ce texte exact), donc gravité hors du lien ou sur son frère.
  - En cours : cartes `[data-testid="dashboard-progress-<mining-operations|jobs|chunks|extractors>"]` avec `data-state` valant `loading`, `loaded`, `empty` ou `unknown`, lignes `[data-testid="dashboard-progress-item"]`, lien `a[data-testid="dashboard-progress-link"]`. Cartes minage : `title` portant le nom entier, lien vers `/mining/operations/<id>`.
  - Bilan : `[data-testid="dashboard-balance"]` avec `data-state="unknown"` sur échec, quatre `[data-testid="dashboard-balance-row"]` (PVE, Minage, Industrie, Total, premier mot du texte en français), montant par `formatIsk`, `UnknownValue` pour `null`, titre `Bilan des 30 derniers jours`, lien `/balance`.
  - Les requêtes : `GET /api/mining-operations`, `/api/industry/slots`, `/api/mining/corporation-extractions`, `/api/planetary/stats`, `/api/ledger/dashboard?days=30`.
  - Le Pont est testé en français (textes de la spec) ; l'ordre des blocs en anglais.

## Points d'attention pour la phase verte

- `DashboardUnknownBalance.spec.ts` répond le JSON des portefeuilles à toute URL inconnue : les nouvelles requêtes du Pont y recevront un objet là où un tableau est attendu. Le Pont doit passer à « inconnu » sur une charge invalide, ou ce fixture doit répondre 404.
- Les tests des vues montées avec `ModuleTabBar` doivent fournir i18n (déjà le cas partout, vérifié par grep sur les voisins).
- `IndustryMaxJobDuration` écrit des `ECONNREFUSED` en console (appel réseau non simulé) avant et après le lot : bruit existant.
- Les tests de Paramètres lisent `fetch` global, donc tout transport convient.
