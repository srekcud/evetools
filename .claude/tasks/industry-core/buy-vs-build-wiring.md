# Câblage de BuyVsBuildService sur l'Application Industry (spec §7, rang 2)

- Rédigé le 2026-10-09, **réécrit le 2026-10-22** sur `main` = `ac0a555` (worktree `evetools-wt-CP`, branche `feat/buy-vs-build-on-core`)
- Socle hérité du rang 1, fusionné (9d77acf, 352bb49, 7459fa9, 24d04f3, f8ef717) : deptrac (`Service` → `IndustryApplication`, `IndustryDomain`, `deptrac.yaml:112`), ports `RigCategories`, `UserProductionStructures`, `Decryptors`, adaptateurs, `MissingDataPresenter`, ME/TE du BPC inventé (`ProductionCostCalculator.php:63-65`), `InventionCost::$recipe/$chance/$outcome`
- Ancienne phase rouge (`archive/buy-vs-build-on-engine/`, base f2c30cb) : obsolète. À reprendre seulement comme idées : stub `getWeightedSellPricesWithFallback`, helper `analyzeHailLWithEveryTypePriced`, `everyGoldenTypeAt()`
- Tout ce qui est cité ci-dessous a été relu dans le code de `ac0a555`

## 0. Ce qui a changé depuis le 2026-10-09

