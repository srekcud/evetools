# Spec #17 — Une seule formule de quantité de matériau hors du noyau

## Contexte

La formule `max(runs, ceil(round(base × runs × ME × structure × rig, 2)))` est écrite en plusieurs exemplaires : `IndustryCalculationService::calculateMaterialQuantity()` (référence), une copie en ligne dans `IndustryTreeService` (l.~162-167), et une version sans bonus de structure ni `round(…, 2)` dans `BatchProfitScannerService` (l.~306) et `PivotAdvisorService` (l.~168). Sans `round(…, 2)`, le bruit flottant peut faire monter une quantité d'une unité.

## Règles métier

- **R1 — Une seule fonction.** Hors du noyau `src/Industry/Domain`, toute quantité de matériau d'un job est calculée par `IndustryCalculationService::calculateMaterialQuantity()`.
- **R2 — Scanner et Pivot Advisor.** Leur calcul « 1 run » devient `calculateMaterialQuantity(base, 1, ME, 0, 0)`, avec leur ME actuel (2 pour un T2, 0 pour une réaction, 10 sinon). Seul l'arrondi change : `round(…, 2)` avant `ceil`, et le minimum `max(runs, …)` = 1.
- **R3 — Arbre.** `IndustryTreeService` appelle la même fonction avec son ME effectif (0 si l'ME ne s'applique pas) et ses bonus de structure et de rig ; aucun changement de valeur.

## Exemples chiffrés

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Scanner, base et ME dont le produit flottant dépasse un entier d'un epsilon (valeur à trouver en phase rouge) | entier + 1 | entier |
| E2 | Pivot Advisor, même cas | entier + 1 | entier |
| E3 | Scanner et Pivot, cas sans bruit (ex. base 33, ME 10 → 29,7) | 30 | 30 (garde-fou) |
| E4 | Arbre : goldens existants (Nomad, Fernite Carbide, Hail L) | inchangés | inchangés (garde-fou) |
| E5 | Réaction dans le scanner (ME 0) | base | base (garde-fou) |

## Hors périmètre

- `src/Industry/Domain/MaterialQuantity` (noyau, déjà sa propre formule testée).
- Règles de bonus de structure divergentes (#6).
- ME par défaut du scanner et du Pivot Advisor.

## Décision prise en autonomie

- Le noyau garde sa formule : il remplacera ces services (industry-engine §7), le converger maintenant créerait une dépendance de `src/Industry/Domain` vers un service Symfony.

## Glossaire

Aucun terme ajouté.
