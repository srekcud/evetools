# Spec #141 (partie « BOM de groupe ») : la BOM d'un projet de groupe se calcule sur le plan du noyau

- **Statut** : validée par Sylvain le 2026-10-28 (Q1 à Q7 : recommandations retenues ; Q4 : pas de régénération dans ce lot, voir #253 ; corrections des références périmées dans le lot docs).
- **Date** : 2026-10-28
- **Issue** : #141, partie « BOM de groupe » (rang 6 du §7 de la [spec du noyau](industry-engine.md)). Les autres parties de l'issue (double formule de quantité, code mort du noyau, autres écrans) ne sont pas traitées ici.
- **Lot** : une seule spec, un seul lot (un rouge puis un vert).

Les termes en gras sont ceux du [glossaire](../glossary.md).

## Contexte

`GroupIndustryProjectService::buildBom()` construit la **BOM** d'un projet de groupe à partir de `IndustryTreeService::buildProductionTree()`, puis aplatit l'arbre. Deux défauts, tous deux visibles des joueurs :

1. **Runs lus comme une quantité (B1, #2).** Le service passe les runs de l'item à `buildProductionTree(runs:)` (`src/Service/GroupIndustry/GroupIndustryProjectService.php:97-103`). L'arbre transmet ce paramètre à `buildNode(int $quantity, …)` (`src/Service/Industry/IndustryTreeService.php:36-39`, `:77`), qui calcule `runs = ceil(quantité / unités par run)` (`:89`). Pour un produit à plusieurs **unités par run**, les runs deviennent 1 ou presque : 25 runs de Nitrogen Fuel Block (40 unités par run) donnent une BOM d'1 run (`tests/Unit/Service/GroupIndustry/GroupIndustryBomGoldenCharacterizationTest.php:165-196`).
2. **Feuilles additionnées par branche (#69).** Chaque nœud de l'arbre arrondit ses matériaux en quantités entières (`IndustryTreeService.php:158`) et ses runs en entiers (`:89`), puis le service additionne ces quantités déjà arrondies, branche par branche (`GroupIndustryProjectService.php:194-197` pour les runs, `:248-249` pour les feuilles). Un intermédiaire consommé par plusieurs branches est donc arrondi une fois par branche : pour 1 run de Fernite Carbide, Hydrogen Fuel Block compte 3 runs au lieu de 1 (`GroupIndustryBomGoldenCharacterizationTest.php:406-428`).

Le noyau (`src/Industry/`, ADR-0009) corrige les deux : la **demande** d'un intermédiaire est agrégée sur tout le plan avant l'arrondi en runs entiers (`src/Industry/Domain/ProductionPlanner.php:30-75`, R3 de la spec du noyau). Aucun code de production hors `src/Industry/` ne l'appelait pour Group Industry. Deptrac ne l'interdit plus : la couche `Service` peut dépendre de `IndustryApplication` et `IndustryDomain` (`deptrac.yaml:112`), comme `ProfitMarginService` et `BuyVsBuildService` le font déjà.

Le test de l'issue : « un projet de groupe sur un produit à plusieurs unités par run donne les mêmes feuilles que `ProductionCostCalculator` en mode plan ». Les deux passent par `ProductionPlanner::plan()`, donc la condition est que la BOM de groupe construise la même `PlanRequest` (cibles, bonus de structure, blacklist) que le calculateur.

## Décisions de Sylvain (2026-10-28)

- **D1.** Une spec, un lot.
- **D2.** La BOM exacte (plan du noyau, agrégation exacte, runs entiers) ne s'applique qu'aux **nouveaux** projets de groupe, ou à une régénération explicite. Les projets existants et leurs **contributions** gardent leurs quantités enregistrées. Aucune commande console de recalcul en production, aucune migration.

## Règles métier

- **R1 : feuilles par le plan du noyau.** Les lignes de matériau de la BOM sont les **feuilles** de `Plan::$leaves` pour les cibles du projet, en mode plan (R12 du noyau). Le service ne somme plus de quantités arrondies : il lit `Plan::$leaves` tel quel. Un matériau d'invention (datacores, R12 ci-dessous) s'y ajoute comme aujourd'hui (`GroupIndustryProjectService.php:340-350`).
- **R2 : runs entiers, runs ≠ quantité.** Chaque item du projet devient une cible (`PlanTarget`) avec ses **runs** (`Runs`, au moins 1), son ME et son TE. Un job de fabrication produit `runs × unités par run`. Les runs d'un intermédiaire valent `ceil(demande / unités par run)` (`ProductionPlanner.php:50`). Le **surplus** n'est pas enregistré dans la BOM.
- **R3 : lignes de job.** Une ligne de job par job du plan. Fabrication et réaction : `requiredQuantity = runs du job × unités par run` (spec #107, R1 et R2), `runs` = runs du job. Copie et invention : `requiredQuantity = runs` (#107, R4 ; #105).
- **R4 : groupe, ME, TE d'une ligne.** Les jobs des cibles sont les lignes `final` ; les jobs des intermédiaires sont les lignes `component`. Ligne `final` de fabrication : ME et TE de l'item (#23, R1). Ligne de réaction : ME 0, TE 0 (#23, R2 ; R2 du noyau). Ligne `component` de fabrication : ME 10, TE 20 (`ProductionPlanner::MANUFACTURED_INTERMEDIATE_ME` et `_TE`, `:15-16`), identiques aux constantes en dur d'aujourd'hui (`GroupIndustryProjectService.php:185-186`).
- **R5 : une ligne `final` par (produit, activité, ME, TE).** Comme #23 (R3) : deux items du même produit au même ME et TE partagent une ligne `final`, runs additionnés ; sinon une ligne chacun. Voir Q3 pour les matériaux de ces items.
- **R6 : item aussi consommé comme intermédiaire.** Il garde sa ligne `final` à côté de sa ligne `component` (#106). La demande de l'intermédiaire ne compte que la consommation des autres jobs (`tests/Unit/Industry/Domain/ProductionPlannerTest.php:274-291`). L'ordre des items ne change pas la BOM (`GroupIndustryBomGoldenCharacterizationTest.php:858-875`).
- **R7 : jobs découpés.** Un job dont les runs dépassent le plafond de son blueprint (`maxProductionLimit` du SDE, `Recipe::$maxProductionLimit`) est découpé en `ceil(runs / plafond)` jobs, runs répartis également, les premiers jobs prenant le reste (`ProductionPlanner.php:187-210`). Les **matériaux sont recalculés job par job** (R1 et R13 du noyau) : le total peut dépasser celui d'un job unique, et la demande des intermédiaires en aval suit. Chaque job donne une ligne (`requiredQuantity` = runs du job × unités par run). Les lignes de copie et d'invention ne sont pas découpées (comme aujourd'hui, `GroupIndustryProjectService.php:381-390`). Cette répartition remplace « jobs pleins puis reste » (`:418-426`). Plafond absent : erreur explicite (R10), plus de plafond par défaut 500 ou 10 000 (`:409-411`). Voir Q2.
- **R8 : stock.** Aucun stock de départ : la BOM de groupe est le besoin brut du projet, `PlanRequest::$startingStock` reste vide. R9 du noyau ne s'applique pas ; les contributions approuvées font avancer `fulfilledQuantity`, pas la BOM.
- **R9 : blacklist.** La **blacklist** du projet (groupes et types, résolus par `resolveBlacklistedTypeIds`, `GroupIndustryProjectService.php:445-462`) alimente `PlanRequest::$blacklist` : un matériau blacklisté est une feuille, sans sous-arbre. Un item du projet blacklisté est quand même fabriqué : la blacklist ne porte que sur les matériaux (`ProductionPlanner.php:118-125` ; `IndustryTreeService.php:164`).
- **R10 : erreurs explicites.** Item sans recette, cycle dans le graphe, blueprint absent de `sde_industry_blueprints` (`src/Industry/Infrastructure/Sde/SdeBlueprintCatalog.php:66-70`) : la création échoue avec un message qui nomme le type, aucune BOM n'est enregistrée. Un type absent du SDE reste une `TypeMissingFromSdeException` qui liste tous les types manquants (#109) : les noms de toutes les lignes viennent du SDE, car le plan ne porte que des typeId. Voir Q7 pour le code HTTP.
- **R11 : structures et bonus.** Les quantités suivent les structures de production du propriétaire (`UserProductionStructures::configuredBy`), choisies par `StructureSelector` sans système favori (`FavoriteSystems::none()`), avec le bonus du **produit du job** (R4 du noyau). Cela remplace le bonus recherché par catégorie de chaque matériau (`IndustryTreeService.php:143-156`, #6). Sans structure configurée, le multiplicateur vaut 1. Le TE n'a aucun effet sur les quantités.
- **R12 : copie, invention, T2.** Inchangés dans leur règle, alimentés par les runs entiers du plan. Ligne de copie : pour chaque produit fabriqué dont le blueprint a une activité de copie, `runs` = somme des runs des jobs de fabrication de ce produit (`GroupIndustryProjectService.php:213-231`). Invention d'un T2 : #105 (tentatives `ceil(runs / (runs de base × probabilité))`, `:306`) sur ces mêmes runs. L'arrondi des tentatives est un écart connu du glossaire (R8 du noyau), hors périmètre.
- **R13 : prix estimés.** Inchangés : `getCheapestPercentilePrices` sur les types des lignes de matériau (`GroupIndustryProjectService.php:119-121`).
- **R14 : parent d'une ligne de job.** `parentTypeId` d'une ligne `component` = produit du premier job du plan qui consomme ce type ; null pour une ligne `final`. Le champ ne sert qu'à l'affichage (`src/State/Provider/GroupIndustry/GroupBomProvider.php:110`, `frontend/src/stores/group-industry/types.ts:67`). Voir Q5.

## Exemples chiffrés (tests rouges)

SDE golden (`tests/Fixtures/Golden/sde-blueprint-recipes.json`), sans structure. Les colonnes « Aujourd'hui » viennent des tests de caractérisation cités ; « Attendu » vient de la spec du noyau quand elle le donne, sinon d'un calcul à la main sur les recettes de la fixture (R1 du noyau), à recalculer par le test-writer depuis le golden.

### E1 : produit à plusieurs unités par run (spec du noyau §4.1, fait foi)

Nitrogen Fuel Block, 25 runs, ME 10, TE 20 : 40 unités par run, plafond 200 (pas de découpage).

| Ligne | Aujourd'hui | Attendu |
|---|---|---|
| Job `final` : runs / `requiredQuantity` | 1 / 40 | 25 / 1 000 |
| Job `copy` : runs | 1 | 25 |
| Nitrogen Isotopes | 405 | 10 125 |
| Liquid Ozone | 315 | 7 875 |
| Heavy Water | 153 | 3 825 |
| Oxygen | 20 | 495 |
| Strontium Clathrates | 18 | 450 |
| Coolant | 9 | 203 |
| Enriched Uranium | 4 | 90 |
| Mechanical Parts | 4 | 90 |
| Robotics | 1 | 25 (`max(runs, …)`) |
| Lignes de la BOM | 11 (`:195`) | 11 (9 matériaux, 2 jobs) |

« Aujourd'hui » : `GroupIndustryBomGoldenCharacterizationTest.php:171-195`.

### E2 : un intermédiaire consommé par trois branches (agrégé, pas arrondi par branche)

Fernite Carbide (réaction, 10 000 unités par run), 1 run. Chaque run de Fernite Carbide, de Fernite Alloy et de Ceramic Powder consomme 5 Hydrogen Fuel Block ; Hydrogen Fuel Block produit 40 unités par run.

| Ligne | Aujourd'hui | Attendu |
|---|---|---|
| Hydrogen Fuel Block : demande | 5 + 5 + 5, arrondie par branche | 15, une fois |
| Job Hydrogen Fuel Block : runs / `requiredQuantity` | 3 / 120 | 1 / 40 |
| Job `copy` Hydrogen Fuel Block : runs | 3 | 1 |
| Hydrogen Isotopes (17889) | 1 215 | 405 |
| Heavy Water / Liquid Ozone / Oxygen | 459 / 945 / 60 | 153 / 315 / 20 |
| Coolant / Robotics / Strontium Clathrates | 27 / 3 / 54 | 9 / 1 / 18 |
| Enriched Uranium / Mechanical Parts | 12 / 12 | 4 / 4 |
| Jobs Fernite Carbide, Fernite Alloy, Ceramic Powder | 1 run chacun | inchangés |
| Feuilles des réactions (16639, 16642, 16635, 16636) | 100 chacune | inchangées |

« Aujourd'hui » : `GroupIndustryBomGoldenCharacterizationTest.php:412-427`. « Attendu » : calcul à la main (1 run de Hydrogen Fuel Block à ME 10 : `450 × 0,9 = 405`, etc., les mêmes valeurs que le 1 run de Nitrogen Fuel Block aujourd'hui) ; la spec du noyau §4.3 donne le même mécanisme à 10 runs avec structure (demande 99, 3 runs au lieu de 4).

### E3 : BOM complète, plusieurs niveaux (spec du noyau §4.4, fait foi)

Nomad, 1 run, ME 10, sans structure.

| Ligne | Aujourd'hui | Attendu |
|---|---|---|
| Oxygen Isotopes | +50 % (simulation de l'arbre historique, §4.4) | 17 820 (Oxygen Fuel Block : 44 runs × 405) |
| Lignes de matériau | | 63 (les feuilles du plan de référence) |
| Autres feuilles partagées (Hydrocarbons, Atmospheric Gases, Coolant…) | | [TO CONFIRM : à calculer par test-writer depuis le golden, avec le plafond de runs] |
| Nombre de lignes de job (découpage, copies compris) | | [TO CONFIRM : à calculer par test-writer depuis le golden, avec le plafond de runs] |

Le golden du noyau est calculé sans découpage (§4.4). La BOM de groupe plafonne les jobs (R7) : un intermédiaire dont les runs dépassent 200 est découpé et ses matériaux recalculés par job, ce qui peut déplacer de quelques unités les feuilles qu'il partage. Hydrogen Fuel Block est dans ce cas d'après la spec #121 (X4, 322 runs). Seul Oxygen Isotopes est sûr sans recalcul.

### E4 : job découpé, matériaux recalculés job par job (R7)

Nitrogen Fuel Block, 250 runs, ME 10, plafond 200.

| Ligne | Aujourd'hui | Attendu |
|---|---|---|
| Jobs `final` | non comparable (les runs sont lus comme une quantité) | 2 jobs de 125 runs, `requiredQuantity` 5 000 chacun |
| Coolant (base 9 par run) | non comparable | 2 026 (`ceil(125 × 8,1) = 1 013`, deux fois) ; un job unique de 250 runs donnerait 2 025 |
| Nitrogen Isotopes | non comparable | 101 250 (50 625 × 2, identique à un job unique) |

« Attendu » : calcul à la main sur la fixture, à recalculer depuis le golden.

### E5 : item aussi consommé comme intermédiaire (garde-fou, #106)

Hail L 1 run (ME 2, TE 4) et Fernite Carbide 1 run (ME 0, TE 0) : deux lignes Fernite Carbide, `final` (1 run, 10 000) et `component` (1 run, parent Hail L). Aujourd'hui Hydrogen Fuel Block y compte 6 runs et Hydrogen Isotopes 2 430 (`GroupIndustryBomGoldenCharacterizationTest.php:836-856`). Attendu : [TO CONFIRM : à calculer par test-writer depuis le golden].

## Cas limites

| Cas | Comportement attendu |
|---|---|
| Régénération explicite d'un projet existant | Aucun point d'entrée ne l'offre aujourd'hui : `buildBom` n'a pour appelant que `createProject` (`GroupIndustryProjectService.php:70`, vérifié par recherche dans `src/`). Reconstruire à la main ajoute une seconde BOM (#253, `tests/Integration/Service/GroupIndustry/GroupIndustryBomPersistenceTest.php:126`). Supprimer des lignes supprime aussi leurs contributions : `GroupIndustryContribution::$bomItem` est en `onDelete: 'CASCADE'` (`src/Entity/GroupIndustryContribution.php:35-37`) et `bomItems` en `orphanRemoval` (`src/Entity/GroupIndustryProject.php:81`). Proposition : hors de ce lot ; voir Q4. |
| Contribution déjà engagée sur une ligne dont la quantité requise baisse | `fulfilledQuantity` peut dépasser `requiredQuantity` : `isFulfilled` est vrai, le reste vaut 0, le pourcentage est plafonné à 100 (`src/Entity/GroupIndustryBomItem.php:216-233`). Rien ne s'efface. |
| Ligne qui disparaît (matériau devenu intermédiaire) alors qu'elle porte une contribution | Ne jamais la supprimer (voir Q4). |
| Item du projet sans recette, cycle | Erreur explicite (R10), aucune BOM enregistrée. |
| Produit à 1 unité par run (vaisseau) | Même résultat qu'aujourd'hui pour les runs ; seules les quantités de feuilles changent quand un intermédiaire est partagé. |
| Ordre des lignes | Non garanti, ni aujourd'hui (`GroupBomProvider.php:58`, sans tri) ni demain ; les tests trient. |
| Matériau d'invention déjà matériau d'un arbre | Quantités additionnées (#105, inchangé). |
| Probabilité d'invention absente | Erreur `InventionProbabilityMissingException`, aucune BOM (#105, inchangé). |

## Invariance : projets existants inchangés

- Aucune migration, aucune commande console : les lignes de BOM et les contributions déjà enregistrées gardent leurs quantités. La migration de #107 (`GroupIndustryJobQuantitiesInUnitsMigrationTest`) est la seule à avoir touché ces lignes ; elle n'est pas rejouée.
- Seul `createProject` (nouveau projet, via `CreateGroupProjectProcessor`, `src/State/Processor/GroupIndustry/CreateGroupProjectProcessor.php:77-79`) calcule une BOM : l'effet du lot est limité aux projets créés après le déploiement.
- Ne pas oublier au déploiement : il n'y a ni seed, ni fixture, ni commande à exécuter en production.
- Effet annoncé aux joueurs : un projet créé avant et un projet créé après, pour les mêmes items, n'ont pas la même BOM. Les quantités requises des anciens projets restent celles du jour de leur création, trop basses pour un produit à plusieurs unités par run (#2).

## Dépendances à câbler

- **Deptrac.** Aucun changement de règle : `Service` dépend déjà de `IndustryApplication` et de `IndustryDomain` (`deptrac.yaml:112`) ; `GroupIndustryProjectService` est dans `src/Service/` (`deptrac.yaml:35-37`). L'infrastructure du noyau n'est jamais importée par un dossier plat : elle est liée par le conteneur (`config/services.yaml:84-91`).
- **Injection.** `ProductionCostCalculator` est une classe autowirée (`config/services.yaml:17-23`, seul `Industry/Domain` est exclu) : l'injecter dans le constructeur de `GroupIndustryProjectService` ne demande aucun câblage. Mais `costOf()` n'est pas le bon point d'entrée pour la BOM :
  - il chiffre chaque feuille par `MarketPrices` (`ProductionCostCalculator.php:82`, `:262-274`) alors que la BOM prend ses prix de `getCheapestPercentilePrices` ;
  - un produit T2 exige des `InventionSettings`, sinon `DomainException` (`:177-178`), et remplace le ME/TE de la cible par ceux du BPC inventé (`:66-68`), alors que la ligne `final` d'un T2 porte le ME/TE de l'item (#23, R1 ; `GroupIndustryBomGoldenCharacterizationTest.php:246-257`) ;
  - `ProductionCost::$jobs` (`JobCost`) ne porte ni ME, ni TE, ni parent (`src/Industry/Application/JobCost.php`).
  Recommandation (Q1) : un cas d'usage de `IndustryApplication` qui construit la `PlanRequest` (sélecteur de structures, modificateurs, blacklist) et renvoie le `Plan`, partagé avec `ProductionCostCalculator` pour qu'il n'existe qu'une construction ; `GroupIndustryProjectService` l'injecte à la place de `IndustryTreeService`.
- **Structures du propriétaire.** Port `UserProductionStructures` (lié à `ConfiguredProductionStructures`, `config/services.yaml:90`) ; `RigCategories` pour la catégorie de rig de chaque produit.
- **Activité de copie.** `Recipe` ne porte ni blueprint ni activité de copie : la ligne de copie lit toujours le SDE (`IndustryTreeService.php:242-251`, aujourd'hui `hasCopy`). Voir Q6.
- **`IndustryTreeService`** reste pour ses autres appelants (factory, Stockpile, catalogue SDE) ; `GroupIndustryProjectService` ne l'utilise plus.

## Hors périmètre

- Les autres écrans de #141 (Profit Margins, Stockpile, liste d'achats, factory) et le reste du code mort du noyau.
- L'équivalence `MaterialQuantityFormula` / `MaterialQuantity` (autre test de l'issue).
- Prix des lignes (`getCheapestPercentilePrices`), contributions, distribution, ventes.
- Tentatives d'invention arrondies (R8 du noyau), décrypteurs et compétences du pilote (#105).
- Un point d'entrée de régénération de la BOM et la correction de #253.
- Une commande ou une migration de recalcul des projets existants (D2).
- Un stock de départ dans un projet de groupe.

## Tests attendus par couche

- **Domain** : aucun nouveau test. Le planificateur est déjà couvert (`GoldenPlanTest`, `ProductionPlannerTest`, dont les cas « demande agrégée sur deux branches » et « cible aussi consommée par une autre », `:274-306`).
- **Application** (`tests/Unit/Industry/Application/`) : si Q1 est retenue, le cas d'usage qui renvoie le `Plan`, avec les doubles `InMemory*` existants : structures du propriétaire, blacklist, cibles à plusieurs unités par run, plafond de runs. Et le test de l'issue : mêmes feuilles que `ProductionCostCalculator::costOf()` en `CostMode::Plan` pour la même entrée (E1, E2).
- **Service** (`tests/Unit/Service/GroupIndustry/`) :
  - `GroupIndustryBomGoldenCharacterizationTest` : les tests nommés `Currently…Issue2` et `Currently…Issue69` (`:165`, `:393`, `:406`, `:432`, `:527`, `:544`, `:642`, `:663`, `:836`) deviennent des tests de comportement (E1, E2, E5, runs de Hail L à 10 et 35 000 runs, tentatives d'invention sur les runs réels) : le test rouge les réécrit, ils perdent `Currently` et le groupe `issue-2` ou `issue-69`. Les tests #23, #105, #106 gardent leur intention ; seuls leurs chiffres de matériaux changent quand un intermédiaire est partagé.
  - `GroupIndustryProjectServiceTest` : il mocke `buildProductionTree` dans 27 tests (arbres fabriqués à la main). Il est réécrit sur un catalogue de recettes en mémoire (ou un double du cas d'usage), pas un mock d'arbre. À conserver : prix, blacklist, découpage et ses limites (E4), copie non découpée, projet sans matériau sans appel de prix.
  - Nouveau : l'erreur explicite d'un blueprint absent (plus de plafond par défaut, R7 et R10).
- **Intégration** (`tests/Integration/Service/GroupIndustry/GroupIndustryBomPersistenceTest.php`) : le test `…PersistsTheBomOfOneRun…Issue2` (`:95`) devient E1 persisté. Le test #253 (`:126`) reste tel quel : il part de 1 run à ME 0, dont les quantités ne changent pas, et #253 reste ouvert. Nouveau : invariance, un projet aux lignes et contributions déjà enregistrées garde ses quantités quand un autre projet est créé (D2).
- **Architecture** : `make deptrac` sans nouvelle violation ni entrée dans `skip_violations`.
- Ordre de passage : les tests rouges d'abord (validation humaine), puis l'implémentation ; `tests/` est verrouillé pendant le vert (ADR-0004).

## Termes du glossaire

Utilisés tels quels : **Run**, **Quantité**, **Unités par run**, **BOM**, **Ligne de BOM**, **Plan**, **Feuille**, **Intermédiaire**, **Demande**, **Surplus**, **Job**, **Groupe découpé**, **Blacklist**, **Contribution**, **Structure de production**, **Bonus de structure**, **ME**, **TE**.

Proposés, non ajoutés (en attente de validation) :

- **Job final / job de composant** (Final job / component job) : valeurs `final` et `component` de `GroupIndustryBomItem::$jobGroup` (la troisième, `blueprint`, porte la copie et l'invention). Le job final est celui d'un item du projet, au ME et au TE de l'item ; le job de composant est celui d'un intermédiaire, au ME 10 et TE 20 par défaut (réaction : 0 et 0). Symbole : `GroupIndustryBomItem::$jobGroup`.
- **Régénération de la BOM** (BOM regeneration) : recalcul de la BOM d'un projet de groupe existant, par une action explicite. N'existe pas encore (Q4). Ne pas l'ajouter tant qu'aucun symbole ne la porte.

Mise à jour à prévoir quand le lot sera livré : le glossaire (entrée **BOM**, ambiguïté B1) et `docs/modules/industry.md` (lignes `IndustryTreeService`, « Group Industry », « Pas encore migrés ») disent encore que `GroupIndustryProjectService` passe par l'arbre.

## Questions ouvertes

1. **Q1 : point d'entrée du noyau.** Injecter `ProductionCostCalculator` tel quel (décision de départ) ou un nouveau cas d'usage qui renvoie le `Plan` ? Recommandation : le cas d'usage, car `costOf()` chiffre inutilement les feuilles, échoue sur un T2 sans réglages d'invention et ne rend ni ME/TE ni parent ; l'équivalence avec le calculateur reste testée.
2. **Q2 : plafonds de runs.** Le plafond est une entrée par produit (`PlanRequest::$blueprintCopyMaxRuns`) que l'appelant doit fournir avant le calcul, alors qu'il ne connaît pas les intermédiaires ; aucun appelant de production ne le fait aujourd'hui. Et le plafond s'applique-t-il aux réactions (la spec #234 non, Group Industry oui aujourd'hui) ? Recommandation : deux passes partageant la même `RecipeMemory` (la première liste les produits du plan, la seconde reçoit les plafonds `Recipe::$maxProductionLimit`), plafond sur fabrication et réaction comme Group Industry aujourd'hui, erreur explicite si le blueprint manque.
3. **Q3 : items du même produit au même ME et TE.** Une cible par item (matériaux arrondis item par item, ligne `final` fusionnée) ou une seule cible aux runs additionnés (un job, comme la ligne fusionnée) ? Recommandation : une cible par (produit, ME, TE) aux runs additionnés, pour que la ligne `final` et ses matériaux décrivent le même job.
4. **Q4 : régénération explicite d'un projet existant.** Que deviennent les contributions déjà engagées ? Recommandation : pas de régénération dans ce lot ; quand elle viendra (avec #253), mise à jour sur place des lignes par clé (type, job, groupe, activité, ME, TE, index de découpage), jamais de suppression d'une ligne qui porte une contribution, et refus pour un projet terminé.
5. **Q5 : `parentTypeId` et système favori.** Premier consommateur dans l'ordre de `Plan::jobs` (aujourd'hui : premier dans le parcours de l'arbre), et `FavoriteSystems::none()` (la sélection actuelle ignore le favori, `IndustryBonusService::findBestStructureForProduct`) ? Recommandation : oui aux deux, pour ne rien changer de visible hors des quantités.
6. **Q6 : activité de copie.** La lire dans le SDE depuis le service (blueprint du produit puis activité de copie) ou ajouter blueprint et activité de copie à `Recipe` ? Recommandation : la lecture dans le service, pour ne pas toucher le Domain dans ce lot.
7. **Q7 : code d'erreur.** Le planificateur lève `\DomainException` (item sans recette, cycle : `ProductionPlanner.php:115`, `:154`), que `CreateGroupProjectProcessor` ne rattrape pas (`:79` : `InvalidArgumentException` et `RuntimeException`) : 500 au lieu du 400 d'aujourd'hui (`IndustryTreeService.php:82-84`). Recommandation : le service convertit en `RuntimeException` à message explicite, sans changer le processor.
