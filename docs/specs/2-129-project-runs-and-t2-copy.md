# Spec #2 (chemin projet) + #129 — Runs du projet à la racine de l'arbre, pas d'étape de copie pour un blueprint inventé

**Statut : rédigée le 2026-10-29 sans entretien, validée par Sylvain (T1 à T4 tranchés le 2026-10-29).** Les choix faits en autonomie sont dans « Décisions prises en autonomie », avec leur raison. Ceux qui changent un montant, une quantité ou une durée affichés sans que les issues le prévoient, ou qui relèvent du produit, sont dans « À trancher par Sylvain » : à poser avant la phase rouge.

## Contexte

Un projet industriel enregistre des **runs** (`IndustryProject::$runs`, saisis comme « Runs » à la création et à la modification). Ses **étapes** sont générées par `IndustryProjectFactory` (`createProject()`, `regenerateSteps()`) à partir de l'arbre de `IndustryTreeService::buildProductionTree()`.

État vérifié dans le code au 2026-10-29 :

- **#2.** `IndustryProjectFactory::productionTreeOf()` passe `$project->getRuns()` à `buildProductionTree(int $productTypeId, int $runs, …)`, qui le transmet à `buildNode(int $productTypeId, int $quantity, …)`. Celui-ci calcule `runs = ceil(quantity / unités par run)`. Pour un produit à plusieurs **unités par run**, le projet planifie donc la racine sur `ceil(runs / unités par run)` runs : 25 runs de Nitrogen Fuel Block (40 par run) deviennent 1 run de 25 unités. Les étapes, l'arbre affiché (`ProductionTreeFromSteps`, dérivé des étapes), la **liste d'achats**, les coûts et la durée du projet suivent les étapes et sont faux d'autant. Les vaisseaux (1 par run) masquent le défaut.
- La factory est désormais le **seul appelant** de `buildProductionTree()` : le Stockpile passe par `ProductionPlanning` (spec #69), la BOM de groupe aussi (spec #141), Profit Margins et Buy vs Build par le noyau. Les trois tests du Stockpile qui figeaient le défaut (`…CurrentlyReadsTheRunsAsAQuantityIssue2`) ont déjà été réécrits en tests d'intention par cette migration.
- `RegenerateStepsProcessor`, cité par le tri, n'existe plus. La régénération passe par `UpdateProjectProcessor` (changement de runs, de ME, de TE ou de durée maximale de job) et par la commande `app:industry:regenerate-steps <project-id>`.
- **#129.** `IndustryTreeService::hasCopyActivity()` pose `hasCopy` dès que le blueprint a l'activité de copie (5) dans le SDE. Les blueprints T2 l'ont. `IndustryProjectFactory::collectStepsFromTree()` crée alors une **étape de copie** « (BPC) » du blueprint T2, que `withCopyStepsFollowingManufacturing()` découpe sous le **plafond de runs d'un BPC**. Or un **blueprint inventé** ne se copie pas : on obtient ses BPC par **invention**. Le coût de cette copie impossible est compté par `InventionService::getProjectCopyCosts()` (`CopyCostsProvider`, une ligne par étape de copie). La partie Profit Margins de #129 est corrigée (la copie T1 n'existe plus que dans `ProductionCostCalculator::inventionCost`), comme la BOM de groupe (#105).
- Le **plafond de runs d'un BPC** d'une étape de fabrication est appliqué par `bpcMaxRunsOf()` aux seuls blueprints qui ont une étape de copie dans le plan (spec #234, R1). Retirer l'étape de copie d'un blueprint inventé retirerait donc aussi le plafond de ses jobs de fabrication : voir T1.

## Règles métier

- **R1 — Runs à la racine.** La racine de l'arbre d'un projet reçoit les runs du projet. Sa quantité vaut `runs × unités par run` du **producteur** du produit (fabrication d'abord, réaction en repli) ; ses runs valent exactement les runs du projet. L'étape racine générée porte ces runs et cette quantité.
- **R2 — Intermédiaires inchangés.** Sous la racine, un nœud reste demandé en quantité (**besoin**) : `runs = ceil(besoin / unités par run)`. Une division inexacte arrondit au run supérieur (250 unités à 100 par run : 3 runs, quantité 250). La planification agrégée (#69), le découpage par durée et par plafond (#234), la copie qui suit la fabrication (#235) et les règles de ME et de TE ne changent pas ; ils s'appliquent aux vrais runs de la racine.
- **R3 — Erreurs de la racine inchangées.** Produit sans producteur : refus avec le message `No blueprint or reaction found for product type ID …` (400 à la création, comme aujourd'hui). Type absent de `invTypes` : `TypeMissingFromSdeException` (#109). Aucune valeur par défaut d'unités par run.
- **R4 — Pas d'étape de copie pour un blueprint inventé.** La génération (création et régénération) ne crée une étape de copie que pour un blueprint qui a l'activité de copie dans le SDE **et** dont le produit n'est pas inventé. Un produit est inventé quand `InventionCatalog::inventionOf(produit)` n'est pas nul (même source que le coût d'invention du noyau et que `isT2` de la ressource projet). La règle vaut à toute profondeur. Réactions, blueprints sans activité de copie (faction, T3) : inchangés, jamais d'étape de copie.
- **R5 — Coût de copie.** `CopyCostsProvider` et `getProjectCopyCosts()` ne changent pas : ils somment les étapes de copie, qui n'incluent plus celles d'un blueprint inventé. La copie T1 d'une invention n'apparaît pas dans les étapes du projet (inchangé ; elle reste comptée par le coût d'invention).
- **R6 — Projets existants.** Rien ne change à l'ouverture d'un projet : l'arbre affiché, la liste d'achats et les coûts sont dérivés des étapes persistées. Les étapes sont corrigées à la prochaine **régénération des étapes**, mise à jour sur place (#21) : l'étape racine garde son identifiant, ses jobs matchés, ses achats et son stock, et prend les runs et la quantité de R1. Les runs fixés sont gardés (#185). Une étape de copie d'un blueprint inventé déjà en base n'est plus dans le plan : elle est gardée hors plan, après les étapes du plan (R10 de la spec #21), sauf décision contraire en T3.
- **R7 — Création = régénération.** Régénérer un projet juste créé donne les mêmes étapes (inchangé, vaut aussi pour les étapes de copie).

Contrat proposé (noms indicatifs, l'implémentation tranche) :

```php
public function buildProductionTree(int $productTypeId, int $runs = 1, int $finalMe = 0, array $excludedTypeIds = [], ?User $user = null, array $structureBonusOverrides = []): array
```

Signature inchangée ; seule la sémantique de la racine change (R1). La factory reçoit une dépendance `App\Industry\Application\InventionCatalog`.

## Exemples chiffrés (tests rouges)

SDE golden (`tests/Fixtures/Golden/sde-blueprint-recipes.json`), blueprints synthétiques = produit + 1 000 000. Colonnes des étapes : `[runs, quantity, depth, meLevel, teLevel, splitIndex, totalGroupRuns]` quand rien d'autre n'est précisé. « Golden » = valeur d'un golden versionné (`tests/Fixtures/Golden/`, provenance dans son README) ; « dérivé » = calculé depuis R1 et R2, à recouper par le test rouge.

### Runs à la racine (#2)

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Factory, Nitrogen Fuel Block (40 par run), 25 runs, ME 10, TE 20, sans structure | une étape `[1, 25, 0, 10, 20, 0, null]` | une étape `[25, 1000, 0, 10, 20, 0, null]` (golden `nitrogen-fuel-block-25runs-me10-npc`, fait foi). Réécrit `IndustryProjectFactoryGoldenStepsCharacterizationTest::testStepsOfTwentyFiveRunsOfNitrogenFuelBlockCurrentlyPlanOneRunOfTwentyFiveUnitsIssue2` |
| E2 | Arbre affiché du projet E1 (`ProjectProvider`) | racine `[quantity 25, runs 1, outputPerRun 40]`, matériaux d'un run | racine `[1000, 25, 40]`, `manufacturing`, sans structure ; matériaux (golden) : 44 → 90, 3683 → 495, 3689 → 90, 9832 → 203, 9848 → 25, 16272 → 3825, 16273 → 7875, 16275 → 450, 17888 → 10125. Réécrit `ProjectProviderGoldenTreeCharacterizationTest::testTreeOfTwentyFiveRunsOfNitrogenFuelBlockPlannedByTheFactoryCurrentlyShowsOneRunIssue2` (et son snapshot) |
| E3 | Factory, Fernite Carbide (réaction, 10 000 par run), 1 run, Tatara + Raitaru | racine `reaction [1, 1, 0, 0, 0, 0, null]` | racine `reaction [1, 10000, 0, 0, 0, 0, null]` ; intermédiaires inchangés (snapshot `fernite-carbide-1run-tatara-nullsec` de la factory, profondeur > 0). Réécrit `…::testStepsOfOneRunOfFerniteCarbideInATataraCurrentlyPlanTheRootReactionForOneUnitIssue2` |
| E4 | Arbre affiché, Fernite Carbide 10 runs, Tatara + Raitaru, durée max 2 j | racine `[10, 1]`, matériaux 4246 → 5, 16656 → 98, 16660 → 98 | racine `[100000, 10]` ; matériaux (golden `fernite-carbide-10runs-tatara-nullsec`) : 4246 → 49, 16656 → 974, 16660 → 974. Réécrit `ProjectProviderGoldenTreeCharacterizationTest::testTreeOfTenRunsOfFerniteCarbideInATataraPlannedByTheFactoryCurrentlyShowsOneRunIssue2` |
| E5 | Étapes du projet E4 | Ceramic Powder et Fernite Alloy 1 run, Hydrogen Fuel Block 1 run | runs (golden) : Ceramic Powder 5, Fernite Alloy 5, Hydrogen Fuel Block 3 ; quantités (dérivé, besoins) : 974, 974, 99 (49 + 25 + 25) |
| E6 | Factory, R.A.M.- Ammunition Tech (11476, 100 par run), 10 runs, ME 10, TE 20, sans structure, durée qui ne découpe rien | racine `[1, 10, …]` | racine `[10, 1000, 0, 10, 20, 0, null]` ; arbre affiché `[1000, 10, 100]` (test demandé par l'issue) |
| E7 | Factory, Hail L (5 000 par run), 10 runs, ME 2, TE 4, sans structure, sans activité de copie, durée qui ne découpe rien | racine 1 run, 10 unités ; R.A.M. `[1, 1, …]` | racine `[10, 50000, 0, 2, 4, 0, null]` ; R.A.M.- Ammunition Tech `[1, 10, 1, 10, 20, 0, null]` (golden `hail-l-10runs-me2-raitaru-nullsec` : besoin 10, 1 run, surplus 90) |
| E8 | Même cas, 250 runs, Fernite Carbide et Fullerides exclus | racine 1 run, 250 unités ; R.A.M. `[1, 1, …]` | racine `[250, 1250000, …]` ; R.A.M. `[3, 250, 1, 10, 20, 0, null]` (dérivé : besoin `max(250, ceil(250 × 0,98))` = 250, division inexacte → 3 runs) |
| E9 | `IndustryTreeService::buildProductionTree()` : réaction à 200 par run, 2 runs, deux entrées de 200 | (entrée 400 lue comme quantité) | racine `runs 2`, `quantity 400`, entrées 400 chacune. Réécrit `IndustryTreeServiceTest::testBuildProductionTreeReactionItem` (entrée 2 au lieu de 400) |
| E10 | Idem, fabrication à 3 par run, 3 runs, une entrée de 50 | (entrée 9) | `runs 3`, `quantity 9`, entrée 150. Réécrit `…::testOutputPerRunAffectsRunsCalculation` (entrée 3) |
| E11 | Idem, réaction à 100 par run, 2 runs, ME 10 passé | (entrée 200) | entrée 400 (pas de ME). Réécrit `…::testReactionDoesNotApplyME` (entrée 2) |
| E12 | Idem, intermédiaire à 100 par run dont le besoin est 250 | 3 runs, quantité 250 | inchangé (R2) |
| E13 | Factory, Titanium Chromide (200 par run), Tatara, ME 0, TE 0, durée max 0,05 j | entrée `runs: 4000` lue comme 4 000 unités, soit 20 runs | entrée `runs: 20` : 20 jobs `[1, 200, 0, 0, 0, i, 20]`, Oxygen Fuel Block `[[3, 100, 1, 10, 20, 0, null]]` (assertions inchangées de `testSplitReactionConsumerInATataraNeedsTheFuelBlocksOfEachOfItsOneRunJobsRoundedJobByJob` et de sa variante régénérée, renommées) ; seule l'entrée passe de 4000 à 20) |
| E14 | Régénération : projet E1 dont l'étape racine a été remise à `[1, 25]` (runs non fixés) | — | même étape (même identifiant) `[25, 1000]`, jobs matchés et achats gardés (R6) |
| E15 | Non-régression : tous les projets à 1 unité par run des goldens (Nomad, Widow, Scorpion, Quantum Microprocessor, Capital Jump Drive…) | — | étapes identiques |

### Pas de copie d'un blueprint inventé (#129)

Harnais golden avec activité de copie (`goldenBlueprintsHaveTheirCopyActivity = true`), ME 10, TE 20, produits inventés du SDE : Widow, Nomad, Hail L (à confirmer, note de câblage).

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| C1 | Widow 3 runs, durée qui ne découpe rien : copies du blueprint 1 022 436 | `[1, 1, 1]` | `[]`. Réécrit `IndustryProjectFactoryCopyStepsTest::testCopyOfThreeWidowsWithABpcMaxRunsOfOneIsOneCopyPerRun`. Jobs de fabrication de la Widow : selon T1 |
| C2 | Nomad 2 runs, durée max 2 j : copies du blueprint 1 028 846 | `[1, 1]` | `[]` ; la copie du Fenrir (1 020 189, T1) reste `[1, 1]`, les autres copies sont inchangées. Réécrit la constante `COPY_RUNS_OF_THE_OTHER_BLUEPRINTS_OF_TWO_NOMADS` de `…::testEveryOtherCopyOfTwoNomadsHasTheRunsOfTheManufacturingJobsOfItsBlueprint` |
| C3 | Hail L 10 runs, durée qui ne découpe rien | copie Hail L `[1]` (racine à 1 run par #2) | aucune copie du blueprint 1 012 779 ; copie de R.A.M.- Ammunition Tech (1 011 476, T1) `[1]` |
| C4 | Scorpion 25 runs (T1) | `[9, 8, 8]` | inchangé |
| C5 | `getProjectCopyCosts()` du projet C1 | lignes du blueprint Widow | aucune ligne du blueprint 1 022 436 ; total = somme des lignes restantes |
| C6 | `IndustryProjectFactoryTest`, arbre simulé : un nœud de fabrication `hasCopy` vrai, produit inventé selon le catalogue | une étape « (BPC) » | aucune étape de copie ; l'étape de fabrication est inchangée |
| C7 | Même nœud, produit non inventé | une étape de copie | inchangé (`testCopyStepCreatedWhenHasCopyIsTrue`) |
| C8 | Projet T2 existant qui a une étape de copie du blueprint inventé (créée avant le lot), régénération | — | selon T3 ; recommandation : étape gardée hors plan, après les étapes du plan, avec ses jobs matchés et achats (R10 de #21) |
| C9 | Nœud de réaction, ou blueprint sans activité de copie | — | le catalogue d'invention n'est pas lu |

## Cas limites

- **Réaction à 10 000 par run** (Fernite Carbide) : quantité racine `runs × 10 000` ; ME et TE de la racine à 0 (inchangé). Plus grand produit possible avec la borne `CreateProjectInput::MAXIMUM_PROJECT_RUNS` (100 000) : 10⁹ unités pour 10 000 par run, sous la limite de la colonne `quantity` (INT). Un producteur à plus de 21 474 unités par run dépasserait la colonne à 100 000 runs : à vérifier dans le SDE (requête dans la note de câblage), hors lot si aucun cas.
- **Produit absent du SDE** (lot A, spec #137 + #125) : R3, aucun changement. La racine a toujours un producteur quand elle est convertie (sinon refus en amont), donc ses unités par run sont lues sans repli.
- **Division inexacte** : uniquement sous la racine (E8, E12), la racine ayant des runs entiers par construction.
- **Blueprint inventé contre T1** : le critère est le produit inventé dans le SDE (R4), pas la possession d'un BPO. Un BPO T2 possédé par un joueur n'est pas connu de la factory : pas d'étape de copie non plus.
- **Produit inventé à une profondeur > 0** : même règle (aucun cas dans les goldens).
- **SDE incohérent sur l'invention** : `SdeInventionCatalog::inventionOf()` lève `\UnexpectedValueException` quand le blueprint T1 qui invente ne fabrique rien. L'erreur remonte : 400 à la création (`CreateProjectProcessor` traduit les `\RuntimeException`), erreur serveur à la régénération. Pas de repli silencieux.
- **Étape de copie déjà existante** : R6 et T3.
- **ME / TE conservés** : à la régénération, chaque intermédiaire garde ses ME, TE et structure (`withSettingsOfExistingStep`, inchangé) ; la racine prend ceux du projet ; une copie reste à ME 0 / TE 0.
- **Runs fixés sur la racine** d'un projet existant : gardés à la régénération (#185) ; la racine reste alors au nombre de runs fixé, quantité plafonnée au besoin.
- **Racine découpée** : avec ses vrais runs, la racine d'un produit à plusieurs unités par run peut maintenant être découpée par la durée maximale de job ou par le plafond de runs de son BPC (T4).

## Décisions prises en autonomie

1. **Conversion dans `buildProductionTree()`, signature inchangée** (R1). La factory est son seul appelant et lui passe déjà des runs ; le paramètre s'appelle déjà `$runs`. Aucune nouvelle dépendance pour #2, le harnais de la factory ne bouge pas. `buildNode()` garde la quantité pour les intermédiaires (R2).
2. **Le critère « inventé » vit dans la factory, par `InventionCatalog`** (R4). C'est une règle de planification, pas une donnée de l'arbre ; le port est celui du noyau (coût d'invention de Profit Margins), et `InventionService::isT2` suit le même chemin SDE : le projet, son onglet d'invention et Profit Margins jugent le T2 de la même façon. Écartés : `InventionService::isT2` (lit en plus les noms des datacores) et un test dans `IndustryTreeService` (il mélangerait la description du SDE et la règle, et compliquerait T1).
3. **Catalogue lu seulement pour un nœud qui a l'activité de copie, une fois par blueprint et par génération** (C9). Les réactions et les blueprints sans copie ne coûtent aucune requête.
4. **Erreurs du catalogue propagées** (cas limites) : règle « pas de catch silencieux ».
5. **Tests Titanium Chromide : on change l'entrée, pas les assertions** (E13). Ils voulaient 20 runs (4 000 unités) ; leur intention (#73) est gardée telle quelle.
6. **Le harnais golden déclare les produits inventés du SDE** plutôt qu'aucun : sinon les tests de copie continueraient d'asserter des copies de Widow et de Nomad que R4 interdit.
7. **Aucune migration ni commande dans ce lot** (R6) : la correction des projets existants est une décision (T2, T3).

## Décisions de Sylvain (2026-10-29)

- **T1** : option B, le plafond de runs reste appliqué à tout blueprint qui a l'activité de copie dans le SDE, étape de copie ou non.
- **T2** : commande ponctuelle de régénération des projets concernés, à lancer en production après déploiement ; issue dédiée, hors de ce lot.
- **T3** : l'étape de copie d'un blueprint inventé déjà en base est gardée.
- **T4** : effets dérivés acceptés.

## À trancher par Sylvain (réponses ci-dessus)

- **T1 — Plafond des jobs de fabrication d'un blueprint inventé, une fois sa copie retirée.** Aujourd'hui le plafond (`maxProductionLimit`) ne s'applique qu'aux blueprints qui ont une étape de copie : R4 le retirerait aux T2.
  - Option B (recommandée) : garder le plafond pour tout blueprint qui a l'activité de copie dans le SDE, étape de copie ou non. Aucun job de fabrication ne change ; seules les copies T2 disparaissent. Un BPC inventé est un BPC (glossaire), le plafond reste pertinent ; sa vraie valeur (runs du BPC inventé) viendra du branchement du noyau (rang 7).
  - Option A : pas de plafond pour un blueprint inventé, comme le dit la décision 4 de la spec #234. Change des runs et des quantités affichés : Widow 3 runs passe de 3 jobs d'un run à un job de 3 runs, et les besoins en aval (Sustained Shield Emitter, Magpulse Thruster) changent ; le Nomad de 2 runs et ses intermédiaires sont à mesurer, avec de nombreux tests de `IndustryProjectFactoryBpcMaxRunsTest`, `IndustryProjectFactoryCopyStepsTest` et `IndustryProjectFactoryRegenerationOnGoldenSdeTest`.
- **T2 — Projets existants à plusieurs unités par run.** Ils restent faux jusqu'à une régénération (changement de runs, ME, TE ou durée max, ou la commande par projet). Les estimations figées à la création (`estimatedMaterialCost`, `estimatedJobCost`, écrites seulement par `CreateProjectProcessor`) ne sont pas recalculées, même après régénération. Options : laisser l'utilisateur régénérer ; commande ponctuelle de régénération des projets concernés, à lancer en prod après déploiement (elle garde jobs matchés, achats et stock, mais change les étapes de projets en cours) ; recalcul des estimations figées. Pour en mesurer l'étendue, requête en lecture seule dans la note de câblage.
- **T3 — Étape de copie d'un blueprint inventé déjà en base.** Gardée hors plan (R10 de #21), elle reste comptée dans le coût de copie. Options : la garder (recommandé : aucune donnée perdue, l'utilisateur la supprime) ; la supprimer à la régénération si elle n'a ni job matché, ni achat, ni stock ; commande de nettoyage.
- **T4 — Effets dérivés de #2 non listés par l'issue.** Pour un projet à plusieurs unités par run, le coût d'installation, le coût de production, la durée totale du projet et le nombre de jobs de la racine (découpage par durée ou par plafond, ex. un fuel block de 250 runs avec un plafond de 200 → 2 jobs) suivent les vraies étapes. Recommandation : accepter, ce sont des valeurs dérivées des étapes corrigées.

## Hors périmètre

- `StockpileService`, `StockpileStartingStockReducer` : déjà sur le noyau (spec #69).
- `CreateStepProcessor` (étape ajoutée à la main : runs stockés comme quantité, #191 ; type sans producteur, #280).
- `ProfitMarginService`, `BuyVsBuildService`, `GroupIndustryProjectService` : déjà sur le noyau ou corrigés (#105).
- Runs d'un BPC inventé (base + decryptor) comme plafond : branchement du noyau (rang 7).
- Kit de BPC (`getBpcKitBreakdown`) : indépendant des étapes, inchangé.
- Mise à jour des exemples E11 de la spec #234 et C7 de la spec #235 (Widow), à reprendre selon T1.

## Tests existants touchés

- **Réécrits, groupe `issue-2` (le nom perd `Currently…Issue2`)** : les 4 tests encore figés, `IndustryProjectFactoryGoldenStepsCharacterizationTest::testStepsOfTwentyFiveRunsOfNitrogenFuelBlockCurrentlyPlanOneRunOfTwentyFiveUnitsIssue2` (E1), `::testStepsOfOneRunOfFerniteCarbideInATataraCurrentlyPlanTheRootReactionForOneUnitIssue2` (E3), `ProjectProviderGoldenTreeCharacterizationTest::testTreeOfTwentyFiveRunsOfNitrogenFuelBlockPlannedByTheFactoryCurrentlyShowsOneRunIssue2` (E2), `::testTreeOfTenRunsOfFerniteCarbideInATataraPlannedByTheFactoryCurrentlyShowsOneRunIssue2` (E4). Les 3 tests du Stockpile cités par le tri n'existent plus sous ce nom : déjà réécrits par la migration du Stockpile. Après ce lot, aucun test ne fige plus #2.
- **Cassent par changement voulu, sans `Currently` dans le nom** : `IndustryTreeServiceTest::testBuildProductionTreeReactionItem`, `::testOutputPerRunAffectsRunsCalculation`, `::testReactionDoesNotApplyME` (E9 à E11) ; les deux tests Titanium Chromide (E13) ; le snapshot `tests/Fixtures/Characterization/ProjectProvider/fernite-carbide-1run-tatara-nullsec.json` (quantité racine 1 → 10 000) de `testTreeOfOneRunOfFerniteCarbideInATataraShowsOneFuelBlockNodeAndReferencesItsOtherConsumers`, et `nitrogen-fuel-block-25runs-me10-npc.json` (E2).
- **#129** : `IndustryProjectFactoryCopyStepsTest::testCopyOfThreeWidowsWithABpcMaxRunsOfOneIsOneCopyPerRun` (C1) et `::testEveryOtherCopyOfTwoNomadsHasTheRunsOfTheManufacturingJobsOfItsBlueprint` (C2). Option A de T1 : en plus `IndustryProjectFactoryBpcMaxRunsTest::testRootStepOfThreeWidowsWithABpcMaxRunsOfOneIsSplitIntoOneJobPerRun` et les tests du Nomad de 2 runs, à mesurer.
- **Reste vert** : `IndustryProjectFactoryTest::testCopyStepCreatedWhenHasCopyIsTrue` (catalogue vide). Son libellé de produit (« Sabre », un T2 inventé dans EVE) est à remplacer par un T1 pour ne pas contredire R4.

## Termes du glossaire

- Ajoutés : **Étape de copie** (Copy step), **Blueprint inventé** (Invented blueprint), **Régénération des étapes** (Step regeneration).
- À mettre à jour à l'implémentation : ambiguïté **B1** (levée pour la factory), **Plafond de runs d'un BPC** (selon T1 : « activité de copie dans le SDE » au lieu de « étape de copie dans le plan »).
