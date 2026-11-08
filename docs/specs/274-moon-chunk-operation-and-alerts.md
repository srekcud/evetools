# Spec #274 (suite) — Opération planifiée depuis un chunk et notifications de chunk

**Statut : validée en autonomie en mode nuit le 2026-11-08, à relire par Sylvain.**

Lot `group:mining-ops`, `circuit:m`, une migration (deux tables). Le calendrier des chunks (synchronisation, noms, API de lecture, vues liste et calendrier) est dans main (spec `274-corporation-moon-chunk-calendar.md`). Cette spec couvre les deux restes de l'issue : **créer une opération depuis un chunk** et les **notifications de chunk** pour les utilisateurs qui suivent la raffinerie. L'opération groupée (#262/#264), le lien opération → chunk (`MiningOperation::chunk`, `chunkId` exposé) et les récurrences (#310) sont déjà dans main.

## 1. Règles métier

- **P1 — Création depuis un chunk.** `POST /api/mining-operations` accepte un champ optionnel `chunkId` (UUID d'une `CorporationMiningExtraction`, l'`id` déjà exposé par `GET /api/mining/corporation-extractions`). L'opération créée est liée à ce chunk (`chunkId` de la ressource renseigné). Absent : comportement inchangé.
- **P2 — Validation.** `chunkId` exige que la corporation de l'utilisateur soit connue et égale à `corporationId` du chunk ; chunk inconnu, d'une autre corporation ou corporation de l'utilisateur inconnue : **404**, aucune opération créée (on ne révèle pas l'existence d'un chunk d'une autre corporation). Chunk déjà `exploded` (`now ≥ naturalDecayTime`) : **400**. La visibilité n'est pas contrainte par l'API (le front pré-remplit « ma corporation »). `chunkId` et `recurrence` peuvent coexister (indépendants). Plusieurs opérations peuvent viser le même chunk (pas d'unicité : plusieurs équipes).
- **P3 — Pré-remplissage (front).** Depuis un chunk non `exploded` du calendrier (ligne de la liste, case de la semaine ou du mois), le bouton « Créer une opération de minage » ouvre `NewMiningOperationModal` avec : libellé = nom de la lune (`moonName`, sinon l'identifiant de la lune en texte), heure de début = `chunkArrivalTime` (EVE time), visibilité « ma corporation », et le système du chunk affiché en lecture seule dans la modale (le système d'une opération est fixé à son démarrage, pas à la création). Le libellé et l'heure restent modifiables. La requête porte `chunkId`.
- **P4 — Suivi d'une raffinerie.** Un utilisateur suit ou ne suit plus une raffinerie (`structureId`) de sa corporation. Table `moon_refinery_follows` : `user`, `corporationId`, `structureId`, `createdAt`, unique `(user, structureId)`. Suivre deux fois = idempotent (même suivi, pas de doublon).
- **P5 — API de suivi.** `POST /api/mining/followed-refineries` (`ROLE_USER`, corps `{structureId}`) : 201 avec `{structureId, corporationId, createdAt}` ; 400 si la corporation de l'utilisateur est inconnue ou si aucune extraction de sa corporation n'existe pour cette raffinerie (`CorporationMiningExtractionRepository::existsForRefinery`, aucune raffinerie inventée). `DELETE /api/mining/followed-refineries/{structureId}` (provider qui renvoie la ressource) : 204 ; 404 si l'utilisateur ne suit pas cette raffinerie (jamais le suivi d'un autre). `GET /api/mining/corporation-extractions` expose en plus, par corporation, `followedStructureIds: list<int>` (les raffineries suivies par l'utilisateur connecté dans cette corporation).
- **P6 — Notifications.** Deux événements par chunk et par suiveur, catégorie nouvelle `moon_chunk` (constante `Notification::CATEGORY_MOON_CHUNK`, ajoutée aux listes de catégories des préférences, du `PreferencesProcessor` et du front), envoyées par `NotificationDispatcher::dispatch` (in-app, Mercure `/user/{userId}/notifications`, push Web selon les préférences) :
  - `soon` : « chunk prêt dans 1 h » quand `chunkArrivalTime − 1 h ≤ now < chunkArrivalTime`, niveau `info` ;
  - `arrived` : « chunk arrivé » quand `chunkArrivalTime ≤ now < naturalDecayTime`, niveau `success`.
  Un chunk déjà explosé n'envoie rien. Le titre et le message disent la lune (ou son identifiant), la raffinerie (ou son identifiant) et l'heure d'arrivée en EVE time ; `data` porte `extractionId`, `structureId`, `kind` ; `route` = `/ledger?tab=mining&view=moonChunks` ; `MiningTab.vue` lit `route.query.view` (valeur parmi ses vues, sinon `entries`) pour choisir sa vue initiale.
- **P7 — Une seule fois.** Table `moon_chunk_alerts` : `user`, `extraction`, `kind` (`soon` ou `arrived`), `sentAt`, unique `(user, extraction, kind)`. Un événement déjà enregistré n'est jamais renvoyé, ni après une nouvelle synchronisation du chunk (même ligne d'extraction), ni au passage suivant du planificateur.
- **P8 — Événements postérieurs au suivi.** Un événement n'est envoyé que si son instant (`chunkArrivalTime − 1 h` pour `soon`, `chunkArrivalTime` pour `arrived`) est ≥ `createdAt` du suivi : suivre une raffinerie ne déclenche pas de notification pour des chunks déjà arrivés (pas d'avalanche).
- **P9 — Suiveur encore membre.** Un suiveur dont `User::getCorporationId()` n'est plus celui du chunk ne reçoit rien (son suivi est conservé).
- **P10 — Planification.** Message `CheckMoonChunkAlerts` toutes les **5 minutes** (`SyncScheduler`, requête bon marché ; un pas de 30 minutes ferait arriver « dans 1 h » entre 60 et 30 minutes). Il traite les suivis de tous les utilisateurs et n'échoue pas silencieusement : l'exception remonte. Le tableau Scheduler de `CLAUDE.md` est mis à jour.
- **P11 — Front du suivi.** Dans le sous-onglet « Chunks lunaires », chaque raffinerie de la corporation a une bascule « Suivre / Ne plus suivre » (état lu dans `followedStructureIds`, mis à jour après la réponse de l'API) ; un message dit que les notifications se règlent dans les préférences (catégorie « Chunks lunaires »). HUD EVE, jamais la couleur seule (texte et glyphe), noms de raffinerie longs testés, textes dans `en.json` et `fr.json`. La catégorie `moon_chunk` apparaît dans les filtres et préférences de notifications (icône, couleur, libellé).

## 2. Exemples chiffrés (`now` = jeudi 2026-11-05)

| # | Cas | Attendu |
|---|---|---|
| A1 | Chunk de la corporation 98000001, utilisateur de 98000001, `chunkId` fourni | 201, `chunkId` de l'opération = le chunk |
| A2 | Même chunk, utilisateur de 98000002 ; ou `chunkId` inexistant | 404, aucune opération |
| A3 | Chunk dont `naturalDecayTime` est 2026-11-05 15:00, `now` = 16:00 | 400, aucune opération |
| A4 | Utilisateur sans corporation connue + `chunkId` | 404, aucune opération |
| A5 | Sans `chunkId` | `chunkId` nul, résultat identique à avant |
| F1 | `POST` suivi d'une raffinerie dont la corporation a des extractions ; deuxième `POST` | 201 puis une seule ligne |
| F2 | `POST` suivi d'une raffinerie sans extraction de la corporation de l'utilisateur | 400, rien créé |
| F3 | `DELETE` d'un suivi existant ; `DELETE` d'une raffinerie non suivie ; `DELETE` du suivi d'un autre utilisateur | 204 ; 404 ; 404 |
| F4 | `GET` des extractions, utilisateur qui suit 1001 mais pas 1002 | `followedStructureIds` = [1001] |
| N1 | Suivi créé mercredi 12:00, chunk arrive jeudi 20:00 ; `now` = jeudi 18:59 | aucune notification |
| N2 | Même cas, `now` = jeudi 19:00 | une notification `soon` ; repassage à 19:05 : aucune de plus |
| N3 | Même cas, `now` = jeudi 20:00 | une notification `arrived` (la `soon` n'est pas rejouée) |
| N4 | Suivi créé jeudi 19:30, chunk à 20:00 | pas de `soon` (19:00 < création), `arrived` à 20:00 |
| N5 | `now` = `naturalDecayTime` | rien |
| N6 | Suivi créé jeudi 21:00, chunk arrivé jeudi 20:00 et pas explosé | rien (événement antérieur au suivi) |
| N7 | Deux utilisateurs suivent la raffinerie, un seul a quitté la corporation | une notification, pour celui qui est resté |
| N8 | Un utilisateur désactive la catégorie `moon_chunk` | `dispatch` n'enregistre rien (comportement existant), l'événement est quand même marqué envoyé |
| N9 | Chunk d'une raffinerie non suivie | rien |

## 3. Cas limites

- Le chunk d'une extraction resynchronisée garde sa ligne (upsert, C4) : pas de doublon d'alerte.
- Message de notification : `moonName` ou `structureName` nul = identifiant affiché, jamais de valeur inventée.
- Suppression d'un utilisateur : ses suivis et alertes disparaissent (clé étrangère `ON DELETE CASCADE`, sur l'extraction aussi).

## 4. Hors périmètre

- Notifications ESI en jeu (aucune lecture des notifications ESI), valeur du chunk (#308, livrée), taxe lunaire (#273, livrée), visibilité alliance.
- Choisir le système d'une opération à la création (fixé au démarrage).
- Suivre une raffinerie d'une autre corporation. Réglage de l'avance (1 h fixe).
- Code Industry.

## 5. Décisions prises en autonomie

- Pas d'unicité chunk → opération et chunk exploité refusé (400) : plusieurs équipes peuvent miner le même chunk, un chunk explosé ne se mine plus.
- Pas de nouvelle notification de chunk pour les événements antérieurs au suivi (P8).
- Pas de Mercure dédié au suivi (actions synchrones) ; les notifications passent par le topic `notifications` déjà déclaré.
- Pas de bascule d'abonnement par raffinerie dans les préférences : la catégorie `moon_chunk` se coupe globalement, comme les autres.

## 6. Termes proposés au glossaire

| Terme | Définition |
|---|---|
| **Suivi de raffinerie** (*refinery follow*) | Choix d'un utilisateur de recevoir les notifications de chunk d'une raffinerie de sa corporation. |
| **Alerte de chunk** (*chunk alert*) | Notification `soon` (prêt dans 1 h) ou `arrived` (arrivé) d'un chunk pour un suiveur, envoyée une seule fois. |
