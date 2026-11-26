# Spec #1386 : courbe de rendement des extracteurs, durée de relance conseillée, stock projeté d'un launchpad

Mode nuit : validée en autonomie, décisions notées en bas. « After #1380, #1381 » : branche empilée sur #1383 (PR #1423, qui porte #1385, #1384 et #1382). Maquette locale non versionnée : `pi-production-mockup-2026-11-25.html` (blocs « Rendement du programme en cours » et « Stock du launchpad, projeté »).

## Contexte

#1381 calcule le rendement décroissant d'un programme d'extracteur (`ExtractorYieldCalculator::cycleYields()`, formule CCP) mais l'écran ne le montre pas. #1380 et #1382 exposent par launchpad le volume utilisé, le débit et la projection depuis la dernière vue (`GET /planetary/input-needs`). Cette issue les dessine dans le détail déplié d'une colonie.

## Prérequis vérifiés sur la branche

- `ExtractorYieldCalculator` : `cycleYields($quantityPerCycle, $cycleTime, $cycleCount)`, `programDailyAverage()`, `yieldBetween()` existent ; les facteurs viennent de la SDE (1683, 1687), `null` s'ils sont absents.
- Le détail d'une colonie (`toDetailResource`, `mapPin`) porte `installTime`, `expiryTime`, `extractorCycleTime`, `extractorQtyPerCycle` par extracteur, mais aucune courbe.
- `input-needs` `launchpads[]` : `colonyId`, `pinId`, `capacityM3`, `usedVolumeM3`, `dailyVolumeM3`, `lastViewAt`, `dataAgeSeconds`, `dataStale`, `feeds[].emptyAt` ; déjà chargé par la page (#1383). Aucune entrée de launchpad n'est modélisée : la projection est un plancher.

## Backend (détail de colonie seulement, sans migration)

1. `ExtractorYieldCalculator::programCurve(int $quantityPerCycle, int $cycleTime, ?\DateTimeImmutable $installTime, ?\DateTimeImmutable $expiryTime): ?array` : `null` dans les cas de `programDailyAverage` (installation ou échéance absente, durée ≤ 0, cycle ≤ 0, facteurs SDE absents). Sinon `{cycleSeconds, yields, totalQuantity, averagePerHour, suggestedDays}` : `yields` = `cycleYields` du programme entier (un élément par cycle), `totalQuantity` = leur somme, `averagePerHour` = `totalQuantity / durée en heures`.
2. `suggestedDays` (`?int`) : rendement de chaque jour entier d'un programme neuf de `SUGGESTION_MAX_DAYS` (14) jours, avec la quantité par cycle de l'extracteur. On garde le plus grand `k` tel que le rendement du jour `k` vaut au moins `SUGGESTION_MARGINAL_SHARE` (0,5) fois celui du jour 1 ; les jours suivants ne comptent plus dès le premier jour sous le seuil. Minimum 1. `null` si le rendement est inconnu.
3. `mapPin` d'un extracteur gagne `yieldCurve` (la sortie de `programCurve`, `null` pour un pin qui n'est pas un extracteur ou quand la courbe est inconnue). Absent de la liste de colonies. Clé présente même nulle.

## Frontend

4. Types `Pin.yieldCurve` et `PlanetaryInputNeedsLaunchpad` complétés dans le store.
5. `PiExtractorYieldCurve.vue` (SVG, aria-label) : débit horaire par cycle sur le programme (`yield × 3600 / cycleSeconds`), droite pointillée du débit initial (« débit constant »), repère « maintenant » placé entre installation et échéance. Texte : quantité totale, moyenne par heure, écart du débit constant à la moyenne (`initial / moyenne − 1`, en %), et « relancer sur N j » quand `suggestedDays` est connu avec sa raison (le jour N rend encore au moins la moitié du jour 1). Courbe inconnue : « courbe inconnue », jamais une ligne plate.
6. `PiLaunchpadStockCurve.vue` (SVG, aria-label) depuis une ligne `launchpads[]` : volume utilisé à la dernière vue (`usedVolumeM3`), décroissance linéaire au débit `dailyVolumeM3` jusqu'à zéro (pointillé, projection), ligne de capacité (`capacityM3`), repères « dernière vue » et « maintenant », point rouge « vide <date> » à `lastViewAt + used / daily` jours. Débit nul : ligne plate, pas de point « vide », texte « non consommé ». Donnée périmée (`dataStale`) : âge en orange. Capacité ou volume `null` : « inconnu ».
7. Détail déplié de `PiColonyCard` : une courbe de rendement sous chaque extracteur, une courbe de launchpad par launchpad de la colonie (lignes `launchpads` d'input-needs par `colonyId`, passées en prop optionnelle). Aucun bloc existant retiré. i18n FR et EN (`pi.curve.*`), noms longs testés, direction HUD EVE.

## Exemples chiffrés

| Cas | Entrée | Sortie |
|---|---|---|
| Programme neuf | 4 400 par cycle de 1 800 s, 48 h | 96 cycles, `totalQuantity` = somme, moyenne `total / 48` |
| Rendement connu de #1382 | cycles 36 à 47 de ce programme | 57 035,5843 (même formule) |
| Lancement | décroissance au jour 2 ≥ 0,5 × jour 1, jour 3 < 0,5 × jour 1 | `suggestedDays` 2 |
| Launchpad | 4 000 m³ utilisés, 960 m³/j | vide 4,17 j après la dernière vue |
| Sans installation | `installTime` null | `yieldCurve` null, « courbe inconnue » |

## Tests

- PHP : `programCurve` (longueur, somme, moyenne, suggestion, inconnus), `mapPin`/détail (`yieldCurve` présent, null hors extracteur, absent de la liste).
- Front (au plus 12) : courbe de rendement (points, repère maintenant, texte, inconnue), launchpad (point vide, débit nul, périmé, inconnu), carte dépliée (une courbe par extracteur et par launchpad).

## Hors périmètre

Projection de sorties d'usine et de storage, entrées de launchpad modélisées (la projection reste un plancher), historique de stock avant la dernière vue, alertes, persistance.

## Décisions

- Durée conseillée : règle simple et réglable (jour marginal à moitié du premier jour, plafond de 14 jours), le plan ne la chiffre pas. Les constantes sont nommées pour qu'on les ajuste.
- La quantité par cycle d'un nouveau programme est supposée égale à celle du programme en cours (même placement des têtes).
- Courbe de launchpad sans historique : on ne dessine que la projection depuis la dernière vue, faute de donnée avant.
