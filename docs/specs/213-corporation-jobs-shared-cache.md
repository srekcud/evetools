# Spec #213 — Jobs corpo lus une fois par corporation, cache partagé

## Contexte

Depuis #30, la sync des jobs envoie un message `SyncCharacterIndustryJobs` par personnage, et chaque message remet à zéro le suivi des corporations déjà lues (`SyncCharacterIndustryJobsHandler.php:34`). Chaque personnage portant le scope corpo relit donc `/corporations/{id}/industry/jobs/?include_completed=true` (paginé, sans cache) : une corporation de N personnages coûte N lectures complètes à chaque sync, alors que l'ESI sert la même réponse en cache pendant sa durée `Expires` (5 min pour cet endpoint).

Décision de Sylvain (2026-10-23, option A du journal de la boucle) : cache Redis des jobs corpo par corporation, dans le pool `esi.cache` existant, d'une durée égale à l'`Expires` de l'ESI, réutilisé par tout personnage de la corporation qui porte le scope corpo, dans `IndustryJobSyncService`, sans changer les messages Messenger.

## Règles métier

- **R1 — Une lecture par corporation et par durée de cache ESI.** Les jobs corpo lus par l'ESI sont mis en cache sous une clé propre à la corporation (`esi.cache`), jusqu'à l'`Expires` de la réponse. Tant que l'entrée est valide, tout personnage de cette corporation qui porte le scope `esi-industry.read_corporation_jobs.v1` reçoit les jobs corpo du cache, sans appel ESI, quel que soit son utilisateur.
- **R2 — Durée = `Expires` de l'ESI.** L'entrée expire au plus tôt des `Expires` des pages lues. Réponse sans `Expires` exploitable (absent ou illisible sur au moins une page) : pas de mise en cache, comportement d'aujourd'hui (le personnage suivant relit). Aucune donnée servie n'est plus vieille que ce que l'ESI renverrait lui-même.
- **R3 — Seule une lecture réussie est mise en cache.** Un 403 (rôles manquants) ne remplit pas le cache et reste traité comme en spec #122 R1/R3 quand le cache est vide : jobs corpo inconnus, aucun job marqué livré, warning. Toute autre erreur remonte sans rien écrire (spec #122 R2), cache inchangé.
- **R4 — Rien d'un autre utilisateur n'est exposé.** Le cache contient tous les jobs de la corporation, comme la réponse ESI d'aujourd'hui ; la sync d'un personnage n'en garde que les jobs installés par les personnages de son utilisateur (enregistrement, rattachement, jobs matchés, notifications, compteurs `syncCompleted`). Un job installé par un personnage d'un autre utilisateur n'est ni enregistré, ni rattaché, ni notifié, ni compté, et aucun message Mercure ne part vers l'autre utilisateur.
- **R5 — 403 d'un personnage sans rôles corpo, cache chaud.** Un personnage qui porte le scope corpo mais pas les rôles reçoit les jobs corpo du cache s'il est valide : aucun appel, donc aucun 403, aucun warning ; les jobs corpo sont connus, et le marquage « livré » s'applique comme pour un personnage qui les a lus (spec #122 R4). Il ne reçoit que ses propres jobs (R4), que l'endpoint personnage lui donne déjà. Cache vide ou expiré : son appel prend un 403, spec #122 R1/R3 inchangée.
- **R6 — Personnage sans scope corpo : inchangé.** Il ne lit ni l'endpoint corpo ni le cache, même chaud (spec #122 R4, comportement actuel).
- **R7 — Un job lu dans l'ESI n'est jamais marqué livré.** Un job du personnage présent dans les jobs corpo de cette sync (lus ou servis par le cache) n'est pas marqué livré. Le suivi en mémoire des corporations lues (`resetCorporationTracking`, `ResetInterface`) disparaît : chaque sync d'un personnage avec le scope corpo dispose de la liste complète des jobs corpo.

## Exemples chiffrés

