# #121 (arbre affiché) — phase rouge

## Fichiers
- Réécrit : `tests/Unit/State/Provider/Industry/ProjectProviderGoldenTreeCharacterizationTest.php` (31 tests : 16 rouges, 15 verts) et 2 snapshots (`fernite-carbide-1run-tatara-nullsec.json`, `nomad-1run-me10-no-structure-leaves-and-job-counts.json`). Le snapshot `nitrogen-fuel-block-25runs-me10-npc.json` est identique octet pour octet à l'arbre dérivé des étapes (X6 inchangé) : non modifié.
- Déplacés dans `tests/Unit/Service/Industry/IndustryTreeServiceTest.php` (harnais `BuildsProjectOnGoldenSde`, verts) : les 5 `…MissingFromTheSde…` et `testRootWithoutRecipeAndMissingFromTheSdeStillReportsTheMissingBlueprint`.
- Nouveau support : `tests/Unit/Service/Industry/TataraAndRaitaruBonusService.php`, extrait tel quel de la classe anonyme de `IndustryProjectFactoryGoldenStepsCharacterizationTest` (qui l'utilise désormais, 51 tests verts).
- `BuildsProjectOnGoldenSde::autowired()` construit récursivement une classe `App\Service\Industry\*` absente de la liste : la phase verte peut injecter une nouvelle classe (passe extraite du builder, `ProductionTreeFromSteps`) sans toucher au harnais, verrouillé en vert.
- Front : `frontend/tests/components/industry/stepHierarchyTreeSharedIntermediate.spec.ts` (3 tests rouges). `npm run build` OK.
- Squelette `src/Service/Industry/ProductionTreeFromSteps.php` : classe vide, nécessaire à `#[CoversClass]`. Le test construit `ProjectProvider` par `autowired()` : aujourd'hui avec `IndustryTreeService` + `IndustryBlacklistService`, demain avec `ProductionTreeFromSteps` (seule nouvelle dépendance attendue, construite récursivement sur les services du harnais : repositories SDE, `IndustryBonusService`, `IndustryCalculationService`, `IndustryBlacklistService`, `TypeNameResolver`, `EntityManagerInterface`).

## Tests rouges (obtenu → attendu)
| Test | Obtenu | Attendu |
|---|---|---|
| X1 `testTreeOfThirtyTwoQuantumMicroprocessorsSplitInThreeJobsSumsTheBlacklistedMaterialJobByJob` | TC 490, Phenolic 173 (23 nœuds) | 491, 174, 16 nœuds, racine 32 runs |
| X2 `…ShowsOneNodePerIntermediateAndReferencesItsOtherConsumers` | nœud TC 490 | 491 u. 1 run ; OFB 1 nœud 25 u. 1 run, 4 références ; feuilles = liste d'achats ; 17 nœuds |
| X3 `testPurchasedStepKeepsItsNodeWithoutRunsNorMaterials` | 490, 1 run, 3 matériaux | 491, 0, [] |
| X3 `testStepWhoseNeedIsInStockKeepsItsNeedWithoutRunsNorMaterials` | 490, 1, 3 matériaux | 491, 0, [] |
| `testProductBlacklistedAfterItsStepWasPlannedIsShownAsANonBuildableMaterial` | ligne TC 490 | 491, isBuildable false |
| X4 `testTreeOfOneNomadSplitInTwoDayJobsShowsOneNodePerIntermediate` | 286 nœuds | 66 ; Hydrogen [12855, 322] + 12 réf. ; Nitrogen [4225, 106] + 7 réf. ; Ravworks +1 sur 37, 38, 40, 9832 |
| X4 `testTreeOfOneUnsplitNomadHasTheLeavesOfRavworks` | écarts (44 → +271…) | aucun écart |
| X5 `testTreeOfARootStepOfTwentyFiveRunsOfNitrogenFuelBlockShowsTwentyFiveRuns` | Uranium 4… Isotopes 405 | 90… 10125 ; [1000, 25, 40] |
| `testTreeOfAProjectWithoutStepIsNull` | arbre | null |
| `testNodeOfASplitGroupTakesTheStructureOfItsFirstJob…` | TC 490 | 489 (167+169+153), Raitaru 1.0 |
| `testNodeOfASplitGroupIgnoresTheJobsLeftWithoutRunsByTheStock` | 15 / 300 / 300 | 5 / 100 / 100 ; [22000, 1] |
| `testCycleBetweenTheProductsOfTheStepsFailsTheProject` | pas d'exception | `LogicException` (cycle) |
| `testTreeOfOneRunOfFerniteCarbideInATataraShowsOneFuelBlockNodeAndReferencesItsOtherConsumers` | 3 nœuds Hydrogen de 5 | 1 nœud [15, 1, prof. 1, Raitaru, 1.0] + ligne de référence |
| `testFuelBlockConsumedByTenRunsOfReactionInATataraGetsTheReactorRigOfTheReactionJob` (ex-Issue6) | 50 | 49 |
| `testOnlyTheNodeOfABlueprintWithACopyStepHasACopy` | false | true (Hydrogen seul) |
| `testMaterialWhoseStepWasRemovedAndBlacklistedIntermediateAreBothShownAsNonBuildableMaterials` | Fernite Alloy buildable + sous-arbre | feuille 100 |
| front (en, fr) reference row | 0 ligne `[data-testid="tree-material-reference"]` | 2 lignes : nom, ×5 / ×4, « see above » / « voir plus haut » |
| front node once | « Hydrogen Fuel Block » 1 fois | 3 fois (1 nœud + 2 références) |

Verts (garde-fous) : 2 × `…CurrentlyShowsOneRunIssue2` (#2 dans la factory), `…CurrentlyShowsTheFuelBlockStepPlannedAtHardCodedMeTenIssue15`, ME ignoré sur une réaction racine, Nitrogen ME 0, Hail L, noms SDE, intermédiaire sans recette, intermédiaire blacklisté, racine sans recette → null, type sans nom → null, mapping des étapes, 404, 401.
Supprimé : `testTreeOfOneRunOfHydrogenFuelBlockInARaitaruWithRigStacksBaseAndRigBonusesMultiplicatively` (empilement multiplicatif = `MaterialQuantityFormula`, déjà couvert ; la catégorie propre de Robotics disparaît avec R3).

## Chiffres
Attendus recoupés par un oracle jetable (hors dépôt, supprimé) qui suit la note de conception sur les étapes de la factory : X1, X2, X3, X4 identiques au tableau de `121-tree-design.md` §5 ; feuilles X2 = liste d'achats ; Nomad sans découpage = Ravworks.

## Grille a-e
- a : #2 n'apparaît que dans les deux tests `Issue2` (quantité racine retirée des assertions FC Tatara et Hail L) ; #15 seulement dans `Issue15` (matériaux du RAM retirés de Hail L, égalité d'arbre complet retirée du test ME de la réaction racine). Exception : le snapshot FC Tatara porte la quantité racine 1 (#2).
- b : données du SDE golden (valeurs distinctes par type) ; fixture front aux quantités toutes distinctes.
- c : champs lus par le front assertés (snapshots complets FC et Nitrogen ; `structureName`, `structureBonus`, `hasCopy`, `isBuildable`, `activityType`, références).
- d : snapshots manquants font échouer ; écriture seulement avec `UPDATE_SNAPSHOTS=1`.
- e : `Currently…Issue2` ×2 et `…Issue15` avec leur groupe ; les autres en `issue-121`.

## Questions / points pour la phase verte
1. Clé i18n proposée : `industry.step.seeAbove` = « see above » / « voir plus haut » ; la ligne de référence porte `data-testid="tree-material-reference"`.
2. Ordre des clés du nœud et des lignes inchangé (le snapshot compare avec `assertSame`) ; une ligne non fabricable a `activityType: null`.
3. Cycle : seul montage possible sur le SDE golden = une étape Hydrogen Fuel Block dont le blueprint est celui de Fernite Alloy (elle se consomme elle-même).
4. Plaque 41 runs : nœud Fernite Carbide = 91102 (besoin persisté) alors que la ligne sous la racine vaut 90191 (bonus Raitaru de l'étape) ; écart #6 annoncé en §4.3 de la note, non asserté.
