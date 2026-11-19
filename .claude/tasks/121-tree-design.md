# #121 — Arbre affiché dérivé des étapes (conception)

Chiffres du §5 sortis d'un prototype jetable hors dépôt : la factory et la boucle de `IndustryShoppingListBuilder` tournent sur le SDE golden.

## 1. Nœud consommé par le front
Seul lecteur : `StepHierarchyTree.vue` (vue « arbre » de `StepsTab`). `useStockAnalysis()` (`buildParentChildrenMap`, `cascadeStockToChildren`) n'a aucun appelant en prod : on n'importe que `parseEveStock` et des types. Il n'impose donc aucune contrainte.
- `productTypeId` → icône ; `productTypeName` → libellé ; `quantity` → « ×N » ; `runs` → « (N runs) » ; `activityType` → badge et `findStep` ; `blueprintTypeId` → `findStep` ; `structureName` → ligne structure (masquée si null), avec `structureBonus` « (x%) ».
- `materials[]` : `typeId` (clé du v-for), `typeName`, `quantity`, `isBuildable`, `blueprint?` (sous-arbre récursif). Une ligne `isBuildable` sans `blueprint` ne rend aujourd'hui **rien**.
- Champs non lus : `depth`, `hasCopy`, `productCategory`, `outputPerRun` et `materials[].activityType` (ces deux derniers absents du type TS). On garde la forme telle quelle.
- `findStep` renvoie la première étape de même blueprint et même activité : pour un groupe découpé, le statut et la case « Acheté » ne portent que sur le job 0 (inchangé).

## 2. Dérivation des champs
Même passe que la liste d'achats : `productionStepsByProduct`, puis `productsFromConsumersToMaterials`, avec le besoin et les runs effectifs de R4/R5 (spec 121). Cette passe est extraite du builder (une classe, deux appelants), pas recopiée.
- **Un nœud par produit** : tous ses jobs, groupe découpé fusionné. La racine est le produit du projet (`getProductTypeId()`). Les autres racines (étapes ajoutées à la main) ne sont pas affichées, comme aujourd'hui.
- `runs` = Σ des runs effectifs (pour la racine, ses runs persistés). `quantity` = besoin avant stock (Σ quantités + Δ). `outputPerRun` vient de `findProductsForBlueprints`. `blueprintTypeId` et `activityType` viennent de l'étape (job 0 par `splitIndex`). `depth` = profondeur d'affichage.
- `structureName` et `structureBonus` = `getStructureBonusForStep(job 0)` (`name`, `materialBonus.total`). `productCategory` = `getCategoryForProduct`. `hasCopy` = le projet a une étape `copy` sur ce blueprint (plus de SQL).
- `materials` : matériaux SDE du blueprint, dans l'ordre du SDE. `quantity` = Σ sur les jobs de R1(runs effectifs, ME de l'étape, bonus de l'étape) ; un job à 0 run ne compte pas. `isBuildable` = le type a une étape de production et n'est pas blacklisté. `activityType` = activité de ses étapes.
- **Intermédiaire à plusieurs consommateurs (option B)** : un seul nœud, placé sous son premier consommateur dans un parcours en profondeur. Chez ses autres consommateurs, une ligne de référence : `isBuildable: true`, sans `blueprint`, avec la quantité consommée par ce parent. `findStep` et la carte parent→enfants ne voient alors qu'un nœud par blueprint.
- **Acheté / blacklisté / en stock** : un produit blacklisté devient une ligne feuille (`isBuildable: false`), comme aujourd'hui. Une étape achetée garde son nœud (pour pouvoir décocher « Acheté »), avec `runs` 0 et `materials` vide. En stock : `runs` après déduction du stock, `quantity` = besoin ; un nœud à 0 run n'a pas de matériaux. Les étapes qui ne sont plus atteintes ne s'affichent pas.
- **Erreurs** (même sémantique qu'aujourd'hui) : racine sans recette ou sans étape → `tree` null. Type sans nom dans le SDE dans un nœud affiché → null. Intermédiaire sans recette → ligne feuille. Le `catch (\RuntimeException)` disparaît ; un cycle (`LogicException`) remonte, comme dans la liste d'achats.

