# Spec #23 — Group Industry : le job final porte le ME et le TE de l'item du projet

## Contexte

`GroupIndustryProjectService::flattenTree()` enregistre la ligne de job `final` (profondeur 0) en ME 0 / TE 0, quel que soit le ME/TE saisi sur l'item du projet (`GroupIndustryProjectService.php`, `meLevel` et `teLevel` de profondeur 0). Les matériaux de la BOM, eux, sont déjà calculés avec le ME de l'item (`finalMe` passé à `IndustryTreeService::buildProductionTree()`). Seuls le ME et le TE affichés sur la ligne du job final sont faux : le membre qui prend le job voit ME 0 / TE 0 alors que le projet attend un blueprint recherché.

## Règles métier

- **R1 — Fabrication.** La ligne `final` d'un item fabriqué porte `meLevel` = ME de l'item et `teLevel` = TE de l'item.
- **R2 — Réaction.** La ligne `final` d'un item réagi reste en ME 0 / TE 0 (une formule de réaction n'a ni ME ni TE).
- **R3 — Items du même produit à des ME/TE différents.** Deux items du même produit et de la même activité partagent une ligne `final` seulement s'ils ont le même ME et le même TE ; sinon chacun a sa ligne `final` (un job ne s'installe qu'à un seul ME/TE).
- **R4 — Inchangé.** Matériaux de la BOM, lignes `component` (ME 10 / TE 20, réactions 0 / 0) et lignes de copie.

## Exemples chiffrés

Colonnes : produit, activité, groupe, runs, ME, TE.

| # | Projet | Attendu |
|---|---|---|
| E1 | Nitrogen Fuel Block, 1 run, ME 10, TE 20 | ligne `final` ME 10, TE 20 ; matériaux inchangés |
| E2 | Nitrogen Fuel Block, 1 run, ME 10, TE 0 | ligne `final` ME 10, TE 0 ; matériaux identiques à E1 |
| E3 | Hail L, 1 run, ME 2, TE 4 | ligne `final` ME 2, TE 4 |
| E4 | Fernite Carbide (réaction), ME 0, TE 0 saisis | ligne `final` ME 0, TE 0 |
| E5 | Deux items Nitrogen Fuel Block 1 run, ME 10/TE 20 et ME 0/TE 0 | deux lignes `final` d'1 run : ME 10/TE 20 et ME 0/TE 0 ; matériaux inchangés (405 + 450 Nitrogen Isotopes) |
| E6 | Deux items Nitrogen Fuel Block 1 run, tous deux ME 10/TE 20 | une seule ligne `final` de 2 runs, ME 10/TE 20 |

## Hors périmètre

- Le TE n'a aucun effet sur la BOM (pas de durée en Group Industry).
- ME/TE des intermédiaires (10 / 20 en dur), à traiter avec le noyau Industry.
- Les BOM déjà enregistrées : aucune migration.

## Décision prise en autonomie

- L'issue demandait de confirmer s'il s'agissait d'un choix voulu : le job final suit le ME/TE saisi, puisque les matériaux de la BOM le suivent déjà (option cohérente avec l'existant).
- R3 : séparer les lignes `final` de ME/TE différents plutôt que garder le ME/TE du premier item ou en choisir un, pour ne jamais afficher un ME/TE qui ne correspond pas aux matériaux comptés.

## Glossaire

Aucun terme ajouté.

## Tests impactés

- `GroupIndustryBomGoldenCharacterizationTest` : `testTeOfTheProjectItemCurrentlyLeavesTheBomUnchangedIssue23` (remplacé par E1/E2), `testSameProductOnTwoItemsCurrentlySharesOneFinalJob` (remplacé par E5), assertions ME/TE 0/0 des lignes `final` de fabrication (Hail L, Nitrogen Fuel Block).
- `GroupIndustryProjectServiceTest` (`:461-477` à l'audit) si elle fige le ME 0 / TE 0 du job final.
