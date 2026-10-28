# Spec #69 (Stockpile) — La demande d'un intermédiaire est agrégée entre branches dans l'import et le statut du Stockpile

- **Statut** : validée par Sylvain (2026-10-28), réécrite le même jour pour le seul Stockpile après la livraison de #141.
- **Issue** : #69, volet Stockpile. Les autres volets sont clos :
  - étapes : `69-steps-aggregate-intermediate-need.md` ;
  - arbre affiché, liste d'achats, coût : #121 ;
  - BOM de groupe : #141, `docs/specs/141-group-bom-on-core.md`, commits `bf62bff`, `cf72fc5`, `d8f1e7e`, `cda21fb`.
- **Lot** : un seul lot, circuit l. Note de câblage : `.claude/tasks/69-wiring.md`.

## Contexte

Le Stockpile est le dernier lecteur de `IndustryTreeService::buildProductionTree()` hors de la factory. Cet arbre parcourt une branche à la fois et arrondit les runs par branche :

- l'aperçu et l'import (`StockpileService::previewImport()`, `importFromBlueprint()`) aplatissent l'arbre par `flattenTree()` et additionnent des quantités déjà arrondies ;
- la réduction par le stock de départ du statut (`StockpileStartingStockReducer::reduce()`, spec #118) reconstruit le même arbre, plus `buildIntermediateTree()` pour un intermédiaire couvert en partie.

Pour 1 run de Fernite Carbide, l'arbre compte 3 runs de Hydrogen Fuel Block au lieu d'un, et 1215 Hydrogen Isotopes au lieu de 405. Ce bug est figé par deux tests : `testPreviewOfOneRunOfFerniteCarbideCurrentlyBuildsTheFuelBlockOncePerBranchIssue69` et `testPreviewOfOneNomadCurrentlyOverestimatesTheNonAggregatedLeavesAndIntermediatesIssue69`. Les trois lectures passent aussi les runs pour une quantité (#2) : 25 runs de Nitrogen Fuel Block donnent 1 run.

Depuis #141, le noyau a un cas d'usage pour cela : `App\Industry\Application\ProductionPlanning::planOf(ProductionPlanRequest)`. Il prend les cibles, les structures de l'utilisateur, le système favori et la blacklist, puis rend un `Plan`. Il planifie en deux passes, la seconde plafonnant chaque job au `maxProductionLimit` de sa recette : répartition égale, matériaux recalculés job par job. Il n'accepte pas encore de stock de départ.

## Approche retenue

Le Stockpile passe par `ProductionPlanning`, la classe existante, sans classe parallèle. `ProductionPlanRequest` gagne un stock de départ, transmis aux deux passes ; il est vide par défaut, ce qui ne change rien pour la BOM de groupe.

Décisions de Sylvain (2026-10-28) :

- **D1** — bonus de structure : règle du noyau.
- **D2** — plafond de runs : celui que `ProductionPlanning` applique après agrégation.
- **D3** — le statut (réduction par le stock de départ) migre dans le même lot que l'aperçu et l'import.

Approche écartée : agréger dans `flattenTree()`. Ce serait une seconde copie de l'agrégation, contraire à l'ADR-0009, et #2 resterait ouvert.

## Règles métier

- **S1 — Cible.** Le plan a une seule cible : le produit importé, ses runs (jamais une quantité) et le ME de l'import. Le TE vaut 0, sans effet sur les quantités ; une réaction prend ME 0. Autres entrées du plan :
  - structures configurées du pilote, `FavoriteSystems::none()`, comme la BOM de groupe ;
  - aucune blacklist (inchangé) ;
  - aucun stock, sauf en S5.
- **S2 — Quantités.**
  - Produit importé : `runs × unités par run`.
  - Intermédiaire : sa **demande** dans le plan, avant arrondi en runs, sans le surplus.
  - Matière première : sa quantité de **feuille**.
- **S3 — Niveau de Stockpile.**
  - `final_product` : le produit importé.
  - `component` : intermédiaire consommé par un job du produit importé.
  - `intermediate` : autre intermédiaire.
  - `raw_material` : feuille.
- **S4 — Aperçu = import.** `previewImport()` et `importFromBlueprint()` lisent les mêmes quantités. Les modes `merge` et `replace` sont inchangés. `totalItems`, `estimatedCost` et `missingPriceTypeIds` (#8) gardent leur règle, appliquée à ces quantités.
- **S5 — Réduction par le stock de départ (#118).** Pour chaque import, dans l'ordre de création :
  - plan complet : le plan de S1 ;
  - plan réduit : le stock du produit importé est consommé d'abord. Avec Q unités demandées et S en stock, on planifie `ceil((Q − S) / unités par run)` runs, et rien si le stock couvre tout. Le stock des intermédiaires (types qui ont une recette) est le stock de départ du plan, consommé avant le calcul de leurs runs (R9 du noyau) ;
  - besoin d'un type dans un plan : la demande d'un intermédiaire, la feuille d'une matière première. Le stock d'un type ne réduit jamais sa propre cible (R4 de #118) ;
  - réduction d'un type : besoin dans le plan complet − besoin dans le plan réduit ;
  - le stock consommé par le plan réduit (produit importé, et `Intermediate::$consumedStock`) sort du réservoir commun avant l'import suivant (R3 de #118).

  Le parcours en pré-ordre (R11 de #118) est remplacé par l'ordre topologique du noyau. R1 et R4 à R10 de #118 sont inchangés.
- **S6 — Erreurs.**
  - Produit importé sans blueprint ni réaction : `\RuntimeException` « No blueprint or reaction found for product type ID {typeId} » (message actuel ; même conversion que la BOM de groupe).
  - Type du résultat sans nom dans le SDE : `TypeMissingFromSdeException`, typeIds manquants par ordre croissant, sans doublon.
  - Recette illisible (erreur de `SdeBlueprintCatalog`) : l'erreur remonte.
  - L'aperçu et l'import n'écrivent rien. Au statut, un import en erreur relève de R7 de #118 quand l'erreur est une `\RuntimeException`.

## Exemples chiffrés (SDE golden, sans structure, plafonds de la fixture)

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Aperçu : Fernite Carbide 1 run, ME 10 | `raw_material` : `44 => 12, 3683 => 60, 3689 => 12, 9832 => 27, 9848 => 3, 16272 => 459, 16273 => 945, 16275 => 54, 17889 => 1215`, et `16635, 16636, 16639, 16642 => 100` | `44 => 4, 3683 => 20, 3689 => 4, 9832 => 9, 9848 => 1, 16272 => 153, 16273 => 315, 16275 => 18, 17889 => 405`, matériaux de réaction à 100 ; `component` : Hydrogen FB 15, Fernite Alloy 100, Ceramic Powder 100 (inchangé) ; 17 items |
| E2 | Aperçu : Nomad 1 run, ME 10 | 29 feuilles et 19 intermédiaires surestimés (`array_replace` du test) | Feuilles et intermédiaires de Ravworks (golden `nomad-1run-me10-no-structure.ravworks.json`), sauf 5 écarts dus au plafond de D2 : Coolant (9832) 4693 au lieu de 4692 ; demande de Fernite Carbide (16673) 9 719 983, de 16679 950 906, de 16681 34 177, de 16678 2 538 073 (chacune +1). `component` inchangé ; 129 items |
| E3 | Aperçu : Nitrogen Fuel Block 25 runs, ME 10 (#2) | produit final 40 ; `17888 => 405` | 1000 ; `44 => 90, 3683 => 495, 3689 => 90, 9832 => 203, 9848 => 25, 16272 => 3825, 16273 => 7875, 16275 => 450, 17888 => 10125` ; 10 items |
| E4 | Aperçu : Nitrogen Fuel Block 41 runs, ME 10 (#2) | 80 ; `17888 => 810` | 1640 ; `44 => 148, 3683 => 812, 3689 => 148, 9832 => 333, 9848 => 41, 16272 => 6273, 16273 => 12915, 16275 => 738, 17888 => 16605` |
| E5 | Aperçu : Fernite Carbide 10 runs (#2) | 10 000 ; Hydrogen FB 15, Fernite Alloy 100, Ceramic Powder 100 | 100 000 ; 100, 1000, 1000 ; `17889 => 1215` (3 runs de Hydrogen FB) |
| E6 | Import (`merge`, `replace`) des cas E1 à E5 | quantités de l'arbre | quantités de l'aperçu (S4) |
| E7 | Statut : import E1, stock Hydrogen FB 5 | — | Hydrogen Isotopes (17889) : cible restante 405 (reste 10 Hydrogen FB, 1 run) ; Hydrogen FB : cible restante 15, stock 5, 33,3 %, `critical` |
| E8 | Statut : import E1, stock Hydrogen FB 15 | — | matériaux du fuel block : cible restante 0 |
| E9 | Réduction sur les fixtures Widget / Frame / Hull / Ore (#118, `StockpileStartingStockReducerTest`) | Ore 280 ; Ore 350 ; Frame 4 et Ore 630… | inchangées : l'ordre topologique donne les mêmes chiffres que le parcours en profondeur |

Chiffres issus d'un prototype jetable de `ProductionPlanning` (deux passes, plafond `maxProductionLimit`) sur le SDE golden. Sans plafond, le Nomad redonne exactement Ravworks. À recouper par les tests rouges.

## Cas limites

- Intermédiaire consommé par une seule branche : inchangé.
- Intermédiaire à plusieurs niveaux de profondeur : un seul niveau, `component` s'il est consommé par un job du produit importé.
- Stock d'un intermédiaire supérieur à sa demande : couvert en totalité, aucun job ni matériau en dessous, le reste du stock sert l'import suivant.
- Utilisateur sans identifiant : impossible en production (le pilote vient de la base).
- Cycle dans les recettes : `\DomainException` du planificateur, convertie comme en S6.

## Hors périmètre

- BOM de groupe (#141, livrée). Factory et étapes, arbre affiché, liste d'achats (déjà agrégés).
- Blacklist et ME des intermédiaires dans le Stockpile.
- `ProductionCostCalculator`, qui construit toujours sa propre `PlanRequest` (note de livraison de #141).

## Effets annoncés

- Les cibles importées après le déploiement baissent pour les produits à plusieurs niveaux (#69). Elles montent pour les produits à plusieurs unités par run importés sur plusieurs runs (#2).
- Les cibles déjà en base ne bougent pas. Le statut d'un ancien import recalcule seulement sa réduction, avec le nouveau plan.
- Pour un pilote qui a des structures, les quantités suivent la règle de bonus du noyau.
- À annoncer dans les notes de version. Après le lot : fermer #69, commenter #2 (la factory reste concernée).
- Pas de seed, de fixture ni de commande à lancer en production.

## Tests existants appelés à changer

Liste exacte et raisons : `.claude/tasks/69-wiring.md`. Les tests `Currently…Issue69` et `Currently…Issue2` de `StockpileGoldenImportCharacterizationTest` perdent ce suffixe et gardent leur groupe en régression. Les harnais passent de `IndustryTreeService` à `ProductionPlanning` sur les doubles des ports.

## Glossaire

- **Niveau de Stockpile** : déjà au glossaire, conforme à S3 (un matériau de la recette du produit importé est consommé par ses jobs).
- Utilisés tels quels : **Demande**, **Feuille**, **Intermédiaire**, **Plan**, **Cible restante**, **Stock de départ**.
- Après le lot : retirer `IndustryTreeService::buildIntermediateTree()` des symboles de **Stock de départ** (méthode supprimée) ; mettre à jour `docs/modules/industry.md` (`StockpileService`, `StockpileStartingStockReducer`, appelants de `IndustryTreeService`).

## Question ouverte

- **Q1 — Plafond dans le Stockpile.** `ProductionPlanning` plafonne toujours ; pour le Stockpile, cela ne change que les matériaux recalculés par job (E2 : +1 sur 5 types d'un Nomad).
  - A : l'accepter. Une seule règle avec la BOM de groupe et la factory (#234).
  - B : une option sans plafond dans `ProductionPlanRequest`, pour coller à Ravworks.

  Recommandation : A.
