# Lot 2 Group Industry — phase rouge (#164 ventes et bornes basses des items, #242 store des ventes)

Date : 2026-10-28. Branche `fix/group-group-industry`. Rien n'est commité.

## Fichiers

### Tests nouveaux (PHP)

| Fichier | Tests | Rouges | Verts (garde-fous) | Lancé |
|---|---|---|---|---|
| `tests/Unit/ApiResource/Input/GroupIndustry/CreateGroupProjectInputValidationTest.php` | 27 | 20 | 7 | oui |
| `tests/Unit/ApiResource/Input/GroupIndustry/RecordSaleInputValidationTest.php` | 28 | 18 | 10 | oui |
| `tests/Unit/ApiResource/Input/GroupIndustry/UpdateSaleInputValidationTest.php` | 20 | 11 | 9 | oui |
| `tests/Unit/ApiResource/Input/GroupIndustry/PropertyPathsOfViolations.php` | trait (chemins des violations, dédoublonnés) | | | |
| `tests/Unit/State/Provider/GroupIndustry/GroupSaleItemProviderTest.php` | 16 | 16 | 0 | oui |
| `tests/Unit/State/Processor/GroupIndustry/UpdateSaleProcessorTest.php` | 19 | 19 | 0 | oui |
| `tests/Unit/State/Processor/GroupIndustry/DeleteSaleProcessorTest.php` | 12 | 12 | 0 | oui |
| `tests/Unit/State/Provider/GroupIndustry/SabreBatchSales.php` | trait (vente V, projet Hecate du même propriétaire, dépôts stub, statut HTTP, payload Mercure) | | | |
| `tests/Integration/State/Processor/GroupIndustry/SaleCorrectionDistributionTest.php` | 4 (X1, X2, X3, D5) | | | **non lancé** (pas de base) |
| `tests/Functional/Api/GroupIndustry/GroupSaleApiTest.php` | 11 (pipeline HTTP : 422, ordre 403/422, PATCH, DELETE, 404) | | | **non lancé** (pas de base) |

### Tests modifiés (PHP)

- `tests/Unit/State/Processor/GroupIndustry/RecordSaleProcessorTest.php` : 4 garde-fous ajoutés (`#[Group('issue-164')]`), verts : S1 (vente Ishtar 5 × 1 200 000, total 6 000 000, `soldAt` gardé), S5 (`Z` enregistré `+00:00`), S7 (sans `soldAt` : date de l'enregistrement, fenêtre ±1 s, pas d'horloge injectable dans le processor), S13 (prix 0, total 0). Aucun test existant ne figeait les 400 de saisie (`Quantity must be positive`, `Unit price must not be negative`) : rien à réécrire de 400 en 422, conforme à la section « Tests existants appelés à changer » de la spec.

### Tests frontend

