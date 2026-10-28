# Spec #69 (BOM de groupe, Stockpile) — La demande d'un intermédiaire est agrégée entre branches

**Statut : validée par Sylvain (2026-10-28).** Circuit l, deux lots dans cet ordre : Stockpile (S1 à S5), puis BOM de groupe (G1 à G7). Note de câblage : `.claude/tasks/69-wiring.md`.

## Contexte

Les étapes d'un projet agrègent la demande d'un intermédiaire depuis `69-steps-aggregate-intermediate-need.md`, et l'arbre affiché, la liste d'achats et le coût de production la lisent sur les étapes (#121). Deux appelants lisent encore `IndustryTreeService::buildProductionTree()`, une branche à la fois, runs arrondis par branche :

- la BOM Group Industry (`GroupIndustryProjectService::buildBom()` puis `flattenTree()`), qui additionne ensuite runs et quantités déjà arrondis ;
- l'import Stockpile (`StockpileService::previewImport()` et `importFromBlueprint()`, même `flattenTree()`), ainsi que la réduction par le stock de départ du statut (`StockpileStartingStockReducer`, spec #118), qui reconstruit le même arbre.

Ces trois lectures passent aussi les runs pour une quantité (#2) : 25 runs de Nitrogen Fuel Block donnent 1 run.

Aucun de ces appelants n'est branché sur le noyau. `grep -rln 'App\\Industry\\' src` ne trouve que Profit Margins, Buy vs Build, l'estimation d'installation, la taxe et le présentateur de données manquantes. `ProductionPlanner::plan()` n'est appelé que par `ProductionCostCalculator`. Les correctifs déjà mergés côté noyau (#142, #149, #151, #153, #159) servent ces appelants, pas la BOM de groupe ni le Stockpile.

## Approche retenue

Brancher les deux appelants sur le noyau (`ProductionPlanner::plan()`, mode plan), conformément aux rangs 3 et 6 de la migration (`docs/specs/industry-engine.md`, §7). Contrairement aux projets (#121), ces écrans n'ont pas d'étapes persistées ni éditées à la main : leur entrée est exactement celle du planificateur (produits, runs, ME/TE, blacklist, stock).

Approche écartée : agréger dans `flattenTree()` comme la factory l'a fait pour les étapes. On aurait une quatrième copie de l'agrégation, contraire à l'ADR-0009, et #2 resterait ouvert.

Conséquences :

- #2 est corrigé pour ces trois lectures, puisque le planificateur prend des `Runs`.
- La règle de bonus de structure devient celle du noyau (#6, décision D1).
- Les recettes viennent de `SdeBlueprintCatalog` : un blueprint sans ligne dans `sde_industry_blueprints` ou `sde_industry_activities` fait échouer le calcul.
- `IndustryTreeService` garde son appelant principal, la factory.

## Règles métier

### Commun

- **C1 — Plan.** Un seul plan par calcul, en mode plan : demande agrégée sur tout le plan, runs entiers, ME 10 / TE 20 pour un intermédiaire fabriqué, 0 / 0 pour une réaction (spec du noyau, R3). Pas de stock dans le plan, sauf en S5.
- **C2 — Structures.** Le multiplicateur matériaux d'un job est celui du noyau (`StructureMaterialModifiers`) : les structures configurées de l'utilisateur (propriétaire du projet de groupe, pilote du Stockpile), sans système favori (`FavoriteSystems::none()`), catégorie du produit du job. Sans structure : multiplicateur 1.
- **C3 — Erreurs.** Produit demandé sans blueprint ni réaction : `\RuntimeException` « No blueprint or reaction found for product type ID {typeId} » (message actuel). Type du résultat (produit, intermédiaire, feuille) sans nom dans le SDE : `TypeMissingFromSdeException` avec les typeIds manquants, par ordre croissant, sans doublon. Recette illisible (erreur de `SdeBlueprintCatalog`) : l'erreur remonte. Dans tous les cas, rien n'est écrit (BOM vide, aucune cible).

### Stockpile (lot 1)

- **S1 — Cible.** Le produit importé, ses runs (jamais une quantité) et le ME de l'import ; TE 0 (sans effet sur les quantités). Pas de blacklist (inchangé).
- **S2 — Quantités.** Produit final : `runs × unités par run`. Intermédiaire : sa **demande** (avant arrondi en runs, sans le surplus). Matière première : sa quantité de feuille.
- **S3 — Niveau.** `raw_material` : feuille. `component` : intermédiaire qui est un matériau de la recette du produit importé. `intermediate` : autre intermédiaire.
- **S4 — Aperçu = import.** `previewImport()` et `importFromBlueprint()` lisent les mêmes quantités (modes `merge` et `replace` inchangés). `totalItems` et `estimatedCost` gardent leur formule, sur ces quantités.
- **S5 — Réduction par le stock de départ (#118).** Pour chaque import, dans l'ordre de création :
  - plan complet : le plan de S1 ;
  - plan réduit : le stock du produit importé est consommé d'abord ; avec Q unités demandées et S en stock, on planifie `ceil((Q − S) / unités par run)` runs, et rien si le stock couvre tout. Le stock des intermédiaires (types qui ont une recette) est donné comme stock de départ du plan ;
  - besoin d'un type dans un plan : demande pour un intermédiaire, feuille pour une matière première (le stock d'un type ne réduit jamais sa propre cible, R4 de #118) ;
  - réduction d'un type = besoin dans le plan complet − besoin dans le plan réduit. Le stock consommé par le plan réduit (produit importé et intermédiaires) sort du réservoir commun avant l'import suivant (R3 de #118).

  Le parcours en pré-ordre (R11 de #118) est remplacé par l'ordre topologique du noyau (R9). R1, R4 à R10 de #118 sont inchangés.

### BOM Group Industry (lot 2)

- **G1 — Cibles.** Une cible du plan par item du projet : ses runs (jamais une quantité) et son ME/TE. Blacklist = types et groupes de la blacklist du projet (résolution actuelle).
- **G2 — Lignes de matériau.** Les feuilles du plan, plus les datacores des inventions (#105, inchangé). Prix Jita inchangés (#8).
- **G3 — Lignes de job de production.** Les jobs d'un item sont `final`, avec le ME/TE de l'item. Les jobs d'un intermédiaire sont `component`. Une ligne par (produit, activité, groupe), et aussi par (ME, TE) pour `final` (#23, inchangé). Les runs sont la somme des runs des jobs du plan, la quantité requise `runs × unités par run` (#107). Un item qui est aussi l'intermédiaire d'un autre item garde ses deux lignes (#106).
- **G4 — Parent.** Ligne `final` : `parentTypeId` nul. Ligne `component` : produit du premier consommateur rencontré dans un parcours en profondeur des recettes (items dans l'ordre du projet, matériaux dans l'ordre du SDE, blacklist exclue). C'est la règle d'aujourd'hui (première insertion), appliquée une fois par produit.
- **G5 — Copies et inventions.** Un produit fabriqué dont le blueprint a une activité de copie a une ligne de copie. Ses runs sont la somme des runs de ses lignes de fabrication (`final` et `component`), son parent celui de sa première ligne de fabrication. L'invention remplace la copie d'un produit T2 comme aujourd'hui (#105), sur ces runs.
- **G6 — Plafond (décision D2).** `splitJobsByMaxProductionLimit()` est inchangé : même source du plafond (`IndustryBlueprintRepository`), mêmes valeurs par défaut (#160), même répartition (jobs pleins puis reste), matériaux non recalculés. Il s'applique aux lignes agrégées de G3.
- **G7 — Ordre des lignes.** Lignes de matériau dans l'ordre des feuilles du plan ; lignes de production dans l'ordre des jobs du plan (items, puis intermédiaires) ; copies et inventions ensuite. L'ordre n'a pas de valeur métier ; il ne change pas pour un produit à un seul niveau.

## Exemples chiffrés (SDE golden, sans structure)

Probabilité d'invention de Hail L 0,34, BPC inventé de 10 runs (harnais actuel). Matériaux : `typeId => quantité`. Sans plafond dans le stub (`maxProductionLimits` vide) : valeurs par défaut 10 000 / 500 de G6.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| S-E1 | Aperçu : Fernite Carbide 1 run, ME 10 | `raw_material` : `44 => 12, 3683 => 60, 3689 => 12, 9832 => 27, 9848 => 3, 16272 => 459, 16273 => 945, 16275 => 54, 17889 => 1215`, moon materials 100 | `44 => 4, 3683 => 20, 3689 => 4, 9832 => 9, 9848 => 1, 16272 => 153, 16273 => 315, 16275 => 18, 17889 => 405`, moon materials 100 ; `component` : Hydrogen FB 15, Fernite Alloy 100, Ceramic Powder 100 (inchangé) ; 17 items |
| S-E2 | Aperçu : Nomad 1 run, ME 10 | Hydrogen FB 12960, Nitrogen FB 4260, Oxygen FB 1815, Helium FB 4265 ; `17889 => 139725`, `16273 => 201600` | `raw_material` = 63 feuilles Ravworks (`17889 => 130410`, `16273 => 182385`) ; intermédiaires = `quantityNeeded` Ravworks (12855, 4225, 1735, 4245) ; `component` inchangé ; 129 items |
| S-E3 | Aperçu : Nitrogen Fuel Block 25 runs, ME 10 (#2) | produit final 40 ; `17888 => 405` ; 10 items | produit final 1000 ; `44 => 90, 3683 => 495, 3689 => 90, 9832 => 203, 9848 => 25, 16272 => 3825, 16273 => 7875, 16275 => 450, 17888 => 10125` ; 10 items |
| S-E4 | Aperçu : Nitrogen Fuel Block 41 runs, ME 10 (#2) | produit final 80 ; `17888 => 810` | 1640 ; `44 => 148, 3683 => 812, 3689 => 148, 9832 => 333, 9848 => 41, 16272 => 6273, 16273 => 12915, 16275 => 738, 17888 => 16605` |
| S-E5 | Aperçu : Fernite Carbide 10 runs (#2) | produit final 10 000 ; Hydrogen FB 15, Fernite Alloy 100, Ceramic Powder 100 | 100 000 ; 100, 1000, 1000 |
| S-E6 | Statut : import S-E1, stock Hydrogen FB 5, rien d'autre | — | Hydrogen Isotopes (17889) : cible restante 405 (reste 10 Hydrogen FB, 1 run) ; Hydrogen FB : cible restante 15, stock 5, 33,3 %, `critical` |
| S-E7 | Statut : import S-E1, stock Hydrogen FB 15 | — | Hydrogen Isotopes et autres matériaux du fuel block : cible restante 0 |
| S-E8 | Réduction : fixtures Widget / Frame / Hull / Ore de #118 et de `StockpileStartingStockReducerTest` | — | inchangées (garde-fou ; « consommé en profondeur » donne les mêmes chiffres en ordre topologique : Ore 280 ; Ore 350 ; Frame 4 et Ore 630) |
| G-E1 | BOM : Fernite Carbide 1 run, ME 10 / TE 20 | Hydrogen FB 3 runs (120 u.), copie 3 ; matériaux comme S-E1 aujourd'hui | 1 run (40 u.), copie 1 ; matériaux comme S-E1 attendu ; lignes Fernite Carbide, Fernite Alloy, Ceramic Powder inchangées |
| G-E2 | BOM : Hail L 1 run, ME 2 / TE 4 | Hydrogen FB 3 runs, Nitrogen FB 2 runs (80 u.), copies 3 et 2 ; `44 => 24, 3683 => 120, 3689 => 24, 9832 => 54, 9848 => 6, 16272 => 918, 16273 => 1890, 16275 => 108, 17888 => 810, 17889 => 1215` | Hydrogen FB 1 run, Nitrogen FB 1 run (40 u., parent Fullerides), copies 1 et 1 ; `44 => 12, 3683 => 60, 3689 => 12, 9832 => 27, 9848 => 3, 16272 => 459, 16273 => 945, 16275 => 54, 17888 => 405, 17889 => 405` ; autres lignes, 1 tentative et datacores (1, 2) inchangés |
| G-E3 | BOM : Hail L 1 run (ME 2 / TE 4) + Fernite Carbide 1 run (ME 0 / TE 0) | Fernite Carbide `component` 1 + `final` 1 ; Hydrogen FB 6 runs, copie 6 ; Fernite Alloy 2 runs ; `17889 => 2430`, `16635 => 200, 16636 => 300, 16639 => 200, 16642 => 200` | Fernite Carbide `component` 1 + `final` 1 (inchangé) ; Hydrogen FB 1 run (demande 20), copie 1 ; Fernite Alloy 1 run (demande 200) ; matériaux identiques à G-E2 |
| G-E4 | BOM : Nitrogen Fuel Block 25 runs, ME 10 / TE 20 (#2) | 1 run, 40 u., copie 1 ; `17888 => 405` | 25 runs, 1000 u., copie 25 ; matériaux de S-E3 |
| G-E5 | BOM : Fernite Carbide 10 runs (#2) | réaction `final` 1 run, 10 000 u. | 10 runs, 100 000 u. ; Hydrogen FB 3 runs (120 u.), copie 3, Fernite Alloy 5, Ceramic Powder 5 |
| G-E6 | BOM : Hail L 10 runs, ME 2 (#2) | 1 run, 1 tentative, 1 copie T1 | 10 runs (50 000 u.) ; 3 tentatives (`ceil(10 / 3,4)`), 3 copies T1, datacores 3 et 6 |
| G-E7 | BOM : Hail L 35 000 runs, ME 2 (#2) | 7 runs, 3 tentatives, datacores 3 et 6 | lignes 10 000 + 10 000 + 10 000 + 5 000 (G6) ; 10 295 tentatives, datacores 10 295 et 20 590 |
| G-E8 | BOM : Hail L 1 run (ME 2 / TE 4) + 35 000 runs (ME 0 / TE 0) ; puis 35 000 + 35 000 | `[1, 2, 4], [7, 0, 0]`, 3 tentatives ; `[7, 0, 0], [7, 2, 4]`, 5 tentatives | `[1, 2, 4]` et 10 000 × 3 + 5 000 à `[0, 0]`, 10 295 tentatives ; deux fois 10 000 × 3 + 5 000, 20 589 tentatives (datacores 20 589 et 41 178) |
| G-E9 | BOM : Fernite Alloy 100 001 runs (#2) | 500 + 1 runs (100 000 + 200 u.) | 200 lignes de 500 runs (100 000 u.) et 1 ligne de 1 run (200 u.) |
| G-E10 | BOM : Nomad 1 run, ME 10 / TE 20, sans invention | 29 matériaux sur 63 surestimés | 63 lignes de matériau = feuilles Ravworks ; 66 jobs de production (1 `final`, 65 `component`) aux runs Ravworks |
| G-E11 | BOM : ordre des items de G-E3 inversé | — | mêmes lignes, mêmes parents (garde-fou #106) |
| G-E12 | Garde-fous à un seul niveau : Nitrogen Fuel Block 1 run (ME/TE, structure base 1 % + rig 2 % → `17888 => 393`), Fenrir 1 run (Capital Propulsion Engine 20, découpage 15 + 5), Widow 1 run (#160, 10 000 + 8 750), Hail L Crate (Hail L 5 runs, 25 000 u.), blacklists (Fernite Carbide 2940, Fullerides 1176) | — | inchangés |

Chiffres issus d'un prototype jetable du planificateur sur le SDE golden (le Nomad redonne exactement les 63 feuilles et 65 intermédiaires Ravworks). À recouper par les tests rouges.

## Cas limites

- Intermédiaire consommé par une seule branche : inchangé.
- Item blacklisté par le projet mais demandé comme item : il reste un job `final` ; ailleurs, c'est une feuille (inchangé).
- Deux items du même produit au même ME/TE : une seule ligne `final` (#23, inchangé).
- Intermédiaire dont la demande est couverte par le surplus d'un job déjà planifié : la demande s'additionne, un seul arrondi (G-E3, Fernite Alloy).
- Blueprint sans ligne dans `sde_industry_blueprints` ou `sde_industry_activities` : erreur de `SdeBlueprintCatalog` (C3), avant même G6. Les valeurs par défaut de G6 ne servent donc plus en production ; elles restent en place jusqu'à #160. Au statut Stockpile, l'import est en erreur (R7 de #118), le catalogue levant une `\UnexpectedValueException` (une `\RuntimeException`). Un `maxProductionLimit` à 0 dans le SDE donne l'`\InvalidArgumentException` de `Runs` : elle remonte.
- Utilisateur sans identifiant : impossible en production (propriétaire et pilote viennent de la base).
- Cycle dans les recettes : `\DomainException` du planificateur, remonte.

## Hors périmètre

- Plafond de runs dans le planificateur (répartition égale, matériaux recalculés job par job, comme #234) et valeurs par défaut du plafond (#160) : plus tard (D2).
- Reconstruction de la BOM qui ajoute une seconde BOM (#253), prix des lignes de job (#156).
- Arrondi au supérieur des tentatives d'invention dans la BOM (glossaire, « Tentative ») : inchangé.
- Factory, étapes, arbre affiché, liste d'achats (déjà agrégés) ; `IndustryTreeService` lui-même.
- Stockpile : blacklist, ME des intermédiaires, `StockpileShoppingList.vue`.

## Effets annoncés

- Les BOM de groupe et les cibles Stockpile créées après le déploiement baissent pour les produits à plusieurs niveaux (#69). Elles montent pour les produits à plusieurs unités par run demandés sur plusieurs runs (#2).
- Les BOM et cibles déjà en base ne bougent pas : pas de recalcul, et les contributions approuvées restent intactes.
- Le statut Stockpile d'un ancien import est recalculé avec le nouveau plan (réduction seulement).
- Pour un utilisateur avec structures, les quantités suivent la règle de bonus du noyau.

À signaler dans les notes de version. Commenter #2 sans la fermer, puisque la factory reste concernée.

## Tests existants appelés à changer

Liste exacte et raisons : `.claude/tasks/69-wiring.md`. En bref, les cinq tests `issue-69` et les tests `issue-2` des deux goldens perdent `Currently…IssueN` (exemples ci-dessus). Les harnais passent de `IndustryTreeService` aux doubles des ports du noyau.

## Décisions (Sylvain, 2026-10-28)

- **D1 (Q1 = A)** : bonus de structure du noyau (C2).
- **D2 (Q2 = B)** : découpage par le plafond inchangé, après agrégation (G6). L'alignement sur #234 et #160 viendra plus tard.
- **D3 (Q3 = A)** : la réduction du statut Stockpile migre dans le même lot que l'aperçu et l'import (S5).

## Glossaire

Ajoutés à [`docs/glossary.md`](../glossary.md) : **Parent d'une ligne de job** (G4) et **Niveau de Stockpile** (S3). Les autres termes existent : **Demande**, **Feuille**, **Intermédiaire**, **Plan**, **Ligne de BOM**, **Cible restante**, **Stock de départ**. Après le correctif, retirer `IndustryTreeService::buildProductionTree()` des symboles de **BOM**, et `IndustryTreeService::buildIntermediateTree()` de ceux de **Stock de départ** s'il n'a plus d'appelant.
