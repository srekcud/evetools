# Spec #106 — Group Industry : un item du projet aussi intermédiaire garde son job final

## Contexte

`GroupIndustryProjectService::buildBom()` aplatit l'arbre de production de chaque item du projet et agrège les jobs par clé `produit:activité` (`GroupIndustryProjectService.php:159-184`). Seul le premier passage fixe `jobGroup`, `parentTypeId`, `meLevel` et `teLevel` ; les passages suivants ajoutent leurs runs.

Quand un produit est à la fois item du projet et intermédiaire d'un autre item, il n'a donc qu'une ligne de job. Si l'intermédiaire est parcouru en premier, la ligne reste `component` avec le parent de l'autre item, et le job final disparaît. Si l'item est parcouru en premier, la ligne reste `final` et absorbe les runs de l'intermédiaire. Le résultat dépend de l'ordre des items.

## Règles métier

- **R1 — Job final distinct.** Un item du projet a toujours sa ligne de job `final` : runs de l'item, `parentTypeId = null`, ME et TE d'un job final (ceux d'aujourd'hui pour la profondeur 0).
- **R2 — Besoin d'intermédiaire distinct.** Le même produit consommé comme intermédiaire par un autre item a sa propre ligne `component` : runs de l'intermédiaire, parent et ME/TE d'un intermédiaire, comme si l'item n'existait pas.
- **R3 — Clé d'agrégation.** Les jobs de production s'agrègent par produit, activité et groupe (`final` ou `component`). Deux items du même produit partagent toujours une seule ligne `final` ; deux besoins du même intermédiaire partagent toujours une seule ligne `component`.
- **R4 — Ordre des items.** La BOM (lignes de job, matériaux) est la même quel que soit l'ordre des items dans le projet, à l'ordre des lignes près.
- **R5 — Matériaux.** Les matériaux de la BOM sont inchangés : la somme des deux arbres, comme aujourd'hui.
- **R6 — Découpage.** Le découpage par `maxProductionLimit` s'applique à chaque ligne séparément.

## Exemples chiffrés

Colonnes : produit, activité, groupe, runs, ME, TE, parent.

| # | Projet | Attendu |
|---|---|---|
| E1 | Hail L (1 run, ME 2) puis Fernite Carbide (1 run, ME 0) | Fernite Carbide : deux lignes de réaction, `final` 1 run ME 0 TE 0 parent `null`, et `component` 1 run ME 0 TE 0 parent Hail L. Lignes `final` du projet : Hail L et Fernite Carbide |
| E2 | Fernite Carbide (1 run) puis Hail L (1 run) | Mêmes lignes de job et mêmes matériaux qu'E1 |
| E3 | Hail L (1 run) puis Nitrogen Fuel Block (1 run, ME 10), fabriqué et intermédiaire des Fullerides | Nitrogen Fuel Block : `final` 1 run ME 0 TE 0 parent `null`, et `component` avec les runs, ME 10 / TE 20 et le parent Fullerides de la BOM de Hail L seul |
| E4 | Hail L seul (garde-fou) | BOM inchangée |
| E5 | Deux items Nitrogen Fuel Block (garde-fou) | une seule ligne `final` de 2 runs, comme aujourd'hui |
| E6 | Hail L puis Fernite Carbide, matériaux | matériaux identiques à aujourd'hui (Fernite Carbide n'est pas un matériau) |

## Cas limites

- Ligne `final` ou `component` au-dessus du `maxProductionLimit` : découpée seule (R6).

## Hors périmètre

- La copie de blueprint (clé `produit:copy`) reste une seule ligne `blueprint`, runs additionnés, parent du premier passage.
- Un intermédiaire consommé par deux parents garde le parent du premier passage.
- L'agrégation de la demande d'un intermédiaire entre branches (#69), le ME/TE du job final (#23), `requiredQuantity` en runs (#107), l'invention des T2 (#105).
- Les BOM déjà enregistrées : aucune migration, la règle s'applique aux BOM générées après le correctif.

## Glossaire

Aucun terme ajouté. « Job final » et « intermédiaire » au sens du glossaire ; les groupes `final` / `component` / `blueprint` sont les valeurs existantes de `GroupIndustryBomItem::$jobGroup`.

## Tests impactés

- `GroupIndustryBomGoldenCharacterizationTest::testProjectItemAlsoNeededAsAnIntermediateCurrentlyLosesItsFinalJobGroup` fige le bug et est remplacé par E1. Ses assertions sur Hydrogen Fuel Block (6 runs) et Fernite Alloy (2 runs) relèvent de #69 et restent inchangées.
