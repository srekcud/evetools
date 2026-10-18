# Spec #103 — Liste d'achats : les économies comparent des totaux de même périmètre

## Contexte

`ShoppingListProvider` (liste d'achats d'un projet) calcule deux économies globales :

- `totals.savingsVsJitaWithImport = totals.jitaWithImport − totals.best` ;
- `totals.savingsVsStructure = totals.structure − totals.best`.

`totals.best` additionne le meilleur prix de tous les matériaux, y compris ceux qui n'ont qu'un prix structure (absents de `totals.jitaWithImport`) ou qu'un prix Jita (absents de `totals.structure`). Les deux soustractions mélangent donc des périmètres différents. Dans le cas figé par les tests, l'économie face à Jita vaut −30 600 ISK alors qu'aucun matériau n'est plus cher à Jita que son meilleur prix, et l'économie face à la structure est sous-estimée (23 400 au lieu de 40 500).

## Règles métier

- **R1 — Périmètre commun.** Une économie globale ne compte que les matériaux qui ont à la fois un total « Jita + import » (`jitaWithImport`) et un total structure (`structureTotal`). Un matériau qui n'a qu'un des deux prix, ou aucun, n'y entre pas.
- **R2 — Économie face à Jita.** `totals.savingsVsJitaWithImport = Σ (jitaWithImport − bestPrice)` sur ce périmètre.
- **R3 — Économie face à la structure.** `totals.savingsVsStructure = Σ (structureTotal − bestPrice)` sur ce périmètre.
- **R4 — Jamais négatif.** Sur ce périmètre, `bestPrice` est le minimum des deux totaux : chaque terme est ≥ 0, donc chaque économie est ≥ 0.
- **R5 — Périmètre vide.** Aucun matériau avec les deux prix : les deux économies valent `0.0`, comme aujourd'hui.
- **R6 — Volume inconnu (#104) inchangé.** Dès qu'un volume est inconnu, `totals.savingsVsJitaWithImport` reste `null`. `totals.savingsVsStructure` suit R3 (un matériau au volume inconnu n'a pas de `jitaWithImport`, il sort du périmètre).
- **R7 — Autres totaux inchangés.** `totals.jita`, `totals.import`, `totals.jitaWithImport`, `totals.structure`, `totals.best`, `totals.volume` et les champs par matériau ne changent pas.

## Exemples chiffrés

Harnais des tests : 1 run de Nitrogen Fuel Block. Nitrogen Isotopes (17888) 405 unités, volume 0,5 m³ ; Coolant (9832) 9 unités, volume 1,5 m³ ; Heavy Water (16272) 153 unités. Transport 1 200 ISK/m³.

| # | Situation | Prix | Attendu |
|---|---|---|---|
| E1 | Cas figé de l'issue : un matériau Jita seul, un structure seul | Isotopes Jita 500 / structure 1 200 ; Coolant Jita 100 ; Heavy Water structure 200 | périmètre = Isotopes seul (jitaWithImport 445 500, structure 486 000, best Jita 445 500) ; savingsVsJitaWithImport **0.0** (au lieu de −30 600) ; savingsVsStructure **40 500.0** (au lieu de 23 400) ; best 493 200 inchangé |
| E2 | Structure moins chère | Isotopes Jita 500 / structure 1 000 ; Coolant Jita 100 ; Heavy Water structure 200 | best Isotopes structure 405 000 ; savingsVsJitaWithImport **40 500.0** ; savingsVsStructure **0.0** |
| E3 | Deux matériaux avec les deux prix, meilleurs lieux différents | Isotopes Jita 500 / structure 1 200 ; Coolant Jita 100 / structure 1 500 | Isotopes best Jita 445 500, Coolant (jitaWithImport 17 100, structure 13 500) best structure ; savingsVsJitaWithImport 3 600.0 ; savingsVsStructure 40 500.0 |
| E4 | Jita seul partout | Isotopes Jita 500 | les deux économies 0.0 (périmètre vide) |
| E5 | Structure seule partout | Isotopes structure 1 200 | les deux économies 0.0 (périmètre vide) |
| E6 | Volume inconnu sur un matériau avec les deux prix | E1 avec le volume des Isotopes `null` | savingsVsJitaWithImport `null` ; savingsVsStructure 0.0 (Isotopes sort du périmètre, plus aucun matériau avec les deux prix) |

## Cas limites

- Échec de lecture des prix (`priceError`) : aucun prix, périmètre vide, économies 0.0 comme aujourd'hui.
- Prix égaux (jitaWithImport = structureTotal) : best Jita (règle actuelle `<=`), les deux termes valent 0.

## Hors périmètre

- La liste d'achats collée (`ParseProcessor`, `AppraiseProcessor`) : son calcul d'économie n'est pas modifié ici.
- L'affichage : le frontend du projet ne lit pas ces deux totaux (seuls les types TypeScript les déclarent).
- Les matériaux sans aucun prix qui sortent des totaux en silence (#8).

## Glossaire

Aucun terme ajouté.

## Tests impactés

- `ShoppingListProviderGoldenCharacterizationTest::testPricedShoppingListCurrentlyLeavesAMaterialWithoutPriceOutOfTheTotalsIssue8` : ses attendus −30 600 et 23 400 deviennent 0.0 et 40 500 (le test reste une caractérisation de #8 pour Robotics).
- Le test qui fige l'économie négative (ligne ~313, −30 600) est remplacé par E1.
- `testPricedShoppingListComparesJitaWithImportToTheStructureForEachMaterial` (23 400) devient 40 500.
