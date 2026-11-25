# Spec #1384 : colonies regroupées par compte, emplacements utilisés contre le skill

Mode nuit : validée en autonomie, décisions notées en bas. Maquette locale non versionnée : `pi-production-mockup-2026-11-25.html` (barre « Regrouper par », en-tête de compte avec avatars et badge d'emplacements).

## Contexte

L'onglet Colonies liste une section par personnage. Il ne dit ni combien de planètes chaque personnage peut encore coloniser, ni combien en tout sur le compte. Le maximum vient du skill Interplanetary Consolidation (type 2495, SDE : « one additional planet per level, to a maximum of 6 »).

## Prérequis constatés sur origin/main

- Le skill 2495 n'est pas synchronisé : `SyncCharacterSkillsProcessor` ne lit que les skills de l'industrie, des slots et `SKILLS_WRITTEN_AT_ZERO`. La sync l'ajoute (règle 5).
- `User::getCharacters()`, `User::getMainCharacter()`, `PlanetaryColonyRepository::findByUser()`, `CachedCharacterSkillRepository::findLevelsByEveCharacterId()` existent. `ColonyResource` porte déjà `characterId` et `characterName`.

## Backend

1. Ressource API Platform `PlanetarySlots`, `GET /planetary/slots`, `security: is_granted('ROLE_USER')`, provider `PlanetarySlotsProvider`, identifiant fixe `slots` (modèle : `PlanetaryStatsResource`). Lecture seule, aucune action async, pas de Mercure.
2. Une ligne par personnage de l'utilisateur, avec ou sans colonie : `{characterId (id EVE), characterName, isMain, coloniesUsed, consolidationLevel, maxColonies}`. Un personnage sans colonie compte : il offre des emplacements libres.
3. `coloniesUsed` : nombre de colonies du personnage. `consolidationLevel` : niveau du skill 2495 lu dans `CachedCharacterSkill`, `null` si aucune ligne n'existe pour ce personnage et ce skill (skills jamais synchronisés depuis cette règle). Un niveau 0 est un vrai 0, jamais confondu avec « inconnu ».
4. `maxColonies` = 1 + `consolidationLevel` (une colonie sans le skill), `null` si le niveau est inconnu. Calcul dans un service pur `PlanetarySlotCalculator` (`src/Service/Planetary/`), constantes nommées (`BASE_COLONIES = 1`), pas de `?? 0`.
5. Sync des skills : le skill 2495 est ajouté à `CachedCharacterSkill` (constante `SKILL_INTERPLANETARY_CONSOLIDATION`, même nom et même emplacement que dans la branche de #1403) et à `SKILLS_WRITTEN_AT_ZERO`, pour qu'un niveau 0 soit écrit et distinct de « pas synchronisé ».
6. Totaux de la réponse : `totalUsed` = somme des `coloniesUsed` ; `totalMax` = somme des `maxColonies`, `null` dès qu'un personnage a un maximum inconnu (pas de total partiel présenté comme complet).
7. Portée : uniquement les personnages de l'utilisateur connecté.

## Frontend

8. Store `planetary` : type `PlanetarySlots`, état `slots`, action `fetchSlots()` (appelée avec les autres au chargement de la page), erreur dans l'état d'erreur du store.
9. Barre de l'onglet Colonies : sélecteur « Regrouper par » `Compte` (défaut) ou `Personnage`. État de page, pas de persistance. Le mode `Personnage` garde la mise en page actuelle, une section par personnage.
10. Mode `Compte` : une section unique, `Compte « <nom du main> »` (premier personnage si aucun main n'est connu), avec les avatars des personnages qui ont des colonies, la liste de tous les personnages `nom utilisé/max`, et le badge `utilisé / max emplacements` du total. Les cartes de colonie de tous les personnages sont dans la section, chacune avec le nom de son personnage ; ordre inchangé (nom du personnage, type de planète), le tri par prochaine action est #1383.
11. Mode `Personnage` : l'en-tête de chaque personnage gagne le badge `utilisé / max`.
12. Maximum inconnu : le badge affiche `utilisé / ?` avec une infobulle qui dit que le skill Interplanetary Consolidation n'est pas synchronisé (sync des skills dans Industrie), jamais un maximum présumé. Utilisé supérieur au maximum connu : badge en rouge (skill baissé ou donnée vieille).
13. Colonnes de l'écran : avatars via `images.evetech.net/characters/<id>/portrait` comme aujourd'hui, noms longs testés (troncature), i18n FR et EN (`pi.groupBy.*`, `pi.slots.*`), direction HUD EVE.

## Exemples chiffrés

| Personnage | Colonies | Niveau 2495 | Max |
|---|---|---|---|
| A | 6 | 5 | 6 |
| B | 3 | 4 | 5 |
| C | 0 | 2 | 3 |
| D | 2 | aucune ligne | inconnu |

- A, B, C : total 9 / 14. Avec D : `totalUsed` 11, `totalMax` `null`, badge `11 / ?`.
- Niveau 0 : max 1. Niveau 5 : max 6.

## Tests

- PHP : calculateur (niveau 0, 5, inconnu, total inconnu, personnage sans colonie, utilisé > max), ressource (401, forme, portée utilisateur), sync des skills (2495 écrit à 0), une garde sur la sync existante.
- Front : store `fetchSlots`, bascule Compte et Personnage, badge normal, inconnu et dépassé, avatars et liste des personnages, nom du personnage sur la carte en mode Compte. Au plus 15 tests.

## Hors périmètre

Tri par prochaine action et filtres (#1383), CPU et PG libres (#1385), emplacements du Planificateur (#1403), plusieurs comptes par utilisateur (le compte est l'utilisateur), alertes.

## Décisions

- Le compte est l'utilisateur : un seul groupe `Compte` aujourd'hui, la bascule garde le regroupement par personnage.
- Base de 1 colonie sans le skill (`1 + niveau`), cohérente avec le plafond de 6 de la SDE au niveau V.
- Le skill n'étant lu qu'à la sync des skills, un personnage reste « inconnu » tant que l'utilisateur ne l'a pas relancée ; aucune sync supplémentaire n'est planifiée ici.
