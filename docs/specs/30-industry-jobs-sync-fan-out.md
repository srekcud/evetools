# Spec #30 — La sync planifiée des jobs d'industrie passe par un message par personnage

## Contexte

Le worker consomme `async` et `scheduler_default` dans un seul processus. Un message planifié est traité en ligne : tant qu'il tourne, les refresh manuels attendent. Les syncs wallet et PI envoient déjà un message par personnage (`a822a59`). `SyncIndustryJobsHandler` boucle encore sur tous les personnages, avec appels ESI et `usleep(500 ms)` entre chacun.

## Règles métier

- **R1 — Mise en file.** `SyncIndustryJobsHandler` envoie un message `SyncCharacterIndustryJobs(characterId)` par personnage actif à token valide portant le scope `esi-industry.read_character_jobs.v1`, sans appel ESI ni pause. Il ne bloque plus le worker.
- **R2 — Routage.** `SyncCharacterIndustryJobs` est routé vers `async`.
- **R3 — Un personnage par message.** Le handler de `SyncCharacterIndustryJobs` remet à zéro le suivi des corporations (`resetCorporationTracking()`) puis synchronise les jobs du personnage (`syncCharacterJobs()`). Les jobs corpo sont donc relus dans chaque message ; le marquage « livré » d'un job disparu de l'ESI reste correct, puisqu'il compare au même message.
- **R4 — Personnage introuvable.** Avertissement dans les logs, aucun appel au service, pas d'erreur (comme wallet et PI).
- **R5 — Échec d'un personnage.** L'exception remonte au bus (nouvelles tentatives Messenger puis `failed`), comme wallet et PI. Elle n'interrompt plus les autres personnages, qui ont leurs propres messages.
- **R6 — Suivi admin.** `SyncTracker` « industry » : `start`, puis `complete` avec `"{n}/{total} chars queued"` ; `fail` si la mise en file échoue.

## Exemples chiffrés

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | 3 personnages actifs, 2 avec le scope jobs | 2 syncs ESI en ligne, 2 pauses de 500 ms | 2 messages `SyncCharacterIndustryJobs` sur `async`, aucun appel au service de sync |
| E2 | Tracker après E1 | `2/3 chars synced` | `2/3 chars queued` |
| E3 | Message pour un personnage existant | — | `resetCorporationTracking()` puis `syncCharacterJobs(personnage)`, dans cet ordre |
| E4 | Message pour un personnage supprimé | — | warning, aucun appel au service |
| E5 | `syncCharacterJobs()` lève une exception | loggée, boucle continue | l'exception remonte du handler |
| E6 | Routage | — | `SyncCharacterIndustryJobs` → `async` |

## Cas limites

- Personnage sans `id` ou sans token : ignoré, comme aujourd'hui pour le token.
- Deux personnages d'une même corporation : l'endpoint corpo est lu deux fois. `getPaginated()` ne passe pas par l'ETag : k personnages d'une corporation de p pages font k × p appels par sync (toutes les 30 min), au lieu de p. Un 420 est absorbé par `EsiClient` (attente du reset puis une relance).

## Hors périmètre

- Sync planifiée du marché des structures (boucle par structure) : inchangée.
- Séparer le scheduler dans un worker dédié (changement d'infrastructure).
- `MatchJobsProcessor` et `MatchProjectJobsCommand`, qui synchronisent en ligne à la demande.

## Décision prise

- Découpage par personnage plutôt que par utilisateur (Sylvain, 2026-10-19).
- R5 aligné sur wallet et PI (exception propagée) plutôt que sur le comportement actuel (log et continue) : option retenue pour la cohérence des handlers par personnage.

## Glossaire

Aucun terme ajouté.
