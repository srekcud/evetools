# #118 — Note de câblage (étape 1b de /tdd-red)

Spec : `docs/specs/118-stockpile-intermediate-partial-stock.md`. Précédent : #20 (`IndustryShoppingListBuilder::collectRawMaterials()`, l. 179-217).

## 1. Dépendances de l'implémentation cible

| Dépendance | Changement cible | Couche (deptrac) |
|---|---|---|
| `App\Entity\IndustryStockpileImport` (nouvelle) | `id` UUID, `user` (ManyToOne, `onDelete: CASCADE`), `productTypeId` int, `runs` int, `me` int, `createdAt` immutable. Table `industry_stockpile_imports`, index `user_id`. | Entity |
| `App\Repository\IndustryStockpileImportRepository` (nouveau) | `findByUserInCreationOrder(User): list<IndustryStockpileImport>` (`createdAt ASC`), `deleteAllForUser(User): void` (calque de `IndustryStockpileTargetRepository::deleteAllForUser`). | Repository |
| Migration `migrations/Version2026101912xxxx.php` | `CREATE TABLE industry_stockpile_imports` + FK + index. Pas de reprise des anciens imports (R6). À exécuter en prod après déploiement. | — |
| `StockpileService::__construct` | + `IndustryStockpileImportRepository $importRepository`, + `StockpileStartingStockReducer $reducer` (voir § 2). | Service |
| `StockpileService::importFromBlueprint()` | `replace` : `importRepository->deleteAllForUser($user)` à côté de `targetRepository->deleteAllForUser($user)` (avant le premier `flush`). Les deux modes : `persist(new IndustryStockpileImport(user, typeId, runs, me))` puis cibles inchangées, un seul `flush` final. | Service |
| `StockpileService::getStockpileStatus()` | Charge les imports, appelle le réducteur avec le stock (`$assetQuantities`), calcule `remaining = max(0, targetQty − reduction[typeId])` pour les cibles **avec** produit source porté par un import ; `remaining = targetQty` sinon. Remplace `targetQty` par `remaining` dans : percent, status, `deficitCost`, `shoppingList` (`stock < remaining`), santé des étapes, `pipelineHealth`, goulot, `computeEstOutput()` (qui reçoit les `remaining` par typeId au lieu d'appeler `isMetBy`). Ajoute `remainingTargetQuantity` à chaque item, `importsWithoutPlan` et `failedProducts` au statut (y compris la branche « aucune cible » : `[]`). | Service |
| `IndustryStockpileTarget::isMetBy()` | Inchangé (compare à la quantité cible). Le service compare à la cible restante sans passer par lui ; pas de nouvelle méthode d'entité nécessaire. | Entity |
| `IndustryTreeService::buildProductionTree()` | **Inchangé.** Utilisé pour l'arbre complet `(productTypeId, runs, me, [], $user)` et pour le produit racine couvert en partie `(productTypeId, Q − S, me, [], $user)`. Forme du nœud lue : `quantity`, `runs`, `outputPerRun`, `materials[]` ; matériau : `typeId`, `quantity`, `isBuildable`, `blueprint` (sous-nœud). | Service |
| `IndustryTreeService::buildIntermediateTree()` | **Inchangé.** Utilisé pour un intermédiaire couvert en partie `(typeId, Q − S, [], $user)` : ME 10 en fabrication, 0 en réaction, comme dans l'arbre complet (`intermediateMeLevelFor`). | Service |
| `CachedAssetRepository::getQuantitiesByUserWithLocations()` | **Inchangé.** Les `total` par typeId déjà lus forment le réservoir de stock (R3). | Repository |
| `StockpileStatusResource` | + `public array $importsWithoutPlan = []` (`list<int>`), + `public array $failedProducts = []` (`list<array{typeId: int, reason: string}>`). PHPDoc des items : `remainingTargetQuantity`. | ApiResource |
| `StockpileStatusProvider` | Recopie `importsWithoutPlan` et `failedProducts`. | StateProvider |
| `StockpileImportProcessor` | **Inchangé** (passe déjà par `importFromBlueprint`). | StateProcessor |
| `frontend/src/stores/industry/types.ts` | `StockpileItemStatus.remainingTargetQuantity: number` ; `StockpileShoppingItem.remainingTargetQuantity: number` ; `StockpileStatus.importsWithoutPlan: number[]`, `failedProducts: StockpileFailedProduct[]` (`{typeId, reason}`, même forme que `ShoppingListFailedProduct`, réutilisable). | Front |
| `frontend/src/stores/industry/stockpile.ts` | Aucun mapping à changer a priori (`fetchStockpileStatus` stocke la réponse brute) ; à confirmer à l'implémentation. | Front |
| `StockpileColumn.vue` (l. 151) | `stock / cible` devient `stock / restante / cible` quand `remainingTargetQuantity !== targetQuantity` (voir question Q3). | Front |
| `StockpileShoppingList.vue` (l. 151) | Colonne « Need » sur `remainingTargetQuantity`. | Front |
| `StockpileDashboard.vue` | Monte l'avertissement (nouveau composant `StockpileImportWarnings.vue`, calqué sur `shopping/ShoppingFailedProductsWarning.vue`) avec `importsWithoutPlan` et `failedProducts`. Noms de produits : `typeName` des cibles `final_product` dont `typeId` = produit, sinon le typeId. | Front |
| `IndustryDashboard.vue` (l. 185-187) | Alerte stockpile critique : `target` = `remainingTargetQuantity` (R5). | Front |
| `frontend/src/i18n/locales/{en,fr}.json` | Clés de l'avertissement (réimporter / produit en erreur). | Front |

## 2. Fourniture par le harnais

### Placement de l'algorithme de réduction

Classe dédiée `final class App\Service\Industry\StockpileStartingStockReducer` (couche `Service`), injectée dans `StockpileService` :

```php
/** @param list<IndustryStockpileImport> $imports  @param array<int, int> $stockByTypeId
 *  @return array{reductionByTypeId: array<int, int>, failedProducts: list<array{typeId: int, reason: string}>} */
public function reduce(array $imports, array $stockByTypeId, User $user): array
```

- Pas dans `src/Industry/Domain` : l'algorithme lit l'arbre tableau legacy et rappelle `IndustryTreeService` ; `IndustryDomain: ~` interdit tout import, et ADR-0009 interdit aux dossiers plats d'importer les couches Industry. Le `ProductionPlanner` du noyau gère déjà R9 (`PlanRequest::$startingStock`) mais avec demande agrégée (#69, hors périmètre) : l'utiliser changerait les quantités. La migration du Stockpile vers le noyau reste le rang 3 du strangler (§ 7).
- Classe séparée plutôt que méthodes privées : `StockpileService` fait déjà 400 lignes, et le réducteur se teste seul (sans prix, sans cibles) sur le même harnais.
- Algorithme : par import (ordre de création), arbre complet ; parcours en profondeur comme #20 sur une **copie** du réservoir ; produit racine compris ; `S ≥ Q` → pas de descente, `0 < S < Q` → sous-arbre reconstruit (`buildProductionTree` pour la racine avec le ME de l'import, `buildIntermediateTree` pour un intermédiaire). Besoin d'un type = somme des quantités **demandées** des nœuds de ce type (avant consommation, donc le stock d'un type ne réduit pas sa propre cible, R4). Réduction = besoin complet − besoin réduit. Si l'arbre complet ou une reconstruction lève `\RuntimeException` (`TypeMissingFromSdeException` en hérite) : import en erreur, copie du réservoir abandonnée (R7), sinon la copie remplace le réservoir.

### Harnais recommandé : vrai `IndustryTreeService` sur repositories SDE en mémoire

Copie réduite de `StockpileGoldenImportCharacterizationTest::treeServiceOnGoldenSde()` (l. 351-397) avec une table de recettes inline au lieu du JSON golden : Widget (fabrication, 1/run, 10 Frame), Frame (**réaction**, 1/run, 70 Ore ; variante E5 : 2/run, 140 Ore), Ore sans producteur. Mocks : `IndustryActivityProductRepository::findBlueprintForProduct`, `IndustryActivityMaterialRepository::findBy`, `InvTypeRepository::find`, `IndustryBonusService` sans structure, `EntityManagerInterface::getConnection()->fetchOne() = 0`.

Justification : il exerce la vraie reconstruction (`buildIntermediateTree` vs `buildProductionTree`, ME, arrondi par run, message d'erreur exact d'E10) sans base, pour ~40 lignes déjà écrites ailleurs ; un stub d'`IndustryTreeService` devrait réimplémenter ces règles et laisserait passer un mauvais appel de reconstruction.

Frame doit être une **réaction** dans le fixture : en fabrication, `buildIntermediateTree`/`buildNode` appliquent ME 10 et l'import donnerait 630 Ore au lieu des 700 de la spec (voir Q1).

| Dépendance | Fourniture |
|---|---|
| `IndustryStockpileTargetRepository` | mock, `findByUser` → liste en mémoire (comme `StockpileStatusCharacterizationTest`) |
| `IndustryStockpileImportRepository` | mock, `findByUserInCreationOrder` → liste en mémoire d'`IndustryStockpileImport` (ordre = ordre de création) ; `deleteAllForUser` espionné pour E11 |
| `CachedAssetRepository` | mock, `getQuantitiesByUserWithLocations` → stock du cas |
| `JitaMarketService` | mock, prix Widget 1000, Frame 50, Ore 2 |
| `IndustryTreeService` | réel, sur SDE en mémoire (ci-dessus) |
| `StockpileStartingStockReducer` | réel, construit avec le même `IndustryTreeService` |
| `EntityManagerInterface` | mock ; `persist` capturé pour E11 (une ligne d'import par appel) |
| `Security` (provider, E12) | stub, `getUser` → `User` mock |

Emplacements des nouveaux tests :
- `tests/Unit/Service/Industry/StockpileStartingStockReducerTest.php` : réductions et `failedProducts` d'E1, E5, E7, E8, E10, ME > 0, réservoir non consommé par un import en erreur.
- `tests/Unit/Service/Industry/StockpileStatusIntermediateStockTest.php` : E1-E10 de bout en bout via `getStockpileStatus()` (cible restante, statut, déficit, liste d'achats, `estOutput`, `pipelineHealth`, goulot, `importsWithoutPlan`).
- E11 : `StockpileServiceTest` (imports `replace`/`merge`).
- E12 : `StockpileStatusProviderTest`.

## 3. Tests existants appelés à casser

Constructeur de `StockpileService` (+2 paramètres) — échec de construction, tous les tests de ces fichiers :

1. `tests/Unit/Service/Industry/StockpileServiceTest.php` (15 tests) — `setUp` l. 41.
2. `tests/Unit/Service/Industry/StockpileStatusCharacterizationTest.php` (30 tests) — `setUp` l. 62.
3. `tests/Unit/Service/Industry/StockpileGoldenImportCharacterizationTest.php` (13 tests) — `setUp` l. 85.
4. `tests/Unit/State/Provider/Industry/StockpileStatusProviderTest.php` (2 tests) — `setUp` l. 47.

Changement de comportement voulu (à réécrire, au-delà du constructeur) :

5. `StockpileStatusCharacterizationTest::testPartialStockOfAnIntermediateDoesNotReduceTheTargetsBelowItIssue20` — remplacé par E1. Sans import enregistré il resterait vert (R6) : il doit être **supprimé**, pas adapté.
6. `StockpileStatusCharacterizationTest::testStatusWithoutTargetsReturnsEmptyStagesAndFullHealth` — `assertSame` sur le tableau complet : + `importsWithoutPlan => []`, `failedProducts => []`.
7. `StockpileStatusCharacterizationTest::testItemCarriesTheStockValueAndTheDeficitCostAtTheJitaPriceRoundedToTwoDecimals` — `assertSame` sur l'item complet : + `remainingTargetQuantity`.
8. `StockpileStatusProviderTest::testExposesTheStockpileStatusOfTheConnectedPilot` — item complet (+ `remainingTargetQuantity`) ; la cible porte la source 4051 sans import → `importsWithoutPlan = [4051]` à asserter (R6).
9. `StockpileGoldenImportCharacterizationTest::testImportInReplaceModeDeletesTheTargetsOfThePilotBeforePersistingTheTree` — séquence exacte `['delete', 'flush', 10×'persist', 'flush']` : devient 11 `persist` (ligne d'import) et une suppression d'imports.
10. `StockpileGoldenImportCharacterizationTest::testImportOfNitrogenFuelBlockInMergeModeAddsTheTreeQuantitiesToTheExistingTargetAndPersistsTheOthers` — `array_map(fn (IndustryStockpileTarget $t) …, $persisted)` : l'`IndustryStockpileImport` persisté lève un `TypeError` ; filtrer par classe et asserter la ligne d'import.

Non cassés : `IndustryStockpileTargetTest` (`isMetBy` inchangé), `SlotTrackerServiceTest` (n'utilise que le repository des cibles), les autres tests de statut (cibles avec source sans import → R6, quantités inchangées ; ils n'assertent pas le tableau complet). Aucun test de `StockpileImportProcessor` n'existe.

Total : 4 fichiers cassés par le constructeur (60 tests), dont 6 tests à réécrire pour le comportement (5 à 10).

## 4. Tests front (E13)

Aucun test vitest sur le Stockpile aujourd'hui. Patron : `frontend/tests/components/industry/shoppingFailedProductsWarning.spec.ts` (mount + i18n `en`).

- `frontend/tests/components/industry/stockpileColumnRemainingTarget.spec.ts` : `StockpileColumn` avec les items d'E1 → Ore affiche `350 / 700`, Frame affiche `10` sans barre oblique supplémentaire.
- `frontend/tests/components/industry/stockpileImportWarnings.spec.ts` : `StockpileImportWarnings` → E9 (Widget dans `importsWithoutPlan`, texte « réimporter »), E10 (raison brute affichée en texte, pas en HTML), rien quand les deux listes sont vides.
- Optionnel : `stockpileShoppingListRemainingTarget.spec.ts` (colonne Need sur la cible restante).

## 5. Questions de spec ouvertes

- **Q1 — Activité de Frame.** Avec Frame en fabrication, le vrai arbre applique ME 10 aux intermédiaires (630 Ore, pas 700). Proposition : Frame = réaction dans le fixture ; à acter dans la spec.
- **Q2 — Racine couverte en partie.** `buildProductionTree($typeId, $runs)` lit les runs comme une quantité (#2). Reconstruire la racine pour `Q − S` avec `Q` = quantité du nœud racine reste cohérent avec #2 ; aucun exemple ne le couvre (E4 = couverture totale). Ajouter un exemple (2 runs de Widget, stock Widget 1 → Ore 700) ?
- **Q3 — Affichage R9.** La colonne affiche aujourd'hui `stock / cible` (`0 / 700`). « La cible Ore affiche `350 / 700` » : remplacer la cible seule (`0 / 350 / 700`) ou toute la ligne ? À trancher avec la maquette.
- **Q4 — Imports orphelins.** « Cible supprimée : l'import reste » : un import dont toutes les cibles ont été supprimées (ou après « Clear all », qui supprime les cibles une à une sans toucher aux imports) continue de consommer le réservoir avant les imports suivants. Voulu, ou « Clear all » doit-il vider les imports ?
- **Q5 — Ordre « topologique ».** #20 consomme en profondeur d'abord (pré-ordre). Même parcours ici ? E8 ne les distingue pas.
- **Q6 — Bug annexe hors périmètre.** `StockpileShoppingList.vue` lit `item.deficit` (l. 30, 61, 153), jamais envoyé par le backend : compteur et copie toujours vides. À ouvrir en issue séparée ou à inclure ?
