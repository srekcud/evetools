# Spec #286 — Un seul bonus de matériaux de structure pour une étape : celui du job consommateur

**Statut : rédigée le 2026-10-29 sans entretien. Règle tranchée par les specs déjà validées** (spec du noyau R4 et §4.3, décision D3 du 2026-10-07 ; golden `fernite-carbide-10runs-tatara-nullsec` confirmé par l'API EVE Ref, D12 ; spec #121 arbre, R3 ; spec #2-129, E5). Aucune question bloquante avant la phase rouge. Les choix faits en autonomie sont dans « Décisions prises en autonomie ».

## Contexte

Pour une même étape, la génération des étapes et l'arbre affiché n'appliquent pas le même bonus de matériaux de structure. Cas de l'issue : Fernite Carbide, 10 runs, Tatara (rig Standup L-Set Reactor Efficiency II, nullsec) et Raitaru sans rig. La génération planifie 100 Hydrogen Fuel Block ; l'arbre affiche 49 sur la ligne de la racine, comme le golden.

État vérifié dans le code au 2026-10-29 :

- **Génération (création et régénération).** `IndustryProjectFactory::planStepsJobByJob()` calcule le **besoin** de chaque intermédiaire par `addNeedOfJobs()`, qui prend le bonus de `IndustryProjectFactory::materialBonusOf()` :
  - consommateur avec structure assignée : `IndustryCalculationService::assignedStructureBonusOf()` sur le produit du consommateur (déjà la règle cible) ;
  - matériau produit par réaction : `reactionMaterialBonusOf()`, meilleure structure pour la catégorie du consommateur, **aucun bonus** si le consommateur n'a pas de catégorie de rig ;
  - matériau fabriqué : `IndustryBonusService::findBestStructureForProduct()` du consommateur, mais si la **catégorie du matériau** diffère de celle du consommateur, `findBestStructureForCategory(catégorie du matériau)`. C'est la règle « par catégorie du matériau » de #6. Pour Hydrogen Fuel Block (`ammunition`) consommé par une réaction (`composite_reaction`), elle prend le Raitaru (1 %) au lieu du rig de la Tatara (2,4 % × 1,1 = 2,64 %).
  - le système favori n'est jamais lu.
- **Arbre affiché, cascade, liste d'achats.** `ProductionTreeFromSteps` et `IndustryShoppingListBuilder` passent par `ProductionStepNeedsResolver::resolve()`, la cascade par `IndustryStepCalculator::needOfConsumer()`, le découpage manuel par `SplitGroupMaterialNeeds::applyChangeOfJobs()`. Tous prennent `IndustryCalculationService::getStructureBonusForStep($consommateur)`, donc `structureBonusOf()` : structure assignée, sinon meilleure structure du système favori, sinon `findBestStructureForProduct()` du **produit du consommateur**. La catégorie du matériau n'y entre pas.
- **Arbre de la factory.** `IndustryTreeService::buildNode()` porte une seconde copie de la règle « par catégorie du matériau » (branche `materialCategory !== productCategory`). Ses quantités de matériaux ne survivent pas : `planStepsJobByJob()` remplace la quantité de tout intermédiaire par son besoin, et la racine vaut `runs × unités par run` (#2). Supprimer la branche ne change aucun test (mesuré, voir « Tests existants appelés à casser »).
- **Conséquence visible.** Une étape générée porte un besoin calculé avec une règle, et l'arbre, la liste d'achats et la cascade le relisent avec l'autre. Sur l'exemple de l'issue, le nœud Hydrogen Fuel Block de l'arbre affiche 100 (besoin persisté) alors que ses lignes de consommation font 49 + 25 + 25 = 99. Dès qu'une modification d'étape ou une structure corrigée par un job lancé déclenche la cascade (`UpdateStepProcessor`, `IndustryJobMatcher::persistAndRecalculate()`), le besoin persisté passe à 99 sans que rien d'autre ait changé.

Ce que les sources déjà validées tranchent :

| Source | Ce qu'elle dit |
|---|---|
| Spec du noyau, R4 (statut validée, D3) | « Le bonus dépend du produit du job, jamais de la catégorie du matériau consommé (#6) » ; choix de la structure : assignée, sinon meilleure pour (activité, produit du job) |
| Spec du noyau, §4.3 | « Hydrogen Fuel Block est consommé à deux profondeurs : 49 (racine) + 25 (Ceramic Powder) + 25 (Fernite Alloy) = 99 » |
| Golden `fernite-carbide-10runs-tatara-nullsec.json` | entrées de la racine : Hydrogen Fuel Block 49 ; intermédiaire Hydrogen Fuel Block : demande 99, 3 runs. Confirmé par l'API EVE Ref le 2026-10-07 (D12, 4 jobs, 18/18 matériaux) |
| Spec #121 (arbre affiché), R3 | quantité d'une ligne = R1 du noyau avec le « bonus de la structure de l'étape » |
| Spec #141, R11 | BOM de groupe : bonus du **produit du job**, « remplace le bonus recherché par catégorie de chaque matériau (#6) » |
| Spec #2-129, E5 | attendu pour les étapes : Hydrogen Fuel Block 99 (49 + 25 + 25) ; le test écrit fige 100, d'où l'issue |
| Test d'intention existant | `ProjectProviderGoldenTreeCharacterizationTest::testFuelBlockConsumedByTenRunsOfReactionInATataraGetsTheReactorRigOfTheReactionJob` (#121) |

Le golden est donc juste et ne change pas : c'est la génération des étapes qui s'aligne. Règle du jeu sous-jacente (le bonus d'un job vient de la structure où il est installé et de ses rigs qui ciblent le produit du job) : non vérifiable ici, mais déjà validée par D3 et recoupée par EVE Ref (D12). Rien ne reste à confirmer en jeu pour ce lot.

## Règles métier

- **R1 — Bonus du job consommateur.** Les quantités de **tous** les matériaux d'un job sont réduites par le bonus de structure et de rig de la structure de ce job, pour la catégorie de rig du **produit du job** (fabrication ou réaction selon l'activité du job). La catégorie du matériau consommé ne compte jamais. Un produit sans catégorie de rig ne reçoit que le bonus de rôle de la structure (Engineering Complex : 1 % en fabrication), comme `ProductionStructure::materialMultiplierFor()` du noyau.
- **R2 — Structure du job.** Structure assignée à l'étape, sinon celle que retient `IndustryCalculationService::structureBonusOf()` pour (utilisateur, produit du job, activité du job) : meilleure du système favori de l'activité, sinon meilleure de toutes. C'est la structure que l'arbre, la liste d'achats et la cascade utilisent déjà (`getStructureBonusForStep()`), et la « structure effective » de la spec #133 pour le coût d'installation.
- **R3 — Une seule implémentation.** La génération calcule le besoin d'un matériau avec `IndustryCalculationService::structureBonusOf($user, produit du consommateur, activité du consommateur, structure du consommateur)['materialBonus']`. `IndustryProjectFactory::materialBonusOf()`, `reactionMaterialBonusOf()`, la constante `NO_MATERIAL_BONUS` et la dépendance de la factory à `IndustryBonusService` disparaissent. La branche « catégorie du matériau » de `IndustryTreeService::buildNode()` disparaît aussi (le nœud garde son propre bonus).
- **R4 — Formule inchangée.** `MaterialQuantityFormula::forJob` / `calculateMaterialQuantity()` (R1 du noyau, arrondi par job) ne changent pas ; seuls les bonus qu'on leur passe changent. Agrégation (#69), découpage (#73, #234), copie (#235), runs de la racine (#2) : inchangés.
- **R5 — Projets existants.** Rien n'est migré. Le besoin persisté d'un intermédiaire passe à la règle cible à la prochaine **régénération des étapes** (changement de runs, de ME, de TE ou de durée maximale par `UpdateProjectProcessor`, ou `app:industry:regenerate-steps <project-id>`) ou à la prochaine cascade, qui l'applique déjà.

## Exemples chiffrés (tests rouges)

« Mesuré » = valeur obtenue sur un prototype jetable de R3 (copie de `HEAD` hors du worktree, suites Unit et Integration). « Golden » = `tests/Fixtures/Golden/`. « Dérivé » = calculé à la main depuis R1 et R2, à recouper par le test rouge. Colonnes des jobs : `[runs, quantity, …]` comme dans les tests cités.

### Harnais SDE golden, pilote avec Tatara (rig de réaction 2,64 %) et Raitaru sans rig (1 %), sans système favori

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Fernite Carbide 10 runs (E5 de #2-129) : étape Hydrogen Fuel Block | `[[3, 100]]` (50 + 25 + 25 au Raitaru) | `[[3, 99]]` : 49 (racine, `ceil(50 × 0,9736)`) + 25 + 25 (golden). Ceramic Powder et Fernite Alloy `[[5, 974]]` inchangés |
| E2 | Arbre affiché du projet E1 | lignes de la racine 4246 → 49, 16656 → 974, 16660 → 974 ; nœud Hydrogen Fuel Block 100 | lignes inchangées ; nœud Hydrogen Fuel Block 99 = somme de ses lignes de consommation (dérivé : la quantité d'un nœud est le besoin persisté, `ProductionStepNeedsResolver::resolve()`) |
| E3 | Liste d'achats du projet E1 | feuilles de 3 runs d'Hydrogen Fuel Block | inchangée (toujours 3 runs ; feuilles du golden) |
| E4 | Nomad 1 run, durée max 365 j : Fernite Carbide, consommé par des composants capitaux sans catégorie de rig dans le harnais | `[[963, 9_622_187]]` (aucun bonus) | `[[953, 9_525_965]]` (bonus de rôle du Raitaru, R1) (mesuré) |
| E5 | Même projet : Fernite Alloy | `[[531, 106_026]]` | `[[525, 104_955]]` (mesuré) |
| E6 | Scorpion 10 runs, ME 10, TE 20 | Oxygen Fuel Block `[7, 253]`, Hydrogen Fuel Block `[6, 218]`, Pressurized Oxidizers `[23, 4500]`, Reinforced Carbon Fiber `[[23, 4600], [22, 4400]]` | `[7, 251]`, `[6, 216]`, `[23, 4455]`, `[[23, 4600], [22, 4314]]` ; Oxy-Organic Solvents `[7, 68]` et Auto-Integrity Preservation Seal inchangés (mesuré) |
| E7 | Titanium Chromide 20 runs découpé en 20 jobs d'un run, dans une Tatara | Oxygen Fuel Block `[[3, 100, …]]` au Raitaru | inchangé : par job, `ceil(5 × 0,99)` = `ceil(5 × 0,9736)` = 5 (mesuré) |
| E8 | Fernite Carbide 100 000 runs, régénération de l'ancien calcul (`MultiUnitProjectStepsRegeneration`) | `steps 3568 -> 7139` | `steps 3567 -> 7138` : un job d'Hydrogen Fuel Block de moins avant comme après (mesuré) |
| E9 | Aperçu de régénération de l'ancien calcul de Fernite Carbide 10 runs | Hydrogen Fuel Block avant et après `[3, 100, 10, 20]` | `[3, 99, 10, 20]` des deux côtés (mesuré) |

### SDE synthétique de `IndustryProjectFactoryRegenerationTest` (destroyer sans catégorie de rig, hull component `advanced_component`, sous-composant sans catégorie ; Raitaru avec rig ME de composants avancés)

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E10 | Destroyer 37 runs, Raitaru nullsec, rien d'assigné : hull component à la création | 95 (rig du Raitaru via la catégorie du hull) | 99 (bonus de rôle seul, comme après assignation du Raitaru à la racine) (mesuré) |
| E11 | Destroyer 5 runs, Raitaru highsec et nullsec, hull assigné au Raitaru highsec, régénération | hull 13, sous-composant 80 | hull 14, sous-composant 86 (mesuré) |
| E12 | Destroyer 5 runs, durée qui découpe le hull | hull `[7, 6]` à la création ; après passage à 6 runs `[8, 8]`, sous-composant 99 | `[7, 7]` ; `[9, 8]`, sous-composant 106 (mesuré) |
| E13 | R2, système favori : destroyer 5 runs, ME 10, favori de fabrication avec un Raitaru sans rig, Raitaru nullsec rigué ailleurs, rien d'assigné | hull 13, sous-composant 78 (meilleure structure globale, favori ignoré) | hull 14, sous-composant 88, égaux à ceux de la cascade et de l'arbre (dérivé) |

### Non-régression

| # | Cas | Attendu |
|---|---|---|
| N1 | Projets sans structure configurée (Nitrogen Fuel Block, Nomad sans structure, Hail L…) | étapes identiques |
| N2 | Consommateur avec structure assignée | identique (déjà `assignedStructureBonusOf()`) |
| N3 | Arbre affiché, liste d'achats, cascade | aucun changement de code ; leurs tests restent verts (mesuré) |
| N4 | Noyau, Stockpile, BOM de groupe, Profit Margins, Buy vs Build | inchangés (déjà R4 du noyau) |

## Cas limites

- **Produit du consommateur sans catégorie de rig** : bonus de rôle seul de la structure retenue (E4, E10). Une raffinerie n'a pas de bonus de rôle matériaux : 0 pour une réaction sans catégorie.
- **Aucune structure configurée** : bonus nul, inchangé (N1).
- **Système favori dont la structure n'a pas de rig adapté** : la génération suit désormais `findBestInFavoriteSystem()` comme l'affichage (E13). L'écart de cette fonction avec le filtre souple du noyau (D3b, spec #151, ambiguïté « Système favori sans contrôle du type de structure » du glossaire) est hors lot.
- **Structure assignée inapte à l'activité** : `assignedStructureBonusOf()` ne la refuse pas (le noyau l'ignore, décision du 2026-10-26) ; inchangé, et désormais identique entre génération et affichage.
- **Matériau blacklisté ou exclu** : pas d'étape, donc pas de besoin calculé ; inchangé.
- **Étapes de copie** : pas de matériaux ; inchangé.
- **Job à 1 run** : l'arrondi par job peut masquer l'écart (E7).
- **Projets existants** : R5. L'écart sur un besoin persisté est de l'ordre de l'arrondi (E1 : 1 unité) à quelques pourcents (E4, E6) ; les runs ne changent que si le besoin franchit un multiple des unités par run.

## Décisions prises en autonomie

1. **Le golden ne bouge pas, le planificateur s'aligne.** L'issue demandait de réécrire « le test E5 et le golden concerné » : le golden porte déjà la règle validée (R4, §4.3, D12) ; seul le test E5 change.
2. **Réutiliser `IndustryCalculationService::structureBonusOf()`** plutôt que porter la factory sur le noyau (`StructureMaterialModifiers`) : c'est la fonction déjà utilisée par l'arbre, la liste d'achats, la cascade et le coût d'installation (#133), déjà injectée dans la factory. La migration de la factory sur le noyau reste le rang 7 de la spec du noyau.
3. **R2 inclut le système favori.** Une règle unique impose la même structure que l'affichage ; l'exclure garderait une seconde règle (E13).
4. **Supprimer la branche « catégorie du matériau » de `IndustryTreeService::buildNode()`** dans ce lot : sans effet mesuré sur les étapes, elle porte la règle contraire à R4 et serait relue comme vraie.
5. **Aucune migration ni commande** (R5) : la cascade applique déjà la règle cible, l'écart est faible, et une régénération massive changerait des étapes de projets en cours. Si Sylvain veut corriger les projets actifs, la commande `app:industry:regenerate-multi-unit-steps` ne convient pas (elle cible la signature de #2) ; il faudrait une issue dédiée.

## Hors périmètre

- `IndustryShoppingListBuilder::bestMaterialBonusOf()` (surcoût d'une structure sous-optimale, `extraQuantity`) : mesure un vrai écart à la meilleure structure, inchangé.
- Paramètre `$structureBonusOverrides` de `buildProductionTree()` : aucun appelant ne le passe ; nettoyage à part.
- `findBestInFavoriteSystem()` contre le filtre souple du noyau (D3b) ; structure assignée inapte.
- Statut « provisoire » du golden Fernite Carbide dans `tests/Fixtures/Golden/README.md`, alors que D12 le dit confirmé par EVE Ref : à aligner dans la documentation, sans effet sur les tests.
- Issue #6 : ce lot lève ses constats sur l'arbre de la factory et sur la factory ; sa fermeture est à décider avec #286.

## Tests existants appelés à casser

Mesuré sur le prototype de R3 : 13 848 tests unitaires, 6 en échec ; suite Integration, 3 nouveaux échecs (les 22 autres échecs du prototype, présents aussi sur `HEAD`, tiennent à l'environnement jetable). Aucun golden ni snapshot ne change.

| Test | Changement |
|---|---|
| `IndustryProjectFactoryGoldenStepsCharacterizationTest::testStepsOfTenRunsOfFerniteCarbideInATataraPlanTheIntermediatesOnTheNeedOfTenRuns` (E5 de #2-129) | Hydrogen Fuel Block `[[3, 100]]` → `[[3, 99]]` (E1). Ajouter `#[Group('issue-286')]` |
| `IndustryProjectFactoryGoldenStepsCharacterizationTest::testReactionConsumedByAReactionInATataraGetsTheReactorRigBonus` | E4, E5 ; son commentaire (« no structure bonus: ME 10 alone ») devient faux, à retirer avec la réécriture |
| `IndustryProjectFactoryGoldenStepsCharacterizationTest::testIntermediatesOfTenScorpionsInATataraAndARaitaruAreAggregatedWithTheBonusRuleOfEachPath` | E6 ; nom à revoir (le « bonus de chaque chemin » n'existe plus : bonus de chaque job consommateur) |
| `IndustryProjectFactoryStepRegenerationPreviewTest::testPreviewTakesTheRootReactionOfTheOldCalculationOfTenRunsOfFerniteCarbideBackAtOneHundredThousandUnitsWithItsIntermediates` | E9 |
| `MultiUnitProjectStepsRegenerationTest::testRootReactionOfTheOldCalculationOfTenRunsOfFerniteCarbideIsRegeneratedAtOneHundredThousandUnits` | Hydrogen Fuel Block `[3, 100]` → `[3, 99]` |
| `MultiUnitProjectStepsRegenerationTest::testHundredThousandRunsOfFerniteCarbideGiveOneBillionRootUnits` | E8 |
| `tests/Integration/…/IndustryProjectFactoryRegenerationTest::testRegenerationKeepsTheStructureOfTheHullComponentAndPlansItsSubComponentsWithItsMaterialBonus` | E11 |
| `…::testChangingTheRunsKeepsTheMeTeAndStructureOfEachJobOfASplitHullComponent` | E12 |
| `…::testAStructureOnARootWithoutRigCategoryPlansItsHullComponentsWithTheBaseMaterialBonusOnly` | E10 (première assertion seulement) |

Restent verts mais leur nom contredit R1 (à renommer) : `IndustryProjectFactoryGoldenStepsCharacterizationTest::testSplitReactionConsumerInATataraNeedsTheFuelBlocksOfEachOfItsJobsWithTheRaitaruBonus` et `::testRegeneratedSplitReactionConsumerInATataraNeedsTheFuelBlocksOfEachOfItsJobsWithTheRaitaruBonus` (E7 : rig de la Tatara, même résultat).

Restent verts sans changement : le golden `fernite-carbide-10runs-tatara-nullsec.json` et ses lecteurs (`GoldenJobMaterialQuantityTest`, `GoldenPlanTest`, `StructureMaterialModifiersTest`), `ProjectProviderGoldenTreeCharacterizationTest` (dont `testTreeOfTenRunsOfFerniteCarbideInATataraPlannedByTheFactoryShowsTenRunsOfOneHundredThousandUnits`, 49), les snapshots `tests/Fixtures/Characterization/`, `IndustryTreeServiceTest`, `IndustryShoppingListBuilderGoldenCharacterizationTest`, `StockpileGoldenImportCharacterizationTest`.

Tests nouveaux attendus : E1 avec la même quantité d'Hydrogen Fuel Block dans les étapes, le nœud de l'arbre (E2) et la liste d'achats (E3), le test demandé par l'issue ; E13 (R2).

## Câblage

| Dépendance de l'implémentation | Fournie par le harnais |
|---|---|
| `IndustryCalculationService::structureBonusOf()` dans `IndustryProjectFactory` (déjà injecté) | Tests unitaires : `BuildsProjectOnGoldenSde::calculationServiceOnGoldenSde()` construit un vrai `IndustryCalculationService` avec le `TataraAndRaitaruBonusService` du test ; le stub de `IndustryUserSettingsRepository` ne rend aucun réglage, donc pas de favori |
| Retrait de `IndustryBonusService` du constructeur de la factory | Aucun changement : les harnais construisent la factory par `AutowiresByType::autowired()` (par type) ; les processors et commandes la doublent par `createStub()` |
| Système favori (E13) | Pas dans le harnais unitaire golden (réglages nuls, `findBestInFavoriteSystem()` appelle le vrai `calculateStructureBonusForCategory()`). Test d'intégration recommandé, sur le SDE synthétique de `IndustryProjectFactoryRegenerationTest` avec ses helpers `raitaru()` / `structure()` et des `IndustryUserSettings` portant le favori, comme `FavoriteSystemStructureChoiceTest::setFavoriteSystems()` |
| Égalité étapes / arbre / liste d'achats (E1 à E3) | `ProjectProviderGoldenTreeCharacterizationTest::projectOnGoldenSde()` (projet planifié par la factory) et `ShoppingListProviderGoldenCharacterizationTest` / `BuildsShoppingListOnGoldenSde` |

Questions de spec ouvertes : aucune bloquante. À confirmer par Sylvain si souhaité : commande de correction des projets actifs (décision 5) et fermeture de #6.

## Documentation à mettre à jour à l'implémentation

- `docs/modules/industry.md` : retirer la factory des appelants de `IndustryBonusService` ; ligne « Bonus de structure et de rig » : génération et affichage par `IndustryCalculationService::structureBonusOf()`.
- `docs/glossary.md` : **Bonus de rig**, « pour la catégorie du produit du job » au lieu de « pour une catégorie de produits ». Aucun terme nouveau.
- Rappel de déploiement : aucune commande ni migration à lancer en production (R5).
