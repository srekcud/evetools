# #182 — tests tueurs (revue test-skeptic)

Tous verts, aucun défaut de production trouvé. Rien de commité, `src/` intact. L1 et C6 (Q2/Q3) non touchés.

## PHP

`tests/Unit/Entity/IndustryProjectStepInStockTest.php` (classe déjà `#[Group('issue-182')]`) : 28 tests OK (22 du fournisseur E1-E11 + 6 nouveaux).
- `testNegativeStockIsStoredAsZero` : `setInStockQuantity(-5)` donne 0.
- `testStepOfNegativeStockAndQuantityZeroIsNotInStock` : stock -5 ramené à 0, quantité 0 : pas en stock (R3 ; tue un `max` retiré du setter combiné à la garde `> 0`).
- `testStockOfAStepIsComparedToItsQuantityNotToItsRuns` : runs 2, quantité 400 : stock 200 faux, stock 400 vrai.
- `testPurchasedQuantityOfAStepIgnoresAMaterialPurchasedAlongsideItsProduct` : 60 × 16656 et 500 × 4246 sur la même étape : `[60, false]`.
- `testStepWhosePurchasedQuantityExceedsItsQuantityIsInStock` : quantité 100, achat 150 : `[150, true]`.
- `testPurchaseOnOneJobOfASplitGroupLeavesTheOtherJobOutOfStock` : deux jobs du groupe `fernite-alloy-split` (1 run, 200 chacun), achat de 200 sur le job 0 : `[[true, 200], [false, 0]]` (R4, pas d'agrégation par produit).

`tests/Unit/Service/Industry/ProductionCostServiceTest.php` : 51 tests OK (51 notices PHPUnit « mock sans attente », préexistantes).
- `installCostCallOfStepB()` reçoit un paramètre `int $runsOfB = 5` (appels existants inchangés).
- `testEstimateJobInstallCostsComparesTheStockAndPurchasesOfAStepToItsQuantityNotToItsRuns` (fournisseur `stepBWhoseRunsDifferFromItsQuantityProvider`, `#[Group('issue-182')]`), assertion `[total, productTypeIds, appels]` :
  - 2 runs / 400 unités, stock 200 : compté, appel avec 2 runs, 1 500 ;
  - 2 runs / 400, stock 400 : sauté, 1 000 ;
  - 5 runs / 50, stock 5 (= runs) : compté, 1 500 ;
  - 5 runs / 50, achat de 5 du produit (= runs) : compté, 1 500 ;
  - 5 runs / 50, achat de 50 du produit : sauté, 1 000.
- `testEstimateJobInstallCostsOfAStepOfZeroRunsWhoseEstimateWouldBeUnknownStaysKnown` : B à 0 run / quantité 0, estimation de B doublée en `missing_cost_index` : `total` 1 000 (pas `null`), étape A seule, aucun appel pour B.

Commandes : `.claude/scripts/wt-php.sh <wt> unit vendor/bin/phpunit --no-coverage --fail-on-incomplete --filter IndustryProjectStepInStockTest` → OK (28 tests, 28 assertions) ; `--filter ProductionCostServiceTest` → OK (51 tests, 176 assertions).

## Vitest

- `frontend/tests/composables/useProjectTime.spec.ts`, nouveau `describe('estimated project time with the in-stock flag from the server')` : composant 4 runs × 1 200 s, racine 2 × 9 000 s.
  - `{purchasedQuantity: 60 ≥ quantity 40, inStockQuantity: 0, inStock: false}` : compté, 22 800 s.
  - `{inStock: true, inStockQuantity: 0, purchasedQuantity: 0}` : exclu, 18 000 s.
- `frontend/tests/components/industry/stepHierarchyTreeInStockStep.spec.ts` (nouveau) : arbre Fernite Carbide → Fernite Alloy (400 unités, 2 runs, profondeur 1), seule l'étape Fernite Alloy est fournie (la racine n'a donc pas de badge). Badge de statut (`span.px-2.py-1`), couleur verte, nombre de cases :
  - `{inStockQuantity 0, purchasedQuantity 400, inStock false}` : `["To launch"]`, pas vert, 1 case « Purchased ».
  - `{inStockQuantity 0, purchasedQuantity 0, inStock true}` : `["In stock"]`, vert, 0 case.

Commande : `npx vitest run tests/composables/useProjectTime.spec.ts tests/components/industry/stepHierarchyTreeInStockStep.spec.ts` → 2 fichiers, 11 tests verts. `npm run build` (vue-tsc -b + vite) : OK ; `frontend/dist/index.html` et `frontend/tsconfig.tsbuildinfo` restaurés.

## Remarque (sans défaut)

`StepHierarchyTree.vue::stepStatusLabel()` (et `StepTree.vue`) garde une branche locale `inStockQuantity > 0` → runs couverts ≥ runs → « In stock ». Avec la règle du serveur, stock ≥ quantité > 0 implique déjà `inStock: true` : pas d'écart observable, mais c'est un second calcul du statut côté écran, hors périmètre de #182.

## Message de commit proposé

```
test(industry): pin the in-stock rule of a step against runs, split jobs and zero-run steps (#182)
```
