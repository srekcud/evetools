# Spec #238 : Type ouvert par identifiant dans Margins, `isT2` connu avant la première analyse

**Statut : validée en autonomie en mode nuit le 2026-10-31 (option A de la décision de Sylvain du 2026-10-28, suite à #228), à relire par Sylvain.**

Source : issue #238 (« Type ouvert par `?typeId=` : `isT2` inconnu avant l'analyse, Margins part en ME 10 / TE 20 »). Lot backend (endpoint, règle `isT2` partagée) et frontend (onglet Margins).

## Contexte

La recherche normale d'un produit dans Margins connaît `isT2` : `GET /api/industry/search?q=` rend `{typeId, typeName, isT2}` (`ProductSearchProvider.php:56-63`) et `onProductSelect()` fixe ME 2 / TE 4 pour un T2, ME 10 / TE 20 sinon (`ProfitMarginTab.vue:315-323`). Un type **ouvert** par identifiant n'a pas cette information :

- `Industry.vue` lit `?typeId=` (entier décimal, `typeIdFromQuery()`, `:92-95`) et le passe en `initialTypeId` (`:110-114`). Deux autres chemins posent le même `initialTypeId` par `navigationIntent` `margins` : `ProjectTable.vue:237` et `BatchScanTab.vue:200` (`Industry.vue:197-199`). La palette de commandes ouvre `/industry?tab=margins&typeId=…` (`commandPaletteTypeActions.ts:17`).
- `ProfitMarginTab.vue` lance alors l'analyse tout de suite (`:353-362`) avec les valeurs initiales `meLevel` 10 et `teLevel` 20 (`:41-42`). `selectedProduct`, donc `isT2`, n'est posé qu'au retour de l'analyse (`:357-361`), calculée avec les mauvais niveaux.
- Aucun endpoint ne rend `isT2` par identifiant : la recherche cherche par nom (`ProductSearchProvider.php:49`), `/industry/profit-margin/{typeId}` ne le rend qu'avec l'analyse complète (`ProfitMarginResource.php:18`, champ `isT2` à `:45`), et `/market/types/{id}` ne le porte pas (issue).

Conséquence : la marge d'un T2 ouvert depuis la palette ou un lien diffère de celle du même produit cherché à la main (issue, « Impact »).

La règle `isT2` existe en plusieurs exemplaires aujourd'hui (voir « Décisions », 3) :

| Où | Règle |
|---|---|
| `ProductSearchProvider.php:44-47` | SQL : le blueprint du produit (activité fabrication ou réaction) est produit d'une activité d'invention |
| `InventionService::isT2()` (`:91-94`, via `getInventionData()` `:145-165`) | le blueprint de fabrication de plus forte quantité (`findBlueprintForProduct()`, `IndustryActivityProductRepository.php:180-201`) est produit d'une invention |
| `PivotAdvisorService::isT2Product()` (`:365`) | SQL séparé |

## Règles métier

### Backend

- **R1 : Endpoint.** `GET /api/industry/types/{typeId}` rend `{typeId, typeName, isT2}` d'un type. API Platform (`Get` d'une ressource avec provider, jamais de contrôleur), `security: "is_granted('ROLE_USER')"` comme `ProductSearchResource.php:38`. L'identifiant du chemin est celui de la ressource (`#[ApiProperty(identifier: true)]`, comme `ProductSearchResource.php:42`) : pas de parent, donc pas de `uriVariables` ni de `Link`.
- **R2 : Mêmes types que la recherche.** Le type est rendu s'il serait trouvé par la recherche : publié, produit d'une activité de fabrication ou de réaction (`ProductSearchProvider.php:46`, `:48`). Un type non fabricable (blueprint, matériau brut, module non publié…) ou absent du SDE rend **404**. Pas de 200 avec `isT2` faux « par défaut ».
- **R3 : Identifiant valide.** `typeId` est un entier décimal strictement positif sans signe ni zéro de tête, qui tient dans un entier PHP. Tout le reste (`0`, `-5`, `abc`, `1.5`, `007`, `99999999999999999999`) rend 404, avec le même message que R2 : jamais de 500, jamais de conversion `(int)` silencieuse en `0`.
- **R4 : Une seule définition de la règle.** La recherche et l'endpoint lisent les types fabricables et calculent `isT2` par le même service, avec la même requête de base (publié, activités, jointure d'invention). Ni le provider de la recherche ni celui de l'endpoint ne contient de SQL. Le résultat de la recherche (20 lignes au plus, tri par nom, vide sous 2 caractères : `ProductSearchProvider.php:33`, `:50-51`) ne change pas.
- **R5 : Contenu minimal, lecture seule.** Trois champs, rien d'autre. Aucune donnée de l'utilisateur : tous les utilisateurs reçoivent la même réponse. Pas de sync, pas d'écriture, pas d'appel ESI.
- **R6 : Accès.** Sans jeton : 401 (`security.yaml:34`, firewall `api` en `jwt`, `:22-25`).

