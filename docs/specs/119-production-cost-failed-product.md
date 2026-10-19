# Spec #119 — Coût de production : un produit en erreur rend le coût matériaux inconnu

## Contexte

`ProductionCostService::estimateMaterialCost()` lit seulement `materials` du retour de `IndustryShoppingListBuilder::getShoppingList()`. Depuis #20, un **produit en erreur** (arbre de production impossible à construire : aucun blueprint ni réaction, type absent du SDE) est porté par `failedProducts` et ses matériaux sont absents de la liste. Le coût l'ignore : il sort du coût en silence, et un projet dont le seul produit est en erreur a un coût matériaux de 0 ISK.

Appelants :

- `CostEstimationProvider` (`GET /industry/projects/{id}/cost-estimation`, via `estimateTotalCost()`), affiché par `CostEstimationTab.vue` ;
- `CreateProjectProcessor` (estimation figée à la création, `IndustryProject::$estimatedMaterialCost`, déjà nullable).

Règles cibles : spec du noyau `docs/specs/industry-engine.md` § 5 (« jamais un produit retiré en silence ») et **Coût inconnu** du glossaire (« jamais remplacé par 0, une somme qui en contient un est inconnue »).

## Règles métier

- **R1 — Coût matériaux inconnu.** Si `failedProducts` n'est pas vide, `estimateMaterialCost()` renvoie `total = null`. Les matériaux des produits calculables restent listés (`items`, inchangés), et le résultat porte `failedProducts` (`{typeId, reason}`, repris tels quels de la liste d'achats, même ordre).
- **R2 — Sans produit en erreur, inchangé.** `failedProducts = []`, `total` chiffré comme aujourd'hui.
- **R3 — Somme inconnue.** Dans `estimateTotalCost()`, un coût matériaux inconnu rend `totalCost` et `perUnit` inconnus (`null`). `jobInstallCost` et `bpoCost` restent chiffrés. `failedProducts` est exposé.
- **R4 — API.** `GET /industry/projects/{id}/cost-estimation` : `materialCost`, `totalCost`, `perUnit` deviennent nullables ; nouveau champ `failedProducts` (`[]` sans erreur).
- **R5 — Estimation à la création.** `CreateProjectProcessor` laisse `estimatedMaterialCost` à `null` quand le coût matériaux est inconnu (jamais 0).
- **R6 — Frontend.** `CostEstimationTab` :
  - coût matériaux, coût total et coût par unité inconnus affichés « Unknown » / « Inconnu », jamais 0 ;
  - un avertissement liste les produits en erreur avec leur raison (même présentation que l'onglet liste d'achats, `ShoppingFailedProductsWarning` réutilisé ou généralisé) ;
  - profit, marge et répartition en pourcentage non calculés quand le total est inconnu ;
  - le bouton qui enregistre les coûts dans le projet n'écrit pas un coût matériaux inconnu (désactivé).

## Exemples chiffrés

Harnais de `ProductionCostServiceTest` (mocks) : 2 runs d'un produit, Pyerite (35) à 7,25 ISK.

| # | Situation | Attendu |
|---|---|---|
| E1 | liste d'achats : 1 200 Pyerite + Robotics (9848) en erreur (`No blueprint or reaction found for product type ID 9848`) | `estimateMaterialCost` : `total = null`, `items` = [Pyerite 1 200 × 7,25 = 8 700], `failedProducts = [{9848, raison}]` (remplace le test `…CurrentlyLeavesTheFailedProductsOfTheShoppingListSilentlyOutOfTheCostIssue119`) |
| E2 | seul produit en erreur, aucun matériau | `total = null`, `items = []`, `failedProducts` porte le produit |
| E3 | aucun produit en erreur (garde-fou) | `total = 8 700`, `failedProducts = []` |
| E4 | `estimateTotalCost`, cas E1, job install 5 000, BPO 1 000 | `materialCost = null`, `totalCost = null`, `perUnit = null`, `jobInstallCost = 5 000`, `bpoCost = 1 000`, `failedProducts` porté |
| E5 | `estimateTotalCost` sans erreur (garde-fou) | totaux inchangés, `failedProducts = []` |
| E6 | provider : réponse API du cas E4 | `materialCost`, `totalCost`, `perUnit` à `null`, `failedProducts` présent ; `[]` sans erreur |
| E7 | création de projet, coût matériaux inconnu | `estimatedMaterialCost = null` |
| E8 | front, réponse du cas E6 | « Unknown » pour coût matériaux, total et par unité ; avertissement avec nom et raison du produit ; pas de profit ni de marge ; bouton d'enregistrement désactivé |
| E9 | front, réponse sans erreur (garde-fou) | affichage inchangé, pas d'avertissement |

## Cas limites

- Plusieurs produits en erreur : tous listés, dans l'ordre de la liste d'achats (typeId croissant).
- Le nom du produit en erreur peut manquer (type absent du SDE) : l'avertissement affiche le typeId, comme dans la liste d'achats.

## Hors périmètre

- Prix de matériau manquant remplacé par 0 (`$prices[$typeId] ?? 0.0`) : #8.
- Coût d'installation faux ou à 0 quand une donnée manque : #7.
- `ProjectTable.vue` (tri et colonne « est. ») : il gère déjà un `estimatedMaterialCost` nul.
- Profit Margins (`ProfitMarginTab`) : n'appelle pas ce service.

## Glossaire

Pas de nouveau terme. « Produit en erreur » : ajouter `ProductionCostService::estimateMaterialCost()` et `CostEstimationResource::$failedProducts` aux symboles ; « Coût inconnu » : citer le coût matériaux d'un projet avec un produit en erreur (#119).

## Tests impactés

- `ProductionCostServiceTest::testEstimateMaterialCostCurrentlyLeavesTheFailedProductsOfTheShoppingListSilentlyOutOfTheCostIssue119` → remplacé par E1.
- Les tests existants de `ProductionCostServiceTest` et `CreateProjectProcessorTest` qui mockent `getShoppingList()` ou `estimateMaterialCost()` sans `failedProducts` : à compléter avec `failedProducts: []` si la forme du retour l'exige.
