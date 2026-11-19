# #115 lot 2 — phase verte (services industrie)

Worktree `evetools-wt-DT`, branche `fix/type-name-missing-from-sde-services`. Rien de commité, `tests/` intact.

## État : BLOQUÉ sur 1 test (harnais à rouvrir)

`BatchProfitScanRulesTest::testProfitableT1ModuleReportsEveryFigureRoundedToTheCent` échoue : `typeName` attendu `'Type 3831'`, obtenu `null`.

- Le stub du test (`:553-555`) répond à `resolveMany()` en inventant `'Type <id>'`, et l'assertion `assertSame` (`:93-95`) porte sur la ligne entière, `typeName` compris.
- Le rapport rouge disait « aucune assertion ne porte sur `typeName` » : c'est inexact.
- E6 impose `findNames()` dans `BatchProfitScannerService`. Le stub non configuré rend alors `[]`, donc `null`. Le code ne peut pas satisfaire à la fois E6 et ce harnais.
- Correctif proposé côté phase rouge (sans changer l'intention) : stubber `findNames` à la place de `resolveMany` dans `BatchProfitScanRulesTest` (même callback), assertion inchangée.

## Backend

- `TypeNameResolver::findNames()` : `array_map(getTypeName, findByTypeIds($typeIds))`, seuls les connus, clés préservées. `resolve()`/`resolveMany()` inchangés.
- `PivotAdvisorService` : `findNames()` (x2), `findName()` dans `emptyResult()`, `?? null` aux 6 lectures.
- `BatchProfitScannerService` : `findNames()`, `?? null`, shape `typeName: string|null`.
- `InventionService` : `findName()` pour `productName`, `blueprintName`, `productTypeName` (helper privé `resolveTypeName()` supprimé). Matériaux d'invention : je garde `InvTypeRepository::findByTypeIds()`, mais avec `($typeNames[$id] ?? null)?->getTypeName()`. Cela corrige le warning `:186`. Raison : `ProfitMarginGoldenCharacterizationTest` construit `InventionService` avec un stub `TypeNameResolver` qui ne répond qu'à `resolve()`, et un `InvTypeRepository` qui répond à `findByTypeIds()`. Passer par `findNames()` cassait son assertion `['Stub Datacore A', 'Stub Datacore B']`. R2 ne vise que `resolve()`/`resolveMany()`, ce choix reste donc conforme. La dépendance `InvTypeRepository` reste utilisée.
- `SlotTrackerService` : `findNames()`, `?? null` pour `productTypeName`. La suggestion garde son fallback sur le nom enregistré (R4). **Ajout** : `productTypeId` dans les entrées de `timeline`, nécessaire pour que le front affiche « Nom inconnu (#id) ». Aucun test ne fige la shape exacte.
- PHPDoc `string|null` : `InventionService` (materials, datacores x2, productName, copies), `BatchScanResultResource::$typeName` (`?string`, défaut `null`), `CopyCostsResource`, `BpcKitResource`. `ProfitMarginService` n'a pas besoin de changer (`analyze()` est en `array<string, mixed>`). `PivotAdvisorResource`/`SlotTrackerResource` restent en `array` non typé.

## Front (R5)

- `types.ts` : `string | null` sur les types Pivot (4), `BatchScanItem`, `CopyCostEntry`, `BpcKitDatacore`, `BpcKitInvention.productName`, `SlotTrackerJob`, `TimelineJob` (+ `productTypeId`), `ProfitMarginResult.invention.datacores`.
- `typeNameLabel()` dans PivotAdvisorTab (y compris le préremplissage « créer projet »), BatchScanTab, BpcKitTab, CostEstimationTab (copies), IndustrySlotsSection (jobs, timeline, prochain slot).
- BatchScanTab : le tri `typeName` passe par le texte affiché (`sortValue()`). L'export multibuy exclut `typeName === null`. Le libellé des favoris garde son repli `#id` existant.
- **Écart à valider** : les datacores de marge (`ProfitMarginService:191`) ne sont qu'une liste de noms, sans typeId. Le golden test fige `['Stub Datacore A', 'Stub Datacore B']`, donc impossible d'ajouter l'id sans rouvrir `tests/`. `InventionOptionsPanel` affiche donc la nouvelle clé `common.unknownName` (« Nom inconnu » / « Unknown name »), sans `#id`.
- **À noter (hors périmètre)** : BpcKitTab envoie `inventionMaterials[].typeName = null` pour un datacore inconnu, au lieu d'un faux nom. La liste de courses (`ShoppingListProvider`, ShoppingTab) relaie ce `null` sans `typeNameLabel()`. C'est à traiter au lot 4 ou ailleurs.

## Gates

| Gate | Résultat |
|---|---|
| Unit | 2722 tests, **1 échec** (ci-dessus), 1 incomplete existant, 1 notice vendor (web-push GMP), 0 warning (le `:186` a disparu) |
| Integration | 183 OK lors du premier passage (avant l'ajout de `productTypeId` à la timeline, changement couvert en Unit). Au second passage, `wt-php.sh` a refusé (exit 66) : `main` a avancé avec 31 lignes supprimées dans `migrations/`, le worktree n'y touche pas. À relancer après rebase, ou par la session principale |
| PHPStan | 61 erreurs (baseline 63), aucune sur une ligne modifiée |
| deptrac | 35 violations (baseline 36), aucune sur les fichiers touchés |
| Infection (5 fichiers PHP de service) | avant (863c3e3, suite Unit complète) 623/997 = **62,5 %** ; après 749/1035 = **72,4 %** (suite Unit moins le test bloqué) |
| vue-tsc -b | 0 erreur |
| vitest | 83/83 |

Le lien `frontend/node_modules` est supprimé et `tsconfig.tsbuildinfo` restauré. Le `var/cache/dev` a été préchauffé dans le worktree pour PHPStan (ignoré par git).

## Message de commit proposé

`fix(industry): expose an unknown name instead of "Type #id" for a type missing from the SDE in the industry analyses`