### Frontend (onglet Margins)

- **R7 : Consulter avant d'analyser.** À chaque nouvelle valeur définie de `initialTypeId` (`?typeId=`, palette, lien, intent depuis un projet ou le scanner), l'onglet appelle `GET /api/industry/types/{typeId}` **avant** toute analyse. Tant que l'appel est en cours, aucune requête `/industry/profit-margin/` n'est émise, le bouton « Analyser » est désactivé et l'état de chargement initial est affiché.
- **R8 : Défauts selon `isT2`.** Appel réussi : `selectedProduct` est posé avec `{typeId, typeName, isT2}` de la réponse, ME et TE reçoivent les défauts de la recherche normale (T2 : ME 2 / TE 4 ; sinon ME 10 / TE 20), puis l'analyse est lancée avec ces niveaux. Les défauts viennent d'une seule fonction de l'onglet, partagée avec `onProductSelect()` : pas de second jeu de littéraux. Ils sont réappliqués à chaque type ouvert, comme la recherche normale le fait à chaque sélection : un ME modifié à la main sur le type précédent n'est pas conservé. Les autres paramètres (runs, système) restent ceux de l'onglet.
- **R9 : Échec de l'appel.** 404, erreur réseau, session expirée, limite de débit : aucune analyse n'est lancée, ni avec les défauts T1 ni avec d'autres « supposés ». L'onglet affiche un message d'erreur explicite (identifiant du type, analyse non lancée) et un bouton « Réessayer » qui refait l'appel puis, s'il réussit, applique R8. Aucun résultat d'une analyse précédente n'reste affiché sous ce message (même principe que #189 : un résultat périmé n'est pas présenté comme celui du type ouvert).
- **R10 : Dernier gagne.** Si `initialTypeId` change, ou si l'utilisateur choisit un produit par la recherche, pendant l'appel : la réponse tardive est ignorée (ni `selectedProduct`, ni ME/TE, ni analyse), comme le garde `props.initialTypeId !== typeId` d'aujourd'hui (`ProfitMarginTab.vue:356`). Un « Réessayer » ou un succès tardif d'un type périmé ne touche pas le type courant.
- **R11 : Inchangés.** La recherche normale d'un produit (aucun appel à l'endpoint, ME/TE de `onProductSelect()`), Buy vs Build (`BuyVsBuildTab.vue:59-63`, qui part déjà en ME 2) et Pivot Advisor (pas de ME/TE) ne changent pas. `Industry.vue` ne change pas : il continue de passer `initialTypeId`.

## Contrat de `GET /api/industry/types/{typeId}`

| Champ | Type | Contenu |
|---|---|---|
| `typeId` | `int` | l'identifiant demandé |
| `typeName` | `string` | nom SDE anglais (`sde_inv_types.type_name`) |
| `isT2` | `bool` | règle partagée avec la recherche (R4) |

Statuts : 200 ; 401 sans jeton (R6) ; 404 type non fabricable, inconnu ou identifiant invalide (R2, R3). Pas d'autre 4xx ni de 5xx pour une entrée utilisateur. Le format de la réponse (JSON-LD ou non) suit la configuration d'API Platform comme `/api/industry/search` ; le front lit `typeId`, `typeName`, `isT2` comme il lit `SearchResult` (`types.ts:217-221`).

## Exemples chiffrés

Identifiants de fixture, pas des identifiants SDE : Ferox 990001, Hecate 990002, réaction « Fernite Carbide » 990003, identifiant absent du SDE 990099, blueprint de l'Hecate 990012. La ligne « Appel d'analyse » est l'URL relative vue par `apiRequest`, après l'appel de l'endpoint.

