# Spec #243 — Scan des projets corporation : personnage lecteur valide, personnages à reconnecter, erreur d'authentification

**Statut : validée en autonomie en mode nuit (périmètres A et B) le 2026-10-30, à relire par Sylvain.**

Circuit : `circuit:m` (pas de note de câblage, étape 1b de `/tdd-red` sautée). Tests unitaires seulement, doublures aux frontières ESI (`MockHttpClient` derrière un vrai `EsiClient`) et `TokenManager` (stub), comme `ScanLootSalesProcessorCorpProjectsTest` le fait déjà. Aucun test d'intégration : aucune donnée en base n'est lue par la partie modifiée.

Périmètre A (livré, commits `c1d264b2` à `78de6f7e`) : la cause vérifiée du « 0 projects scanned » de l'issue #243, sections « Contexte » à « Fichiers de production à modifier » ci-dessous. Périmètre B (décidé par Sylvain le 2026-10-30, options recommandées de B1 à B5) : section « Périmètre B (décidé) », en trois lots.

## Contexte

`POST /api/pve/scan-loot-sales?days=N` (`ScanResultResource`, sortie `ScanLootSalesResultResource`, processor `ScanLootSalesProcessor`) lit, après les transactions et les contrats, les projets de chaque corporation joueur des personnages de l'utilisateur.

