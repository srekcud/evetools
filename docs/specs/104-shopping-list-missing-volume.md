# Spec #104 — Liste d'achats : un volume manquant est inconnu, jamais 0 m³

## Contexte

`ShoppingListProvider` (liste d'achats d'un projet, `GET /industry/projects/{id}/shopping-list`) lit le volume de chaque matériau dans `invTypes`. Un volume `null`, ou un type absent de `invTypes`, devient `0.0` (`ShoppingListProvider.php:91` et `:128`). Le matériau compte alors 0 m³ : son coût d'import vaut 0, son total « Jita + import » est sous-estimé, Jita peut être choisi à tort comme meilleur lieu d'achat, et le volume total à transporter l'ignore. Rien ne le signale.

Le glossaire pose la règle : une donnée manquante n'est jamais remplacée par 0, et une somme qui en contient une est inconnue.

## Règles métier

- **R1 — Volume inconnu.** Un matériau dont le volume est `null` dans `invTypes`, ou dont le type est absent de `invTypes`, a un volume inconnu. Un volume de `0.0` présent dans le SDE reste un volume connu.
- **R2 — Ligne.** Pour un matériau au volume inconnu : `volume`, `totalVolume`, `importCost` et `jitaWithImport` valent `null`. `jitaUnitPrice`, `jitaTotal`, `structureUnitPrice`, `structureTotal` et les prix Jita pondérés sont inchangés.
- **R3 — Meilleur lieu d'achat.** Sans `jitaWithImport`, Jita n'est jamais choisi. Avec un prix structure : `bestLocation = structure`, `bestPrice = structureTotal`, `savings = null` (aucune comparaison possible). Sans prix structure : `bestLocation`, `bestPrice` et `savings` valent `null`.
- **R4 — Totaux.** Dès qu'au moins un matériau a un volume inconnu, `totals.volume`, `totals.import`, `totals.jitaWithImport` et `totals.savingsVsJitaWithImport` valent `null`. `totals.jita`, `totals.structure`, `totals.best` et `totals.savingsVsStructure` sont calculés comme aujourd'hui (le meilleur prix d'un matériau au volume inconnu suit R3).
- **R5 — Signalement.** La réponse porte `missingVolumeTypeIds` : les typeIds au volume inconnu, chacun une fois, triés par ordre croissant ; `[]` quand tous les volumes sont connus.
- **R6 — Frontend.** L'onglet liste d'achats du projet affiche « inconnu » à la place d'un volume, d'un coût d'import ou d'un total « Jita + import » `null`, et un avertissement qui nomme les types sans volume. Aucun calcul front ne traite `null` comme 0.

## Exemples chiffrés

Transport à 1 200 ISK/m³. Nitrogen Isotopes (17888) : 100 unités, Jita 500 ISK, volume SDE 0,03 m³ sauf mention contraire. Matériau B : 10 unités, volume 1 m³, Jita 1 000 ISK.

| # | Situation | Entrée | Attendu |
|---|---|---|---|
| E1 | Volume connu (garde-fou) | 17888 volume 0,03, Jita seul | volume 0.03, totalVolume 3.0, importCost 3 600.0, jitaWithImport 53 600.0, best Jita, `missingVolumeTypeIds = []` |
| E2 | Volume `null`, Jita seul | 17888 volume `null` | volume, totalVolume, importCost, jitaWithImport `null` ; jitaTotal 50 000.0 ; best `null` ; totals.volume, totals.import, totals.jitaWithImport, totals.savingsVsJitaWithImport `null` ; totals.jita 50 000.0 ; `missingVolumeTypeIds = [17888]` |
| E3 | Type absent de `invTypes` | 17888 absent, Jita seul | identique à E2 |
| E4 | Volume `null`, Jita et structure | 17888 volume `null`, structure 450 | bestLocation structure, bestPrice 45 000.0, savings `null` ; totals.best 45 000.0, totals.structure 45 000.0, totals.savingsVsStructure 0.0 |
| E5 | Volume `null` alors que Jita + import serait moins cher | 17888 volume `null`, Jita 400, structure 450 | bestLocation structure (jamais Jita sans import), bestPrice 45 000.0 |
| E6 | Un matériau connu, un inconnu | 17888 volume `null` ; B volume 1 | ligne B inchangée (totalVolume 10.0, importCost 12 000.0, jitaWithImport 22 000.0) ; totals.volume, totals.import, totals.jitaWithImport `null` ; totals.jita 60 000.0 ; `missingVolumeTypeIds = [17888]` |
| E7 | Volume `0.0` dans le SDE (garde-fou) | 17888 volume 0.0 | volume 0.0, importCost 0.0, jitaWithImport 50 000.0, `missingVolumeTypeIds = []` |
| E8 | Même type sur deux lignes | 17888 volume `null` sur une ligne de fabrication et une ligne de matériau d'invention | les deux lignes inconnues ; 17888 une seule fois dans `missingVolumeTypeIds` |
| E9 | Plusieurs types inconnus | volumes `null` pour 17888 et un type 34 | `missingVolumeTypeIds = [34, 17888]` |

## Cas limites

- Échec de lecture des prix (`priceError`) avec un volume inconnu : R2 et R5 s'appliquent, best `null` comme aujourd'hui.
- Liste vide : totaux à 0.0 comme aujourd'hui, `missingVolumeTypeIds = []`.

## Hors périmètre

- La liste d'achats collée (`ParseProcessor`, `/shopping-list/parse`, Appraisal) a le même défaut (`ParseProcessor.php:84`, `:109`) : issue séparée.
- Le périmètre différent de `totals.best` et `totals.jitaWithImport` (#103).
- `BatchProfitScannerService` et tout autre usage du volume.

## Glossaire

Aucun terme ajouté : « Donnée manquante » et « Coût inconnu » couvrent le cas (le volume est une donnée manquante, le coût d'import un coût inconnu).

## Tests impactés

- `ShoppingListProviderGoldenCharacterizationTest::testPricedShoppingListCurrentlyCountsAMissingSdeVolumeAsZero` fige le bug et est remplacé par E2.
- Les types TypeScript `ShoppingListItem` et `ShoppingListTotals` (`frontend/src/stores/industry/types.ts`) passent les champs concernés en `number | null` et ajoutent `missingVolumeTypeIds`.
