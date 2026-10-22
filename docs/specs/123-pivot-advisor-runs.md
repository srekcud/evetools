# Spec #123 — Pivot Advisor : profit estimé et composants pour les runs demandés

## Contexte

`PivotAdvisorService::analyze(…, int $runs, …)` n'utilise `$runs` qu'une fois : `estimatedProfit = profitPerUnit × runs` (`:253`), où `profitPerUnit` est un profit **par unité**. Pour un produit qui sort plusieurs unités par run, le profit est sous-estimé d'un facteur « unités par run ». La couverture, les composants manquants et leur coût sont calculés pour **un** run quel que soit `runs` (`MaterialQuantityFormula::forJob($mat['quantity'], 1, …)`). Le front affiche ces valeurs telles quelles (`PivotAdvisorTab.vue`).

## Règles métier

- **R1 — Profit estimé.** `estimatedProfit = profitPerUnit × unités par run × runs` (profit des runs demandés). `marginPercent` et `profitPerUnit` restent par unité / par run (inchangés).
- **R2 — Composants.** Pour chaque composant fabriquable du candidat : quantité nécessaire = `MaterialQuantityFormula::forJob(quantité de base, runs, ME, …)` (au lieu de 1 run). Couverture, `missingComponents[].quantity`, `missingComponents[].cost` et `additionalCost` en découlent.
- **R3 — Score.** Inchangé dans sa formule ; il utilise la couverture de R2.
- **R4 — Inconnu.** Inchangé : un profit unitaire inconnu donne un `estimatedProfit` null ; un prix de composant inconnu une couverture et un `additionalCost` null.

## Exemples

| # | Cas | Attendu (aujourd'hui) |
|---|---|---|
| E1 | Candidat 100 unités/run, `profitPerUnit` 5, runs 10 | `estimatedProfit` 5 000 (50) |
| E2 | Candidat 1 unité/run, `profitPerUnit` 5, runs 10 | 50 (inchangé) |
| E3 | Composant fabriquable, 10 par run, ME 10, runs 10, stock 30, prix 100 | nécessaire `max(10, ⌈10 × 10 × 0,9⌉)` = 90 ; manquant 60, coût 6 000 ; couverture 33,33 % (aujourd'hui nécessaire 9 : rien de manquant, couverture 100 %) |
| E4 | Même composant, runs 1 | nécessaire 9, rien de manquant, couverture 100 % (inchangé) |
| E5 | `profitPerUnit` inconnu, runs 10 | `estimatedProfit` null |

Les valeurs exactes de `MaterialQuantityFormula::forJob` sont celles du code (`src/Service/Industry/MaterialQuantityFormula.php`) : le test-writer vérifie E3 avec elle.

## Hors périmètre

Système favori des réactions (#128, lot suivant), formule du coût d'installation, produit source (`sourceProduct`, sans runs).

## Glossaire

Termes existants : **Runs**, **Quantité**. Aucun ajout.