| Fichier | Tests | Rouges | Verts |
|---|---|---|---|
| `frontend/tests/stores/groupDistributionAnotherProject.spec.ts` (#242) | 14 | 10 | 4 |
| `frontend/tests/stores/groupDistributionSaleCorrection.spec.ts` (#164 R9) | 7 | 6 | 1 |
| `frontend/tests/views/GroupIndustry/groupProjectRefreshes.spec.ts` (modifié : `sale_recorded` passe en `it.each` avec `sale_updated` et `sale_deleted`) | 3 | 2 | 1 |

`npm --prefix frontend run build` passe (type-check des specs), `frontend/dist` et `frontend/tsconfig.tsbuildinfo` restaurés. Specs des composants Group Industry existantes : 24/24 vertes avec les squelettes.

## Squelettes créés dans `src/` et `frontend/src/` (à signaler)

| Fichier | Contenu |
|---|---|
| `src/ApiResource/Input/GroupIndustry/UpdateSaleInput.php` | six propriétés nullables (`typeId`, `typeName`, `quantity`, `unitPrice`, `venue`, `soldAt`), sans contrainte |
| `src/State/Provider/GroupIndustry/GroupSaleItemProvider.php` | constructeur `(Security, GroupIndustryProjectRepository, GroupIndustrySaleRepository, GroupProjectAccessChecker)`, `provide()` lève `\LogicException('not implemented')` |
| `src/State/Processor/GroupIndustry/UpdateSaleProcessor.php` | constructeur `(Security, GroupIndustryProjectRepository, GroupIndustrySaleRepository, GroupProjectAccessChecker, EntityManagerInterface, MercurePublisherService)`, `process()` lève `\LogicException` |
| `src/State/Processor/GroupIndustry/DeleteSaleProcessor.php` | même constructeur, `process(): void` lève `\LogicException` |
| `frontend/src/stores/group-industry/types.ts` | type `UpdateSaleInput` (six champs facultatifs) |
| `frontend/src/stores/group-industry/distribution.ts` | refs `loadingDistribution`, `loadingSales`, `loadingMembers` exposées mais non branchées ; actions `updateSale(projectId, saleId, input): Promise<GroupSale>` et `deleteSale(projectId, saleId): Promise<void>` qui lèvent `Error('not implemented')` |

Les signatures des constructeurs sont figées par les tests (phase verte verrouillée). `GroupIndustrySaleResource` n'a **pas** été modifiée : les opérations `Patch` et `Delete` (uriVariables `projectId` → `GroupIndustryProjectResource`, `id` → `self`, provider `GroupSaleItemProvider`, `input: UpdateSaleInput` pour le PATCH) sont à ajouter en vert ; le test fonctionnel les exige.

## Raisons d'échec observées (sorties résumées)

- Validation (49 rouges) : toutes `Failed asserting that two arrays are identical`, attendu le chemin (`items[0][runs]`, `soldAt`, `quantity`…), obtenu `[]` (aucune contrainte aujourd'hui).
- Provider et processors (47 rouges) : 33 échecs d'assertion où l'issue attendue (`granted`, `403 Only project owner or admin can perform this action`, `404`) vaut `'LogicException: not implemented'` ; 14 erreurs `LogicException: not implemented` (squelette) sur les tests qui lisent la ressource rendue ou attrapent la seule `BadRequestHttpException`.
- Suite `Unit` complète : `Tests: 13059, Errors: 14, Failures: 82, Incomplete: 1`. Les 96 en échec sont exactement les 6 nouvelles classes ; l'incomplet est `InstallCostTest` (D11a, préexistant).
- Vitest : 18 rouges. 12 `AssertionError` (données d'un autre projet gardées, `loading` éteint par le premier fetch terminé, réponse obsolète appliquée, `sale_updated` → `['bom', 'projectDetail']`, `error` resté `null`) ; 6 échecs par `Error: not implemented` (squelettes `updateSale`/`deleteSale`).

## Couverture de la spec #164

- R1 : I1, I2, I4, I5, I6, I9 (une clé manquante par data set : `typeId`, `typeName`, `meLevel`, `teLevel`, `runs`), I11, I12, I13 (Sabre 22456, ME 8, TE 16), I14 (trois chemins, triés), I15, clé en trop (`quantity` à côté de `runs`, `items[0][quantity]`), `typeName` 255/256, `typeId` texte, runs décimaux, TE −2. Garde-fous : item de base (ME 10, TE 20), runs 1, I7, I8 (TE 15 passe la validation, le 400 reste au noyau), I10. I3 est figé en caractérisation : `testItemWithOneHundredThousandRunsCurrentlyPassesTheInputValidationIssue257`, `#[Group('issue-257')]` (borne haute hors lot, Q5). Fonctionnel : I14 en 422 sans projet créé.
- R2 / S1-S17 : S2, S3, S4, S6, millisecondes, sans fuseau, S8, S9, S10 (et les autres champs requis), S11, S12, S14, S15, S16, `typeId` −1 → 422 ; garde-fous S1, S5, `+02:00`, S7, S13, quantité 1 et 2 147 483 647, longueurs 255. S17 : test existant `testAdminCannotRecordASaleOnACompletedProject`. Fonctionnel : S2 (rien persisté), S10, S11, et l'ordre « non-admin + date invalide → 422 ».
- R4/R6/R7 : C1 (200, 950 000, total 1 900 000, nom de l'auteur et `createdAt` inchangés, `sale_updated` avec `saleId`, `typeName`, `quantity`, `totalPrice`), C2 (total 2 700 000, `soldAt` changé), C3/C4 (validation de `UpdateSaleInput`, et C3 en fonctionnel avec V inchangée), C5, C6 (matrice des profils, V inchangée, ni flush ni Mercure), C7, C8, C9 (400, V inchangée, ni flush ni Mercure), C10. En plus : quantité et prix ensemble (4 × 875 000 = 3 500 000), nom et typeId corrigés sans toucher aux montants, PATCH vide, `soldAt` en `Z`.
- R5 : D1 (suppression au flush + `sale_deleted` avec `saleId`), D2 (matrice), D3, D4 ; D5 en intégration.
- R8 : X1, X2, X3 en intégration (tolérance 0,01 ISK), payouts par nom de pilote (Brann Holt 3 000 000, Cyra Lune 2 000 000 apportés).
- R9 : actions de store `updateSale` (PATCH du seul champ modifié, remplacement à sa place, message de `ValidationError` dans `error`, liste inchangée) et `deleteSale` (DELETE, retrait, ordre gardé, refus affiché) ; `groupProjectRefreshes` pour `sale_updated` et `sale_deleted`. **Composant `SalesTable.vue` non testé** : la spec R9 exige une maquette `/frontend-design` avant le développement frontend ; les tests du composant sont à écrire après elle.

## Couverture de #242

(1) chacun des trois fetchs vers un autre projet vide `distribution`, `sales`, `members`, `lineRentalRates` dès son départ ; le même projet ne vide rien (garde-fou) ; (2) séquence des quatre indicateurs sur trois fetchs résolus un à un, fetch en échec qui éteint seulement le sien ; (3) réponse tardive du projet précédent ignorée pour la distribution, les ventes et les membres, y compris quand le projet a changé par une autre ressource (ventes Sabre arrivées après `fetchDistribution(Hecate)`), et sans éteindre `loadingSales` du projet ouvert ; (4) `recordSale` (garde-fou), `updateSale`, `deleteSale`.

À signaler : `lineRentalRates` vient de `/group-industry/line-rental-rates`, barème de l'utilisateur et non du projet. Le brief demande sa remise à vide au changement de projet, c'est ce que le test fige ; il faudra que l'écran le recharge après le changement.

## Décisions de test (contraintes posées sur le vert)

- Contrôles d'accès, 404 et projet terminé testés par la chaîne provider puis processor, comme API Platform (`previous_data` passé en contexte) : le vert place le refus 400 du projet terminé où il veut. Le provider seul porte 401, 404 et 403 (R6), et le profil A13 (étranger, projet terminé) attend 403 : l'accès se contrôle avant le statut.
- 404 de vente vérifiés sur le seul code HTTP (aucun message imposé). 400 du projet terminé vérifiés sur la classe `BadRequestHttpException`.
- Dépôt des ventes stubé en `find` et en `findOneBy` (`id`, `project`) : les deux implémentations passent.
- Suppression constatée par `EntityManager::remove(V)` ou par le retrait de V de `project->getSales()` (orphanRemoval).
- Store : les échecs de `updateSale` et `deleteSale` sont tolérés qu'ils soient relancés ou non (`.catch`), seuls `sales` et `error` sont assertés.

## Exigences a-e (un seul test de caractérisation : I3)

- a. Le seul comportement figé (pas de borne haute de runs, #257) n'apparaît que dans son test ; les autres items ont 1 à 5 runs.
- b. Valeurs distinctes : Ishtar 5 × 1 200 000, Sabre 2 × 900 000, Hound 1 × 38 000 000, Hecate 3 × 41 500 000 ; contributions 3 000 000 et 2 000 000 ; dates et lieux différents par vente.
- c. Assertions exactes sur chaque champ lu : ressource de vente, entité persistée, nombre de flush, payload Mercure, chemins de violation, résumé de distribution.
- d. Aucun snapshot, aucun `markTestIncomplete`/`Skipped`.
- e. `…CurrentlyPassesTheInputValidationIssue257` + `#[Group('issue-257')]`.

## Recherche des tests qui figent les formes touchées

`grep -rn "Quantity must be positive\|Unit price must not\|sale_recorded\|useGroupDistributionStore\|RecordSaleInput\|CreateGroupProjectInput" tests frontend/tests` et `grep -rn "Currently.*Issue\(164\|242\|257\)\|CARACTÉRISATION" tests` : seul `groupProjectRefreshes.spec.ts` touchait `sale_*` (modifié) ; `CreateGroupProjectProcessorTest` appelle le processor sans validateur (inchangé, I10 y reste couvert) ; `memberTableAdminActions.spec.ts` écrit `store.members` et espionne `fetchMembers` (non concerné). Aucun test existant ne lit `store.loading`.

## Non lancés

- `SaleCorrectionDistributionTest` (Integration) et `GroupSaleApiTest` (Functional) : pas de base cette nuit. `php -l` OK sur les deux.

## Dette de test notée

- `SaleCorrectionDistributionTest` reprend les aides de `GroupDistributionProviderTest` (`addMember`, `addBomItem`, `addApprovedContribution`, `addSale`) ; une extraction en trait toucherait ce test existant, laissée hors lot.

## Message de commit proposé

```
test(group-industry): specify the sale and item input bounds, the sale correction and deletion, and the per-project sales store (#164, #242)
```
