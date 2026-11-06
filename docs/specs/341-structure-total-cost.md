# Spec #341 : comparer les structures au coût total et accepter un autre système

Mode nuit : décisions de Sylvain du 2026-11-03 (coût total, option à la création avec seuil d'économie, barème de transport en constante nommée). Maquette `industry-project-v3` pages 2 et 7. Lot 1 : noyau, calculateur, option du projet, branchement du choix de structure d'un projet. Le tableau « Structures » (p=7) est le lot 2.

## Contexte

`StructureSelector` retient la structure au plus petit multiplicateur de matériaux (fabrication, réaction) ou au plus petit taux d'installation (copie, invention), après le filtre souple du système favori. Le transport n'est jamais compté : une citadelle voisine à bien meilleure taxe n'est jamais proposée. Le choix de structure d'un projet passe par `IndustryCalculationService::structureBonusOf`, code historique qui n'utilise pas le noyau.

## Règles

1. **Coût total d'une structure pour un job** (fabrication, réaction) = matériaux + installation + transport, tous en ISK, sur les runs de référence.
2. **Matériaux** : somme, sur les matériaux directs de la recette, de `quantité de base × multiplicateur de matériaux de la structure pour la catégorie du produit` (arrondi du noyau, `MaterialQuantity`) × prix unitaire du marché (`MarketPrices`). Un matériau sans prix rend le coût de la structure **inconnu**, jamais 0.
3. **Installation** : `InstallCost::forJob` avec l'EIV du job (prix ajustés ESI, `AdjustedPrices`), le cost index du système de la structure, son bonus de rôle de coût et sa taxe. Un cost index absent rend le coût inconnu.
4. **Transport** : `volume transporté (m³) × sauts × TRANSPORT_ISK_PER_CUBIC_METER_JUMP`. Volume = matériaux directs (aller, quantités de base avant ME et bonus de structure, donc identiques pour toutes les structures) + produits (retour), volumes du SDE (`ItemVolumes`). Constante nommée `StructureTotalCostComparator::TRANSPORT_ISK_PER_CUBIC_METER_JUMP = 150.0` (barème de la maquette, à confirmer en jeu : décision notée au journal).
5. **Sauts** : nombre de portes stellaires entre le système favori de l'activité (l'atelier) et le système de la structure (`JumpDistances`, graphe SDE `MapSolarSystemJump`, plus court chemin). Ansiblex hors périmètre. 0 saut dans le système favori. Système de structure inconnu ou aucun chemin : sauts **inconnus**, la structure n'est pas comparable.
6. **Runs de référence** : les runs de la cible du plan (calculateur) ou les runs de l'étape (projet). Les coûts et le seuil se lisent sur ces runs.
7. **Politique de sélection** `StructureSelectionPolicy` : `acceptsOutsideFavoriteSystem` (faux par défaut) et `minimumSaving` (Isk). Faux : comportement actuel, à l'identique (aucun appel au comparateur).
8. **Choix avec la politique active** (fabrication, réaction, aucune structure assignée, un système favori défini pour l'activité) :
   a. la référence est la meilleure structure du système favori apte à l'activité (matériaux, puis préférence), adaptée ou non à la catégorie : le filtre souple ne s'applique pas avec la politique active ;
   b. le comparateur calcule le coût total de chaque structure apte à l'activité et comparable ;
   c. si `coût(référence) − coût(meilleure) ≥ minimumSaving` (strictement positif), la meilleure est retenue, statut `BestByTotalCost`, hors système favori ou non ; sinon la référence reste ;
   d. coût de la référence inconnu, ou aucune structure comparable : la référence reste, jamais d'erreur ;
   e. égalité de coût : ordre de préférence actuel (système favori, plus récemment configurée, id).
9. **Structure assignée** : prime toujours (inchangé). Sans système favori pour l'activité : comportement actuel. Copie et invention : inchangés (plus petit taux d'installation).
10. **Option du projet** : `IndustryProject::$acceptsOutsideFavoriteSystem` (bool, faux par défaut) et `$minimumOutsideSaving` (ISK, `DEFAULT_MINIMUM_OUTSIDE_SAVING = 500000.0`, ≥ 0). Saisies à la création (`CreateProjectInput`, mêmes noms), lues dans `ProjectResource`, non modifiables ensuite en lot 1. Migration : deux colonnes avec défaut.
11. **Branchement du projet** : `structureBonusOf` et ses appelants (`getStructureBonusForStep`, `IndustryProjectFactory` pour les besoins, `TimePerRunCalculator`) reçoivent la politique du projet et les runs de l'étape ; politique inactive = code actuel inchangé. Avec la politique active, le choix passe par `StructureSelector` du noyau (structures de l'utilisateur converties par `UserProductionStructures`), et le multiplicateur de matériaux, le bonus de temps et le nom viennent de la structure retenue.
12. **Front** : formulaire de création (Industry.vue) : case « Accepter une structure hors du système préféré si elle revient moins cher, transport compris » et champ « économie minimale » (désactivé tant que la case est décochée), style HUD du formulaire existant, textes fr et en. Aucun appel Mercure (aucune action asynchrone).