| Changement sur `main` | Effet sur ce plan |
|---|---|
| #98 (ae9cc79) : feuilles du job final pricées à Jita | Règle à garder. Le noyau la donne d'office (feuilles du plan racine) |
| #99 (6109c51) : réactions au système favori des réactions ; `analyze(…, ?int $reactionSolarSystemId = null)` ; un `solarSystemId` explicite vaut pour les deux activités (`BuyVsBuildProvider.php:62-67`) | `FavoriteSystems` a déjà deux champs (`FavoriteSystems.php`, `forActivity()`) : pas de contradiction avec la décision (d) du rang 1, qui ne vaut que pour Profit Margins (un seul sélecteur). Voir décision (g) |
| #100 (41dd0b5) : `?int $sellStructureId`, aucun appel à `StructureMarketService` sans structure (`BuyVsBuildService.php:98-100`, `:115-117`), `BuyVsBuildResource::$sellStructureId` | Règle à garder, hors noyau |
| #101 (1b76460) : « T2 Component » seulement si le composant consomme un produit de réaction (`classifyStage()`, `:396-409`) | Règle à garder, hors noyau, lue sur les recettes (`BlueprintCatalog`) au lieu de l'arbre |
| #115 (6b1a8bd) : nom de type inconnu = `null`, jamais « Type #id » | Règle à garder (`TypeNameResolver::findName()`) |
| #8 (a845f03, 0f6134e) : prix absent = montant `null`, verdict selon le côté connu, `missingPriceTypeIds` | Règles à garder (spec `docs/specs/8-buy-vs-build-unknown-price.md`, R1-R8). **Le verdict n'est pas `unknown` dès qu'un côté manque** : l'ancien plan et l'archive le supposaient |
| #7 (7477449, e59a14e, 7e74330) : installation inconnue = `null`, `unknownInstallCostReasons`, indice du système de l'activité | Règles à garder ; les motifs passent dans `missingData` (décision k) |
| Rang 1 : `missingData` au total et par étape (PM3), `unknownReason` limité au bloc invention de Profit Margins (PM2) | Même contrat pour Buy vs Build : `missingData` au résultat et par composant, aucun `unknownReason` |
| Décisions 2026-10-22 : D3c, D3d (#151, non implémentées dans `StructureSelector`), D13 (blacklist ignorée par Buy vs Build avec mention), D14 (taxe null = 0 %) | D3c/D3d/D14 : hérités du noyau, rien à faire ici. D13 : décision (h) |
| Audit : #142 (plafond de runs BPC jamais transmis), #124 (Buy vs Build ignore l'invention), #172, #174, #195, #198 (frontend) | #124 fermé pour Buy vs Build par (c) ; #142 : décision (i) ; #172/#174/#195/#198 hors périmètre |

## 1. Comportement actuel à préserver

Tests qui le fixent aujourd'hui et règles qu'ils portent :

| Règle | Source | Tests |
|---|---|---|
| Composants = intrants directs du job racine qui ont une recette ; coût « build » sur un niveau (matériaux directs au prix du marché + installation) ; pas de récursion (`collectBuildableComponents()`, `:295-348`) | historique | `BuyVsBuildServiceCharacterizationTest` |
| Achat = prix structure × quantité, sinon Jita, sinon inconnu (`:131`) ; structure préférée même plus chère | #8 R2 | `testStructurePriceIsPreferredToACheaperJitaPriceForTheBuyCost` |
| Verdict : deux coûts connus → `build` si build ≤ buy, sinon `buy` ; seul build connu → `build` ; seul buy connu → `buy` ; aucun → `unknown` (`verdictOf()`, `:272-280`) | #8 R3, #7 R2 | `BuyVsBuildServiceUnknownPriceTest`, `BuyVsBuildServiceUnknownInstallCostTest` |
| `savings` / `savingsPercent` `null` sauf deux coûts connus ; `savingsPercent = |savings| / max × 100` | #8 R4 | idem |
| `buildAllCost` null dès qu'un build est inconnu ; `buyAllCost` idem côté achat ; `optimalMixCost` = côté du verdict, null dès qu'un verdict est `unknown` ; un `unknown` n'est ni dans `buildTypeIds` ni dans `buyTypeIds` | #8 R5 | idem |
| `totalProductionCost` = mix optimal + feuilles du job final + installation du job final, null dès qu'un terme manque ; marge null sans coût ou sans prix de vente > 0 | #8 R6, #98, #7 R3 | `testRootLeaf…`, `testFinalJobLeafWithoutJitaPrice…` |
| Tri : `savings` décroissant, puis les `savings` null par typeId croissant (`:208-212`) | #8 R8 | `testComponentsWithUnknownSavingsComeAfterTheOthersByAscendingTypeId` (+ `BuyVsBuildServiceTest::testAnalyzeComponentsSortedBySavingsDescending`) |
| Prix 0 = prix connu (matériau, feuille, achat) | #8 | `testZeroMaterialAndLeafPricesAreKnownPrices`, `testZeroBuyPriceIsAKnownPriceSoTheFreePurchaseWins` |
| Prix de vente = prix structure de vente, sinon Jita (`:98-101`) ; un prix structure à 0 masque Jita | historique, fige #8 | `testZeroStructureSellPriceCurrentlyWrong…` (reste tel quel : la vente est hors noyau) |
| Sans structure de marché : aucune lecture de `StructureMarketService`, `buyCostStructure` null, vente à Jita | #100 | `BuyVsBuildServiceWithoutSellStructureTest` (3 tests), `BuyVsBuildProviderTest` (#100) |
| Fabrication au système favori de fabrication, réaction au favori des réactions, repli sur le premier ; `solarSystemId` explicite pour les deux | #99 | `BuyVsBuildProviderTest` (#99, 5 tests), `testEachComponentInstallCostUsesTheCostIndexOfTheSystemOfItsActivity` |
| Libellés `Reaction` / `T2 Component` / `T1 Component` | #101 | `testFuelBlockConsumedByAReactionIsLabelledT1Component`, `testManufacturedComponentConsumingAReactionProductIsLabelledT2Component` |
| Nom de type absent du SDE = `null` (produit, composant, matériau) | #115 | `BuyVsBuildServiceTest` (3 tests), `BuyVsBuildProviderTest` (#115) |
| Montants arrondis à 2 décimales en sortie | historique | `testPricesAndCostsAreRoundedToTwoDecimals` |

Ce qui change de toute façon avec le noyau (attendu, pas une régression) : runs lus comme des runs (#2 : Hail L 10 runs, Fernite Carbide 10 runs), installation = indice + taxe (NPC 0,25 % ou structure) + SCC 4 % (R6), structures de l'utilisateur et leurs bonus (R4), système du job = celui de la structure choisie, sinon le favori de l'activité (`ProductionCostCalculator.php:263`), prix des feuilles pondéré sur la quantité (`JitaMarketPrices`), invention des T2 (décision c).

## 2. Signature cible et déroulé

```php
public function __construct(
    private ProductionCostCalculator $productionCosts,      // App\Industry\Application
    private BlueprintCatalog $blueprints,                    // App\Industry\Domain, alias services.yaml:71 → SdeBlueprintCatalog
    private UserProductionStructures $productionStructures,  // App\Industry\Application (rang 1)
    private JitaMarketService $jitaMarketService,            // prix de vente Jita du produit (inchangé)
    private StructureMarketService $structureMarketService,  // vente du produit, achat des composants en structure
    private TypeNameResolver $typeNameResolver,
)
```

`analyze()` garde sa signature exacte (`int $typeId, int $runs, int $meLevel, int $solarSystemId, ?int $sellStructureId, float $brokerFeeRate, float $salesTaxRate, ?User $user, ?int $reactionSolarSystemId = null`) : le provider et ses tests ne changent pas d'appel.

Disparaissent : `IndustryTreeService`, `EsiCostIndexService`, `InventionService` (`isT2` = `ProductionCost->invention !== null`), `IndustryActivityMaterialRepository`, et dans le service `collectBuildableComponents`, `estimateJobInstallCost`, `collectLeafMaterialsFromTree`. Aucune de ces classes ne devient morte ailleurs (`InventionService::isT2()` : `CreateProjectProcessor.php:62`, `IndustryResourceMapper.php:124` ; `EsiCostIndexService::estimateJobInstallCost()` : `PivotAdvisorService.php:168,433`). `IndustryBlacklistService` n'est pas injecté (D13).

Déroulé (mécanisme : décision f, recommandation « un `costOf()` par composant ») :

1. **Contexte commun.** `structures` = `[]` sans utilisateur, sinon `configuredBy(idOf($user))` (même garde que `ProfitMarginService.php:157`) ; `favoriteSystems = new FavoriteSystems($solarSystemId, $reactionSolarSystemId ?? $solarSystemId)` (g) ; `CostMode::Plan` (a) ; `InventionSettings(0, 0, 0)` sans decryptor, constante nommée comme `ProfitMarginService::INVENTION_SKILL_LEVEL_WITHOUT_SKILLS` (c) ; blacklist utilisateur jamais lue (h).
2. **Composants** (d) : `blueprints->recipeFor($typeId)->materials` dont `recipeFor()` n'est pas null.
3. **Job racine** : `costOf($typeId, Runs($runs), BlueprintEfficiency(ME $meLevel, TE constante), blacklist = composants)`. Les composants y deviennent des feuilles : `leaves[c]->quantity` = demande du job racine (runs entiers, ME, structure), `leaves[c]->cost` = achat Jita pondéré (j). Les autres feuilles sont les matières premières du job racine ; `installCost` = installation du job racine ; `invention` = espérance d'invention si le produit est inventé. Pour un produit inventé, `$meLevel` est ignoré par le noyau (ME/TE du BPC inventé).
4. **Chaque composant c** : `runs_c = ceil(quantité_c / recipe_c->outputPerRun)` ; `costOf(c, Runs(runs_c), efficacité par défaut, blacklist = typeIds des matériaux de c)`. Efficacité par défaut : ME 10 / TE 20 en fabrication, 0 en réaction, la même que `ProductionPlanner.php:15-19` (constantes privées : soit les exposer, soit les dupliquer en constantes nommées du service ; à trancher par l'implementer, la première évite la duplication). `buildCost` = `totalCost` (matériaux + installation + invention si c est inventé), `buildJobInstallCost` = `installCost`, `buildMaterials` = `leaves` (prix unitaire pondéré, `null` si inconnu), `meUsed` = ME passé, `runs` = `runs_c`.
5. **Achat de c** : prix structure (`getLowestSellPrices`, seulement si `$sellStructureId !== null`) × quantité, sinon `leaves[c]->cost` du job racine. `buyCostJita` = montant de `leaves[c]->cost`, `buyCostStructure` = montant structure ou null.
6. **Verdict** (#8 R3 + décision e), `savings`, `savingsPercent`, totaux (#8 R5), tri (#8 R8) : inchangés dans leur logique, calculés sur des `Cost`.
7. **Total** : `totalProductionCost` = `optimalMixCost` + Σ coûts des feuilles du job racine qui ne sont pas des composants + `installCost` du job racine + `invention->total`. `stage` : `Reaction` si `recipe_c->activity === Reaction`, sinon `T2 Component` si un matériau de c a une recette de réaction, sinon `T1 Component` (#101 R1-R3).
8. **Vente et marge** : inchangées (`:98-101`, `:236-243`), `outputQuantity = producedQuantity` du job racine.

Exemple de contrôle pour le test-writer (Hail L 1 run, système d'usine, indice de fabrication 0,05, sans structure) : R.A.M., 1 run ME 10, matériaux `501 × 5 + 400 × 10 + 200 × 50 + 74 × 100 + 33 × 800 = 50 305`, EIV 47 236, installation `47 236 × (0,05 + 0,0025 + 0,04) = 4 369,33`, `buildCost = 54 674,33` (aujourd'hui 52 666,8 : 2 361,8 d'installation sans taxe ni SCC).

## 3. Forme de la réponse (décision k)

Reprise du rang 1 (PM3) : montant inconnu = `null`, `missingData: list<array{reason: string, typeId: ?int}>` produit par `MissingDataPresenter::of(Cost)`.

- **Composant** : `missingData` = données manquantes de `buildCost` puis du coût d'achat retenu (`Cost::plus`, dédoublonné, ordre d'apparition). Le coût d'achat inconnu porte `MissingData::marketPrice(c)` (le noyau le fournit déjà par `leaves[c]`). Pas de `missingData` sur les lignes `buildMaterials` (comme PM3 pour les matériaux).
- **Résultat** : `missingData` = somme des coûts de tous les composants (build et achat) puis du job racine (feuilles, installation, invention).
- **Supprimés** : `missingPriceTypeIds` et `unknownInstallCostReasons` (service, resource, types frontend). Le frontend dérive les motifs d'installation comme `ProfitMarginTab.vue:51-64` (`cost_index` → `missing_cost_index`, `adjusted_price` → `missing_adjusted_price`).
- **Nouveau motif visible** : `invention_probability` (T2 sans probabilité dans le SDE). Libellé existant : `industry.inventionUnknown.missing_invention_probability`.
- `verdict` reste `'build' | 'buy' | 'unknown'` (déjà en place) ; aucun `unknownReason`.

## 4. Décisions

**(a) Mode plan.** Recommandé : `CostMode::Plan` pour le job racine et chaque composant. Buy vs Build compare des jobs réels : on achète la quantité demandée ou on lance des runs entiers, surplus compris (R.A.M. : 1 run de 100 pour 1 unité). C'est aussi la sémantique actuelle (`runs` entiers, `:318`). Mettre Buy vs Build dans la colonne « Plan » de R12. Alternative : coût marginal (comme Profit Margins) ; le build serait alors favorisé à tort sur tout composant à gros lot.

**(b) Coût « build » sur un niveau.** Recommandé, inchangé : matériaux directs du composant au prix du marché + son installation. Le mécanisme (f) le donne sans code dédié (blacklist = matériaux de c). Alternative : coût build récursif optimal (min build/buy à chaque nœud) ; autre fonctionnalité, issue séparée.

**(c) Invention incluse.** Recommandé : espérance sans decryptor, compétences à 0, comme Profit Margins. Ferme le volet Buy vs Build de #124 et aligne les deux écrans pour un même T2. Raison technique aussi : `costOf()` lève une `DomainException` pour un produit inventé sans `InventionSettings` (`ProductionCostCalculator.php:180-181`). Effet visible : le coût total des T2 monte, la marge baisse. Alternative : l'exclure ; il faudrait un drapeau dans `ProductionCostRequest`, et #124 resterait ouvert.

**(d) Composants de profondeur 1, demande du seul job racine.** Recommandé : la quantité d'un composant est ce que consomme le job racine ; ce qu'en consomment les autres composants est acheté au marché dans leur propre coût build. Pas de double compte dans `optimalMixCost` et chaque verdict reste une décision indépendante. Conséquence : le test `testFerniteCarbideTenRunsCurrentlyWrong…CountsEachDepthApart` perd « CurrentlyWrong » pour la profondeur (le volet runs, #2, est corrigé par le noyau). Alternative (ancien plan) : demande agrégée sur tout le plan (#69, Hydrogen Fuel Block 50 + 25 + 25 = 100) avec les composants retirés des matériaux de leurs consommateurs ; le coût build d'un consommateur est alors sous-estimé et `buyAllCost` compte un composant dont le consommateur est acheté.

**(e) Égalité build = buy.** Recommandé : `Isk::equals()` (tolérance 1 ISK, D1) ; écart ≤ 1 ISK → `build` ; sinon le moins cher. `savings` reste la différence calculée, sans être forcée à 0. Utile en pratique : le test d'égalité fixe un prix structure à 2 décimales (`round`) face à un build non arrondi ; sans tolérance, un écart de 0,003 ISK fait basculer le verdict. Alternative : règle stricte actuelle `build <= buy`.

**(f) Nouveau — mécanisme : un `costOf()` par composant, sans `ProductionCost::$plan`.** Recommandé : N + 1 appels (racine avec blacklist = composants, puis chaque composant avec blacklist = ses matériaux). Aucun changement de l'Application, aucun test Application, sémantique actuelle conservée (b, d), toutes les règles du noyau (ME, structures, rigs, installation, invention, prix pondérés) appliquées à chaque job. Coût : N + 1 plans courts et N + 1 appels au marché (Marshal : 16) ; à mesurer, pas à optimiser d'avance. Alternative (ancien plan, rang 1 §0.3) : un seul `costOf()` en mode plan, ajout de `ProductionCost::$plan` (`?Plan`, null en coût marginal) et test Application associé, découpage des jobs et des feuilles par composant dans le service ; va avec l'alternative de (d).

**(g) Système des réactions.** Recommandé : garder #99, `FavoriteSystems($solarSystemId, $reactionSolarSystemId ?? $solarSystemId)`. Le noyau sait déjà séparer les deux ; la décision (d) du rang 1 (« système sélectionné pour toutes les activités ») vaut pour l'écran Profit Margins seulement, sans contradiction. Effet du noyau à savoir : ce favori sert aussi de filtre de structure (`StructureSelector`, D3/D3c), et un job dans une structure prend le système de cette structure. Alternative : un seul système pour tout (régression de #99).

**(h) Blacklist (D13).** Tranché par Sylvain : Buy vs Build ignore la blacklist utilisateur et le dit à l'écran. Conséquence pour le câblage : la seule blacklist passée au planificateur est technique (composants pour la racine, matériaux pour un composant) ; `IndustryBlacklistService` n'est pas injecté. Reste à valider le texte : « La blacklist industrie ne s'applique pas ici : chaque composant est comparé à son achat » / « Your industry blacklist does not apply here: every component is compared with buying it », sur le modèle de `noSellStructure` (`BuyVsBuildTab.vue:378`). Pas de maquette : une ligne de texte dans un bloc existant.

**(i) #142 (plafond de runs du BPC) hors rang 2.** Recommandé : le corriger dans le noyau sous #142 (`ProductionCostRequest` + `PlanRequest::$blueprintCopyMaxRuns`, tous appelants) ; Buy vs Build en hérite sans changement. Seul le job racine d'un T2 est concerné ici (composants = BPO, R3) : un T2 de 32 runs avec un BPC de 10 runs reste un job de 32 runs, écart de quelques unités par matériau (R13). Alternative : passer `InventionOutcome->runs` comme plafond dans ce lot ; mélange deux lots et change aussi Profit Margins.

**(j) Nouveau — prix d'achat Jita d'un composant.** Recommandé : `leaves[c]` du job racine, soit le prix pondéré sur la quantité achetée (`JitaMarketPrices`), même définition que les matériaux. Supprime une des trois définitions du prix Jita relevées par l'audit (legacy#13). Alternative : garder `getPricesWithFallback()` (meilleur ordre) pour l'achat, avec une définition différente entre achat et matériaux.

**(k) Nouveau — contrat d'erreur.** Recommandé : `missingData` (résultat + composant) remplace `missingPriceTypeIds` et `unknownInstallCostReasons`, comme Profit Margins. Alternative : garder les deux anciens champs, dérivés côté serveur de `MissingData` ; frontend intact, mais deux vocabulaires (`missing_cost_index` / `cost_index`) et pas de place pour `invention_probability`.

## 5. Ce que le test-writer modifie avant verrouillage

### Harnais `BuildsBuyVsBuildOnGoldenSde`

1. Câbler `new BuyVsBuildService(...)` du §2 : `ProductionCostCalculator(SdeBlueprintCatalog, SdeInventionCatalog, JitaMarketPrices, EsiAdjustedPrices, EsiSystemCostIndices, RigCategories)` ; `SdeBlueprintCatalog` aussi comme `BlueprintCatalog` ; ports `RigCategories` (null partout) et `UserProductionStructures` stubés comme `ProfitMarginGoldenCharacterizationTest.php:1138-1154`.
2. Stubs SDE de `SdeBlueprintCatalog` (`IndustryActivityRepository` pour les temps, `IndustryBlueprintRepository` pour `maxProductionLimit`, `SdeBlueprintCatalog.php:60` lève sans ligne) et données d'invention du Hail L (blueprint T1, datacores, probabilité, runs). Le golden de Profit Margins les a déjà en méthodes privées (`sdeProductRepository()`, `sdeMaterialRepository()`, `sdeActivityTimeRepository()`, `sdeBlueprintRepository()`) : les extraire dans un trait partagé plutôt que les copier.
3. `getWeightedSellPricesWithFallback` stubé avec les mêmes prix que `getPricesWithFallback` (le second ne sert plus qu'au prix de vente du produit).
4. **Indices `copying` et `invention`** dans `costIndices` de chaque système utilisé avec Hail L ; sans eux, l'installation de l'invention est inconnue et tout total T2 devient `null` (`InstallCost.php`, `MissingData::costIndex()`).
5. Retirer le paramètre `isT2` et le stub `InventionService` ; retirer `IndustryTreeService`, `IndustryBonusService`, `IndustryActivityMaterialRepository::findMaterialsForBlueprints`.
6. `User` avec un id (réflexion sur `User::$id`, comme `ProfitMarginGoldenCharacterizationTest.php:1168-1172`) partout où un utilisateur atteint le vrai service : `BuyVsBuildProviderTest` passe aujourd'hui `new User()` sans id, ce qui lèverait `LogicException`.

### Par fichier

| Fichier | Action |
|---|---|
| `BuyVsBuildServiceCharacterizationTest` | Recalculer les montants (installation R6, invention du Hail L, prix pondérés). `testHailLTenRunsCurrentlyWrong…` devient un test de 10 runs réels (`issue-2`, retirer CurrentlyWrong). `testFerniteCarbideTenRunsCurrentlyWrong…` : 10 runs de réaction, composants au niveau 1 (d), renommer. `testZeroStructureSellPriceCurrentlyWrong…` : inchangé hors montants. Ajouter : tri par `savings` décroissant avec plusieurs `savings` connus ; nom de produit / composant / matériau absent du SDE (#115) ; identité `totalProductionCost = optimalMixCost + feuilles racine + installation racine + invention` (ex-`testNoDoubleCountingWithNestedBuildableComponents`) ; mention d'une égalité à 1 ISK près (e) ; `missingData` `invention_probability` d'un T2 sans probabilité. Remplacer `missingPriceTypeIds` / `unknownInstallCostReasons` par `missingData`. Supprimer les commentaires `CARACTÉRISATION` (règle « pas de commentaire », audit tests#7) |
| `BuyVsBuildServiceUnknownPriceTest` | Stube `IndustryTreeService` sur un arbre Sabre absent du fixture : **porter** ses 14 cas (#8 R1-R8, prix 0, matériau absent de deux composants listé une fois) sur le harnais golden (Hail L : R.A.M., Fernite Carbide, Fullerides), puis supprimer la version stubée |
| `BuyVsBuildServiceUnknownInstallCostTest` | Garder, recalculer. Motifs → `missingData` (`['reason' => 'cost_index', 'typeId' => null]`, `['reason' => 'adjusted_price', 'typeId' => …]`), en ordre d'apparition et non plus triés ; `testUnknownInstallCostReasonsAreListedOnceAndSorted` change de sens (dédoublonnage par `Cost`) |
| `BuyVsBuildServiceWithoutSellStructureTest` | Garder tel quel (harnais seul) |
| `BuyVsBuildServiceTest` | Supprimer après portage des cas listés ci-dessus (mocke `IndustryTreeService`, `EsiCostIndexService`, `InventionService`) |
| `BuyVsBuildProviderTest` | Recalculer les montants #99 (4040, 3776, 1684,5, 164 050, 106 325,5, 92 225,5, 97 865,5 : formule sans taxe ni SCC aujourd'hui) ; utilisateur avec id ; tests #8 et #7 stubés : `missingData` au lieu des deux anciens champs |
| Tests Application | Aucun avec (f) recommandé. Avec l'alternative : `ProductionCostCalculatorTest`, `ProductionCost::$plan` rempli en mode plan (jobs, intermédiaires) et `null` en coût marginal |
| Frontend `tests/components/industry/buyVsBuildUnknownPrice.spec.ts`, `buyVsBuildUnknownInstallCost.spec.ts` | Fixtures : `missingData` au lieu de `missingPriceTypeIds` / `unknownInstallCostReasons` (vue-tsc vérifie les specs) ; mêmes libellés attendus. Ajouter : mention blacklist (h) toujours affichée ; libellé `invention_probability` |

Tests appelés à casser à l'implémentation, avec leur raison : tous les montants d'installation (R6 : taxe + SCC), tous les totaux du Hail L (invention), les deux CurrentlyWrong de runs (#2), l'ordre des motifs (#7), les deux tests provider stubés (contrat k).

## 6. L'implementer, tests verrouillés

1. `BuyVsBuildService` (§2, §3) ; constantes nommées : compétences d'invention, TE du job racine (sans effet sur un montant), efficacités par défaut si non exposées par le planificateur.
2. `BuyVsBuildResource` : `missingData` au résultat, `missingData` dans la forme d'un composant, suppression des deux anciens champs. `BuyVsBuildProvider::mapToResource()` suit ; la résolution des systèmes et de la structure (`:43-67`) ne change pas.
3. Frontend : `BuyVsBuildResult` / `BuyVsBuildComponent` (`stores/industry/types.ts:591-625`), `BuyVsBuildTab.vue` (motifs dérivés de `missingData` ; la dérivation de `ProfitMarginTab.vue:51-64` a alors deux appelants : l'extraire en composable), mention D13, FR/EN.
4. Docs : spec R12 (Buy vs Build en mode plan) et §7 rang 2 ; glossaire **Coût inconnu** (Buy vs Build migré) ; `decisions.md` pour (a)-(k).
5. `make test`, `make deptrac`, PHPStan, build frontend, `make infection FILTER=src/Service/Industry/BuyVsBuildService.php`.

## 7. Risques et compromis

- **Chiffres visibles** : installation plus haute (taxe + SCC), invention dans les T2, runs corrigés (#2), prix pondérés, structures de l'utilisateur prises en compte. Prévoir une ligne de note de version (ADR-0009).
- **N + 1 `costOf()`** (f) : plans courts, mais autant d'appels à `getWeightedSellPricesWithFallback` et de lectures de recettes SDE (non mémorisées). Mesurer sur un capital avant toute mémorisation.
- **Composant partagé entre profondeurs** (d) : un fuel block fabriqué au niveau 1 et acheté dans le coût d'un autre composant n'est pas mutualisé. C'est le comportement actuel, assumé ; le mutualiser relève du coût build récursif (b).
- **ME de requête ignoré pour un T2** : le provider envoie `me = 10` par défaut ; pour un produit inventé, le noyau prend le ME du BPC inventé (2 sans decryptor). Afficher le ME réellement utilisé est une amélioration frontend séparée.
- **D3c / D3d non implémentés** (#151) : Buy vs Build hérite du filtre strict actuel de `StructureSelector` ; il suivra le correctif du noyau sans changement ici.
- **Hors périmètre, signalés par l'audit** : #172 (marge null au frontend), #174 (« Créer le projet » ignore le mix), #195 (« Votre mix » recalculé au frontend), #198 (réglages non chargés). À ne pas mélanger à ce lot.
