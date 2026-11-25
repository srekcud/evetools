# Spec #1381 — Rendement décroissant des extracteurs (formule CCP)

Mode nuit : validée en autonomie. Empilée sur #1380 (PR #1409). Aucune migration, aucun nouvel appel ESI.

## Source de la formule (primaire)

Documentation développeur hébergée par CCP, dépôt `esi/esi-docs`, page « Planetary Industry » > « Extraction calculation » : https://developers.eveonline.com/docs/guides/pi/ (snippets C#, Kotlin, Python identiques). Les facteurs sont les attributs Dogma 1683 `ecuDecayFactor` (défaut 0,012) et 1687 `ecuNoiseFactor` (défaut 0,8), lus dans `sde_dgm_attribute_types.default_value`. La page précise : « These constants are the defaults in dgmAttributeTypes. They may change », donc lecture SDE, jamais de constante en dur. Lue le 2026-11-25 ; la page se dit « crowdsourced » : c'est la référence publique la plus proche de CCP, pas du code CCP.

Formule (Python de la page, `bar_width = cycle_time / 900`) pour le cycle `i` (0 à `total_cycles − 1`) :

- `t = (i + 0.5) × bar_width`
- `decay = qty_per_cycle / (1 + t × decayFactor)`
- `phase = qty_per_cycle ^ 0.7`
- `noise = max((cos(phase + t/12) + cos(phase/2 + t×0.2) + cos(t×0.5)) / 3, 0)`
- rendement du cycle = `bar_width × decay × (1 + noiseFactor × noise)`

`total_cycles = intdiv(expiryTime − installTime en secondes, cycle_time)`. `qty_per_cycle` et `cycle_time` (secondes) sont ceux de l'ESI (`extractor_details`).

## Règles

1. Nouveau service `App\Service\Planetary\ExtractorYieldCalculator` (dépend de `DgmAttributeTypeRepository`, facteurs lus une fois par instance). `cycleYields(int $quantityPerCycle, int $cycleTime, int $cycleCount): ?array` renvoie la liste des rendements par cycle ; `null` si un des deux attributs est absent de la SDE ou sans valeur par défaut (jamais de facteur par défaut inventé).
2. `programDailyAverage(int $quantityPerCycle, int $cycleTime, \DateTimeImmutable $installTime, \DateTimeImmutable $expiryTime): ?float` = somme des rendements du programme ÷ (durée du programme en jours). `null` si les facteurs sont inconnus ou si la durée est ≤ 0. Zéro cycle complet : 0.0 (connu).
3. Le débit quotidien d'un extracteur actif est ce `programDailyAverage` (moyenne du programme entier, stable le temps du programme), à la place de `qty_per_cycle × 86400 / cycle_time`.
4. Utilisé par `PlanetaryProductionCalculator::calculatePinDailyOutput()` (branche extracteur seulement, aucune autre règle du fichier ne change) et par `PlanetaryInputNeedsCalculator::addExtractor()` (#1380).
5. Extracteur sans `installTime`, sans `expiryTime` ou à facteurs inconnus : débit inconnu. Dans `PlanetaryProductionCalculator` il est ignoré comme l'étaient déjà les extracteurs incomplets (comportement existant) ; dans `PlanetaryInputNeedsCalculator` son type passe en `unknownExtraction` (production locale et net null). L'ancien repli « extracteur sans échéance compte au débit constant » disparaît : sans les deux dates, pas de débit.
6. Un extracteur dont `expiryTime` est passé reste exclu (règle inchangée).
7. Le constructeur de `PlanetaryProductionCalculator` reçoit `ExtractorYieldCalculator` en dernier argument ; les tests existants qui le construisent sont mis à jour.

## Exemples chiffrés (calculés avec le Python de la source, flottants, sans troncature)

| Entrée | Sortie |
|---|---|
| 6965, cycle 1800 s, 95 cycles (programme de 171 000 s), facteurs 0,012 / 0,8 | cycle 0 : 24 261,6594 ; cycle 1 : 20 481,1009 ; somme 789 365,5893 ; moyenne 398 837,3504 /jour |
| 4400, cycle 1800 s, 48 cycles (24 h) | cycle 0 : 8 695,6522 ; somme et moyenne /jour 313 874,1191 |
| 1000, cycle 3600 s, 1 cycle | 6 337,1720 |
| 6965, cycle 1800 s, 95 cycles, facteurs 0 et 0 | 1 323 350 (= 2 × 6965 × 95 : `bar_width` = 2) |
| attribut 1683 absent de la SDE | `null` |
| expiry = install | `null` ; expiry − install < un cycle : 0.0 |

## Hors périmètre

- Projection depuis la dernière vue (#1382), front, courbe de rendement (piste du plan), statut et minuteurs.
- `usePlanetaryTimers.ts` (débit par heure affiché côté front, ligne 90) : non modifié, point à reprendre dans une issue front.
- `resolveRunRates()` (#1378).

## Décisions prises en autonomie

- Débit quotidien = moyenne du programme entier : le bruit étant déterministe et la décroissance monotone, une moyenne donne un chiffre stable à planifier ; la valeur « sur les prochaines 24 h » reste calculable depuis `cycleYields` pour #1382.
- Le rendement des cycles intègre le facteur `cycle_time / 900` de la source : le débit passe, à 30 min de cycle, d'environ 1 × à environ 1,2 × l'ancien débit constant sur un programme de 2 jours (voir exemple 1 : 398 837 contre 334 320). À signaler dans la PR.

## Tests

- Unit `ExtractorYieldCalculatorTest` : exemples chiffrés ci-dessus ; une garde sur l'ordre et le nombre des cycles.
- Unit `PlanetaryProductionCalculatorTest` et `PlanetaryInputNeedsCalculatorTest` : extracteur à `installTime` et `expiryTime` ; extracteur sans `installTime` ignoré / inconnu.
