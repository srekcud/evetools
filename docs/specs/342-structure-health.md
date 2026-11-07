# #342 — Santé des structures prévues

Décision de Sylvain (2026-11-07) : lecture ESI de `/corporations/{id}/structures/` à l'ouverture de l'onglet Chaîne, par le premier personnage du lecteur qui porte `Station_Manager` ; états `ok | low-fuel | offline-service | unknown` ; seuil carburant bas 3 jours (constante nommée) ; carte sous le tableau Structures (#341 lot 2).

## Règles

1. Structures prévues = structures distinctes retenues par le plan du propriétaire (`JobStructureComparison::chosenStructureId` non nul de `PlanStructureComparator`). Une structure non retenue n'est pas évaluée.
2. Identité ESI d'une structure prévue = `IndustryStructureConfig::getLocationId()` (= `structure_id` ESI) et `getCorporationId()`. L'un des deux absent : `unknown`, aucune lecture ESI.
3. Lecteur d'une corporation = premier personnage du lecteur de la requête (membre accepté du projet, pas le propriétaire) trié par `eveCharacterId` croissant, avec `isAuthValid()`, la corporation de la structure, les scopes `esi-corporations.read_structures.v1` et `esi-characters.read_corporation_roles.v1`, et le rôle `Station_Manager` (`CharacterService::hasRole`). Aucun lecteur : `unknown` pour toutes les structures de cette corporation, aucune lecture de `/structures/`.
4. Une seule lecture paginée (`EsiClient::getPaginated`) par corporation, quel que soit le nombre de structures prévues.
5. `EsiApiException` ou `EveAuthRequiredException` à la lecture (403 compris) : `unknown` pour la corporation, avertissement journalisé, jamais `ok`. Structure prévue absente de la réponse : `unknown`.
6. Évaluation d'une structure présente (`StructureHealthAssessor`, pur) :
   - un service dont l'état est `offline` ou `cleanup` : `offline-service`, avec les noms de ces services (prioritaire sur le carburant) ;
   - `fuel_expires` absent : `unknown` (jamais `ok` ni 0 jour) ;
   - jours de carburant = `fuel_expires - maintenant`, en jours, arrondis à 1 décimale, plancher 0 si déjà expiré ;
   - jours de carburant strictement inférieurs à `LOW_FUEL_THRESHOLD_DAYS` (3) : `low-fuel` ; sinon `ok`.
   - `services` absent de la réponse = aucun service listé, donc aucun service hors ligne.
7. API : `GET /industry/projects/{projectId}/structure-health`, `assertAcceptedMember`, lecture seule, une ligne par structure prévue dans l'ordre de première apparition dans le plan. Ligne : `structureId` (uuid de la configuration), `name`, `kind`, `state`, `fuelDays` (`null` hors `ok`/`low-fuel`/`offline-service` avec carburant connu), `offlineServices` (liste). Ressource : `id` (projet), `lowFuelThresholdDays`, `rows`. Plan en échec : 422 comme `/structures`.
8. Front : carte « Santé des structures » (`ProjectStructureHealth.vue`, store `structureHealth.ts`), rendue sous `ProjectStructures` dans l'onglet Chaîne, chargée à l'ouverture (montage) et au changement de projet ; un `unknown` est affiché « inconnu » avec la raison (rôle Station_Manager absent ou structure illisible), jamais comme OK ; erreur de chargement visible ; états vides explicites. Chaînes i18n en et fr.

## Exemples

| Réponse ESI de la structure | État | `fuelDays` | `offlineServices` |
|---|---|---|---|
| fuel_expires +41 j, services online | ok | 41 | [] |
| fuel_expires +2,5 j, services online | low-fuel | 2.5 | [] |
| fuel_expires +3 j exactement | ok | 3 | [] |
| fuel_expires +41 j, service « Invention » offline | offline-service | 41 | [Invention] |
| fuel_expires +2,5 j, service offline | offline-service | 2.5 | [nom] |
| fuel_expires dans le passé | low-fuel | 0 | [] |
| fuel_expires absent, services online | unknown | null | [] |
| structure absente de la réponse | unknown | null | [] |

## Cas limites

- Deux structures prévues de la même corporation : un seul appel ESI.
- Deux corporations : un lecteur et un appel par corporation, indépendants.
- Le premier personnage qualifié échoue à la lecture : pas d'essai du suivant (un lecteur par corporation, règle 3).
- Projet sans job de fabrication ni réaction : `rows` vide.

## Hors périmètre

- Rattacher un service précis (invention, copie, réaction) au type d'activité du job prévu : tout service hors ligne est signalé.
- Cache de la lecture, sync planifiée, notification Mercure : la lecture est synchrone à l'ouverture de l'onglet.
- Renseigner `corporationId` ou `locationId` sur une configuration qui n'en a pas (import existant inchangé).
- Blocage du lancement d'un job.

## Décisions prises en autonomie

- `fuel_expires` absent = `unknown` (règle « donnée ESI manquante = état inconnu »).
- Configuration sans `corporationId` : `unknown`, pas de repli sur la corporation du lecteur.
- `cleanup` compte comme hors ligne.

## Actions prod

Aucune (pas de migration, pas de commande console, scope `esi-corporations.read_structures.v1` déjà demandé).
