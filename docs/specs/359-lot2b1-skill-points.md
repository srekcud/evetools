# #359 lot 2b-1 : points de compétence déjà entraînés

Suite du lot 2a. Décision de Sylvain (2026-11-09) : lire `skillpoints_in_skill` et ne compter que les points restants vers le niveau suivant, à la place de la borne haute du lot 2a. Mode nuit : validée en autonomie.

## Règles

1. `CachedCharacterSkill` gagne `skillPoints` (`?int`, colonne `skill_points` nullable, migration). `null` = inconnu.
2. `SyncCharacterSkillsProcessor` : pour une compétence non manuelle, `skillPoints` = `skillpoints_in_skill` de l'entrée ESI, `null` si l'entrée n'a pas ce champ ou s'il n'est pas un entier ; 0 pour une compétence absente de l'ESI (jamais injectée). Une ligne manuelle garde ses points inchangés ; une ligne manuelle créée à la main reste à `null`.
3. `SkillTraining::secondsToNextLevel(rang, niveau, principal, secondaire, pointsEntraînés)` : points restants = `points(N+1) − max(pointsEntraînés, points(N))` (`points(0)` = 0). Points entraînés < 0 : `InvalidArgumentException`. Points entraînés ≥ `points(N+1)` : `InvalidArgumentException` (rien à entraîner).
4. `SkillTrainingEstimator::nextLevel(Character, skillId, currentLevel, ?int $skillPoints)` : `$skillPoints` null → `SkillPointsUnknown` ; points ≥ seuil du niveau suivant (niveau actif sous le niveau entraîné) → `ActiveLevelBelowTrainedLevel`. Ordre : niveau 5, compétence hors SDE, points inconnus, niveau actif sous le niveau entraîné, attributs non synchronisés.
5. `SkillTrainingUnknownReason` gagne `SkillPointsUnknown` et `ActiveLevelBelowTrainedLevel`.

## Exemples

| # | Entrée | Résultat |
|---|---|---|
| E1 | rang 1, niveau 4, 150 000 points entraînés, 20/20 | (256 000 − 150 000) / 30 pts/min, 212 000 s |
| E2 | mêmes, points = seuil du niveau 4 (45 255) | 421 490 s (comme le lot 2a) |
| E3 | points entraînés 100 000 au niveau 3 (seuil niveau 4 : 45 255) | `ActiveLevelBelowTrainedLevel` |
| E4 | ligne manuelle ou jamais resynchronisée | `SkillPointsUnknown` |

## Hors périmètre

Gain par mois (lot 2b-2), page (lot 2c), file d'entraînement.