| # | Entrée | Réponse de l'endpoint | Appel d'analyse |
|---|---|---|---|
| E1 | `?tab=margins&typeId=990001`, Ferox (T1 : blueprint non inventé) | 200 `{typeId: 990001, typeName: "Ferox", isT2: false}` | `/industry/profit-margin/990001?runs=1&me=10&te=20` |
| E2 | `?tab=margins&typeId=990002`, Hecate (T2 : blueprint produit d'une invention) | 200 `{typeId: 990002, typeName: "Hecate", isT2: true}` | `/industry/profit-margin/990002?runs=1&me=2&te=4` |
| E3 | `typeId=990003`, produit d'une réaction | 200 `isT2: false` | `…?runs=1&me=10&te=20` : les défauts de la recherche normale, réaction comprise (R8) |
| E4 | `typeId=990099`, absent du SDE | 404 | aucun ; message d'erreur et « Réessayer » |
| E5 | `typeId=990012`, un blueprint (produit d'aucune activité de fabrication ni de réaction) | 404 | aucun ; message et « Réessayer » |
| E6 | Produit non publié (`published` faux) | 404 | aucun |
| E7 | E4, puis « Réessayer » après que 990099 est devenu joignable, ou `typeId=990002` rechargé | 200 | E2 : une seule analyse, `me=2&te=4` |
| E8 | Onglet monté sur le Ferox, ME mis à 7 à la main, puis `initialTypeId` passe à 990002 | 200 `isT2: true` | `…/990002?runs=1&me=2&te=4` (R8 : défauts réappliqués) |
| E9 | Recherche normale « Hecate », clic sur la ligne | aucun appel à l'endpoint | `me=2&te=4`, comme avant (R11) |
| E10 | `initialTypeId` 990001 puis 990002 avant la réponse de la première | 200 pour chacun ; la réponse du Ferox est ignorée | une seule analyse : 990002, `me=2&te=4` (R10) |
| E11 | `initialTypeId` 990002, appel en cours | pas encore de réponse | zéro appel `/industry/profit-margin/` (R7) |

Identifiants invalides (R3) : `GET /api/industry/types/0`, `/-5`, `/abc`, `/1.5`, `/007`, `/99999999999999999999` rendent tous 404.

Accès :

| # | Appel | Attendu |
|---|---|---|
| E12 | Sans jeton | 401 |
| E13 | Utilisateur ordinaire, `typeId=990002` | 200 |

## Cas limites

- **Trois chemins d'ouverture, un seul correctif.** L'intent `margins` de `ProjectTable.vue:237` et `BatchScanTab.vue:200` pose le même `initialTypeId` (`Industry.vue:199`) : le correctif est dans l'onglet, il vaut pour les trois et pour `?typeId=`. L'issue ne cite que `?typeId=` ; le comportement des intents change donc aussi (ME/TE d'un T2 passent de 10/20 à 2/4 pour eux aussi, ce que l'issue appelle le bon résultat).
- **`?typeId=0` ou identifiant géant.** `typeIdFromQuery()` accepte tout `^\d+$` (`Industry.vue:94`), `Number()` perd la précision au-delà de 2^53 : l'onglet appelle l'endpoint avec cette valeur, obtient 404 (R3) et affiche l'erreur de R9. Pas de correction de `typeIdFromQuery()` dans ce lot.
- **404 et « Réessayer ».** Un 404 est définitif tant que le SDE ne change pas : « Réessayer » le refait quand même (décision 4). Le message ne promet pas une cause.
- **Type à plusieurs blueprints.** Le SDE peut avoir plusieurs blueprints pour un produit (`IndustryActivityProductRepository.php:191-198` journalise ce cas : « test blueprints »). La requête partagée peut alors rendre une ligne par blueprint, avec `isT2` différent. Pour l'endpoint, `isT2` est vrai si une ligne l'est ; c'est le comportement de la recherche (qui liste alors le type deux fois avec `DISTINCT`, `:43`). `InventionService::isT2()` retient seulement le blueprint de plus forte quantité : pour un tel type l'analyse peut rendre un `isT2` différent de l'endpoint. Existence de ce cas dans le SDE réel : inconnu (aucun SDE lu pour cette spec). Voir « Décisions », 3.
- **Verrouillage ME/TE d'un T2.** Les champs ME/TE sont désactivés quand `isT2` (`ProfitMarginTab.vue:60`, `:439`, `:460`). Avec R8, `selectedProduct` est posé avant l'analyse : les champs sont verrouillés dès le début, alors qu'ils ne l'étaient qu'au retour de l'analyse.
- **Échec de l'analyse après un appel réussi.** Inchangé : `marginError` du store (`projects.ts:244-248`), affiché par `data-testid="margin-error"` (`ProfitMarginTab.vue:504`). L'erreur de l'appel (R9) a son propre élément, `data-testid="type-lookup-error"`.
- **Pas de cache.** Un appel par ouverture. Pas d'en-tête de cache HTTP : la réponse est triviale et le SDE change à l'import seulement.
- **Latence.** Un aller-retour de plus avant l'analyse. Coût de la requête : inconnu (aucun plan d'exécution lu ; l'index de `sde_inv_types.type_id` n'a pas été vérifié).

## Tests

Backend en `#[Group('issue-238')]`, frontend en `describe` nommant l'issue.

**Unitaire, `ManufacturableTypeProvider`** (`tests/Unit/State/Provider/Industry/ManufacturableTypeProviderTest.php`, lecteur de types fabricables doublé) :

- U1 : identifiants invalides de R3 (`0`, `-5`, `abc`, `1.5`, `007`, `99999999999999999999`) : `NotFoundHttpException`, le lecteur n'est jamais appelé.
- U2 : lecteur sans résultat : `NotFoundHttpException` (E4, E5, E6).
- U3 : T1 et T2 : la ressource porte `typeId`, `typeName`, `isT2` du lecteur (E1, E2).

**Unitaire, `ProductSearchProvider`** (`tests/Unit/State/Provider/Industry/ProductSearchProviderTest.php`, nouveau : aucun test n'existe pour ce provider, vérifié par recherche dans `tests/`) : requête de moins de 2 caractères : liste vide sans appel du lecteur ; sinon la liste du lecteur est mappée en `ProductSearchResource` dans l'ordre reçu, avec la limite de 20 (R4).

**Intégration, lecteur de types fabricables** (`tests/Integration/Service/Industry/ManufacturableProductReaderTest.php`, base de test, lignes SDE insérées dans la transaction du test comme les autres tests d'intégration, `IntegrationTestCase.php:11-12`). C'est le seul endroit qui teste la règle :

- I1 : produit de fabrication dont le blueprint est produit d'une invention : `isT2` vrai (E2).
- I2 : produit de fabrication sans invention : faux (E1). Produit de réaction : faux (E3).
- I3 : blueprint (produit d'aucune activité), type absent, type non publié : pas de résultat (E4, E5, E6).
- I4 : même jeu de lignes lu par la recherche (`q` = nom) et par l'identifiant : même `typeId`, même `typeName`, même `isT2` (R4).
- I5 : produit à deux blueprints, un inventé et un non : lecture par identifiant : `isT2` vrai, une seule entrée.

**HTTP fonctionnel** (`tests/Functional/Api/Industry/ManufacturableTypeApiTest.php`, `WebTestCase`, `loginUser` comme `DataFreshnessApiTest`) :

- H1 : T1 : 200 `{typeId, typeName, isT2: false}` (E1). T2 : 200 `isT2: true` (E2). Les trois clés seulement (R5).
- H2 : type non fabricable (blueprint) : 404 (E5). Type absent : 404 (E4).
- H3 : identifiants de R3 : 404, jamais 500.
- H4 : sans jeton : 401 (E12) ; utilisateur ordinaire : 200 (E13).

**Vitest, onglet** (`frontend/tests/components/industry/profitMarginTabTypeLookup.spec.ts`, `apiRequest` simulé comme `analysisTabsInitialType.spec.ts`, aides de `frontend/tests/deferred`) :

- V1 : `initialTypeId` T1 : l'appel de l'endpoint précède l'analyse (ordre des appels) ; analyse en `me=10&te=20` (E1).
- V2 : `initialTypeId` T2 : analyse en `me=2&te=4`, champs ME/TE désactivés avant même la réponse de l'analyse (E2, verrouillage).
- V3 : appel en cours (promesse non résolue) : zéro appel `/industry/profit-margin/`, bouton « Analyser » désactivé (E11, R7).
- V4 : 404 : zéro analyse, `type-lookup-error` visible avec l'identifiant et un bouton « Réessayer », aucun résultat précédent affiché (E4, R9). Même chose pour une erreur réseau.
- V5 : « Réessayer » : refait l'appel ; succès T2 : une seule analyse en `me=2&te=4` (E7).
- V6 : changement de `initialTypeId` pendant l'appel, puis résolution du premier appel après le second : une seule analyse, celle du second type (E10, R10). Même test avec un choix par la recherche pendant l'appel.
- V7 : onglet monté, ME modifié, nouvel `initialTypeId` T2 : `me=2&te=4` (E8).
- V8 : recherche normale d'un T2 : aucun appel à l'endpoint, `me=2&te=4` (E9, R11).
- V9 : Buy vs Build et Pivot Advisor avec `initialTypeId` : aucun appel à l'endpoint (R11 ; `analysisTabsInitialType.spec.ts` reste vert tel quel, à adapter seulement pour les appels de Margins).

**Vitest, vue** (`frontend/tests/views/IndustryOpenedTypeDefaults.spec.ts`, `Industry.vue` monté avec le **vrai** `ProfitMarginTab`, les autres enfants en stub ; `IndustryProjectAndTypeQuery.spec.ts` stube l'onglet et ne peut pas servir) :

- W1 : `?tab=margins&typeId=<T2>` : endpoint puis analyse en `me=2&te=4` (E2).
- W2 : intent `margins` posé dans le store (`navigationIntent`) pour un T2 : même séquence (cas limite des trois chemins).
- W3 : `?tab=margins&typeId=<inconnu>` : erreur et « Réessayer », aucune analyse (E4).

**Tests existants à garder verts** : `analysisTabsInitialType.spec.ts`, `profitMarginTabFailure.spec.ts` (le simulateur d'`apiRequest` y répond à l'endpoint nouveau : à compléter si un test monte l'onglet avec `initialTypeId`), `IndustryProjectAndTypeQuery.spec.ts`, `IndustryPillClearsTypeQuery.spec.ts`, `IndustryAnalyzeProductIntent.spec.ts`, `ProfitMarginProviderTest.php`.

## Fichiers de production à modifier

| Fichier | Changement |
|---|---|
| `src/Service/Industry/ManufacturableProductReader.php` (nouveau) | `search(string $query, int $limit)` et `findByTypeId(int $typeId): ?ManufacturableProduct` ; la requête de base (publié, activités fabrication et réaction, jointure d'invention, `is_t2`) est écrite une fois. Reprend le SQL de `ProductSearchProvider.php:42-52` |
| `src/Service/Industry/ManufacturableProduct.php` (nouveau) | objet valeur `typeId`, `typeName`, `isT2` |
| `src/State/Provider/Industry/ProductSearchProvider.php` | plus de SQL : lit `ManufacturableProductReader::search()` (`<2` caractères, limite 20, mapping inchangés) |
| `src/ApiResource/Industry/ManufacturableTypeResource.php` (nouveau) | `Get` `/industry/types/{typeId}`, `security: "is_granted('ROLE_USER')"`, `typeId` identifiant, `typeName`, `isT2`, `openapi` avec le paramètre de chemin entier (tag `Industry - Configuration` comme `ProductSearchResource.php:25`) |
| `src/State/Provider/Industry/ManufacturableTypeProvider.php` (nouveau) | R3 puis `findByTypeId()` ; `NotFoundHttpException` au même message pour R2 et R3 |
| `frontend/src/stores/industry/projects.ts` | `fetchManufacturableType(typeId): Promise<SearchResult>` sur `/industry/types/{typeId}`, qui **propage** l'erreur (contrairement à `searchProducts`, `:24-37`, qui la jette) ; exposée comme `analyzeMargin` |
| `frontend/src/components/industry/ProfitMarginTab.vue` | constantes nommées des défauts ME/TE (T1 10/20, T2 2/4) et une fonction partagée avec `onProductSelect()` ; `watch` de `initialTypeId` : appel, R8 à R10 ; état local de chargement et d'erreur du lookup ; élément `type-lookup-error` avec « Réessayer » ; résultat masqué sous l'erreur |
| `frontend/src/i18n/locales/en.json`, `fr.json` | clé `industry.margins.typeLookupFailed` (avec `{typeId}`) ; « Réessayer » existe (`common.actions.retry`, `en.json:13`, `fr.json:13`). `fr.json` est écrit sans accents |

Aucun changement de `config/` : `^/api` exige déjà `IS_AUTHENTICATED_FULLY` (`security.yaml:34`). `Industry.vue` inchangé. Pas de migration, pas de commande à lancer en production, pas de seed.

À faire à la phase verte, hors production :

- `docs/glossary.md` : ajouter « Produit fabricable » et « Type ouvert » (voir plus bas) ; sur la ligne « Blueprint inventé », ajouter `ManufacturableProductReader` aux symboles.
- `docs/specs/228-command-palette.md` : la ligne « Présélection d'un type dans un onglet d'analyse : uniquement par `store.navigationIntent` » (« Contexte ») est périmée depuis `ddb1f15c` (`?typeId=` lu par `Industry.vue:92-95`).

## Décisions retenues en autonomie

Mode nuit, décisions validées avant l'écriture :

1. Endpoint `GET /api/industry/types/{typeId}`, API Platform, `ROLE_USER`, pas de contrôleur ; sous-ressource sans parent, donc pas de `Link` ; identifiant entier positif, sinon 404 (R1, R3). Alternative écartée de l'issue : `GET /api/industry/search?typeId=`, qui mélange liste et élément dans une même opération.
2. Mêmes types fabricables que la recherche : non fabricable ou inconnu = 404 (R2).
3. La règle `isT2` de `ProductSearchProvider` est réutilisée, extraite dans un service partagé par la recherche et l'endpoint, jamais deux définitions (R4). **Hors lot** : `InventionService::isT2()` et `PivotAdvisorService::isT2Product()` restent des définitions séparées (voir le tableau de « Contexte » et le cas du type à plusieurs blueprints). Les fusionner toucherait le calcul des coûts : issue séparée à ouvrir si Sylvain le veut.
4. Front : appel avant la première analyse ; T2 en ME 2 / TE 4 ; T1 avec les défauts actuels ; aucune analyse pendant l'appel ; échec (404, réseau) : message explicite dans l'onglet et « Réessayer », jamais d'analyse avec des défauts supposés (R7 à R9).
5. Buy vs Build et Pivot Advisor inchangés ; la recherche normale reste inchangée (R11).

Choix d'implémentation, retenus par la même passe :

6. Le correctif est dans l'onglet et non dans `Industry.vue` : l'onglet est le seul à connaître ME/TE, et les trois chemins d'ouverture passent par `initialTypeId` (cas limites).
7. Pas d'`isT2` dans l'intent ni dans l'URL : l'URL partagée ou ancienne ne porterait qu'une donnée supposée.
8. Défauts réappliqués à chaque type ouvert (R8), comme `onProductSelect()` : un seul comportement pour les deux entrées.
9. Message d'erreur unique pour 404 et erreurs réseau : l'utilisateur n'a rien à faire de plus fin, la raison technique n'est pas affichée (pas de texte d'exception ESI dans l'interface).
10. L'endpoint rend le nom SDE du type, non nul ; l'onglet n'utilise plus `typeNameLabel()` (`ProfitMarginTab.vue:359`) sur ce chemin.
11. Le contrôle de l'identifiant est dans le provider, pas dans une exigence de route (`{typeId<\d+>}`) : le provider reste testable seul (U1) et `0`, les zéros de tête et les valeurs hors plage d'un entier PHP passent de toute façon une exigence `\d+`. Les exigences de route existent pour des UUID (`ColonyResource.php:28`, `GroupIndustryMemberResource.php:40`) ; aucune n'existe pour un identifiant entier dans `src/ApiResource` (recherche `{nom<\d…}`).

## Glossaire

« Blueprint inventé » (`docs/glossary.md:94`) définit déjà le T2 d'un produit ; « ME » et « TE » existent. Termes proposés, à valider :

| Terme | EN | Définition proposée | Symboles |
|---|---|---|---|
| **Produit fabricable** | Manufacturable product | Type publié, produit d'au moins une activité de fabrication ou de réaction du SDE. C'est l'ensemble des types que la recherche de produit trouve et que l'endpoint de consultation par identifiant rend ; son `isT2` est vrai quand le blueprint du produit est un **blueprint inventé** (spec #238, R2, R4). | `ManufacturableProductReader`, `ManufacturableProduct`, `ProductSearchResource`, `ManufacturableTypeResource` |
| **Type ouvert** | Opened type | Type présélectionné dans un onglet d'analyse sans passer par la recherche : par `?typeId=` (palette, lien) ou par un `navigationIntent` (projet, scanner). L'onglet Margins consulte son `isT2` avant la première analyse pour en fixer les ME/TE par défaut (spec #238, R7, R8). | `initialTypeId` (`ProfitMarginTab.vue`), `typeIdFromQuery()` (`Industry.vue`) |
