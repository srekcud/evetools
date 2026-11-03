# Spec #274 (partie calendrier) — Minage lunaire : calendrier des chunks de la corporation

**Statut : validée en autonomie en mode nuit le 2026-11-03, à relire par Sylvain.**

Lot `group:mining-ops`, partie valeur du minerai. Circuit `circuit:m`. Une migration (deux tables). Cette spec couvre le **calendrier** : synchronisation des extractions, noms, API de lecture et vue front (liste et calendrier). Restent hors de ce lot, notés sur l'issue : « créer une opération depuis un chunk » (attend l'opération groupée #262/#264) et les notifications de chunk (suivi de raffinerie, in-app et push).

## Contexte

Les membres d'une corporation qui possède des raffineries veulent savoir quand le prochain chunk lunaire arrive et sur quelle lune. L'information n'est visible en jeu que pour le rôle Station_Manager. Un personnage de la corporation qui a ce rôle et un token valide lit l'ESI ; tous les utilisateurs evetools de la corporation voient le résultat.

Scopes déjà demandés (`AuthenticationService::REQUIRED_SCOPES`) : `esi-industry.read_corporation_mining.v1`, `esi-corporations.read_structures.v1`, `esi-characters.read_corporation_roles.v1`. Aucun scope à ajouter.

## Règles métier

- **C1 — Source.** `GET /corporation/{corporation_id}/mining/extractions/` (cache ESI 1800 s, paginé : `EsiClient::getPaginated()`). Champs lus : `structure_id`, `moon_id`, `extraction_start_time`, `chunk_arrival_time`, `natural_decay_time`. Un champ manquant ou illisible rend la réponse entière illisible (échec `esi_error`, rien n'est enregistré) : aucune date par défaut.
- **C2 — Personnage lecteur.** Parmi les personnages des utilisateurs evetools dont `corporationId` est celle de la corporation, ceux dont le token porte les trois scopes ci-dessus et qui ont le rôle `Station_Manager` (`CharacterService::hasRole`). Ils sont essayés dans l'ordre de leur identifiant ; le premier dont la lecture des extractions réussit fait foi. Un 403 passe au suivant. Une autre erreur ESI (5xx, réseau, 420/429 après le rejeu du client) passe au suivant aussi, et si aucun ne réussit l'échec est `esi_error`. Aucun candidat : échec `no_station_manager_token`.
- **C3 — Noms.** Raffinerie : `GET /corporations/{corporation_id}/structures/` (paginé, même token) donne `name`, `system_id`, `type_id` ; le nom du système vient du SDE (`MapSolarSystem`). Lune : `GET /universe/moons/{moon_id}/` (public) donne `name`, `system_id` ; lu une seule fois par lune puis gardé avec l'extraction. Nom de raffinerie ou de lune illisible : le champ reste `null` (l'identifiant est affiché par le front, nom marqué inconnu). Une erreur sur les noms n'est pas un échec de synchronisation.
- **C4 — Enregistrement.** Table `corporation_mining_extractions` : une ligne par `(corporationId, structureId, moonId, extractionStartTime)` (upsert). Les lignes ne sont jamais supprimées par la synchronisation (l'ESI ne les liste plus une fois le chunk exploité : l'historique reste).
- **C5 — État de synchronisation.** Table `corporation_mining_sync_states` : une ligne par `corporationId` avec `lastSuccessAt`, `lastAttemptAt`, `lastFailure` (`null`, `no_station_manager_token`, `esi_error`). Succès : `lastSuccessAt` = maintenant, `lastFailure` `null`. Échec : `lastFailure` renseigné, `lastSuccessAt` et les extractions déjà enregistrées conservés.
- **C6 — Planification et rafraîchissement.** Toutes les 30 minutes (`SyncScheduler`), un message de déclenchement envoie un message de synchronisation par corporation distincte des personnages evetools. `POST /api/mining/corporation-extractions/sync` (`EmptyInput`) le fait pour les corporations de l'utilisateur. Le résultat est publié par Mercure (type de synchronisation `corporation-mining-extractions`, `syncStarted` → `syncCompleted` ou `syncError`) aux utilisateurs evetools de la corporation. Mise à jour du tableau Scheduler de `CLAUDE.md`.
- **C7 — Statut d'un chunk** (calculé à la lecture, jamais enregistré, à l'instant `now`) : `extracting` si `now < chunkArrivalTime` ; `ready` si `chunkArrivalTime ≤ now < naturalDecayTime` ; `exploded` si `now ≥ naturalDecayTime`.
- **C8 — API.** `GET /api/mining/corporation-extractions` (`ROLE_USER`) : une entrée par corporation distincte des personnages de l'utilisateur : `corporationId`, `access`, `lastSuccessAt`, `extractions`. `access` : `never_synced` (aucun état), `ok` (`lastFailure` `null`), `denied` (`no_station_manager_token`), `error` (`esi_error`). `extractions` : celles dont `naturalDecayTime ≥ now − 14 jours`, triées par `chunkArrivalTime` croissant ; chacune : `id`, `structureId`, `structureName`, `solarSystemId`, `solarSystemName`, `moonId`, `moonName`, `extractionStartTime`, `chunkArrivalTime`, `naturalDecayTime`, `status`. Un utilisateur ne voit que les corporations de ses propres personnages.
- **C9 — État refusé.** Quand `access` vaut `denied`, la liste des extractions enregistrées reste exposée (historique) mais le front affiche « accès refusé, rôle Station_Manager requis » avec la date de dernière synchronisation réussie s'il y en a une, jamais un calendrier vide présenté comme « aucune extraction ». `never_synced` : « pas encore synchronisé ». `error` : « lecture ESI en échec » avec la dernière synchronisation réussie.
- **C10 — Front.** Sous-onglet « Chunks lunaires » de l'onglet Minage : vue liste (prochains chunks triés par arrivée : lune, raffinerie, système, arrivée, décroissance naturelle, compte à rebours, statut) et vue calendrier (semaine, mois), bouton de rafraîchissement avec progression Mercure (`stores/sync.ts`), heures en EVE time (UTC) par défaut, conversion locale en option d'affichage. Nom inconnu : identifiant affiché et « inconnu » signalé. HUD EVE, jamais la couleur seule pour le statut, noms longs testés.

