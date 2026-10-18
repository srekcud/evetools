# Spec #107 — Group Industry : l'avancement d'une ligne de job se compte en unités

## Contexte

`GroupIndustryProjectService::buildBom()` enregistre `requiredQuantity = runs` sur chaque ligne de job de la BOM (`GroupIndustryProjectService.php:128`). Le glossaire réserve « quantité » aux unités, et `GroupIndustryContribution::$quantity` y est défini en unités. Tout l'avancement d'une ligne (`fulfilledQuantity`, `getRemainingQuantity()`, `isFulfilled()`, quantité pré-remplie dans la modale de contribution) suit `requiredQuantity` : il est donc aujourd'hui en runs pour les jobs, en unités pour les matériaux.

## Règles métier

- **R1 — Ligne de production.** Pour une ligne de job de fabrication ou de réaction, `requiredQuantity = runs × unités par run` du produit (`outputPerRun` de l'arbre). `runs` est inchangé.
- **R2 — Ligne découpée.** Après découpage par `maxProductionLimit`, chaque ligne porte `requiredQuantity = runs de la ligne × unités par run`.
- **R3 — Avancement.** `fulfilledQuantity`, le reste et `isFulfilled` d'une ligne de production sont en unités ; une contribution sur une ligne de production se saisit en unités produites. Aucun changement de code au-delà de R1 n'est nécessaire pour cela.
- **R4 — Copie.** Les lignes `copy` (groupe `blueprint`) gardent `requiredQuantity = runs`.
- **R5 — Projets existants.** Une migration Doctrine passe en unités les lignes de production déjà enregistrées : `required_quantity` et `fulfilled_quantity` de la ligne, et `quantity` de chaque contribution rattachée à la ligne, multipliés par les unités par run (`sde_industry_activity_products.quantity`, activité 1 pour la fabrication, 11 pour la réaction, `product_type_id` = type de la ligne). Les lignes de matériaux et de copie ne bougent pas.

## Exemples chiffrés

| # | Situation | Attendu |
|---|---|---|
| E1 | Nitrogen Fuel Block, 1 run (40 unités par run), ligne `final` | runs 1, requiredQuantity 40 |
| E2 | Réaction Fernite Carbide, 10 runs du projet (caractérisation actuelle : 1 run), 10 000 unités par run | requiredQuantity = runs × 10 000 |
| E3 | Intermédiaire Hydrogen Fuel Block, 3 runs (40 unités par run) | requiredQuantity 120 |
| E4 | Capital Propulsion Engine, 20 runs découpés 15 + 5 (1 unité par run) | requiredQuantity 15 et 5 |
| E5 | Ligne de copie (garde-fou) | requiredQuantity = runs |
| E6 | Ligne de matériau (garde-fou) | requiredQuantity inchangé |
| E7 | Ligne de 1 run à 40 unités, contribution approuvée de 40 | `isFulfilled = true`, reste 0 |

Les unités par run exactes des exemples sont celles du harnais de `GroupIndustryBomGoldenCharacterizationTest`.

## Cas limites

- Migration : une ligne dont le produit n'a aucune ligne, ou plusieurs lignes de quantités différentes, dans `sde_industry_activity_products` pour l'activité concernée reste inchangée.

## Hors périmètre

- Les lignes de copie et l'invention (#105).
- `submitAutoDetected` (jamais appelé) et la valeur estimée des contributions.
- Les runs des lignes (#2, #69).

## Décision prise en autonomie

- Migration (R5) : quand les unités par run ne sont pas uniques pour un produit et une activité, la ligne n'est pas migrée plutôt que de choisir une valeur (option la plus conservatrice).
- Frontend : aucun libellé ne présente l'avancement d'une ligne de job en runs ; la modale pré-remplit le reste, qui devient en unités sans changement. Pas de modification front.

## Glossaire

Aucun terme ajouté : « Quantité » (unités) et « Unités par run » couvrent la règle.

## Tests impactés

- Les assertions de `GroupIndustryBomGoldenCharacterizationTest` sur la colonne `requiredQuantity` des lignes de production (aujourd'hui égale aux runs).