## Exemples chiffrés (tests rouges du comparateur)

Job : 10 runs, 1 produit par run de 100 m³ ; matériau M 10 000 par run, 100 ISK (prix marché et ajusté), 0,01 m³ ; EIV = 10 000 000 ; volume transporté = 1 000 m³ (matériaux) + 1 000 m³ (produits) = 2 000 m³. SCC 4 %. Système favori S0 ; indice 4 % partout.

| Structure | Sauts | Multiplicateur | Taxe | Matériaux | Installation | Transport | Total |
|---|---|---|---|---|---|---|---|
| A (S0, sans rig) | 0 | 1,0 | 1 % | 10 000 000 | 900 000 | 0 | 10 900 000 |
| C (rig) | 3 | 0,9 | 0,5 % | 9 000 000 | 850 000 | 900 000 | 10 750 000 |
| D (rig) | 8 | 0,9 | 0,5 % | 9 000 000 | 850 000 | 2 400 000 | 12 250 000 |

| Cas | Politique | Résultat |
|---|---|---|
| A et C, économie 150 000 | active, seuil 100 000 | C, `BestByTotalCost` |
| idem | active, seuil 500 000 | A, statut actuel |
| idem | active, seuil 150 000 | C (seuil atteint, `≥`) |
| A et D | active, seuil 0 | A (D plus cher) |
| idem A et C | inactive | A, comparateur jamais appelé |
| C sans volume SDE pour M | active | A (C non comparable) |
| C sans chemin depuis S0 | active | A |
| prix marché de M absent | active | A (référence inconnue), pas d'exception |
| structure assignée C | active | C, `Assigned` |
| aucun système favori | active | choix actuel |
| copie ou invention | active | plus petit taux d'installation, inchangé |

Projet : création avec `acceptsOutsideFavoriteSystem=true, minimumOutsideSaving=100000` ; une étape de 10 runs prend les matériaux de C (×0,9) et la durée de C ; avec la case décochée, A.

## Cas limites

- Plusieurs unités par run, ME : les quantités passent par `MaterialQuantity` (un seul arrondi par job), pas de formule dupliquée.
- Réaction : même comparaison, indice et rigs de réaction, ME 0.
- Produit sans catégorie de rig : multiplicateur de rôle seul (déjà géré par `ProductionStructure`).
- Seuil négatif ou NaN : refusé à la création (400), `Assert\PositiveOrZero`.
- Un matériau intermédiaire fabriqué est compté comme matériau acheté au prix du marché (le comparateur ne descend pas dans l'arbre) ; sans prix : coût inconnu, référence conservée.

## Accès et fraîcheur

Aucun nouvel endpoint ni identifiant reçu. Les structures lues sont celles de l'utilisateur du projet (`UserProductionStructures::configuredBy`). Cost index, prix ajustés et prix marché : sources existantes, absents = inconnu. Volumes et graphe : SDE, statiques.

## Tests existants modifiés

Aucun attendu : politique inactive = comportement inchangé. `StructureSelectorTest` et `ProductionCostCalculatorTest` doivent rester verts sans modification.

## Hors périmètre

Tableau « Structures » de la page 7 (lot 2, nécessite une ressource API de comparaison) ; Ansiblex dans les sauts ; transport réellement payé (contrat de courrier) ; copie et invention au coût total ; modifier l'option d'un projet existant ; modèles de projet ; groupe (#362).

## Décisions ouvertes (à confirmer en jeu)

Barème de transport (150 ISK/m³/saut) ; seuil appliqué job par job sur les runs de la cible plutôt que sur le projet entier ; EIV lu dans les prix ajustés ESI.

## Glossaire

Ajouts : **Coût total d'une structure** (matériaux + installation + transport d'un job), **Transport** (volume × sauts × barème), **Politique de sélection** (option du projet : accepter une structure hors du système favori au-delà d'une économie minimale), statut de choix **BestByTotalCost**.
