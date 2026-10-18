# Spec #97 — Production estimée du Stockpile

## Contexte

Le KPI « Production estimée » (`estOutput`) du tableau de bord Stockpile compte les produits finaux prêts. Un import d'arbre crée une cible par type, toutes rattachées au produit importé (`sourceProductTypeId`), y compris la cible du produit final lui-même. `StockpileService::computeEstOutput()` exige que toutes les cibles de la source soient remplies, donc aussi celle du produit final : un produit n'est compté prêt que s'il est déjà fabriqué et en stock, même quand tous ses matériaux sont là. Un produit final sans source (cible manuelle) compte dans le total sans pouvoir être prêt.

## Règles métier

- **R1 — Cible remplie.** Une cible est remplie quand le stock atteint au moins 100 % de sa quantité cible. Une cible de quantité 0 est remplie. Un type sans asset a un stock de 0.
- **R2 — Cibles amont.** Les cibles amont d'un produit final sont les cibles de même produit source (`sourceProductTypeId`), hors la cible du produit final lui-même.
- **R3 — Produit prêt.** Un produit final avec produit source est prêt si toutes ses cibles amont sont remplies, **ou** si sa propre cible est remplie (déjà en stock).
- **R4 — Sans cible amont.** Un produit final avec produit source mais sans aucune cible amont est prêt.
- **R5 — Sans source.** Un produit final sans source (cible manuelle) est exclu : il ne compte ni dans `ready` ni dans `total`, et n'apparaît pas dans `readyNames`.
- **R6 — Forme de la réponse.** Inchangée : `{ready, total, readyNames}`. `total` est le nombre de produits finaux avec source ; `readyNames` liste les produits prêts dans l'ordre des cibles.

## Exemples chiffrés

| # | Produit final (cible, stock) | Cibles amont (cible, stock) | Attendu |
|---|---|---|---|
| E1 | Widget A (1, 0) | Ore A (10, 10) | prêt (matériaux en stock) |
| E2 | Widget D (1, 1) | Ore D (10000, 9901) | prêt (déjà en stock) |
| E3 | Widget C (1, 1) | Ore C (1, aucun asset) | prêt (déjà en stock) |
| E4 | Widget B (2, 2) | Ore B (0, 0) | prêt |
| E5 | Manual Widget (1, 5), sans source | — | exclu du total |
| E6 | Widget E (2, 1) | Ore E (10, 9) | non prêt (stock partiel du produit, amont incomplet) |
| E7 | Widget F (1, 0) | aucune | prêt (R4) |
| E8 | Widget G (1, 0) | Ore G1 (10, 10), Ore G2 (5, 4) | non prêt |

Ensemble E1 à E5 (le jeu du test de caractérisation actuel) : `{ready: 4, total: 4, readyNames: [Widget A, Widget B, Widget C, Widget D]}` dans l'ordre des cibles, au lieu de `{ready: 1, total: 5, readyNames: [Widget B]}` aujourd'hui.

## Cas limites

- Stock du produit final juste sous la cible (99,9 %) et amont incomplet : non prêt.
- Aucune cible : `{ready: 0, total: 0, readyNames: []}` (inchangé).

## Hors périmètre

- Libellé « buildable » du KPI côté frontend (il couvre désormais aussi les produits déjà en stock) : issue séparée si besoin.
- Cible partagée entre deux produits en mode `merge` (elle garde la source du premier import).
- Comptage en unités : le KPI compte des produits, pas des unités.

## Glossaire

Termes ajoutés à [`docs/glossary.md`](../glossary.md) (section « Quantités et production ») :

- **Cible remplie** (Met target) : R1.
- **Produit source** (Source product) : `sourceProductTypeId`.
- **Cible amont** (Upstream target) : R2.
- **Produit prêt** (Ready product) : R3, R4.
