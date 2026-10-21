# Spec #120 — Déficit de la liste d'achats Stockpile

## Contexte

La liste d'achats du Stockpile (`StockpileShoppingList.vue`, `StockpileDashboard.vue`) lit `item.deficit` pour le compteur « sous l'objectif », le filtre des lignes affichées et le texte copié. `StockpileService::getStockpileStatus()` n'envoie que `deficitCost` : `deficit` est `undefined`, le compteur vaut 0, la liste du tableau de bord est vide et la copie aussi.

## Règles métier

- **R1 — Déficit.** Chaque cible du statut porte `deficit = max(0, remainingTargetQuantity − stock)`, en unités entières. C'est la même quantité que celle qui sert à `deficitCost`.
- **R2 — Partout.** Le champ est présent sur les cibles des étapes (`stages.*.items`) et sur celles de `shoppingList` (ce sont les mêmes tableaux).
- **R3 — Liste d'achats.** Une cible est dans `shoppingList` si et seulement si son déficit est strictement positif (inchangé : `stock < remainingTargetQuantity`).
- **R4 — Copie.** Le texte copié contient une ligne `<nom>\t<déficit>` par cible de la liste filtrée dont le déficit est positif.

## Exemples chiffrés

| # | Cible | Cible restante | Stock | `deficit` | Dans `shoppingList` |
|---|---|---|---|---|---|
| E1 | Ore, cible 10 | 10 | 0 (aucun asset) | 10 | oui |
| E2 | Ore, cible 10 | 10 | 4 | 6 | oui |
| E3 | Ore, cible 10 | 10 | 10 | 0 | non |
| E4 | Ore, cible 10 | 10 | 25 | 0 | non |
| E5 | Ore sous un intermédiaire en stock partiel, cible 700 | réduite par le stock de départ (ex. 350) | 100 | 250 (pas 600) | oui |

Frontend, liste `[Ore A (déficit 10), Ore B (déficit 3)]` : compteur « sous l'objectif » = 2 ; copie = `Ore A\t10\nOre B\t3`.

## Cas limites

- Prix inconnu : `deficit` reste la quantité, indépendante du prix.
- Cible de quantité 0 : déficit 0, hors liste.

## Hors périmètre

- Prix absent compté comme 0 dans `deficitCost` (#8).
- Libellés et mise en page du composant.

## Glossaire

**Déficit** (Deficit) : quantité qui manque pour atteindre la cible restante, `max(0, cible restante − stock)`. Ajouté à `docs/glossary.md` si absent.
