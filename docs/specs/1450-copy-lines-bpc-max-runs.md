# Spec #1450 — Une ligne de copie ne dépasse jamais le plafond de runs d'un BPC

## Contexte

Retour joueur sur un projet de groupe : croiseur T1 au **plafond de runs d'un BPC** de 10 (`max_production_limit` du SDE : 10 pour ce blueprint), produit fabriqué 28 runs et inventé en T2. Le projet montre une ligne « Copie » de 28 runs et une ligne « Copie (copies T1 pour l'invention) » de 77 runs. Chaque ligne n'a qu'un champ « Lancé » et une réservation de membre couvre tous ses runs : l'étape ne se lance pas en jeu (une copie porte au plus 10 runs).

### Pistes vérifiées sur le code (origin/main, 124fca9cc)

| Piste de l'issue | Verdict |
|---|---|
| `IndustryProjectService::copyLinesOf()` additionne les runs des jobs de fabrication du produit en une seule ligne de copie, sans plafond | **Vraie.** Clé `<typeId>:copy`, `runs += runs du job`. Voulu par #141 (« copie non découpée », `testBuildBomCopyJobsAreNotSplit`) et écrit au glossaire. 28 runs donnent une ligne de 28. |
| La branche invention (`:copy:invention`, `replaceT2BlueprintCopiesByInventions()`) additionne les tentatives en une ligne sans plafond | **Vraie.** 77 tentatives donnent une ligne de 77. En jeu, une tentative consomme un run du BPC T1, pas le BPC entier : 77 tentatives demandent 77 runs, donc 8 copies de 10 runs au plus. |
| `ProductionPlanner::splitIntoJobs()` découpe par `blueprintCopyMaxRuns` | **Vraie**, et la fabrication est bien plafonnée : `ProductionPlanning::planWithStructuresOf()` plafonne tout job du plan (cible et intermédiaire) à `Recipe::$maxProductionLimit`, sauf blueprint possédé utilisé original. 28 runs sans BPO donnent 3 jobs 10/9/9, donc 3 lignes. |
| Le plafond est sauté quand `ownedBlueprints[produit]->original` | **Vraie mais voulue** (#411 R4, #453) : un BPO n'a pas de plafond. Une ligne de fabrication de 28 runs non découpée signifie que le propriétaire voyait un BPO du produit à la construction. L'attente « 3 jobs 10/9/9 » de l'issue est fausse dans ce cas. |
| `copyLinesOf()` lit « original possédé » d'une source différente du planner (`typeIdsOwnedBy` contre `bestUsesOwnedBy`) | **Fausse en pratique.** Les deux lisent `OriginalBlueprintFinder::visibleBlueprintsOf($owner)` du même propriétaire dans le même `buildBom()`. Si le meilleur blueprint possédé est un BPO, un original est visible, donc aucune ligne de copie. Une fabrication non plafonnée et une ligne de copie du même produit ne sortent jamais d'une même construction. |

Le trio du retour (fabrication 28 non découpée et copie 28) ne se reproduit donc pas sur une construction neuve. Hypothèse non vérifiée [à confirmer sur les données du projet] : lignes de constructions successives. Par exemple, une ligne de copie qui porte un apport est gardée par `reconcileBomLines()` après que le BPO est devenu visible. Elle sort de cette spec (voir Hors périmètre).

`git log -S` : la découpe vient de d8f1e7e6b (#141), le saut du plafond d'un BPO de aa95e3ec9 (#453), le plafond des lignes fusionnées sous un BPC de 08ae0a920 (#1170). Aucun correctif n'a jamais visé les lignes de copie.

## Règles

- **R1. Capacité d'une copie.** Le plafond d'une ligne de copie est le **plafond de runs d'un BPC** du blueprint copié : `Recipe::$maxProductionLimit` (SDE), la même source que le planner. Pour une copie T1 d'invention, c'est le blueprint T1. Aucune ligne de copie ne porte plus de runs que ce plafond.
- **R2. Copies de fabrication.** Pour un produit fabriqué dont le blueprint a l'activité de copie et dont aucun original n'est possédé, les jobs de fabrication du plan sont rangés dans des copies. Les jobs sont pris du plus grand au plus petit, chacun dans la première copie où il tient (somme ≤ plafond), sinon dans une nouvelle copie (*first fit decreasing*). Une ligne de copie par copie, `runs` = somme des runs de ses jobs. Tout job tient ainsi dans une copie. Quand le total tient dans le plafond, il reste une seule ligne, comme aujourd'hui.
- **R3. Copies T1 d'invention.** Le total des tentatives par blueprint T1 se calcule comme aujourd'hui (somme sur les produits T2 qui partagent ce T1, après les BPC T2 déclarés). Il est ensuite découpé comme un job (D2 du noyau) : `ceil(tentatives / plafond)` lignes, runs répartis également, les premières lignes prenant le reste.
- **R4. Ligne de copie.** Chaque ligne garde les champs d'aujourd'hui : `requiredQuantity = runs`, ME 0, TE 0, groupe `blueprint`, structure de copie du plan. `parentTypeId` est celui du premier job rangé dans la copie (R2), ou celui de la ligne d'invention (R3).
- **R5. Original possédé (inchangé, #357 et #411).** Si un BPO du produit est visible du propriétaire du projet, la fabrication n'est pas plafonnée et le produit n'a aucune ligne de copie. La source reste unique : `OriginalBlueprintFinder::visibleBlueprintsOf($owner)`, lue par le planner (`bestUsesOwnedBy`) et par les lignes de copie (`typeIdsOwnedBy`). Un test de garde fixe la paire « fabrication non plafonnée, aucune copie ».
- **R6. Plafond inconnu.** Un plafond absent ou ≤ 0 (blueprint ou T1 hors SDE) lève une erreur explicite, celle de `SdeBlueprintCatalog::recipeFor()` ou une `\UnexpectedValueException` qui nomme le blueprint. Jamais de plafond par défaut, et aucune BOM partielle n'est enregistrée.
- **R7. Ce qui ne change pas.** La ligne d'invention (runs = tentatives), les datacores, les décrypteurs, la fabrication, les réactions (pas de copie), la durée prévue de copie par ligne (`runs × temps de base`).

## Exemples chiffrés (tests rouges)

| # | Entrée | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Produit plafond 10, une ligne de 28 runs, aucun blueprint possédé | fabrication 10/9/9, copie [28] | fabrication 10/9/9, copies [10, 9, 9] |
| E2 | Même produit final 8 runs et intermédiaire 20 runs, plafond 10 | fabrication 8 + 10/10, copie [28] | copies [10, 10, 8] |
| E3 | Quatre lignes du produit à ME différents : 3, 3, 3 et 10 runs, plafond 10 | copie [19] | copies [10, 9] (3+3+3 dans une copie) |
| E4 | 20 runs, plafond 5 (`testBuildBomCopyJobsAreNotSplit`) | copie [20], fabrication 5/5/5/5 | copies [5, 5, 5, 5] |
| E5 | Fuel block 250 runs, 40 unités par run, plafond 200 (golden) | copie [250], fabrication 125/125 | copies [125, 125], `requiredQuantity` 125 chacune |
| E6 | Deux lignes de 1 run, ME différents (golden #23) | copie [2] | inchangé : [2] |
| E7 | T2 inventé, 77 tentatives, plafond du T1 10 | copie T1 [77], invention [77] | copies T1 [10, 10, 10, 10, 10, 9, 9, 9], invention [77] |
| E8 | Hail L, 59 tentatives, plafond du T1 ≥ 59 | copie T1 [59] | inchangé : [59] |
| E9 | Deux T2 du même T1 : 12 + 9 tentatives, plafond du T1 10 | copie T1 [21] | copies T1 [7, 7, 7] |
| E10 | 28 runs, BPO ME 10 TE 20 visible du propriétaire | fabrication [28], aucune copie | inchangé (garde de R5) |
| E11 | 28 runs, seul un BPC ME 10 TE 20 visible | fabrication 10/9/9, copie [28] | fabrication 10/9/9, copies [10, 9, 9] |
| E12 | Plafond du T1 absent du catalogue | copie T1 construite | erreur explicite, aucune ligne enregistrée |

## Cas limites

- **Plusieurs unités par run** : le plafond se compte en runs, jamais en unités (E5).
- **Arrondi** : R3 répartit le reste sur les premières lignes. R2 ne découpe aucun job, il range des jobs entiers.
- **Plafond 1** : une ligne de copie par run ou par tentative. Au-delà de `MAX_BOM_LINES`, l'erreur explicite existante s'applique.
- **Projet existant** : les lignes changent à la prochaine construction de la BOM (pas de migration, comme #141 D2). Une ligne de copie déjà marquée ou liée au-delà des runs replanifiés de son rang fait échouer la reconstruction avec l'erreur existante de `assertNoLineIsCutBelowItsProgress()`, sans écrêtage silencieux.
- **Invention exclue ou BPC T2 déclarés couvrant tout** : aucune copie T1, comme aujourd'hui.

## Accès et fraîcheur

- « Original possédé » est lu sur le **propriétaire du projet** seul : ses personnages, plus la corporation de son personnage principal selon `CorpAssetVisibility`. Un BPO d'un membre ne retire pas la ligne de copie (défaut connu de #357, hors périmètre). La **bibliothèque de blueprints** se synchronise toutes les heures. `Character.corporationId` ne se rafraîchit qu'à la connexion (préexistant).
- Aucun identifiant reçu d'un autre périmètre, aucune ressource API ni migration nouvelle.

## Tests existants contredits ou modifiés

- `tests/Unit/Service/Industry/Project/IndustryProjectServiceTest.php::testBuildBomCopyJobsAreNotSplit` (#141) : la copie [20] devient [5, 5, 5, 5] (E4). Le test change de nom (`…CopyLinesAreSplitAtTheBlueprintCopyMaxRuns`).
- `tests/Unit/Service/Industry/Project/GroupIndustryBomGoldenCharacterizationTest.php::testTwoHundredFiftyRunsOfNitrogenFuelBlock…` : la copie [250] devient deux lignes de 125 (E5).
- Tests d'invention de `IndustryProjectServiceTest` (Hail L, Prototype Ammunition L, `testT1CopySharedByTwoInventedProductsKeeps…`) : le harnais doit donner une recette, donc un plafond, au produit T1. Changement d'arrangement seulement, les assertions restent (plafond par défaut 300 > tentatives).
- Constructeur de `IndustryProjectService` (si le catalogue y est injecté, D2) : sept constructions de test à adapter (`grep -rl "new IndustryProjectService(" tests`), dont `BuildsProjectServiceOnGoldenSde` et `IndustryProjectServiceOwnedBlueprintTest`.
- Les autres assertions de copie (golden #23 à [2], `IntermediateStockBomTest`, `GroupIndustryBomPersistenceTest`, OwnedBlueprint 10 et 4 runs sous un plafond de 300) restent vraies.

## Hors périmètre

- Découpe de la **ligne d'invention** en jobs d'au plus un BPC T1 (77 tentatives sur 8 copies demandent 8 jobs). Issue de suivi à ouvrir.
- Unité du rapprochement ESI d'une copie : les `runs` ESI d'un job de copie comptent les copies, ceux de la ligne comptent les runs (`IndustryJobMatcher`, préexistant). Issue de suivi à ouvrir.
- Ligne de copie périmée gardée par `reconcileBomLines()` parce qu'elle porte un apport (hypothèse du contexte) : issue à ouvrir si les données la confirment.
- Champ « copies × runs par copie » sur une seule ligne (migration, API, front).
- BPO d'un membre et BPC possédé déjà assez long : pas de retrait de la copie.

## Décisions (mode nuit, recommandation retenue)

- **D1. Une ligne par copie** plutôt qu'une ligne « N copies × R runs ». Ce modèle reprend celui de la fabrication (une ligne par BPC) avec le champ « Lancé » et la réservation par ligne, sans migration ni changement d'API ni de durée prévue.
- **D2. Source du plafond** : `Recipe::$maxProductionLimit` par le `BlueprintCatalog`, celui du planner, avec la même erreur explicite. Pas de clé ajoutée à `InventionService::getInventionData()`.
- **D3. Rangement *first fit decreasing*** (R2) plutôt qu'une ligne par job : les tests à une seule copie restent verts (E6) et aucune copie ne gâche de runs (E3).
- **D4. Piste « deux sources »** : fausse, aucun changement de code. Le test de garde E10 fixe l'invariant.

## Glossaire

« Plafond de runs d'un BPC » : la BOM d'un projet ne porte plus une seule ligne de copie par produit. Les jobs de fabrication sont rangés dans des copies d'au plus le plafond, et les tentatives d'une copie T1 d'invention sont découpées au plafond du T1 (#1450).
