# #359 lot 2a : attributs du personnage et durée d'entraînement d'une compétence

Mode nuit : validée en autonomie. Groupe `unified-industry`, circuit l découpé : ce lot est le socle de la page Compétences (lot 2b : gain par mois, lot 2c : page). Décisions de Sylvain du 2026-11-05 : attributs de base ESI, pas de scope implants, durée affichée hors implants, attributs absents ou non synchronisés = durée inconnue avec sa cause, jamais supposée.

## Contexte

- `GET /characters/{id}/attributes/` (scope `esi-skills.read_skills.v1`, déjà demandé) renvoie `charisma`, `intelligence`, `memory`, `perception`, `willpower` ; jamais lu aujourd'hui.
- La synchro des compétences est `SyncCharacterSkillsProcessor` (à la demande, une boucle par personnage de l'utilisateur) ; les standings suivent le modèle `CharacterStandingsSynchronizer`.
- Le SDE (`sde_dgm_type_attributes`) porte, par compétence : attribut 275 (multiplicateur de temps, « rang »), 180 (attribut principal), 181 (attribut secondaire), valeurs 164 Charisme, 165 Intelligence, 166 Mémoire, 167 Perception, 168 Volonté (vérifié dans `dogmaAttributes.jsonl` : ex. Industrie rang 1, principal 166, secondaire 165 ; Advanced Industry rang 3).

## Règles

1. **Cache des attributs** : `CachedCharacterAttributes` (un par personnage, unique), `intelligence`, `memory`, `perception`, `willpower`, `charisma` (entiers), `cachedAt`. Migration, suppression en cascade avec le personnage.
2. **Synchro** : `CharacterAttributesSynchronizer::sync(Character, EveToken)` lit l'endpoint, met à jour ou crée la ligne et la date. Une réponse qui n'est pas un objet ou dont un des cinq attributs n'est pas un entier lève `UnexpectedValueException` (le personnage est alors nommé en échec), aucune ligne n'est écrite ni modifiée.
3. **Câblage** : `SyncCharacterSkillsProcessor` appelle la synchro pour chaque personnage dont le token porte le scope des compétences, juste après les compétences, dans le même `try` : un échec compte comme échec de synchro du personnage (`CharacterSyncFailure`), le personnage n'est pas compté dans `syncedCount`.
4. **Points de compétence** (domaine pur, `App\Industry\Domain\SkillTraining`) : total pour atteindre le niveau L (1 à 5) = `ceil(250 × rang × 32^((L−1)/2))` ; rang 1 : 250, 1 415, 8 000, 45 255, 256 000. Niveau hors 1 à 5 ou rang < 1 : `InvalidArgumentException`.
5. **Durée du niveau suivant** : `SkillTraining::secondsToNextLevel(rang, niveauActuel, principal, secondaire)` avec niveauActuel de 0 à 4 : `ceil((points(N+1) − points(N)) / (principal + secondaire / 2) × 60)` secondes (points(0) = 0). Principal ou secondaire < 1 ou niveauActuel hors 0 à 4 : `InvalidArgumentException`.
6. **Estimation d'un personnage** (`SkillTrainingEstimator::nextLevel(Character, skillId, currentLevel): SkillTrainingEstimate`) : `seconds` (`?int`) et `unknownReason` (`?SkillTrainingUnknownReason`, enum de l'application : `AttributesNotSynchronized`, `SkillNotInSde`, `MaxLevelReached`). Ordre d'évaluation : niveau 5 → `MaxLevelReached` ; rang ou attribut principal ou secondaire absent du SDE (ou identifiant d'attribut hors 164 à 168) → `SkillNotInSde` ; aucune ligne d'attributs pour le personnage → `AttributesNotSynchronized`. Sinon `seconds`, `unknownReason` null. Jamais 0 ni durée par défaut.
7. **Hors implants** : l'estimation n'ajoute aucun implant ni bonus d'apprentissage ; elle ne lit pas les points déjà entraînés vers le niveau suivant : la durée part du seuil du niveau actuel (borne haute, à afficher « hors implants »).
8. Mercure : aucune action asynchrone ajoutée (la synchro reste dans la requête de synchro des compétences). Aucune ressource API exposée dans ce lot.

## Exemples

| # | Entrée | Résultat |
|---|---|---|
| E1 | rang 1, niveau 4 → 5, principal 20, secondaire 20 | 210 745 points, 30 pts/min, 421 490 s |
| E2 | rang 3, niveau 0 → 1, principal 27, secondaire 21 | 750 points, 37,5 pts/min, 1 200 s |
| E3 | Industrie (3380), niveau 3, attributs mémoire 25 / intelligence 20 | rang 1, principal mémoire, secondaire intelligence, 35 pts/min |
| E4 | personnage sans ligne d'attributs | `AttributesNotSynchronized`, `seconds` null |
| E5 | skill absente du SDE | `SkillNotInSde` |
| E6 | niveau actuel 5 | `MaxLevelReached` |
| E7 | ESI renvoie `intelligence: "20"` | échec nommé, ligne existante inchangée |

## Hors périmètre

Gain par mois, page Compétences, ressource API, implants, scope `read_implants`, points déjà entraînés (`skillpoints_in_skill`), file d'entraînement, Skill Planner, compétences d'un personnage dont le token est révoqué (échec nommé comme aujourd'hui).

## Décisions ouvertes (prises en autonomie)

- Durée depuis le seuil du niveau actuel plutôt que depuis les points entraînés : évite de lire et stocker `skillpoints_in_skill` ; surestime légèrement. À revoir en lot 2b si la page l'exige.
- Pas de ressource API avant le lot 2b : le lot est un socle (cache, synchro, calcul).