- Les appels au niveau corporation (liste des projets, contributeurs, détail d'un projet) prennent le token du **premier** personnage de la corporation qui a un token (`ScanLootSalesProcessor.php:274-297`), sans regarder son statut d'autorisation (`Character::isAuthValid()`, spec #86).
- Si ce personnage est en auth invalide, `TokenManager::getValidAccessToken()` lève `EveAuthRequiredException` (`refuseRevokedCharacter()`, `TokenManager.php:213-220`) ; le `catch (\Throwable)` de la ligne 426 journalise un `warning` et abandonne toute la corporation. Constaté sur une corporation où 6 personnages sur 7 sont invalides, dont le premier : un seul personnage valide aurait suffi.
- L'écran affiche alors « 0 projects scanned - 0 sales detected » (`LootSaleScanDialog.vue:39`, clé `pve.lootScanSummary`), indiscernable d'une corporation sans projet. Le composable remplace en plus un `scannedProjects` absent par 0 (`usePveExpenseScanning.ts:208`, `|| 0`), et le dialogue aussi (`LootSaleScanDialog.vue:39`).
- Les appels de contribution par personnage (`ScanLootSalesProcessor.php:357`) échouent de la même façon pour un personnage invalide, avalés par `catch (\Throwable) { continue; }` (`:417`).

## Règles métier

- **R1 — Lecteur des projets.** Pour chaque corporation joueur (identifiant ≥ 2 000 000) dont au moins un personnage de l'utilisateur a un token, le scan choisit comme **lecteur des projets** le premier personnage de cette corporation, dans l'ordre des personnages de l'utilisateur, qui a un token **et** une autorisation valide (`isAuthValid()`). Un personnage invalide n'est jamais essayé : aucun appel `TokenManager` ni ESI avec son token.
- **R2 — Essai du suivant.** Si `getValidAccessToken()` ou la lecture de la liste des projets lève `EveAuthRequiredException` pour le lecteur (refresh refusé par EVE SSO alors que le statut était valide au départ), ce personnage devient **à reconnecter** et le scan essaie le personnage valide suivant de la corporation. Le premier qui lit la liste reste le lecteur pour les contributeurs et le détail des projets de la corporation.
- **R3 — Corporation sans personnage valide.** Si aucun personnage de la corporation ne peut lire la liste (aucun valide, ou tous refusés selon R2), la corporation est ajoutée à `corporationsWithoutValidCharacter` (nom de la corporation, `Character::getCorporationName()`), aucun de ses projets n'est compté, et `scannedProjects` vaut `null` (**inconnu**) pour tout le scan, même si d'autres corporations ont été lues : un total qui contient un inconnu est inconnu, jamais un 0 plausible.
- **R4 — Contributions.** Les contributions ne sont demandées que pour les personnages de l'utilisateur qui sont contributeurs du projet, ont un token et sont valides à ce moment. Un personnage invalide qui a contribué n'est pas appelé. Si l'appel de contribution d'un personnage lève `EveAuthRequiredException`, il devient à reconnecter, sa contribution n'est pas détectée, et les autres contributeurs du projet sont traités normalement.
- **R5 — Personnages à reconnecter.** `charactersToReconnect` liste les noms des personnages de l'utilisateur, avec token, qui sont en auth invalide au début de la lecture des projets corporation **ou** qui ont levé `EveAuthRequiredException` pendant cette lecture (R2, R4), sans doublon, dans l'ordre des personnages de l'utilisateur. Tous les personnages comptent, corporation PNJ comprise : le scan des transactions et des contrats les ignore aussi.
- **R6 — Autres erreurs inchangées.** Une erreur ESI qui n'est pas `EveAuthRequiredException` (500, 403, 401 de l'ESI, réseau) garde le comportement actuel : corporation abandonnée avec un `warning` journalisé sur la liste des projets, projet ignoré sur les contributeurs ou le détail, contribution ignorée. Pas d'essai d'un autre personnage sur ces erreurs.
- **R7 — Écran.**
  - `scannedProjects` à `null` : le résumé affiche « projets : inconnu » à la place du nombre (clé `pve.lootScanSummaryUnknownProjects`).
  - `corporationsWithoutValidCharacter` non vide : un bandeau d'erreur (`role="alert"`) en tête du dialogue, clé `pve.corpProjectsAuthenticationError` avec la liste des corporations, et un lien vers `/characters` (clé `pve.reconnectCharactersLink`).
  - `charactersToReconnect` non vide : un avertissement liste les noms (clé `pve.charactersToReconnect`, pluriel par nombre), avec le même lien.
  - Les deux s'affichent aussi quand aucune vente n'est détectée (état vide « Aucune vente de loot detectee » conservé en dessous).
  - Le composable transmet `scannedProjects` tel quel (`number | null`), sans `|| 0` ; le dialogue non plus.

## Contrat de `POST /api/pve/scan-loot-sales`

`ScanLootSalesResultResource` :

| Champ | Avant | Après |
|---|---|---|
| `scannedTransactions` | `int` | inchangé |
| `scannedContracts` | `int` | inchangé |
| `scannedProjects` | `int` | `?int` : `null` si au moins une corporation est dans `corporationsWithoutValidCharacter` (R3) ; 0 reste possible pour une corporation lue sans projet dans la fenêtre |
| `detectedSales` | `DetectedSaleResource[]` | inchangé |
| `noLootTypesConfigured` | `bool` | inchangé |
| `charactersToReconnect` | absent | `list<string>`, noms des personnages (R5) ; `[]` sinon |
| `corporationsWithoutValidCharacter` | absent | `list<string>`, noms des corporations illisibles faute de personnage valide (R3) ; `[]` sinon |

Type front `LootSaleScanResult` (`frontend/src/types/pve.ts`) : `scannedProjects: number | null`, `charactersToReconnect: string[]`, `corporationsWithoutValidCharacter: string[]`.

## Exemples chiffrés

Données fictives. Corporation C = 98000001 « Fictional Mining Corp », corporation K = 98000002 « Second Fictional Corp ». Personnages de l'utilisateur, dans cet ordre : P1 « Pilot One » (2112000001), P2 « Pilot Two » (2112000002), P3 « Pilot Three » (2112000003), P4 « Pilot Four » (2112000004), tous avec token. Le token de Pn vaut `access-token-<id>`. Projet T « Tritanium Drive », livre du Tritanium (34), `reward_per_contribution` 12,5, modifié il y a 1 jour.

| # | Situation | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | P1..P4 dans C ; P1, P2, P4 invalides, P3 valide. Contributeurs de T : P1 et P3 ; P3 a livré 1 000 il y a 2 jours | 0 requête utile : `getValidAccessToken(P1)` lève, corporation abandonnée ; `scannedProjects` 0, 0 vente, aucun message | Liste des projets, contributeurs et détail de T avec `Bearer access-token-2112000003` ; une seule contribution demandée, `/contribution/2112000003` ; `scannedProjects` 1 ; 1 vente de P3, 1 000 × 12,5 = 12 500 ISK ; `charactersToReconnect` = `["Pilot One", "Pilot Two", "Pilot Four"]` ; `corporationsWithoutValidCharacter` = `[]` |
| E2 | P1..P4 dans C, tous invalides | « 0 projects scanned - 0 sales detected » | Aucune requête `/corporations/…` ; `scannedProjects` `null` ; 0 vente ; `charactersToReconnect` = les 4 noms ; `corporationsWithoutValidCharacter` = `["Fictional Mining Corp"]` ; écran : résumé « projets : inconnu », bandeau d'erreur d'authentification, lien `/characters` |
| E3 | P1..P4 dans C, tous valides ; contributeur P1, 1 000 livrés | 1 vente de 12 500 ISK, appels avec le token de P1 | Inchangé : mêmes requêtes, token de P1, `scannedProjects` 1 ; `charactersToReconnect` `[]`, `corporationsWithoutValidCharacter` `[]`, aucun bandeau |
| E4 | P1 seul, valide, dans C ; C n'a aucun projet | « 0 projects scanned », 0 vente | Inchangé : `scannedProjects` 0, listes vides, résumé « 0 projets scannés », aucun bandeau |
| E5 | P1 et P2 dans C, valides ; `getValidAccessToken(P1)` lève `EveAuthRequiredException` (refresh refusé) ; contributeur P2, 1 000 livrés | Corporation abandonnée, 0 projet | Lecture avec le token de P2 ; `scannedProjects` 1 ; 1 vente de P2 ; `charactersToReconnect` = `["Pilot One"]` ; `corporationsWithoutValidCharacter` `[]` |
| E6 | P1 et P2 dans C ; P1 valide mais refresh refusé, P2 invalide | Corporation abandonnée, 0 projet | Aucune requête `/corporations/…` aboutie ; `scannedProjects` `null` ; `charactersToReconnect` = `["Pilot One", "Pilot Two"]` ; `corporationsWithoutValidCharacter` = `["Fictional Mining Corp"]` |
| E7 | P1 et P2 dans C, valides ; contributeurs de T : P1 (1 000) et P2 (400) ; l'appel `/contribution/2112000002` lève `EveAuthRequiredException` | 1 vente (P1), P2 avalé sans message | `scannedProjects` 1 ; 1 vente de P1, 12 500 ISK ; `charactersToReconnect` = `["Pilot Two"]` |
| E8 | P1 et P2 valides dans C (T : contributeur P1, 1 000) ; P3 et P4 invalides dans K | Selon l'ordre : K abandonnée, total 1 | C lue avec P1 : 1 vente de 12 500 ISK ; `scannedProjects` `null` ; `corporationsWithoutValidCharacter` = `["Second Fictional Corp"]` ; `charactersToReconnect` = `["Pilot Three", "Pilot Four"]` |
| E9 | P1 valide dans C ; la liste des projets répond 500 | `scannedProjects` 0, 0 vente, une seule requête | Inchangé (R6, test existant `testUnavailableCorpProjectsListYieldsNoProjectSaleAndNoScannedProject`) : `scannedProjects` 0, listes vides |
| E10 | P1 valide dans C (contributeur, 1 000) ; P2 invalide dans une corporation PNJ (1000125) | 1 vente | 1 vente de P1 ; `charactersToReconnect` = `["Pilot Two"]` ; `corporationsWithoutValidCharacter` `[]` ; `scannedProjects` 1 |

Écran (FR / EN), `LootSaleScanDialog` :

| # | Données | Attendu |
|---|---|---|
| F1 | `scannedProjects` `null`, 3 transactions, 2 contrats, 0 vente | « 3 transactions, 2 contrats, projets : inconnu - 0 ventes détectées » / « 3 transactions, 2 contracts, projects: unknown - 0 sales detected » ; jamais « 0 projets » |
| F2 | `corporationsWithoutValidCharacter` = `["Fictional Mining Corp"]` | bandeau `role="alert"` : « Erreur d'authentification : projets de Fictional Mining Corp illisibles, aucun personnage de cette corporation n'a d'autorisation valide. » / « Authentication error: projects of Fictional Mining Corp could not be read, no character of this corporation has a valid authorization. » ; lien « Reconnecter des personnages » / « Reconnect characters » vers `/characters` |
| F3 | `charactersToReconnect` = `["Pilot One", "Pilot Two", "Pilot Four"]` | « 3 personnages à reconnecter, ignorés par le scan : Pilot One, Pilot Two, Pilot Four » / « 3 characters to reconnect, skipped by the scan: Pilot One, Pilot Two, Pilot Four » |
| F4 | `charactersToReconnect` = `["Pilot One"]` | « 1 personnage à reconnecter, ignoré par le scan : Pilot One » / « 1 character to reconnect, skipped by the scan: Pilot One » |
| F5 | `scannedProjects` 0, listes vides | résumé « … 0 projets scannés … » inchangé, ni bandeau ni avertissement |

## Clés i18n

À ajouter dans `pve` de `frontend/src/i18n/locales/fr.json` et `en.json` (mêmes clés dans les deux fichiers ; aucun test de parité automatique trouvé, la phase rouge l'asserte par les textes de F1 à F4 en EN et la revue vérifie le FR) :

| Clé | FR | EN |
|---|---|---|
| `pve.lootScanSummaryUnknownProjects` | `{transactions} transactions, {contracts} contrats, projets : inconnu - {detected} ventes détectées` | `{transactions} transactions, {contracts} contracts, projects: unknown - {detected} sales detected` |
| `pve.corpProjectsAuthenticationError` | `Erreur d'authentification : projets de {corporations} illisibles, aucun personnage de cette corporation n'a d'autorisation valide.` | `Authentication error: projects of {corporations} could not be read, no character of this corporation has a valid authorization.` |
| `pve.charactersToReconnect` | `{count} personnage à reconnecter, ignoré par le scan : {names} \| {count} personnages à reconnecter, ignorés par le scan : {names}` | `{count} character to reconnect, skipped by the scan: {names} \| {count} characters to reconnect, skipped by the scan: {names}` |
| `pve.reconnectCharactersLink` | `Reconnecter des personnages` | `Reconnect characters` |

`{corporations}` et `{names}` : noms joints par « , ». La clé existante `dashboard.todo.charactersToReconnect` n'est pas réutilisée : elle n'a pas de `{names}` et appartient au tableau de bord.

## Cas limites

- **Token qui lève pendant l'appel alors que `auth_status` est valide** : `TokenManager::requestNewAccessToken()` reçoit `invalid_grant`, passe le personnage en invalide et lève `EveAuthRequiredException` ; traité par R2 (liste des projets) ou R4 (contribution). Avec le vrai `TokenManager`, le personnage est aussi invalide en mémoire pour la suite du scan ; les tests le simulent par un stub qui lève, d'où le suivi explicite de R5.
- **Liste des projets en cache** (`corp_projects_{corporationId}`, 15 min) : l'appel explicite `getValidAccessToken()` avant la lecture (déjà présent ligne 294) reste ; c'est lui qui écarte un lecteur refusé quand la liste vient du cache.
- **Lecteur refusé après la liste** (contributeurs ou détail) : le token vient d'être validé, ce n'est pas réaliste dans un même scan ; comportement actuel conservé (projet ignoré, log `debug`). Pas de rotation à ce stade.
- **Erreur ESI non liée à l'auth** (500, 403 faute du scope `esi-corporations.read_projects.v1` sur un vieux token, 401 de l'ESI, réseau) : hors périmètre (R6). Le correctif ne l'impose pas : la rotation porte uniquement sur `EveAuthRequiredException`. Elle reste affichée « 0 projets » (E9) : remplacé par R8 et R9 du périmètre B (lot 1).
- **Personnage sans token** : ignoré comme aujourd'hui, ni lecteur, ni contributeur appelé, ni listé à reconnecter. Une corporation où aucun personnage n'a de token n'est pas scannée et n'est pas listée.
- **Corporation PNJ** (< 2 000 000) : jamais lue ; un personnage invalide qui y est rattaché est quand même listé à reconnecter (E10).
- **Aucun type de loot configuré** : le scan s'arrête avant les projets (`:66-72`), listes vides, comportement inchangé (remplacé par R12 du périmètre B, lot 1).
- **Réponse HTTP non OK du scan** : le composable remplace aujourd'hui la réponse par `{ detectedSales: [] }`. Ce repli porte `scannedProjects: null` et des listes vides, pour ne pas afficher un 0 plausible ; le message d'erreur réel reste hors périmètre A (remplacé par R13 du périmètre B, lot 1).

## Tests

**Tests existants appelés à casser** (changement de comportement voulu, harnais à corriger, pas l'assertion) :

- `tests/Unit/State/Processor/Pve/ScanLootSalesProcessorCorpProjectsTest.php` : les 11 tests qui appellent `process()` (tous sauf `testConstructorNoLongerTakesAnHttpClient`). Le personnage est un `createStub(Character::class)` sans `isAuthValid()` configuré, qui rend donc `false` : après R1 il n'est plus lecteur, aucune requête `/corporations/…` n'est faite. Correctif du harnais : `isAuthValid()` → `true` et `getCorporationName()` configuré dans `createUserWithCorporationCharacter()`.
- `tests/Unit/State/Processor/Pve/ScanLootSalesProcessorTest.php` : ne casse pas (corporation PNJ, aucune assertion sur les nouveaux champs). Son personnage stub apparaîtra dans `charactersToReconnect` ; configurer `isAuthValid()` → `true` pour rester réaliste.
- Front : aucun test existant de `LootSaleScanDialog` ni de `usePveExpenseScanning` (`grep -rn "LootSaleScanDialog\|usePveExpenseScanning\|lootScanSummary" frontend/tests` vide).

**Tests rouges attendus** (`#[Group('issue-243')]`) :

- Back, `ScanLootSalesProcessorCorpProjectsTest` ou une classe sœur `ScanLootSalesProcessorCorpProjectsAuthenticationTest` réutilisant le même simulateur ESI : E1, E2, E5, E6, E7, E8, E10 ; E3, E4, E9 couverts par les tests existants une fois le harnais corrigé (ajouter seulement l'assertion des listes vides pour E3/E4). Harnais : un `EveToken` stub distinct par personnage, `TokenManager` stub dont `getValidAccessToken()` rend `access-token-<id>` ou lève `EveAuthRequiredException` selon le token (le vrai `EsiClient` appelle aussi ce stub pour l'en-tête `Authorization`), en-tête `Authorization` vérifié par requête.
- Front, `frontend/tests/components/ledger/lootSaleScanDialogAuthentication.spec.ts` (montage de `LootSaleScanDialog` avec `en.json`, comme `miningLedgerHarness.ts`) : F1 à F5.

## Fichiers de production à modifier

| Fichier | Changement |
|---|---|
| `src/ApiResource/Pve/ScanLootSalesResultResource.php` | `?int $scannedProjects`, `array $charactersToReconnect = []`, `array $corporationsWithoutValidCharacter = []` |
| `src/State/Processor/Pve/ScanLootSalesProcessor.php` | partie projets corporation (`:270-430`) : choix du lecteur (R1, R2), corporations illisibles (R3), contributeurs valides seulement (R4), suivi des personnages à reconnecter (R5) ; transactions et contrats inchangés |
| `frontend/src/types/pve.ts` | `LootSaleScanResult` : trois champs (contrat ci-dessus) |
| `frontend/src/composables/usePveExpenseScanning.ts` | `scanLootSales()` : transmet `scannedProjects` sans `\|\| 0`, les deux listes ; repli non OK avec `scannedProjects: null` |
| `frontend/src/components/ledger/pve/LootSaleScanDialog.vue` | résumé à projets inconnus, bandeau d'erreur, avertissement à reconnecter, lien `/characters` |
| `frontend/src/i18n/locales/fr.json`, `en.json` | les 4 clés |

Pas de migration, pas de commande à lancer en production.

## Décidé (anciens blocages B1 à B5)

Sylvain a tranché le 2026-10-30 : option recommandée A partout. Détail dans « Périmètre B (décidé) ».

| Blocage | Option retenue | Lot | Règles |
|---|---|---|---|
| B3 — erreurs ESI non liées à l'auth à l'écran, replis `?? 0`, 403 d'un vieux token, repli front non OK | A : liste d'erreurs avec motif, projets inconnus, corporation ou projet en erreur listé | 1 | R8 à R11, R13 |
| B5 — scan arrêté sans type de loot | A : lire les projets quand même | 1 | R12 |
| B4 — gains hors fenêtre de Revenue | A : période « Tout » | 1 | R14 |
| B2 — identifiant de suivi sans la contribution | A : quantité déjà importée par couple projet/personnage, proposition du delta | 2 | R15 à R18 |
| B1 — synchro automatique | A : étape de la sync PVE horaire, import direct en `corp_project`, lecteur de R1 | 3 | R19 à R21 |

## Périmètre B (décidé)

Trois lots livrés dans l'ordre, chacun avec sa phase rouge et sa phase verte. Tests unitaires seulement, sans base : doublures aux frontières ESI (`MockHttpClient` derrière un vrai `EsiClient`), `TokenManager` stub, repositories stub, `EntityManagerInterface` mock qui capture les `persist()`.

| Lot | Blocages | Dépend de | Migration |
|---|---|---|---|
| 1 | B3 + B5 + B4 | périmètre A (livré) | non |
| 2 | B2 | lot 1 : lecteur extrait (`CorpProjectContributionReader`), liste d'erreurs | oui |
| 3 | B1 | lot 1 (lecteur) et lot 2 (quantité déjà comptée, sinon la synchro réimporterait le cumul à chaque passage) | non |

Les trois parties du lot 1 sont indépendantes entre elles ; B4 ne touche que le Ledger et peut partir seul si le lot est trop gros. B3 et B5 touchent le même processor et le même dialogue : à garder ensemble.

### Contexte vérifié (code du 2026-10-30, branche `fix/group-mining-ops`, après le périmètre A)

**Scan** (`src/State/Processor/Pve/ScanLootSalesProcessor.php`, 593 lignes) :

- `:66-75` : sans type de loot, retour immédiat avec `scannedTransactions` 0, `scannedContracts` 0, `noLootTypesConfigured` true. La partie projets (`:272-439`) n'est jamais lue alors qu'elle ne filtre pas sur les types de loot (B5). Le composable ouvre alors la configuration des types de loot si rien n'est détecté (`usePveExpenseScanning.ts:208-211`).
- `:458-479` `selectProjectsReader()` : lecteur = premier personnage valide non refusé. Aucun contrôle du scope `esi-corporations.read_projects.v1` sur le token, alors que `EveToken::hasScope()` existe (`EveToken.php:126`). Un vieux token sans le scope prend un 403 de l'ESI (`EsiClient::failedResponseException()`, `EsiApiException` de statut 403), traité comme toute erreur : la corporation est abandonnée par le `catch (\Throwable)` de `:435-438` (log `warning`) et l'écran affiche « 0 projets » (B3).
- `:430-433` : erreur des contributeurs ou du détail d'un projet → log `debug`, projet ignoré sans trace à l'écran. `:426-428` : erreur non liée à l'auth sur une contribution → avalée.
- Replis : `:349` `type_id ?? 0` (libellé `Items` et icône seulement), `:352` `reward_per_contribution ?? 0`, `:372` `contributed ?? quantity ?? amount ?? 0`, `:373` `last_modified ?? contributed_at ?? null`, `:386-388` date illisible ignorée, `:417` date du jour quand la contribution n'a pas de `last_modified`.
- `:392` identifiant de suivi `-abs(crc32("{projectId}_{characterId}"))`, sans quantité : une fois importé (`:394`) ou refusé (`:397`), le couple n'est plus jamais proposé (B2).
- Front : une contribution à 0 ISK ouvre déjà une saisie de valeur (`LootSaleScanDialog.vue:108-119`, `usePveExpenseScanning.ts:177` `needsPriceInput`) ; le 0 de `:352` y sert de marqueur « valeur inconnue ». Le composable remplace en plus `scannedTransactions` et `scannedContracts` absents par 0 (`usePveExpenseScanning.ts:214-215`, `|| 0`) et la réponse non OK par `FAILED_LOOT_SALES_SCAN` ou `{ detectedContracts: [] }` (`:12-17`, `:168-169`) : un échec s'affiche comme un scan vide.

**ESI** (spec OpenAPI officielle `https://esi.evetech.net/meta/openapi.json?compatibility_date=2025-12-16`, lue le 2026-10-30 ; les pages du portail `developers.eveonline.com` ne donnent que la navigation) :

- `GET /corporations/{corporation_id}/projects/{project_id}/contribution/{character_id}` : « Show your contribution to a corporation project. », scope `esi-corporations.read_projects.v1`, aucun rôle. Réponse `CorporationsProjectsContribution` : `contributed` (int64, **requis**, description « Your contribution ») et `last_modified` (date-time, **optionnel**, « Moment this information was last modified »). Un seul objet par couple projet/personnage, pas une liste de livraisons : c'est un état, donc vraisemblablement un cumul, **mais la spec n'écrit pas « cumul » ni « total »**. [TO CONFIRM : `contributed` est le cumul des livraisons du personnage au projet.] Le lot 2 retient le calcul qui ne compte jamais deux fois si l'hypothèse est fausse (R16).
- `GET …/projects/{project_id}/contributors` exige le **rôle corporation `Project_Manager`** (`x-required-roles`) en plus du scope. Le scan l'appelle avant toute contribution (`:333`) : pour un lecteur sans ce rôle, chaque projet prend un 403 et disparaît en silence. Non relevé par l'issue ; traité par R10. Les trois autres routes n'ont pas de rôle.
- `GET …/projects/{project_id}` : l'objet `contribution` est optionnel, et `reward_per_contribution` (« ISK reward per contribution ») l'est aussi dedans : leur absence est un cas prévu par l'ESI, pas une erreur. `last_modified` du projet est requis (« Project contributions also count as a modification »). `configuration` est un `oneOf` (`deliver_item`, `mine_material`, `destroy_npc`, `manual`…), et `deliver_item.items` peut porter un `group_id` au lieu d'un `type_id`.
- `submission_multiplier` (« Multiplier towards progress per contribution ») : le montant `contributed × reward_per_contribution` du code suppose que `contributed` compte des contributions et non de la progression. [TO CONFIRM.] Inchangé dans cette spec.
- Les quatre routes sont dans le groupe de limite `corp-project` : 600 jetons par fenêtre de 15 min. [TO CONFIRM : seau par personnage ou par application.]
- La liste des projets est décrite « Listing of all (active) corporation projects » ; le code passe `state=All` et filtre lui-même `Deleted`.

**Revenue** (B4) :

- Le sélecteur de période de `frontend/src/views/Ledger.vue` propose 7, 14, 30, 60, 90 jours (`:27-33`) **et un bouton YTD** (`:35-39`, `:110-120`, jours depuis le 1er janvier, 303 au 2026-10-30). L'issue dit « 90 jours au plus » : c'est inexact depuis YTD, et les 2 lignes `corp_project` locales de février 2026 sont visibles en YTD. Restent hors d'atteinte les gains d'une année précédente.
- `days` est un entier libre, sans plafond, lu par chaque provider (`IncomeProvider.php:43`, `LedgerDashboardProvider.php:49`, `LedgerDailyStatsProvider.php:46`, providers minage et PVE). `LedgerDailyStatsProvider` crée un point par jour (`:66-88`). Le même `selectedDays` sert de fenêtre au scan (`PveTab.vue` → `scanLootSales(selectedDays)`).
- `LedgerDashboardProvider` n'injecte pas `MiningEntryRepository` (`:30-36`) ; aucun repository n'expose la plus ancienne date d'une entrée.

**Synchro PVE** (B1) :

- `src/Scheduler/SyncScheduler.php:62` : `TriggerPveSync` toutes les heures → `TriggerPveSyncHandler` envoie `SyncUserPveData` (transport `async`, `config/packages/messenger.yaml:26,33`) pour chaque utilisateur actif avec `canSync()` et `shouldSync()` (`autoSyncEnabled`, 15 min depuis `lastSyncAt`) → `SyncUserPveDataHandler` → `PveSyncService::syncAll()`. `POST /api/pve/sync` (`SyncPveProcessor.php:41`) appelle aussi `syncAll()`.
- `syncAll()` (`PveSyncService.php:120-233`) enchaîne bounties/ESS/missions, ventes de loot (transactions), contrats de loot, dépenses. Chaque étape importe directement en `pve_income` ou `pve_expense` sur 30 jours (`DEFAULT_DAYS_TO_SYNC`) et respecte les listes de refus. L'issue (« ne traite que bounty, ESS et missions ») est incomplète : ventes et contrats de loot sont déjà importés automatiquement ; seuls les projets corporation manquent.
- `PveIncome` : `transactionId`, `journalEntryId`, `contractId` (bigint, nullables), index non uniques `(user_id, transaction_id)` et `(user_id, journal_entry_id)` ; aucune colonne projet, personnage ou quantité. `ImportLootSalesProcessor.php:57-81` dédoublonne sur `transactionId` et ignore la `quantity` envoyée par le front (`usePveExpenseScanning.ts:277`). `UserPveSettings` garde les refus en listes JSON d'identifiants (`declinedLootSaleTransactionIds`) ; `clearDeclinedExcept()` les vide (`POST /api/pve/settings/reset-declined`).
- Le front construit la description importée : `"{projectName}: {quantité}x {typeName}"` (`usePveExpenseScanning.ts:276`, la partie `"{quantité}x {typeName}"` venant de `ScanLootSalesProcessor.php:414`). Aucune route ne modifie la description d'un revenu.
- `UserLedgerSettings::corpProjectAccounting` (`pve` ou `mining`) décide seulement où les montants `corp_project` sont totalisés (dashboard, stats) ; il n'intervient pas dans l'import. Les entrées de minage marquées `corp_project` (`UpdateMiningEntryUsageProcessor`) sont une autre source, déjà arbitrée par ce réglage : B1 n'ajoute pas de recouvrement nouveau.

### Règles — lot 1 (B3, B5, B4)

- **R8 — Lecteur avec le scope des projets.** R1 est complété : un candidat lecteur doit aussi avoir un token qui porte `esi-corporations.read_projects.v1` (`EveToken::hasScope()`). Un personnage valide sans le scope n'est ni lecteur ni appelé pour les projets, et n'est pas listé à reconnecter (le reste du scan le lit normalement). Sans candidat : motif `missing_projects_scope` si au moins un personnage valide et non refusé de la corporation existe sans le scope, sinon `no_valid_character` (R3).

- **R9 — Erreurs de lecture des projets.** `corporationsWithoutValidCharacter` est remplacé par `corporationProjectErrors` : liste d'objets `{corporationName: string, projectName: ?string, characterName: ?string, reason: string, httpStatus: ?int}`, dans l'ordre de rencontre (personnages, puis projets dans l'ordre de la liste ESI). Le niveau se déduit des champs : sans projet = corporation, avec projet sans personnage = projet, avec personnage = contribution. Motifs (enum `App\Enum\CorporationProjectReadFailure`, valeurs chaîne) :

  | Motif | Niveaux | Quand |
  |---|---|---|
  | `no_valid_character` | corporation | R3 inchangé |
  | `missing_projects_scope` | corporation, contribution | R8 ; contributeur valide dont le token n'a pas le scope : sa contribution n'est pas demandée |
  | `esi_error` | corporation, projet, contribution | `EsiApiException` (hors `EveAuthRequiredException`) sur la liste des projets, le détail, les contributeurs (sauf le 403 de R10) ou une contribution ; `httpStatus` = statut ESI (403, 500, 502, 503…), `null` pour une erreur réseau (`EsiApiException` de statut 0) |
  | `unexpected_response` | corporation, projet, contribution | réponse 2xx sans un champ requis par la spec ESI (`contributed`, `last_modified` du projet), date illisible, page de curseur sans sa liste (`\UnexpectedValueException` de `EsiClient::getUnversionedCursorPaginated()`) ; `httpStatus` `null` |

  - Une erreur de niveau corporation rend `scannedProjects` `null` (R3 généralisé) : liste illisible = nombre de projets inconnu.
  - Une erreur de niveau projet laisse le projet compté dans `scannedProjects` (il a été listé) ; aucune vente n'en sort ; l'erreur le nomme.
  - Une erreur de niveau contribution n'empêche pas les autres contributions du projet.
  - Pas d'essai d'un autre lecteur sur ces erreurs (décision A.1 maintenue) ; le log `warning` reste pour toutes.
  - `EveAuthRequiredException` garde R2, R4 et R5 (rotation, à reconnecter), sans entrée d'erreur.

- **R10 — Lecteur sans le rôle `Project_Manager`.** Si `…/contributors` répond 403, ce n'est pas une erreur : le scan demande la contribution de chaque personnage de l'utilisateur de la corporation qui a un token, est valide, non refusé et a le scope (R8), chacun avec son propre token, dans l'ordre des personnages. Dans ce repli, une contribution répondue 404 vaut « pas de contribution », sans erreur [TO CONFIRM : statut rendu par l'ESI à un personnage qui n'a pas contribué ; un 200 avec `contributed` 0 est aussi couvert, quantité 0 ignorée comme aujourd'hui]. Tout autre statut suit R9. La liste des contributeurs n'est mise en cache que sur un succès (inchangé).

- **R11 — Pas de valeur plausible sur une donnée ESI manquante.**
  - `reward_per_contribution` absent (ou `contribution` absent) : la vente est détectée avec `price` `null` (valeur inconnue), sans entrée d'erreur puisque le champ est optionnel dans l'ESI ; l'écran ouvre la saisie de valeur existante. Un 0 explicite reste 0 (saisie de valeur aussi, comme aujourd'hui).
  - `contributed` absent : pas de vente, erreur `unexpected_response` de niveau contribution. Les alias `quantity` et `amount` sont retirés (absents de la spec ESI).
  - `last_modified` de la contribution absent : la date de la vente et le filtre de fenêtre prennent le `last_modified` du projet (requis, mis à jour par chaque contribution), jamais la date du jour. Date illisible (contribution ou projet) : erreur `unexpected_response`.
  - `type_id` (`:349`) reste 0 et `Items` : libellé et icône seulement, aucun montant n'en dépend ; hors règle.

- **R12 — Projets lus sans type de loot (B5).** Sans type de loot configuré, le scan saute toujours les transactions et les contrats (ils filtrent sur ces types) mais lit les projets corporation. Sortie : `noLootTypesConfigured` true, `scannedTransactions` `null` et `scannedContracts` `null` (non lus, pas 0), `scannedProjects`, ventes de projets, erreurs et personnages à reconnecter comme avec des types configurés. Front :
  - la configuration des types de loot s'ouvre à la place du dialogue seulement si aucune vente n'est détectée (contrats de loot compris), sans erreur de projets ni personnage à reconnecter ;
  - sinon le dialogue s'ouvre avec l'avis `pve.noLootTypesProjectsOnly` et un bouton `pve.configureLootTypes` (nouvel emit `openLootConfig`, relié par `PveTab.vue` à `showLootConfig`) ; le résumé prend `pve.lootScanSummaryNoLootTypes` ou `pve.lootScanSummaryNoLootTypesUnknownProjects`, avec `{contracts}` = `scannedContracts` du seul scan des contrats de loot ;
  - le composable ne remplace plus `scannedTransactions` ni `scannedContracts` par 0 (`:214-215`) : type front `number | null`.

- **R13 — Échec HTTP d'un scan.** Si `scan-loot-sales` ou `scan-loot-contracts` répond non OK, le composable n'ouvre pas le dialogue et appelle `onError` avec `pve.errors.lootSalesScanHttpFailure` ou `pve.errors.lootContractsScanHttpFailure` (`{status}` = statut HTTP). `FAILED_LOOT_SALES_SCAN` et le repli `{ detectedContracts: [] }` sont supprimés : aucun résultat partiel présenté comme complet. Si les deux échouent, le message du scan des ventes est affiché.

- **R14 — Période « Tout » (B4).**
  - `GET /api/ledger/dashboard` expose `oldestEntryDate: ?string` (`Y-m-d`) : la plus ancienne date parmi `pve_income.date`, `pve_expense.date` et `mining_entries.date` de l'utilisateur, indépendante de `days` ; `null` sans aucune entrée.
  - `Ledger.vue` ajoute après YTD un bouton « Tout » (`ledger.period.all`), absent si `oldestEntryDate` est `null`. Il pose `selectedDays = ceil((maintenant − oldestEntryDate à 00:00 UTC) / 1 jour) + 1` ; le +1 couvre l'écart entre le fuseau du navigateur et l'heure serveur. Le bouton est actif quand `selectedDays` vaut ce nombre (même logique que YTD), et la valeur est gardée dans `localStorage` comme les autres.
  - Aucun provider ne change : `days` reste un entier. Le scan, qui prend `selectedDays`, remonte alors jusqu'à la plus ancienne entrée.

### Règles — lot 2 (B2)

- **R15 — Quantité déjà comptée** d'un couple (projet, personnage EVE) pour un utilisateur : somme des `corp_project_quantity` de ses lignes `pve_income` de type `corp_project` portant ce couple, plus la quantité refusée mémorisée (`UserPveSettings::$declinedCorpProjectQuantities`, clé `"{projectId}:{characterId}"`), plus la quantité reprise d'un import d'avant le lot 2 (R18). Inconnue quand R18 la rend inconnue.

- **R16 — Contribution en attente.** delta = `contributed` − quantité déjà comptée.
  - delta > 0 : vente proposée avec `quantity` = delta, `price` = delta × `reward_per_contribution` (`null` si la récompense est inconnue, R11), `accountedQuantity` = quantité déjà comptée, `characterId`, libellé `"{delta}x {typeName}"`, et l'identifiant de suivi `-abs(crc32("{projectId}_{characterId}_{contributed}"))`.
  - delta = 0 : rien.
  - delta < 0 : rien, erreur `contribution_below_imported` de niveau contribution. C'est le signal visible si `contributed` n'est pas un cumul : l'hypothèse fausse ne crée jamais d'ISK en double, au pire une contribution non proposée et signalée.
  - Le filtre de fenêtre sur la date de la contribution (R11) reste.

- **R17 — Import et refus d'une contribution en attente.**
  - `POST /api/pve/import-loot-sales`, ligne de `type` `corp_project` : `projectId`, `characterId`, `quantity` et `accountedQuantity` deviennent obligatoires. Le serveur recalcule la quantité déjà comptée (R15) ; si elle diffère de `accountedQuantity` (import ou refus entre-temps, par un autre onglet ou la synchro du lot 3), ou si un des quatre champs manque, la ligne est rejetée, rien n'est écrit, `rejectedContributions` +1. Sinon la ligne `pve_income` porte en plus `corp_project_id`, `corp_project_character_id` et `corp_project_quantity` = `quantity`. Le dédoublonnage par `transactionId` reste appliqué en premier.
  - Refus (`declined`, mêmes quatre champs) : même contrôle ; s'il passe, `declinedCorpProjectQuantities[couple] += quantity`. L'identifiant n'est plus ajouté à `declinedLootSaleTransactionIds` pour une contribution.
  - Front : `rejectedContributions` > 0 → `onError(t('pve.errors.corpProjectContributionStale'))`, la ligne quitte la liste ; un nouveau scan propose le delta à jour.
  - `POST /api/pve/settings/reset-declined` vide aussi `declinedCorpProjectQuantities`.
  - Suppression d'une ligne de revenu (`DELETE /api/pve/loot-sales/{id}`) : sa quantité n'est plus comptée et revient au scan suivant, comme aujourd'hui où la suppression rend le couple de nouveau proposable.

- **R18 — Imports d'avant le lot 2** (lignes `corp_project` dont `transactionId` vaut `-abs(crc32("{projectId}_{characterId}"))`, sans couple).
  - La migration reprend leur quantité depuis la description, dernière occurrence de `: <chiffres>x ` (forme `"{projectName}: {quantité}x {typeName}"`), dans `corp_project_quantity`. Les colonnes du couple restent NULL : crc32 n'est pas réversible.
  - À la lecture, une ligne sans couple dont le `transactionId` vaut l'identifiant ancien du couple compte pour sa quantité reprise. Quantité reprise NULL (description d'une autre forme) : quantité déjà comptée inconnue → rien de proposé ni d'importé pour ce couple, erreur `legacy_import_quantity_unknown` de niveau contribution. Issue pour l'utilisateur : supprimer cette ligne de revenu, le cumul entier est alors proposé.
  - Identifiant ancien présent dans `declinedLootSaleTransactionIds` (refus d'avant le lot 2) : couple ni proposé ni importé, sans erreur, comme aujourd'hui ; « réinitialiser les refus » le libère.

### Règles — lot 3 (B1)

- **R19 — Étape « projets corporation » de la synchro PVE.** `PveSyncService::syncCorpProjectContributions(User): int`, appelée par `syncAll()` après les contrats de loot, dans son propre `try`, avec la progression Mercure 75 « Fetching corporation projects... ».
  - Même lecteur que le scan (`CorpProjectContributionReader`, R1, R2, R8 à R11) et même calcul de la contribution en attente (R15, R16, R18), fenêtre `DEFAULT_DAYS_TO_SYNC` (30 jours) sur le `last_modified` des projets et des contributions.
  - Importe chaque contribution en attente de delta > 0 dont la récompense est connue et > 0 : `PveIncome` de type `corp_project`, montant delta × `reward_per_contribution`, date = `last_modified` de la contribution (repli R11), description `"{projectName}: {delta}x {typeName}"` (forme de l'import manuel), `transactionId` de R16, couple, `corp_project_quantity` = delta. Un `flush` à la fin si au moins une ligne.
  - Récompense inconnue ou 0 : laissée au scan manuel (saisie de valeur), sans erreur.
  - Indépendante des types de loot (R12) et de `corpProjectAccounting`.
  - Résultat : clé `corpProjects` (nombre de lignes créées) dans le tableau rendu par `syncAll()`, dans les données de `syncCompleted` et dans celles de `SyncPveProcessor`. Le texte du message (`'%d bounties, %d sales, %d contracts, %d expenses'`) ne change pas.
  - Erreurs du lecteur (R9, R16, R18) : une entrée par erreur dans `errors`, `"corpProjects: {corporationName}[ / {projectName}][ / {characterName}]: {reason}[ (HTTP {httpStatus})]"`, et un log `warning`. Elles ne comptent pas dans `failedCharacters` : le lecteur n'appelle pas les personnages invalides, qui échouent déjà aux autres étapes.

- **R20 — Pas de double comptage entre la synchro et le scan.**
  - Une seule comptabilité (R15) : le scan ne propose que ce que la synchro n'a pas importé, et l'inverse.
  - Scan affiché, puis synchro, puis clic « Ajouter » : la quantité déjà comptée a changé, l'import est rejeté (R17). Si la synchro a lu le même cumul, l'identifiant de suivi est déjà importé, ligne sautée.
  - Deux synchros simultanées du même utilisateur (planifiée et `POST /api/pve/sync`) : risque déjà présent pour toutes les étapes, aucun verrou ni contrainte d'unicité sur `(user_id, transaction_id)`. L'identifiant qui contient le cumul limite le doublon possible au même cumul lu deux fois au même instant. Hors périmètre (voir « Décisions B », point 16).

- **R21 — Scopes et rôles.** Aucun nouveau scope : `esi-corporations.read_projects.v1` est déjà dans `AuthenticationService::REQUIRED_SCOPES` (`:70`). Les tokens antérieurs à ce scope sont traités par R8. Le rôle `Project_Manager` n'est jamais exigé grâce à R10. Volume par passage et par corporation : les pages de projets, un détail par projet de la fenêtre (cache 15 min), les contributeurs (cache 5 min) ou le repli R10, une contribution par personnage concerné et par projet, dans le groupe `corp-project` (600 jetons par 15 min).

### Contrats

`ScanLootSalesResultResource` :

| Champ | Après A | Lot 1 | Lot 2 |
|---|---|---|---|
| `scannedTransactions` | `int` | `?int` : `null` sans type de loot (R12) | |
| `scannedContracts` | `int` | `?int` : `null` sans type de loot (R12) | |
| `scannedProjects` | `?int` | `null` dès une erreur de niveau corporation (R9) | |
| `corporationsWithoutValidCharacter` | `list<string>` | **supprimé**, remplacé par `corporationProjectErrors` | |
| `corporationProjectErrors` | absent | `list<CorporationProjectErrorResource>` `{corporationName, projectName, characterName, reason, httpStatus}` | motifs `contribution_below_imported`, `legacy_import_quantity_unknown` en plus |
| `charactersToReconnect`, `detectedSales`, `noLootTypesConfigured` | | inchangés | |

`DetectedSaleResource` : lot 1 `?float $price` (`null` = récompense inconnue, R11) ; lot 2 `?int $characterId` et `?int $accountedQuantity` (renseignés pour `corp_project`), `quantity` = delta (R16).

`ImportLootSalesInput` (lot 2) : chaque élément de `sales` et `declined` accepte `projectId?: string`, `characterId?: int`, `quantity?: int`, `accountedQuantity?: int`, obligatoires pour `type` `corp_project` (R17). `ImportResultResource` : `int $rejectedContributions = 0`.

`LedgerDashboardResource` (lot 1) : `?string $oldestEntryDate`.

Synchro (lot 3) : clé `corpProjects` dans le tableau de `syncAll()`, les données Mercure `syncCompleted` du type `pve` et la sortie de `POST /api/pve/sync`.

Types front (`frontend/src/types/pve.ts`) : `LootSaleScanResult.scannedTransactions: number | null`, `scannedContracts?: number | null`, `corporationProjectErrors: CorporationProjectError[]` (remplace `corporationsWithoutValidCharacter`), `noLootTypesConfigured: boolean` ; `DetectedLootSale.price: number | null`, `characterId?: number`, `accountedQuantity?: number`. `LedgerDashboard.oldestEntryDate: string | null` (`frontend/src/stores/ledger.ts`).

### Exemples chiffrés

Mêmes données que le périmètre A (C, K, P1 à P4, T = `a1b2c3d4-project` « Tritanium Drive », Tritanium 34, récompense 12,5, tous les tokens avec le scope des projets sauf mention). Projet U = `e5f6a7b8-project` « Veldspar Haul », livre du Veldspar (1230), récompense 7,25, modifié il y a 3 jours. Date de référence : 2026-10-30.

**Lot 1 — scan (back)**

| # | Situation | Aujourd'hui | Attendu |
|---|---|---|---|
| E11 | P1 valide dans C ; la liste des projets répond 503 (remplace E9) | `scannedProjects` 0, 0 vente, rien à l'écran | `scannedProjects` `null` ; 0 vente ; `corporationProjectErrors` = `[{corporationName: "Fictional Mining Corp", projectName: null, characterName: null, reason: "esi_error", httpStatus: 503}]` |
| E12 | P1 et P2 valides dans C ; le token de P1 n'a pas le scope ; contributeur P2, 400 livrés | 403 sur la liste avec le token de P1, corporation abandonnée | Aucune requête avec `access-token-2112000001` ; lecture avec P2 ; 1 vente de P2, 400 × 12,5 = 5 000 ISK ; erreurs `[]` ; `charactersToReconnect` `[]` |
| E13 | P1 seul dans C, valide, token sans le scope | 403, « 0 projets » | Aucune requête `/corporations/…` ; `scannedProjects` `null` ; erreurs = `[{Fictional Mining Corp, null, null, "missing_projects_scope", null}]` ; `charactersToReconnect` `[]` |
| E14 | P1 valide dans C ; T et U dans la fenêtre ; contributeur P1 des deux ; le détail de U répond 502 ; P1 a livré 1 000 à T | 1 vente, U disparaît (log `debug`) | `scannedProjects` 2 ; 1 vente (T, 12 500 ISK) ; erreurs = `[{Fictional Mining Corp, "Veldspar Haul", null, "esi_error", 502}]` |
| E15 | P1 et P2 valides dans C, sans le rôle `Project_Manager` : `…/contributors` de T répond 403 ; `/contribution/2112000001` → 1 000, `/contribution/2112000002` → 404 | T ignoré, 0 vente | Contributions demandées pour 2112000001 avec `Bearer access-token-2112000001` puis 2112000002 avec `Bearer access-token-2112000002` ; 1 vente de P1, 12 500 ISK ; erreurs `[]` ; `scannedProjects` 1 |
| E16 | Détail de T sans objet `contribution` ; P1 a livré 1 000 | 1 vente à 0 ISK | 1 vente de P1, `quantity` 1 000, `price` `null` ; erreurs `[]` |
| E17 | Contribution de P1 à T = `{"last_modified": "2026-10-28T09:00:00Z"}` (sans `contributed`) | rien, sans message | 0 vente ; erreurs = `[{Fictional Mining Corp, "Tritanium Drive", "Pilot One", "unexpected_response", null}]` |
| E18 | Contributeurs de T : P1 (1 000) et P2 ; `/contribution/2112000002` répond 500 | 1 vente, P2 avalé | 1 vente de P1, 12 500 ISK ; erreurs = `[{Fictional Mining Corp, "Tritanium Drive", "Pilot Two", "esi_error", 500}]` |
| E19 | Lecteur P1 ; contributeurs de T : P1 (1 000) et P2 valide dont le token n'a pas le scope | 403 avalé sur P2 | Aucune requête `/contribution/2112000002` ; 1 vente de P1 ; erreurs = `[{Fictional Mining Corp, "Tritanium Drive", "Pilot Two", "missing_projects_scope", null}]` |
| E20 | Contribution de P1 à T = `{"contributed": 1000}` sans `last_modified` ; `last_modified` de T = `2026-10-29T10:00:00Z` | `dateIssued` = date du jour (2026-10-30) | `dateIssued` `2026-10-29` |
| E21 | Aucun type de loot ; P1 valide dans C a livré 1 000 à T | aucune requête ESI, `noLootTypesConfigured` true, la configuration s'ouvre | Aucune requête `/characters/…` ; `/corporations/…` lus ; `noLootTypesConfigured` true ; `scannedTransactions` `null` ; `scannedContracts` `null` ; `scannedProjects` 1 ; 1 vente de 12 500 ISK |
| E22 | Aucun type de loot ; C n'a aucun projet ; aucun contrat de loot | configuration ouverte | `scannedProjects` 0, 0 vente, erreurs `[]` ; front : la configuration s'ouvre, pas le dialogue (inchangé) |
| E23 | Aucun type de loot ; la liste des projets répond 503 | configuration ouverte, erreur cachée | `scannedProjects` `null`, erreur de E11 ; front : le dialogue s'ouvre avec l'erreur et l'avis « aucun type de loot », pas la configuration |

**Lot 1 — Ledger (back)**

| # | Situation | Attendu |
|---|---|---|
| E24 | Revenus au 2026-01-31 08:15 (`corp_project`) et 2026-05-04 ; dépense au 2026-03-02 ; minage au 2026-02-14 ; `days=30` | `oldestEntryDate` = `"2026-01-31"` (indépendant de `days`) |
| E25 | Aucune entrée | `oldestEntryDate` `null` |

**Lot 1 — écran (FR / EN)**

| # | Données | Attendu |
|---|---|---|
| F6 | `scan-loot-sales` répond 500 | pas de dialogue ; `onError` : « Scan des ventes de loot et des projets corporation en échec (HTTP 500) » / « Loot sales and corporation projects scan failed (HTTP 500) » |
| F7 | `scan-loot-sales` OK, `scan-loot-contracts` répond 503 | pas de dialogue ; « Scan des contrats de loot en échec (HTTP 503) » / « Loot contracts scan failed (HTTP 503) » |
| F8 | erreur de E11 | `role="alert"` : « Projets de Fictional Mining Corp illisibles : erreur ESI (HTTP 503) » / « Projects of Fictional Mining Corp could not be read: ESI error (HTTP 503) » ; pas de lien `/characters` |
| F9 | erreur de E14 | « Projet Veldspar Haul de Fictional Mining Corp illisible : erreur ESI (HTTP 502) » / « Project Veldspar Haul of Fictional Mining Corp could not be read: ESI error (HTTP 502) » |
| F10 | erreur de E19 | « Contribution de Pilot Two au projet Tritanium Drive illisible : accès aux projets corporation non accordé, reconnectez le personnage » / « Contribution of Pilot Two to project Tritanium Drive could not be read: access to corporation projects not granted, reconnect the character » ; lien `/characters` |
| F11 | `no_valid_character` sur C (remplace F2) | « Projets de Fictional Mining Corp illisibles : aucun personnage de cette corporation n'a d'autorisation valide » / « Projects of Fictional Mining Corp could not be read: no character of this corporation has a valid authorization » ; lien `/characters` |
| F12 | `esi_error` sur C avec `httpStatus` `null` | « Projets de Fictional Mining Corp illisibles : ESI injoignable » / « Projects of Fictional Mining Corp could not be read: ESI unreachable » |
| F13 | erreur de E17 | « Contribution de Pilot One au projet Tritanium Drive illisible : réponse ESI incomplète » / « Contribution of Pilot One to project Tritanium Drive could not be read: incomplete ESI response » |
| F14 | vente de E16 (`price` `null`) | saisie de valeur affichée, « Ajouter » désactivé ; valeur 9 000 saisie → l'import envoie `price` 9000 |
| F15 | E21 côté écran : `noLootTypesConfigured` true, 2 contrats de loot scannés, 1 vente | dialogue ouvert ; résumé « 2 contrats, 1 projets scannés - 1 ventes détectées » / « 2 contracts, 1 projects scanned - 1 sales detected » ; avis « Aucun type de loot configuré : les ventes du marché ne sont pas lues, seuls les contrats de loot PVE et les projets corporation le sont. » ; bouton « Configurer les types de loot » / « Configure loot types » qui émet `openLootConfig` |
| F16 | Ledger, `oldestEntryDate` `"2026-01-31"`, horloge `2026-10-30T22:00:00Z` | bouton « Tout » / « All » après YTD ; clic → `selectedDays` 274 (272 j 22 h → 273, + 1), requête `/ledger/dashboard?days=274` ; bouton actif |
| F17 | Ledger, `oldestEntryDate` `null` | pas de bouton « Tout » |

**Lot 2 — quantité déjà comptée** (couple T/P1 sauf mention, récompense 12,5)

| # | Situation | Aujourd'hui | Attendu |
|---|---|---|---|
| E26 | Rien d'importé ; `contributed` 1 000 | 1 vente de 12 500 ISK | 1 vente : `quantity` 1 000, `price` 12 500, `accountedQuantity` 0, `transactionId` = `-abs(crc32('a1b2c3d4-project_2112000001_1000'))` ; import → ligne avec `corp_project_id` `a1b2c3d4-project`, `corp_project_character_id` 2112000001, `corp_project_quantity` 1 000 |
| E27 | Ligne importée de 1 000 (avec couple) ; `contributed` 1 600 | rien (couple déjà importé) | 1 vente : `quantity` 600, 7 500 ISK, `accountedQuantity` 1 000, identifiant `…_1600`, libellé « 600x Tritanium » |
| E28 | Lignes de 1 000 et 600 ; `contributed` 1 600 | rien | rien, erreurs `[]` |
| E29 | Ligne de 1 000 ; refus mémorisé 600 ; `contributed` 1 900 | rien | 1 vente : `quantity` 300, 3 750 ISK, `accountedQuantity` 1 600 |
| E30 | Ligne ancienne : `transactionId` = `-abs(crc32('a1b2c3d4-project_2112000001'))`, description « Tritanium Drive: 1000x Tritanium », reprise 1 000 ; `contributed` 1 450 | rien | 1 vente : `quantity` 450, 5 625 ISK, `accountedQuantity` 1 000 |
| E31 | Ligne ancienne de E30 avec description « Tritanium Drive » (reprise NULL) ; `contributed` 1 450 | rien | rien ; erreurs = `[{Fictional Mining Corp, "Tritanium Drive", "Pilot One", "legacy_import_quantity_unknown", null}]` |
| E32 | Identifiant ancien dans `declinedLootSaleTransactionIds` ; rien d'importé ; `contributed` 1 200 | rien | rien, erreurs `[]` ; après « réinitialiser les refus » : 1 vente de 1 200, 15 000 ISK |
| E33 | Ligne de 1 000 ; `contributed` 800 | rien | rien ; erreurs = `[{…, "Tritanium Drive", "Pilot One", "contribution_below_imported", null}]` |
| E34 | Scan : vente de E27 (`accountedQuantity` 1 000) ; entre-temps, ligne de 800 importée au cumul 1 800 ; clic « Ajouter » | ligne importée en double | rien d'écrit ; `imported` 0, `rejectedContributions` 1 ; front : « Contribution déjà comptée entre-temps (synchro ou autre onglet) : relancez le scan. » |
| E35 | Refus de la vente de E27 | identifiant ajouté aux refus, couple perdu pour toujours | `declinedCorpProjectQuantities` = `{"a1b2c3d4-project:2112000001": 600}` ; scan au même cumul : rien ; cumul 1 900 : E29 |
| E36 | P1 : ligne de 1 000, `contributed` 1 000 ; P2 : rien d'importé, `contributed` 400 | 1 vente de P2 | 1 vente de P2 seulement : 400, 5 000 ISK, `accountedQuantity` 0 |
| E37 | Ligne `corp_project` envoyée sans `accountedQuantity` | importée | rejetée, `rejectedContributions` 1 |

Reprise de la migration (M1) : « Tritanium Drive: 1000x Tritanium » → 1000 ; « Ops: Phase 2: 250x Veldspar » → 250 (dernière occurrence) ; « Tritanium Drive » → NULL.

**Lot 3 — synchro horaire**

| # | Situation | Aujourd'hui | Attendu |
|---|---|---|---|
| E38 | P1 a livré 1 000 à T, contribution modifiée il y a 2 jours, rien d'importé | aucune ligne | 1 `PveIncome` : `corp_project`, 12 500, date = `last_modified` de la contribution, description « Tritanium Drive: 1000x Tritanium », identifiant `…_1000`, couple, quantité 1 000 ; `corpProjects` 1 |
| E39 | Passage suivant, même cumul | aucune ligne | aucune ligne, `corpProjects` 0 |
| E40 | Cumul passé à 1 600 | aucune ligne | 1 ligne de 7 500 ISK (600) |
| E41 | Détail de T sans `reward_per_contribution` | aucune ligne | aucune ligne, `errors` sans entrée `corpProjects` ; le scan manuel propose la contribution à valeur inconnue (E16) |
| E42 | T modifié il y a 45 jours | aucune ligne | T non lu, aucune ligne ; le scan manuel sur 60 jours ou plus le propose |
| E43 | Liste des projets de C en 503, bounties lus | bounties importés | bounties importés ; `errors` contient `"corpProjects: Fictional Mining Corp: esi_error (HTTP 503)"` ; `lastSyncAt` mis à jour ; `failedCharacters` 0 |
| E44 | Aucun type de loot configuré, E38 sinon | aucune ligne | 1 ligne de 12 500 ISK |
| E45 | Identifiant ancien du couple dans `declinedLootSaleTransactionIds` | aucune ligne | aucune ligne |

### Cas limites

- **`contributed` qui n'est pas un cumul** (hypothèse ESI non vérifiable) : si c'est la dernière livraison seulement, le delta devient négatif ou nul après le premier import ; R16 ne propose rien et l'écran affiche `contribution_below_imported`. Aucun ISK n'est compté deux fois.
- **Récompense modifiée en cours de projet** : le delta est valorisé à la récompense lue au moment du scan ou de la synchro, les lignes déjà importées ne sont pas recalculées.
- **Contribution d'un personnage qui a quitté la corporation** : il n'est plus lu (la corporation lue est celle du personnage aujourd'hui) ; ses lignes déjà importées restent.
- **Projet supprimé (`Deleted`)** : ignoré comme aujourd'hui, ses lignes restent.
- **Repli R10 sur une grande corporation** : une contribution par personnage de l'utilisateur et par projet de la fenêtre ; les 404 coûtent des jetons de la limite `corp-project`. Fenêtre de la synchro limitée à 30 jours pour cette raison.
- **Personnage valide sans le scope ET lecteur ailleurs** : R8 ne s'applique qu'à la corporation lue ; le personnage reste lu par les transactions et les contrats.
- **« Tout » sans entrée ancienne** : un utilisateur sans aucune entrée n'a pas le bouton ; une contribution plus ancienne que toute entrée reste hors de la fenêtre du scan avec « Tout » (la synchro du lot 3 ne lit que 30 jours). Pas de lecture sans borne : trop d'appels ESI.
- **Fuseau** : `oldestEntryDate` est une date sans heure ; le +1 de R14 garantit que la plus ancienne entrée est dans la fenêtre quel que soit le fuseau du navigateur.
- **Deux synchros simultanées** : voir R20 ; non traité.
- **Ligne de revenu `corp_project` créée à la main** (`POST /api/pve/loot-sales` avec `type` `corp_project`) : sans couple ni identifiant ancien, elle ne compte pour aucun couple.

### Fichiers de production à modifier

**Lot 1**

| Fichier | Changement |
|---|---|
| `src/Service/Pve/CorpProjectContributionReader.php` (nouveau) | lecteur extrait de `ScanLootSalesProcessor` (`selectProjectsReader()`, `canBeCalledByTheScan()`, trois caches) : R1, R2, R4, R5, R8 à R11 ; dépendances `EsiClient`, `TokenManager`, `CacheItemPoolInterface`, `LoggerInterface` ; `read(User, \DateTimeImmutable $from): CorpProjectContributionReading` |
| `src/Service/Pve/CorpProjectContributionReading.php`, `CorpProjectContribution.php`, `CorporationProjectReadError.php` (nouveaux) | objets de résultat : contributions lues (projet, nom, corporation, personnage, `typeId`, `contributed`, `?float` récompense, date), erreurs, personnages refusés, `?int` projets scannés |
| `src/Enum/CorporationProjectReadFailure.php` (nouveau) | motifs de R9 |
| `src/State/Processor/Pve/ScanLootSalesProcessor.php` | délègue la partie projets au lecteur, construit les `DetectedSaleResource` ; R12 (pas de retour anticipé) ; constructeur : `+ CorpProjectContributionReader`, `- CacheItemPoolInterface` |
| `src/ApiResource/Pve/ScanLootSalesResultResource.php` | `?int` transactions et contrats, `corporationProjectErrors` à la place de `corporationsWithoutValidCharacter` |
| `src/ApiResource/Pve/CorporationProjectErrorResource.php` (nouveau) | `corporationName`, `projectName`, `characterName`, `reason`, `httpStatus` |
| `src/ApiResource/Pve/DetectedSaleResource.php` | `?float $price` |
| `src/Repository/PveIncomeRepository.php`, `PveExpenseRepository.php`, `MiningEntryRepository.php` | `findOldestDate(User): ?\DateTimeImmutable` (requête `MIN(date)`) |
| `src/State/Provider/Ledger/LedgerDashboardProvider.php` | `+ MiningEntryRepository`, calcul de `oldestEntryDate` |
| `src/ApiResource/Ledger/LedgerDashboardResource.php` | `?string $oldestEntryDate` |
| `frontend/src/types/pve.ts`, `frontend/src/stores/ledger.ts` | types (section « Contrats ») |
| `frontend/src/composables/usePveExpenseScanning.ts` | R12 (choix dialogue ou configuration, plus de `\|\| 0`), R13 (plus de replis), `needsPriceInput` sur `price === null \|\| price === 0` |
| `frontend/src/components/ledger/pve/LootSaleScanDialog.vue` | liste des erreurs de projets (une ligne par erreur, `role="alert"`), lien `/characters` si `charactersToReconnect` ou un motif `no_valid_character` / `missing_projects_scope`, avis et bouton sans type de loot, résumés R12, saisie de valeur pour `price` `null` |
| `frontend/src/components/ledger/PveTab.vue` | relie `@open-loot-config` à `showLootConfig` |
| `frontend/src/views/Ledger.vue` | bouton « Tout » (R14) |
| `frontend/src/i18n/locales/fr.json`, `en.json` | clés ci-dessous ; suppression de `pve.corpProjectsAuthenticationError` |

**Lot 2**

| Fichier | Changement |
|---|---|
| `migrations/Version20261030xxxxxx.php` (nouveau, nom donné par `doctrine:migrations:diff`) | voir « Migration » |
| `src/Entity/PveIncome.php` | `?string $corpProjectId` (36), `?int $corpProjectCharacterId`, `?int $corpProjectQuantity` (bigint), index `(user_id, corp_project_id)` |
| `src/Entity/UserPveSettings.php` | `array $declinedCorpProjectQuantities` (JSON), `declinedCorpProjectQuantityOf()`, `addDeclinedCorpProjectQuantity()` ; `clearDeclinedExcept()` vide aussi cette carte |
| `src/Repository/PveIncomeRepository.php` | `findCorpProjectImports(User): list<array{corpProjectId: ?string, corpProjectCharacterId: ?int, corpProjectQuantity: ?int, transactionId: ?int}>` (lignes `corp_project`) |
| `src/Service/Pve/CorpProjectContributionAccounting.php` (nouveau) | R15 et R18 : quantité déjà comptée d'un couple (`?int`, `null` = inconnue), refus ancien ; utilisé par le lecteur et par `ImportLootSalesProcessor` |
| `src/Service/Pve/CorpProjectContributionReader.php` | R16 : delta, `accountedQuantity`, identifiant de suivi avec le cumul, erreurs `contribution_below_imported` et `legacy_import_quantity_unknown` |
| `src/Enum/CorporationProjectReadFailure.php` | deux motifs |
| `src/State/Processor/Pve/ScanLootSalesProcessor.php` | `characterId`, `accountedQuantity`, `quantity` = delta ; plus de contrôle des identifiants importés ou refusés pour les projets (fait par la comptabilité) |
| `src/ApiResource/Pve/DetectedSaleResource.php` | `?int $characterId`, `?int $accountedQuantity` |
| `src/ApiResource/Input/Pve/ImportLootSalesInput.php` | formes des éléments (section « Contrats ») |
| `src/State/Processor/Pve/ImportLootSalesProcessor.php` | R17 ; dépend de `CorpProjectContributionAccounting` |
| `src/ApiResource/Pve/ImportResultResource.php` | `int $rejectedContributions = 0` |
| `frontend/src/types/pve.ts`, `frontend/src/composables/usePveExpenseScanning.ts` | envoi des quatre champs à l'import et au refus d'une contribution, traitement de `rejectedContributions` |
| `frontend/src/i18n/locales/fr.json`, `en.json` | trois clés du lot 2 |

**Lot 3**

| Fichier | Changement |
|---|---|
| `src/Service/Sync/PveSyncService.php` | `syncCorpProjectContributions()` (R19), appel dans `syncAll()`, clé `corpProjects`, constructeur `+ CorpProjectContributionReader` |
| `src/State/Processor/Pve/SyncPveProcessor.php` | `corpProjects` dans les données rendues |

Le scheduler, les messages et les handlers ne changent pas : l'étape passe par `syncAll()`. `CLAUDE.md` (tableau du scheduler) n'est pas modifié par cette spec ; à mettre à jour avec le lot 3 si Sylvain le souhaite.

### Clés i18n du périmètre B

| Clé | FR | EN | Lot |
|---|---|---|---|
| `pve.corpProjectErrors.line` | `{subject} : {reason}` | `{subject}: {reason}` | 1 |
| `pve.corpProjectErrors.subject.corporation` | `Projets de {corporation} illisibles` | `Projects of {corporation} could not be read` | 1 |
| `pve.corpProjectErrors.subject.project` | `Projet {project} de {corporation} illisible` | `Project {project} of {corporation} could not be read` | 1 |
| `pve.corpProjectErrors.subject.contribution` | `Contribution de {character} au projet {project} illisible` | `Contribution of {character} to project {project} could not be read` | 1 |
| `pve.corpProjectErrors.reason.no_valid_character` | `aucun personnage de cette corporation n'a d'autorisation valide` | `no character of this corporation has a valid authorization` | 1 |
| `pve.corpProjectErrors.reason.missing_projects_scope` | `accès aux projets corporation non accordé, reconnectez le personnage` | `access to corporation projects not granted, reconnect the character` | 1 |
| `pve.corpProjectErrors.reason.esi_error` | `erreur ESI (HTTP {status})` | `ESI error (HTTP {status})` | 1 |
| `pve.corpProjectErrors.reason.esi_error_network` | `ESI injoignable` | `ESI unreachable` | 1 |
| `pve.corpProjectErrors.reason.unexpected_response` | `réponse ESI incomplète` | `incomplete ESI response` | 1 |
| `pve.lootScanSummaryNoLootTypes` | `{contracts} contrats, {projects} projets scannés - {detected} ventes détectées` | `{contracts} contracts, {projects} projects scanned - {detected} sales detected` | 1 |
| `pve.lootScanSummaryNoLootTypesUnknownProjects` | `{contracts} contrats, projets : inconnu - {detected} ventes détectées` | `{contracts} contracts, projects: unknown - {detected} sales detected` | 1 |
| `pve.noLootTypesProjectsOnly` | `Aucun type de loot configuré : les ventes du marché ne sont pas lues, seuls les contrats de loot PVE et les projets corporation le sont.` | `No loot type configured: market sales are not read, only PVE loot contracts and corporation projects are.` | 1 |
| `pve.configureLootTypes` | `Configurer les types de loot` | `Configure loot types` | 1 |
| `pve.errors.lootSalesScanHttpFailure` | `Scan des ventes de loot et des projets corporation en échec (HTTP {status})` | `Loot sales and corporation projects scan failed (HTTP {status})` | 1 |
| `pve.errors.lootContractsScanHttpFailure` | `Scan des contrats de loot en échec (HTTP {status})` | `Loot contracts scan failed (HTTP {status})` | 1 |
| `ledger.period.all` | `Tout` | `All` | 1 |
| `pve.corpProjectErrors.reason.contribution_below_imported` | `quantité ESI inférieure à la quantité déjà importée` | `ESI quantity lower than the quantity already imported` | 2 |
| `pve.corpProjectErrors.reason.legacy_import_quantity_unknown` | `quantité de l'import précédent inconnue, supprimez la ligne de revenu de ce projet pour la réimporter` | `quantity of the previous import unknown, delete this project's income line to import it again` | 2 |
| `pve.errors.corpProjectContributionStale` | `Contribution déjà comptée entre-temps (synchro ou autre onglet) : relancez le scan.` | `Contribution already counted in the meantime (sync or another tab): run the scan again.` | 2 |

`pve.corpProjectsAuthenticationError` est supprimée (remplacée par la ligne `no_valid_character`) ; les clés du périmètre A `pve.lootScanSummaryUnknownProjects`, `pve.charactersToReconnect` et `pve.reconnectCharactersLink` restent.

### Câblage des tests (unitaires, sans base)

**Lot 1**

- Harnais back : extraire du test existant un trait `tests/Unit/State/Processor/Pve/CorpProjectsEsiSimulation.php` (`createSimulatedEsi()`, `projectListPages()`, `contributorPages()`, `projectDetails()`, `contribution()`, `character()`, `accessTokenOf()`). `character()` gagne `bool $hasProjectsScope = true` : stub `EveToken::hasScope()` qui rend ce booléen pour `esi-corporations.read_projects.v1`. Réponses 403, 404, 500, 502, 503 par clé de requête (`MockResponse` de `http_code` voulu ; `EsiClient` ne rejoue que 420 et 429, pas de réponse de rejeu à prévoir). Le processor est construit avec un **vrai** `CorpProjectContributionReader` sur le même `EsiClient` simulé et le même stub `TokenManager`. `createProcessor()` accepte `array $lootTypeIds` pour R12.
- Nouvelle classe `tests/Unit/State/Processor/Pve/ScanLootSalesProcessorCorpProjectErrorsTest.php`, `#[Group('issue-243')]` : E11 à E23 (E22 et E23 côté back ; le choix dialogue ou configuration est testé côté front).
- `tests/Unit/State/Provider/Ledger/LedgerDashboardProviderTest.php` : E24, E25 avec stubs de `findOldestDate()` des trois repositories.
- Front : `frontend/tests/components/ledger/lootSaleScanDialogCorpProjectErrors.spec.ts` (F8 à F15, montage avec `en.json` comme `lootSaleScanDialogAuthentication.spec.ts`) ; `frontend/tests/composables/usePveExpenseScanning.spec.ts` : F6, F7, E22 et E23 côté composable (`onOpenLootConfig` appelé ou non, dialogue ouvert ou non) ; `frontend/tests/views/LedgerPeriodAll.spec.ts` : F16, F17 avec `vi.setSystemTime(new Date('2026-10-30T22:00:00Z'))` et `apiRequest` simulé.
- **Non couvert par un test unitaire** : les trois requêtes `findOldestDate()` (DQL `MIN`, exigent une base). À couvrir par `make test-integration` si Sylvain l'accepte, sinon par la revue.
- **Tests existants appelés à casser** (changement voulu) :
  - `ScanLootSalesProcessorCorpProjectsTest` : tous les tests qui appellent `process()` (constructeur : lecteur injecté, plus de `cache:` ; `EveToken` stub sans `hasScope()` configuré rend `false`, donc plus aucun lecteur avant correction du harnais) ; `testUnavailableCorpProjectsListYieldsNoProjectSaleAndNoScannedProject` (0 → `null` et entrée `esi_error` 500 : E11) ; les 6 assertions sur `corporationsWithoutValidCharacter` (champ remplacé).
  - `ScanLootSalesProcessorTest` : constructeur.
  - `LedgerDashboardProviderTest` : constructeur (`MiningEntryRepository`).
  - Front : `lootSaleScanDialogAuthentication.spec.ts` (champ et texte de F2 remplacés par F11) ; `usePveExpenseScanning.spec.ts` (test « falls back to unknown scannedProjects and empty lists when the loot sale scan fails » remplacé par F6, champs `corporationsWithoutValidCharacter`).

**Lot 2**

- Back : `tests/Unit/State/Processor/Pve/ScanLootSalesProcessorCorpProjectDeltaTest.php` (E26 à E33, E36), stub `PveIncomeRepository::findCorpProjectImports()` qui rend les lignes de l'exemple, `UserPveSettings` réel avec refus ; `tests/Unit/State/Processor/Pve/ImportLootSalesProcessorCorpProjectTest.php` (import de E26, E34, E35, E37), `EntityManagerInterface` mock qui capture les `persist()`, repositories stub ; `tests/Unit/Entity/UserPveSettingsDeclinedCorpProjectQuantitiesTest.php` (ajout cumulé, vidage par `clearDeclinedExcept()`).
- Front : `usePveExpenseScanning.spec.ts` : corps envoyé à l'import et au refus d'une contribution (quatre champs), traitement de `rejectedContributions`.
- **Non couvert par un test unitaire** : la reprise SQL de la migration (M1) et `findCorpProjectImports()` ; vérification par la requête de contrôle des actions prod.
- **Tests existants appelés à casser** : les 9 assertions d'identifiant `-abs(crc32(PROJECT_ID . '_' . characterId))` de `ScanLootSalesProcessorCorpProjectsTest` (le cumul entre dans l'identifiant : `…_1000`, `…_360`, `…_640` selon le test).

**Lot 3**

- Back : `tests/Unit/Service/Sync/PveSyncServiceCorpProjectsTest.php` (E38 à E45) : vrai `EsiClient` sur le trait `CorpProjectsEsiSimulation`, vrai lecteur, vraie `CorpProjectContributionAccounting` sur repositories stub, `EntityManagerInterface` mock qui capture les `PveIncome`, hub Mercure stub qui capture les données de `syncCompleted` (`corpProjects`). `SyncPveProcessorTest` : `corpProjects` dans la sortie.
- **Tests existants appelés à casser** : constructeur de `PveSyncService` dans `PveSyncServiceTest`, `PveSyncServiceCharacterizationTest` et `SyncPveProcessorTest`. Correctif du harnais : un vrai lecteur sur un `MockHttpClient` qui échoue à toute requête ; leurs personnages stub ont `getCorporationId()` 0 (corporation PNJ), le lecteur n'appelle donc rien et les assertions restent vraies.

### Migration (lot 2)

`up()` :

```sql
ALTER TABLE pve_income ADD corp_project_id VARCHAR(36) DEFAULT NULL;
ALTER TABLE pve_income ADD corp_project_character_id BIGINT DEFAULT NULL;
ALTER TABLE pve_income ADD corp_project_quantity BIGINT DEFAULT NULL;
CREATE INDEX IDX_<généré> ON pve_income (user_id, corp_project_id);
UPDATE pve_income
   SET corp_project_quantity = CAST(substring(description FROM '.*: ([0-9]+)x ') AS BIGINT)
 WHERE type = 'corp_project' AND corp_project_id IS NULL AND transaction_id < 0;
ALTER TABLE user_pve_settings ADD declined_corp_project_quantities JSON DEFAULT '{}' NOT NULL;
ALTER TABLE user_pve_settings ALTER declined_corp_project_quantities DROP DEFAULT;
```

Les colonnes et l'index sortent de `doctrine:migrations:diff` ; l'`UPDATE` de reprise et le `DEFAULT '{}'` temporaire (lignes existantes de `user_pve_settings`) sont ajoutés à la main. `down()` supprime l'index et les quatre colonnes ; la reprise est perdue, sans effet sur les montants. Le motif de reprise n'a pas pu être vérifié sur des lignes réelles (aucune base lancée dans ce worktree) [TO CONFIRM sur la base locale : les 2 lignes `corp_project` de février 2026].

### Actions prod

- **Lot 1** : aucune migration, aucune commande.
- **Lot 2** : après le déploiement, `docker compose exec app php bin/console doctrine:migrations:migrate` (Sylvain), puis contrôle de la reprise :

  ```sql
  SELECT count(*) AS legacy,
         count(*) FILTER (WHERE corp_project_quantity IS NULL) AS unknown_quantity
    FROM pve_income
   WHERE type = 'corp_project' AND corp_project_id IS NULL;
  ```

  `unknown_quantity` > 0 : ces couples affichent `legacy_import_quantity_unknown` jusqu'à suppression de la ligne par l'utilisateur. À ajouter aux actions du prochain déploiement (mémoire `prod-deploy-actions`).
- **Lot 3** : aucune commande. Ne pas déployer avant d'avoir fait le contrôle du lot 2 : le premier passage horaire importe d'un coup, pour chaque utilisateur en synchro automatique, les contributions en attente des 30 derniers jours, calculées sur les quantités reprises.

### Décisions B retenues en autonomie

Mode nuit, aucune question posée ; option la plus sûre ou la plus simple retenue :

1. **Rôle `Project_Manager`** (constat ESI hors issue) : repli par personnage sur 403 des contributeurs (R10) plutôt que suppression de l'appel aux contributeurs : le chemin qui marche aujourd'hui pour un lecteur qui a le rôle ne change pas.
2. Dans ce repli, 404 = pas de contribution, sans erreur [TO CONFIRM] ; tout autre statut reste visible.
3. Récompense absente = prix inconnu (`null`) avec la saisie de valeur existante, pas une erreur : le champ est optionnel dans l'ESI.
4. Scope vérifié sur le token avant de choisir le lecteur (R8), plutôt que réagir au 403 : pas d'appel inutile, motif exact à l'écran.
5. Une erreur de niveau projet garde le projet compté ; seule une erreur de niveau corporation rend le nombre inconnu.
6. Scan HTTP en échec : pas de dialogue du tout plutôt qu'un dialogue partiel (R13).
7. Sans type de loot : transactions et contrats `null` (non lus), pas 0 (R12).
8. « Tout » calculé côté front depuis `oldestEntryDate` ; aucun provider ne change (R14). YTD existe déjà : B4 ne sert qu'aux gains d'une année précédente.
9. Quantité importée portée par les lignes de revenu (somme) plutôt qu'une table à part : supprimer une ligne libère sa quantité, comme la suppression libère aujourd'hui le couple.
10. Quantités refusées dans une carte JSON de `UserPveSettings`, à côté des autres refus ; « réinitialiser les refus » la vide.
11. Contrôle `accountedQuantity` à l'import et au refus (concurrence optimiste) plutôt qu'un verrou.
12. Imports d'avant le lot 2 : quantité reprise de la description (donnée enregistrée, pas une valeur inventée) ; sinon inconnue, rien n'est proposé.
13. Identifiant de suivi avec le cumul : unique par état de la contribution, protège la course synchro / clic.
14. Synchro : fenêtre de 30 jours (comme les autres étapes) et import seulement si la récompense est connue et > 0.
15. Texte du message de synchro inchangé, compteur `corpProjects` dans les données seulement.
16. Pas de contrainte d'unicité `(user_id, transaction_id)` ni de verrou de synchro : risque déjà présent sur toutes les étapes PVE, à décider à part.
17. Pas de maquette : la consigne de cette passe limite l'écriture à ce fichier, et les changements tiennent dans le dialogue existant (une liste d'alertes, un avis, une variante du résumé) et la barre de période (un bouton). À produire avant le lot 1 si Sylvain le demande.

## Décisions retenues en autonomie

Mode nuit, aucune question posée ; option la plus simple retenue :

1. Rotation du lecteur uniquement sur `EveAuthRequiredException`, pas sur les autres erreurs ESI (R2, R6).
2. Un seul `null` pour `scannedProjects` dès qu'une corporation est illisible, plutôt qu'un compte partiel (R3).
3. `charactersToReconnect` liste tous les personnages invalides avec token de l'utilisateur, corporation PNJ comprise, et pas seulement ceux de la corporation (R5, E10).
4. Listes de noms (`list<string>`) plutôt que d'objets `{id, name}` : le front n'affiche que les noms, uniques dans EVE.
5. Nom de champ `corporationsWithoutValidCharacter` plutôt qu'un booléen `authenticationError`, pour couvrir plusieurs corporations (E8). Remplacé par `corporationProjectErrors` au lot 1 du périmètre B.
6. Personnage sans token : ni listé ni lecteur (comportement actuel, cohérent avec la spec #86).
7. Repli front non OK : `scannedProjects: null` plutôt que 0 ; le message d'erreur réel reste en B3 (traité par R13).
8. Pas de maquette : le changement tient dans le dialogue existant (un bandeau, un avertissement, une variante du résumé), avec les styles d'alerte déjà présents.

## Glossaire

Aucun terme existant ne devient faux (« Autorisation révoquée » reste exact) : `docs/glossary.md` n'est pas modifié. « Contribution » existe déjà pour l'industrie collaborative : les termes ci-dessous disent toujours « contribution à un projet corporation ». Termes proposés, à valider :

| Terme | EN | Définition proposée | Symboles |
|---|---|---|---|
| **Projet corporation** | Corporation project | Projet ouvert par une corporation joueur dans EVE, lu par l'ESI corp projects ; une contribution d'un personnage de l'utilisateur rapporte `quantité × reward_per_contribution` et devient un revenu `corp_project` à l'import. | `PveIncomeType::CorpProject`, `DetectedSaleResource::$projectId` |
| **Lecteur des projets** | Projects reader | Personnage de la corporation dont le token sert à lire la liste, les contributeurs et le détail des projets : le premier, dans l'ordre des personnages de l'utilisateur, qui a un token, une autorisation valide et le scope des projets corporation ; s'il est refusé, le suivant (spec #243, R1, R8). | `CorpProjectContributionReader` |
| **Personnage à reconnecter** | Character to reconnect | Personnage de l'utilisateur, avec token, en **autorisation révoquée** au moment du scan ou refusé par EVE SSO pendant celui-ci : le scan l'ignore et l'écran le liste (spec #243). Le tableau de bord en a déjà la notion (`dashboard.todo.charactersToReconnect`). | `ScanLootSalesResultResource::$charactersToReconnect` |
| **Erreur de lecture des projets** | Corporation project read error | Corporation, projet ou contribution à un projet corporation que le scan ou la synchro n'a pas pu lire, avec son motif ; jamais présentée comme une absence de projet (spec #243, R9). | `CorporationProjectReadFailure`, `ScanLootSalesResultResource::$corporationProjectErrors` |
| **Quantité déjà comptée** | Accounted quantity | Pour un projet corporation et un personnage : quantités déjà importées en revenu, plus quantités refusées, plus quantité reprise d'un import d'avant le suivi des quantités (spec #243, R15). | `CorpProjectContributionAccounting`, `DetectedSaleResource::$accountedQuantity` |
| **Contribution en attente** | Pending contribution | Part d'une contribution à un projet corporation pas encore comptée : `contributed` de l'ESI moins la quantité déjà comptée ; proposée par le scan, importée par la synchro PVE (spec #243, R16, R19). | `CorpProjectContributionReader`, `PveSyncService::syncCorpProjectContributions()` |
