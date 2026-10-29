# Spec #243 — Scan des projets corporation : personnage lecteur valide, personnages à reconnecter, erreur d'authentification

**Statut : validée en autonomie en mode nuit le 2026-10-29, à relire par Sylvain.**

Circuit : `circuit:m` (pas de note de câblage, étape 1b de `/tdd-red` sautée). Tests unitaires seulement, doublures aux frontières ESI (`MockHttpClient` derrière un vrai `EsiClient`) et `TokenManager` (stub), comme `ScanLootSalesProcessorCorpProjectsTest` le fait déjà. Aucun test d'intégration : aucune donnée en base n'est lue par la partie modifiée.

Périmètre : **seulement** la cause vérifiée du « 0 projects scanned » de l'issue #243. Le reste de l'issue est une décision produit en attente (voir « Hors périmètre / Blocages »).

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
- **Erreur ESI non liée à l'auth** (500, 403 faute du scope `esi-corporations.read_projects.v1` sur un vieux token, 401 de l'ESI, réseau) : hors périmètre (R6). Le correctif ne l'impose pas : la rotation porte uniquement sur `EveAuthRequiredException`. Elle reste affichée « 0 projets » (E9) : c'est la décision produit B3 ci-dessous.
- **Personnage sans token** : ignoré comme aujourd'hui, ni lecteur, ni contributeur appelé, ni listé à reconnecter. Une corporation où aucun personnage n'a de token n'est pas scannée et n'est pas listée.
- **Corporation PNJ** (< 2 000 000) : jamais lue ; un personnage invalide qui y est rattaché est quand même listé à reconnecter (E10).
- **Aucun type de loot configuré** : le scan s'arrête avant les projets (`:66-72`), listes vides, comportement inchangé (blocage B5).
- **Réponse HTTP non OK du scan** : le composable remplace aujourd'hui la réponse par `{ detectedSales: [] }`. Ce repli porte `scannedProjects: null` et des listes vides, pour ne pas afficher un 0 plausible ; le message d'erreur réel reste hors périmètre (B3).

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

## Hors périmètre / Blocages

Décisions produit de l'issue #243, en attente de Sylvain. Rien n'est implémenté ici.

- **B1 — Synchro automatique des gains de projets corporation.**
  Option A : tâche planifiée (avec la sync PVE horaire, `PveSyncService`) qui importe directement les contributions en `corp_project` (le montant est connu : quantité × `reward_per_contribution`).
  Option B : scan déclenché à l'ouverture de Revenue, proposition à importer comme aujourd'hui.
  Recommandation : A, après B2 (sinon une seconde contribution reste perdue), et en réutilisant le lecteur de R1.
- **B2 — Identifiant de suivi qui inclut la contribution** (aujourd'hui `crc32(projet_personnage)`, `:386`).
  Option A : mémoriser la quantité déjà importée par couple projet/personnage et proposer le delta.
  Option B : inclure `last_modified` de la contribution dans l'identifiant.
  Recommandation : A ; B réimporterait le cumul si `contributed` est cumulatif [TO CONFIRM : `contributed` de `/contribution/{character_id}` est un cumul].
- **B3 — Erreurs ESI non liées à l'auth remontées à l'écran** (R6, `:417`, `:421`, `:426`, repli front non OK).
  Option A : même principe que R3, projets inconnus et corporation ou projet en erreur listé.
  Option B : statu quo (logs).
  Recommandation : A, en généralisant `corporationsWithoutValidCharacter` en liste d'erreurs avec motif.
- **B4 — Gains hors de la fenêtre de 90 jours de Revenue.**
  Option A : période « tout ».
  Option B : rappel de la fenêtre à l'écran.
  Recommandation : A.
- **B5 — Scan arrêté sans message si aucun type de loot n'est configuré** (`:66-72`), alors que la partie projets ne filtre pas sur les types de loot.
  Option A : lire les projets quand même.
  Option B : le dire à l'écran.
  Recommandation : A.
- Replis `?? 0` sur `reward_per_contribution` et la quantité (`:346`, `:366`) : à traiter avec B3.

## Décisions retenues en autonomie

Mode nuit, aucune question posée ; option la plus simple retenue :

1. Rotation du lecteur uniquement sur `EveAuthRequiredException`, pas sur les autres erreurs ESI (R2, R6).
2. Un seul `null` pour `scannedProjects` dès qu'une corporation est illisible, plutôt qu'un compte partiel (R3).
3. `charactersToReconnect` liste tous les personnages invalides avec token de l'utilisateur, corporation PNJ comprise, et pas seulement ceux de la corporation (R5, E10).
4. Listes de noms (`list<string>`) plutôt que d'objets `{id, name}` : le front n'affiche que les noms, uniques dans EVE.
5. Nom de champ `corporationsWithoutValidCharacter` plutôt qu'un booléen `authenticationError`, pour couvrir plusieurs corporations (E8).
6. Personnage sans token : ni listé ni lecteur (comportement actuel, cohérent avec la spec #86).
7. Repli front non OK : `scannedProjects: null` plutôt que 0 ; le message d'erreur réel reste en B3.
8. Pas de maquette : le changement tient dans le dialogue existant (un bandeau, un avertissement, une variante du résumé), avec les styles d'alerte déjà présents.

## Glossaire

Aucun terme existant ne devient faux (« Autorisation révoquée » reste exact) : `docs/glossary.md` n'est pas modifié. Termes proposés, à valider :

| Terme | EN | Définition proposée | Symboles |
|---|---|---|---|
| **Projet corporation** | Corporation project | Projet ouvert par une corporation joueur dans EVE, lu par l'ESI corp projects ; une contribution d'un personnage de l'utilisateur rapporte `quantité × reward_per_contribution` et devient un revenu `corp_project` à l'import. | `PveIncomeType::CorpProject`, `DetectedSaleResource::$projectId` |
| **Lecteur des projets** | Projects reader | Personnage de la corporation dont le token sert à lire la liste, les contributeurs et le détail des projets : le premier, dans l'ordre des personnages de l'utilisateur, qui a un token et une autorisation valide ; s'il est refusé, le suivant (spec #243). | `ScanLootSalesProcessor` |
| **Personnage à reconnecter** | Character to reconnect | Personnage de l'utilisateur, avec token, en **autorisation révoquée** au moment du scan ou refusé par EVE SSO pendant celui-ci : le scan l'ignore et l'écran le liste (spec #243). Le tableau de bord en a déjà la notion (`dashboard.todo.charactersToReconnect`). | `ScanLootSalesResultResource::$charactersToReconnect` |
