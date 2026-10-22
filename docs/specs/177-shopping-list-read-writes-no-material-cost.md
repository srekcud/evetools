# Spec #177 — Charger la liste d'achats n'écrit pas le coût matériaux

## Contexte

Sur `main`, `ShoppingTab.vue` (l. 308-315, `loadShoppingList()`) envoie `PATCH materialCost = roundUpToMillion(totals.best)` dès que le coût matériaux du projet est `null` ou 0 et que `totals.best > 0`. `loadShoppingList()` est appelé à l'ouverture de l'onglet, au rechargement d'un stock mémorisé (`ProjectDetail.vue:151-156`, sans ouvrir l'onglet), au collage d'un stock, et à la fin d'une sync de marché de structure. Une lecture écrit donc une donnée en silence, alors que `totals.best` ignore les matériaux sans prix et que le coût saisi prime ensuite sur l'estimation (`IndustryResourceMapper.php:153`).

Sans coût saisi, le profit du projet utilise déjà `estimatedMaterialCost` : supprimer l'écriture ne demande pas de choisir un autre lieu de calcul ni de stockage.

## Règles

- **R1 — Une lecture n'écrit pas.** Charger la liste d'achats, quel que soit le chemin, n'envoie aucune requête d'écriture sur le projet.
- **R2 — Écriture explicite seulement.** Le bouton « Appliquer comme coût matériaux » (`applyAsMaterialCost`) reste le seul chemin de la liste d'achats qui écrit `materialCost`, avec son calcul actuel.

## Exemples

Projet en cours, réponse de la liste d'achats avec `totals.best` = 123 456 789.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | `materialCost` null, onglet ouvert (`loadShoppingList`) | `PATCH materialCost 124 000 000` | aucun `PATCH` |
| E2 | `materialCost` 0, onglet ouvert | `PATCH materialCost 124 000 000` | aucun `PATCH` |
| E3 | `materialCost` null, stock mémorisé en localStorage, chargement du projet (`loadPersistedStock` + `reanalyzeStock`) | `PATCH materialCost 124 000 000` | aucun `PATCH` |
| E4 | `materialCost` null, clic sur « Appliquer comme coût matériaux », rien en stock | — | `PATCH materialCost 124 000 000` (garde-fou) |

## Lot 2 — Le bouton applique le coût total, seulement s'il est connu (décision de Sylvain, 2026-10-23, option A)

- **R3 — Montant.** « Appliquer comme coût matériaux » écrit `roundUpToMillion(totals.best)`, quel que soit le stock collé ou acheté : le coût matériaux d'un projet ne dépend pas de ce qui est déjà en stock (même base que `estimatedMaterialCost`).
- **R4 — Coût inconnu.** Si une ligne n'a pas de `bestPrice`, ou si `failedProducts` n'est pas vide (#119), le bouton est désactivé, son `title` donne la raison, et aucun `PATCH` n'est envoyé.
- **R5 — Inchangé.** Bouton masqué pour un projet terminé ou quand `totals.best` vaut 0.

Ligne A : `quantity` 100, `bestPrice` 123 456 789, `totals.best` 123 456 789.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E5 | 99 A en stock collé | `PATCH materialCost 2 000 000` | `PATCH materialCost 124 000 000` |
| E6 | 100 A en stock collé | `PATCH 124 000 000` | inchangé (garde-fou) |
| E7 | Ligne A + ligne B sans `bestPrice` | `PATCH 124 000 000` | bouton désactivé avec raison, aucun `PATCH` au clic |
| E8 | Ligne A, `failedProducts` non vide | `PATCH 124 000 000` | bouton désactivé avec raison, aucun `PATCH` |

## Hors périmètre

- Coûts matériaux déjà écrits en silence en prod : indiscernables d'une saisie, laissés tels quels (recommandation acceptée par défaut).