Corporation 98000001. Utilisateur U1 : personnage A (12345, scope et rôles corpo) et alt B (67890, scope corpo). Utilisateur U2 : personnage C (55501, scope corpo, même corporation). Personnage D (55600, utilisateur U3, scope corpo sans rôles, même corporation). Personnage E (55700, autre corporation 98000002, scope corpo). Jobs corpo renvoyés par l'ESI : 700 (installé par A, `active`), 701 (installé par C, `ready`), 702 (installé par D, `active`), 703 (installé par 55503, hors utilisateurs). `Expires` = maintenant + 5 min, sauf mention.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Sync de A puis de B (deux messages), même corporation | endpoint corpo appelé 2 fois | appelé 1 fois ; B dispose des jobs corpo |
| E2 | Sync de A puis de C (autre utilisateur) | appelé 2 fois | appelé 1 fois |
| E3 | Sync de C sur cache rempli par A | C relit l'ESI | C enregistre 701 seul, attribué à C ; 700, 702, 703 ni enregistrés ni modifiés ; `syncCompleted` de U2 : total 1, active 0, ready 1 ; aucune notification ni message Mercure vers U1 ; la notification de fin de 701 va à U2 |
| E4 | Sync de A puis de C, `Expires` dépassé à la sync de C | appelé 2 fois | appelé 2 fois |
| E5 | Réponse sans `Expires` | appelé 2 fois | appelé 2 fois, rien en cache |
| E6 | Deux pages, `Expires` page 1 = +5 min, page 2 = +4 min | — | `EsiClient` rend les jobs des deux pages et l'expiration +4 min |
| E7 | Deux pages, `Expires` absent sur la page 2 | — | expiration inconnue (null), pas de mise en cache |
| E8 | D (sans rôles) en premier, cache vide | 403, jobs corpo inconnus | inchangé (spec #122 E1) ; rien en cache ; A lit ensuite l'ESI |
| E9 | D après A (cache chaud) ; job perso 100 de D en base `active`, fin passée, absent de l'ESI ; job 702 en base `active` | 403 ; 100 reste `active` ; warning | aucun appel corpo, aucun warning ; 702 mis à jour (connu) ; 100 marqué `delivered` |
| E10 | Personnage sans scope corpo, cache chaud | pas d'appel corpo | inchangé : pas d'appel, cache non lu, ses jobs corpo absents de la sync |
| E11 | E après A | E lit 98000002 | E lit l'ESI pour 98000002 ; l'entrée de 98000001 n'est pas servie |
| E12 | Lecture corpo en 503, cache vide | l'exception remonte | inchangé, rien en cache ; la sync suivante relit |
| E13 | Job 700 en base pour A, `active`, fin passée, présent dans le cache ; sync de A servie par le cache | — | 700 non marqué `delivered` (mis à jour depuis le cache) |

## Cas limites

- `Expires` déjà passé à la réception (horloge) : l'entrée expire aussitôt, le personnage suivant relit.
- Personnage changé de corporation : la clé suit `Character::getCorporationId()` au moment de la sync.
- Suppression de compte (#53) : l'entrée n'est pas étiquetée par personnage (elle appartient à la corporation) ; elle contient les jobs de tous les membres comme la réponse ESI et disparaît à son `Expires` (≤ 5 min).
- Une lecture manuelle (`MatchJobsProcessor`, `MatchProjectJobsCommand`) dans la durée de cache reçoit les jobs corpo du cache, comme l'ESI les aurait servis depuis le sien.

## Hors périmètre

- ETag sur `getPaginated` (évite le transfert, pas les appels).
- Personnage sans scope corpo dont un job corpo est marqué « livré » (spec #122 R4).
- Budget d'erreurs ESI suivi par processus (`EsiClient.php:30-31`).

## Décisions prises en autonomie

- `EsiClient::getPaginatedWithExpiry()` rend les jobs et l'expiration (`PaginatedEsiResponse`) ; `getPaginated()` en devient un raccourci. Le cache reste dans `IndustryJobSyncService` : un cache générique partagé entre tokens dans `EsiClient` exposerait des données authentifiées par erreur.
- R2 : sans `Expires`, pas de cache plutôt qu'une durée par défaut (la décision fixe la durée à l'`Expires`).
- R5 : le personnage sans rôles profite du cache (conséquence annoncée de l'option A) ; il ne reçoit que ses propres jobs.

## Glossaire

« Job corpo » : ajout de la lecture partagée par corporation pendant l'`Expires` de l'ESI (spec #213), code `IndustryJobSyncService` (le suivi en mémoire disparaît).
