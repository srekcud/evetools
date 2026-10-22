# Spec #127 — Pas de coût d'installation pour une étape achetée ou en stock

## Contexte

`ProductionCostService::estimateJobInstallCosts()` compte le coût d'installation de toutes les étapes fabrication/réaction, y compris celles marquées achetées (`IndustryProjectStep::isPurchased()`) ou entièrement en stock (`isInStock()` : stock ≥ quantité). Ces étapes ne lancent aucun job : la liste d'achats compte déjà l'intermédiaire acheté, et le front les exclut de la durée (`useProjectTime.ts:38`). Le coût de production est surestimé.

## Règles métier

- **R1.** Une étape achetée ou entièrement en stock n'a pas de job : elle n'apparaît pas dans `steps` de `estimateJobInstallCosts()` et n'entre pas dans `total`, ni dans `jobInstallCost`/`totalCost` de `estimateTotalCost()`, ni dans le snapshot `estimatedJobCost` à la création.
- **R2.** Elle ne rend jamais le total inconnu (pas d'estimation demandée pour elle).
- **R3.** Une étape partiellement en stock reste comptée comme aujourd'hui (runs de l'étape) : hors périmètre.

## Exemples chiffrés

`ProductionCostServiceTest` (`estimateJobInstallCost` stubbé) :

| # | Cas | Attendu |
|---|---|---|
| E1 | Étapes A (1 000) et B (500), B achetée | `total` 1 000, une seule étape (A), estimation jamais demandée pour B |
| E2 | Idem, B entièrement en stock | `total` 1 000, une seule étape |
| E3 | B en stock partiel (stock < quantité) | `total` 1 500, deux étapes |
| E4 | B achetée et dans un système sans indice (estimation inconnue si demandée) | `total` 1 000, connu |
| E5 | `estimateTotalCost`, matériaux 10 000, A 1 000, B achetée | `jobInstallCost` 1 000, `totalCost` 11 000 |

## Hors périmètre

Liste d'achats (thread #173/#177), stock partiel, durée.

## Glossaire

Pas de terme dédié pour « étape achetée » ni « étape en stock » (symboles `IndustryProjectStep::isPurchased()`, `isInStock()`) ; aucun ajout dans ce lot.