## Exemples chiffrés (`now` = jeudi 2026-11-05 19:00 UTC)

| # | Cas | Attendu |
|---|---|---|
| K1 | Extraction : début lundi 2026-11-02 18:00, `chunk_arrival_time` jeudi 2026-11-05 20:00, `natural_decay_time` jeudi 2026-11-05 23:00 | statut `extracting` ; avec `now` = 20:30 : `ready` ; avec `now` = 23:00 : `exploded` |
| K2 | `now` = `chunk_arrival_time` exactement | `ready` ; `now` = `natural_decay_time` exactement : `exploded` |
| K3 | Corporation avec deux personnages candidats, le premier en 403 sur les extractions, le second OK | second personnage utilisé, succès |
| K4 | Aucun personnage avec le rôle Station_Manager | `lastFailure` `no_station_manager_token`, `lastSuccessAt` inchangé, extractions conservées |
| K5 | Tous les candidats en erreur 500 | `lastFailure` `esi_error` |
| K6 | Réponse ESI dont une extraction n'a pas `chunk_arrival_time` | `esi_error`, aucune ligne écrite ni modifiée |
| K7 | Deuxième synchronisation avec la même extraction | une seule ligne (upsert), nom de lune non relu |
| K8 | Extraction absente de la deuxième réponse | ligne conservée |
| K9 | `GET /universe/moons/{id}` en erreur | `moonName` `null`, synchronisation réussie |
| K10 | Structures illisibles (403 sur `/corporations/{id}/structures/`) | `structureName` `null`, synchronisation réussie |
| K11 | API : utilisateur dont les personnages sont dans les corporations 98000001 et 98000002 | deux entrées, chacune avec son état |
| K12 | API : extraction exploitée depuis 15 jours ; une autre depuis 13 jours | la première absente, la seconde `exploded` présente |
| K13 | API : utilisateur d'une autre corporation | ne voit aucune des deux |
| K14 | `access` : aucun état / `lastFailure` null / `no_station_manager_token` / `esi_error` | `never_synced` / `ok` / `denied` / `error` |
| K15 | `POST …/sync` | dispatche une synchronisation par corporation de l'utilisateur, rien d'autre |
| K16 | Déclencheur planifié, trois personnages dans deux corporations | deux messages de synchronisation |
| K17 | Front : `denied` avec `lastSuccessAt` | message « accès refusé… » et date ; la liste d'historique reste lisible ; jamais le texte « aucune extraction » |
| K18 | Front : calendrier mois, chunk jeudi 20:00 UTC | placé dans la case du jeudi, heure affichée 20:00 en EVE time |

## Cas limites

- Corporation sans extraction en cours (liste ESI vide, lecture réussie) : `access` `ok`, `extractions` vide, le front peut dire « aucune extraction » (c'est la seule situation).
- Personnage déplacé de corporation : `corporationId` à jour dans `Character` fait foi ; l'ancienne corporation disparaît de l'API pour lui.
- Deux extractions sur la même lune successives : deux lignes (début différent).

## Hors périmètre

- Composition de la lune, valeur du chunk (#308), taxe lunaire et visibilité alliance (#273), lancer une extraction.
- **Créer une opération depuis un chunk** : attend l'entité Opération (#262/#264).
- **Notifications** (chunk prêt dans 1 h, chunk arrivé) et suivi de raffinerie : suite, notée sur l'issue.
- Purge des vieilles lignes (aucune purge dans ce lot).

## Termes proposés au glossaire (non ajoutés, la PR #326 modifie `docs/glossary.md`)

| Terme | Définition |
|---|---|
| **Chunk lunaire** (*moon chunk*) | Extraction d'une raffinerie sur une lune : début, arrivée du chunk (`chunk_arrival_time`), décroissance naturelle (`natural_decay_time`). |
| **Statut d'un chunk** | `extracting` avant l'arrivée, `ready` entre l'arrivée et la décroissance naturelle, `exploded` ensuite. Calculé à la lecture. |
