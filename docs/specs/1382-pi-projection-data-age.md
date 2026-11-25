# Spec #1382 — Projection depuis la dernière vue et âge de la donnée

Mode nuit : validée en autonomie. Empilée sur #1381 (PR #1412), elle-même sur #1380 (PR #1409). Lecture seule, aucune migration, aucune sync.

## Contexte

L'ESI ne renvoie l'état d'une colonie qu'au moment où le joueur l'ouvre en jeu : `PlanetaryColony::getLastUpdate()` est cette dernière vue (`last_update` de l'ESI), `getCachedAt()` n'est que l'heure de notre synchro. Stocks et sorties affichés sont ceux de cet instant.

## Règles

1. **Âge de la donnée** : toute ligne de colonie, de launchpad et d'extraction porte `lastViewAt` (ISO 8601, `format('c')` de `lastUpdate`), `dataAgeSeconds = max(0, maintenant − lastUpdate)` (horloge `ClockInterface`) et `dataStale` = âge strictement supérieur à 86 400 s (constante nommée ; exactement 24 h n'est pas périmé).
2. **Projection du stock d'un launchpad** (`launchpads[].feeds[]`) : `projectedStock = max(0, stock − dailyRate × dataAgeSeconds / 86400)` et `emptyAt` = `lastViewAt + stock / dailyRate` jours (ISO 8601 ; null quand `dailyRate` vaut 0). Les entrées du launchpad (extracteur ou usine qui y déposent) ne sont pas modélisées : la projection ne dépasse jamais le stock de la dernière vue, ce qui est exact pour un launchpad d'import.
3. **Production d'extracteur depuis la dernière vue** : nouvelle liste `extractions[]`, une ligne par colonie qui a au moins un pin extracteur (colonie sans usine comprise) : `colonyId`, `planetId`, `planetName`, `lastViewAt`, `dataAgeSeconds`, `dataStale`, `extractors[]`.
4. **Ligne d'extracteur** (triée par `pinId`) : `pinId`, `typeId` (produit extrait), `typeName`, `expired` (échéance passée), `programEndsAt` (ISO 8601, null sans échéance), `extractedSinceLastView`.
5. **`extractedSinceLastView`** = somme des rendements (#1381, `cycleYields`) des cycles du programme dont la **fin** tombe dans `]lastUpdate, min(maintenant, échéance)]`, la fin du cycle `i` étant `installTime + (i + 1) × cycleTime`. Un cycle terminé avant la dernière vue est déjà dans le stock ; un cycle terminé exactement à `lastUpdate` n'est pas recompté. Null quand le rendement est inconnu (pas de `installTime`, d'échéance, de cycle ou de quantité, ou facteurs SDE absents) : jamais 0.
6. Nouvelle méthode `ExtractorYieldCalculator::yieldBetween(int $quantityPerCycle, int $cycleTime, ?\DateTimeImmutable $installTime, ?\DateTimeImmutable $expiryTime, \DateTimeImmutable $from, \DateTimeImmutable $to): ?float` qui porte la règle 5 ; null dans les mêmes cas que `programDailyAverage` (dont durée du programme ≤ 0).
7. La ressource `PlanetaryInputNeedsResource` gagne `extractions` ; les champs existants gardent leur nom et leur sens.

## Exemples chiffrés (maintenant = 2026-11-25 12:00, rendements du Python de la source pour 4 400 par cycle de 1 800 s)

| Cas | Entrée | Sortie |
|---|---|---|
| Âge | dernière vue 06:00 | `dataAgeSeconds` 21 600, `dataStale` false |
| Âge périmé | dernière vue 24 h exactement / 25 h avant | false / true |
| Stock projeté | stock 5 000, débit 960 /j, vue 6 h avant | 4 760 ; `emptyAt` 2026-11-30 11:00 |
| Stock épuisé | stock 100, débit 960 /j, vue 6 h avant | 0 ; `emptyAt` 2026-11-25 08:30 |
| Extraction | install 24 nov. 12:00, échéance 26 nov. 12:00, vue 25 nov. 06:00 | cycles 36 à 47 : 57 035,5843 |
| Extraction expirée | install 23 nov. 12:00, échéance 24 nov. 12:00, vue 24 nov. 06:00 | mêmes cycles 36 à 47 (fin plafonnée à l'échéance), `expired` true |
| Inconnu | extracteur sans `installTime` | `extractedSinceLastView` null |

## Hors périmètre

- Projection des sorties d'usine et des stocks de storage facility ; entrées de launchpad.
- Front (pointillés, orange au-delà de 24 h), alertes de donnée trop vieille, notifications.
- `PlanetaryProductionCalculator`, `resolveRunRates()`.

## Décisions prises en autonomie

- Une seule ressource pour besoins et projections : un seul passage sur les colonies, un seul appel du front.
- Le stock projeté d'un launchpad est un plancher (aucune entrée modélisée) : il vaut mieux sous-estimer un stock que promettre une autonomie fausse.
- Seuil « périmé » : 24 h, repris du plan (orange au-delà de 24 h).

## Tests

- Unit `ExtractorYieldCalculatorTest` : `yieldBetween` (fenêtre, cycle terminé exactement à `from`, plafond à l'échéance, inconnus).
- Unit `PlanetaryInputNeedsCalculatorTest` : âge et seuil, stock projeté et épuisé, extraction depuis la dernière vue, colonie sans usine présente dans `extractions`.
- Les fixtures de colonie des tests existants posent `lastUpdate` (maintenant).