## 3. Fichiers
- src : nouveau `src/Service/Industry/ProductionTreeFromSteps.php` ; passe partagée extraite de `IndustryShoppingListBuilder` (nom à valider ; ajouter « runs effectifs » au glossaire) ; `ProjectProvider`, qui n'injecte plus `IndustryTreeService` ni `IndustryBlacklistService`. `IndustryTreeService` reste intact.
- tests : `ProjectProviderGoldenTreeCharacterizationTest` réécrit sur les étapes de la factory (`BuildsProjectOnGoldenSde`). Aujourd'hui, il construit un projet sans étape.
  - Perdent `Currently` : `…Issue16`, `…Issues69And16`, `…Issue6` (la ligne Hydrogen Fuel Block suit le bonus Tatara du job, golden 49).
  - Restent des caractérisations, désormais dues aux étapes de la factory : les deux `…Issue2` et `…Issue15`. `…HaveACopy` suit la nouvelle règle de `hasCopy`.
  - Les 6 tests `typeMissingFromSdeErrorOfTree` et `testRootWithoutRecipe…` visent `IndustryTreeService` : on les déplace dans `IndustryTreeServiceTest`.
  - Les 3 snapshots `tests/Fixtures/Characterization/ProjectProvider/*.json` sont réécrits.
  - Les tests de la liste d'achats ne changent pas et restent verts : c'est le garde-fou de l'extraction.
- front : `StepHierarchyTree.vue` (rendu de la ligne de référence), une clé i18n dans `fr.json` et `en.json`, un nouveau `frontend/tests/components/industry/stepHierarchyTreeSharedIntermediate.spec.ts`. `types.ts` ne change pas.
- docs : `docs/modules/industry.md` (appelants de `IndustryTreeService`), « Hors périmètre » de la spec 121, glossaire.

## 4. À trancher par Sylvain
1. **Intermédiaire partagé** : B, nœud unique et références (recommandé) ; ou A, nœud agrégé dupliqué sous chaque consommateur, sans toucher au front, mais le Hydrogen Fuel Block du Nomad apparaîtrait 13 fois avec ses 322 runs. Maquette `/frontend-design` de la ligne de référence à faire une fois le choix fait.
2. **Étape achetée** : nœud gardé sans matériaux (recommandé, la case reste décochable) ; ou ligne feuille, comme un type blacklisté.
3. **Quantité d'une ligne matériau** : bonus de la structure de l'étape (recommandé : c'est le vrai job, = `quantity + extraQuantity` de la liste) ; ou meilleure structure (= `quantity` de la liste). Avec le bonus de l'étape, la Σ des lignes sous les consommateurs peut s'écarter du besoin persisté tant que la factory applique R3 de #69 (#6).
4. **Groupe découpé dont les jobs ont des structures différentes** : structure du job 0 (recommandé) ; ou `structureName` null.

## 5. Exemples (SDE golden, sans structure, ME 10 / TE 20)
| # | Cas | Aujourd'hui | Dérivé des étapes |
|---|---|---|---|
| X1 | Quantum Microprocessor 32 runs, durée max 0,01 j (11 + 11 + 10), Titanium Carbide blacklisté | racine 32 runs ; ligne Titanium Carbide 490 ; Phenolic Composites 173 ; 23 nœuds | racine 32 runs ; 491 ; 174 ; 16 nœuds |
| X2 | X1 sans blacklist | nœud Titanium Carbide 490 u., 1 run ; Oxygen Fuel Block : 7 nœuds de 5 u., 1 run ; feuilles Oxygen Isotopes 2835, Robotics 14, Liquid Ozone 4410 | Titanium Carbide 491 u., 1 run ; Oxygen Fuel Block : 1 nœud de 25 u., 1 run, et 4 références ; 405, 4, 1260 |
| X3 | X2, étape Titanium Carbide achetée (ou 491 en stock) | 490 u., 1 run, 3 matériaux | 491 u., 0 run, aucun matériau |
| X4 | Nomad 1 run, durée max 2 j | 286 nœuds ; Hydrogen Fuel Block 50 nœuds (345 runs) ; Nitrogen Fuel Block 21 (114 runs) ; 29 feuilles sur 63 ≠ Ravworks | 66 nœuds ; Hydrogen 1 nœud de 12855 u., 322 runs (161 + 161), et 12 références ; Nitrogen 4225 u., 106 runs, et 7 références ; 4 feuilles sur 63 à +1 (Coolant, Isogen, Megacyte, Nocxium : arrondi job par job) ; sans découpage, 0 sur 63 |
| X5 | Nitrogen Fuel Block 25 runs | 1 run, ×25, Nitrogen Isotopes 405 | étapes de la factory (1 run, 25 u.) : inchangé (#2 vient de la factory) ; étape racine de 25 runs : 25 runs, ×1000, 10125 |
