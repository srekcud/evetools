# Spec #277 + #275 — Durée par run : arrondi sans bruit flottant, compétence Science sur la copie

**Statut : #277 fusionnée. La partie #275 est déplacée le 2026-10-30 dans [`docs/specs/275-copy-science-and-advanced-industry.md`](275-copy-science-and-advanced-industry.md)**, mise à jour après les décisions de Sylvain du 2026-10-30 (D-3 retenue, Advanced Industry appliquée à la copie, A3 acceptée) et après #282 et #283. Ce fichier ne garde que #277 ; ses règles et exemples sont inchangés. Les règles R2 à R8 citées ci-dessous (exemple A5) sont celles de la spec #275, qui garde leur numérotation.

Rédigée en mode nuit le 2026-10-29. Pour #277 seul, le cas A5 a gardé la copie à 14 100 et le personnage `null` : seule la fabrication est passée de 12 921 à 12 920. La copie à `null` de A5 est la cible de #275 (cas G1 de sa spec).

## Contexte

La **durée par run** d'une **étape** est calculée par une seule fonction depuis le lot #126/#134 (`docs/specs/126-134-job-duration.md`) : `IndustryCalculationService::timePerRunOf` au moment de la rédaction, `TimePerRunCalculator::timePerRunOf` depuis #283, appelée par l'affichage (`IndustryResourceMapper::stepToResource`) et par la génération des étapes (`IndustryProjectFactory::addTimeDataToSteps`).

État vérifié dans le code au 2026-10-29 (branche `fix/group-production-tree`) :

| Élément | État avant #277 | Fichier |
|---|---|---|
| Arrondi | `(int) ceil($baseTime * $teMultiplier * $structureTimeMultiplier * $skillMultiplier)` : le `ceil` porte sur un flottant IEEE (#277) | `IndustryCalculationService::timePerRunOf` (depuis #283 : `TimePerRunCalculator::timePerRunOf`) |
| Multiplicateur de compétence | fabrication : Industry 4 %/niv., Advanced Industry 3 %/niv. ; réaction : Reactions 4 %/niv. ; puis sciences du blueprint 1 %/niv. | `calculateSkillTimeMultiplier` |

## Règles métier

**R1 — Arrondi de la durée par run (#277).**

```
durée par run = ceil(round(temps de base × (1 − TE/100) × (1 − bonus de temps de la structure/100) × multiplicateur de compétence, 6))
```

Le produit est arrondi à 6 décimales avant le `ceil` : une partie fractionnaire inférieure à 0,000 000 5 est du bruit flottant (`19 000 × 0,68` vaut `12 920,000000000002` en IEEE), pas une seconde à ajouter. La précision (6) est une constante nommée. Même schéma que la quantité de matériau (`ceil(round(…, 2))`, `calculateMaterialQuantity`). S'applique à toute activité (fabrication, réaction, copie), à l'affichage et à la génération, puisque les deux passent par `timePerRunOf`.

**R2 à R8 (#275)** : voir [`docs/specs/275-copy-science-and-advanced-industry.md`](275-copy-science-and-advanced-industry.md).

## Exemples chiffrés (tests rouges)

Hypothèses communes sauf mention : aucune structure, aucun système favori, aucune science du blueprint, durée maximale de job 2 jours (172 800 s).

### #277 — arrondi

| # | Étape | Données | Durée par run attendue | Aujourd'hui | Test |
|---|---|---|---|---|---|
| A1 | Fabrication | base 19 000 ; TE 0 ; Industry 5 + Advanced Industry 5 (0,68) | **12 920** | 12 921 (`12 920,000000000002`) | `IndustryCalculationTest`, nouveau cas |
| A2 | Fabrication | base 10 000 ; TE 0 ; Industry 5 + Advanced Industry 5 | **6 800** | 6 801 (`6 800,000000000001`) | `IndustryCalculationTest`, nouveau cas |
| A3 | Fabrication | base 16 003 ; TE 20 ; Industry 5 + Advanced Industry 5 | 8 706 (`8 705,632` : vraie fraction, toujours arrondie au supérieur) | 8 706 (non-régression) | `IndustryCalculationTest` |
| A4 | Fabrication | base 16 000 ; TE 2 ; Industry 5 + Advanced Industry 5 | 10 663 (`10 662,4`) | 10 663 (non-régression, `testTimePerRunIsRoundedUpToTheNextSecondEvenWhenItsFractionIsBelowOneHalf`) | existant |
| A5 | Projets de 13 et 14 runs | base fabrication 19 000 ; copie 14 100 ; personnage `Industrialist` Industry 5 + Advanced Industry 5, sans ligne Science | fabrication **12 920** ; découpage inchangé : 13 runs → un job (13 × 12 920 = 167 960 ≤ 172 800), 14 runs → 2 jobs de 7 (180 880 > 172 800, `floor(172 800 / 12 920)` = 13) ; copie **`null`**, personnage `null` (R5) | fabrication 12 921, copie 14 100 | réécriture du cas figé de `IndustryProjectFactoryTest` (voir « Tests existants appelés à casser ») |

Depuis #283, les tests de durée de `IndustryCalculationTest` vivent dans `TimePerRunCalculatorTest` (groupe `issue-277`).

## Cas limites

- **Arrondi R1, vraie fraction inférieure à 0,000 000 5** : elle serait perdue. Les entrées (temps de base entier, TE entier, bonus de structure et de rig à quelques décimales, multiplicateurs de compétence à 2 décimales) rendent ce cas théorique ; le compromis est accepté (décision D-1).

## Tests existants appelés à casser

- `IndustryProjectFactoryTest::testEveryGeneratedJobDisplaysTheTimePerRunThatDecidedItsSplit`, cas `'copy of the blueprint, never split, without character and with its copying base time'` : fabrication **12 921 → 12 920** (#277) sur les deux projets. La copie (14 100 → `null`) est réécrite par #275 (cas G1 de sa spec).
- Aucun autre test ne fige un `ceil` flottant : toutes les durées attendues de `IndustryCalculationTest`, `IndustryProjectFactoryTest` et `JobSplitCalculationTest` ont été recalculées en IEEE (`ceil(x)` contre `ceil(round(x, 6))`) et sont identiques (16 000, 12 800, 13 600, 10 880, 10 240, 8 704, 9 072, 8 640, 7 258, 7 399, 6 804, 8 443, 8 275, 8 530, 8 891, 10 663, 7 140, 9 350, 9 180, 10 400, 10 068). Les tests de caractérisation golden ne lisent pas `timePerRun` ; leur découpage dépend de la durée : la suite complète doit rester verte, sinon le cas est à documenter ici.

## Hors périmètre

- **#275** : compétences Science et Advanced Industry sur la copie, ligne Science toujours écrite par la sync : [spec dédiée](275-copy-science-and-advanced-industry.md).
- **#276** : bonus de temps de structure propre à la copie.
- **#283** : extraction d'un service de durée hors de `IndustryCalculationService` (fusionnée depuis).

## Décisions prises en autonomie

- **D-1 — Précision 6 (issue #277).** Arrondi à 6 décimales avant le `ceil`, dans `timePerRunOf` seulement. Alternative écartée : multiplier par des entiers (pourcentages) pour rester exact ; plus intrusif, et le dépôt applique déjà `round` avant `ceil` aux matériaux.
- D-2 à D-7 (#275) : voir la spec #275, où D-2 (Science seule sur la copie) est remplacée par la décision A1 de Sylvain du 2026-10-30.

## Termes du glossaire

- **Durée par run** : formule mise à jour (`ceil(round(…, 6))`, #277). Les termes propres à #275 sont listés dans sa spec.
