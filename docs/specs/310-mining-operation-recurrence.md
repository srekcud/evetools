# Spec #310 — Minage groupé : opérations récurrentes

- **Statut** : rédigée et validée en autonomie (mode nuit) le 2026-11-03, à relire par Sylvain.
- **Circuit** : `circuit:m`. Une migration. Dépend de #262 (opération) et #274 (calendrier des chunks, fusionné).
- **Maquette** : `mockups/grouped-mining-v4.html`, étape 4 : champ « Récurrence » de la création (`radio` une fois / chaque jeudi 20:00 / à chaque chunk de la raffinerie X), panneau « Récurrences » (règle, prochaine occurrence, owner), icône ↻ sur les opérations issues d'une récurrence.

## 1. Règles métier

- **R1 — Récurrence.** Règle attachée à un propriétaire, créée avec une opération : `weekly` (chaque semaine, même jour UTC et même heure UTC que `plannedStartAt` de l'opération créée) ou `chunk` (à chaque chunk d'une raffinerie, identifiée par `structureId`). « Une seule fois » : pas de récurrence.
- **R2 — Création.** `POST /api/mining-operations` accepte un champ optionnel `recurrence` : `{kind: 'weekly'}` ou `{kind: 'chunk', structureId}`. Absent : comportement inchangé. L'opération créée porte la récurrence (`recurrenceId`) mais n'est liée à aucun chunk (créer une opération depuis un chunk reste hors périmètre).
- **R3 — Règles copiées.** La récurrence garde `label`, `visibility`, `splitMode`, `corporationId` de l'opération initiale. Une occurrence reprend en plus, depuis l'opération la plus récente de la récurrence encore existante (par `plannedStartAt`) : base de valorisation (forme, marché, côté, structure, rendement de retraitement), part du booster, part de la corporation. Plus aucune opération existante : valeurs par défaut de l'entité. Le booster est repris seulement quand c'était le propriétaire (son adhésion devient booster de l'occurrence) ; sinon il reste vide et le blocage `BoosterMissing` existant s'applique.
- **R4 — Occurrence.** Opération `planned`, propriétaire = celui de la récurrence, adhésion propriétaire `Accepted`, inscrite avec ses personnages `Eligible` au moment de la création (les autres sont ignorés, pas d'erreur ; zéro personnage est permis), nouveau code d'invitation, `recurrenceId` renseigné.
- **R5 — `weekly`.** Prochaine occurrence = `plannedStartAt` de la dernière occurrence + 7 jours, jamais dans le passé : si cette date est antérieure à `now`, on avance de 7 jours jusqu'à une date ≥ `now` (les occurrences manquées ne sont pas rattrapées). Elle est créée dès que `now ≥ date − 7 jours`. Au plus une occurrence par passage. Un chunk est lié si **exactement un** chunk de la corporation de la récurrence a son `chunkArrivalTime` le même jour UTC que l'occurrence ; zéro ou plusieurs : l'occurrence est créée sans chunk lié.
- **R6 — `chunk`.** Pour chaque extraction de la corporation de la récurrence sur la raffinerie `structureId`, avec `extractionStartTime ≥ createdAt` de la récurrence, `chunkArrivalTime > now`, et sans occurrence déjà liée à cette extraction : une occurrence est créée, `plannedStartAt` = `chunkArrivalTime`, chunk lié. Une seule occurrence par extraction (idempotent).
- **R7 — Validation de la création.** `chunk` exige que la corporation du propriétaire soit connue (`corporationId` non nul) et qu'au moins une extraction de cette corporation existe pour `structureId` ; sinon 400, aucune raffinerie inventée. `weekly` et `chunk` : la visibilité de l'opération initiale n'est pas contrainte.
- **R8 — Arrêt.** `DELETE /api/mining-operations/recurrences/{id}` (propriétaire seul) : la récurrence passe à arrêtée (`stoppedAt`), plus aucune occurrence n'est créée, les opérations déjà créées gardent leur `recurrenceId` et ne changent pas. Non-propriétaire ou inconnue : 404. Déjà arrêtée : 404. Modifier une récurrence = l'arrêter et en créer une autre (pas de PATCH).
- **R9 — Lecture.** `GET /api/mining-operations/recurrences` (`ROLE_USER`) : récurrences actives du propriétaire connecté seulement, avec `id`, `kind`, `label`, `structureId`, `structureName`, `nextOccurrenceAt` (`weekly` : date R5 ; `chunk` : `null`), `createdAt`. `MiningOperationResource` expose `recurrenceId` (nullable) et `chunkId` (nullable) ; `chunkId` est l'identifiant de `CorporationMiningExtraction`.
- **R10 — Planification et Mercure.** Message `TriggerMiningOperationRecurrences` toutes les 30 minutes (`SyncScheduler`), un message `CreateRecurringMiningOperations` par récurrence active. Chaque occurrence créée publie `publishMiningOperationEvent([ownerId], operationId, 'recurrence_created')` sur `/user/{userId}/mining-operations` (topic déjà déclaré dans `getTopicsForUser()`) ; le front recharge la liste à la réception. Tableau Scheduler de `CLAUDE.md` mis à jour.
- **R11 — Front.** Modale de création (`NewMiningOperationModal.vue`) : champ « Récurrence » à trois choix ; le choix `chunk` n'apparaît que si la corporation a au moins une raffinerie avec extraction (liste lue sur l'API de #274) et demande la raffinerie. Liste des opérations : ↻ devant le libellé quand `recurrenceId` est non nul. Panneau « Récurrences » (règle en clair, prochaine occurrence, bouton Arrêter avec confirmation). HUD EVE, noms longs testés, textes dans `en.json` et `fr.json`, jamais la couleur seule.

## 2. Exemples chiffrés (UTC)

| # | Cas | Attendu |
|---|---|---|
| E1 | Opération créée jeudi 2026-11-05 20:00, `weekly` ; `now` = jeudi 2026-11-05 20:05 | occurrence jeudi 2026-11-12 20:00, créée (now ≥ 12/11 − 7 j) |
| E2 | Même récurrence, `now` = mercredi 2026-11-04 | rien créé : la prochaine (12/11 20:00) n'est créée qu'à partir du 05/11 20:00 |
| E3 | Dernière occurrence jeudi 2026-11-05, scheduler à l'arrêt jusqu'au jeudi 2026-11-26 21:00 | une seule occurrence : jeudi 2026-12-03 20:00 ; aucune pour 12/11, 19/11, 26/11 |
| E4 | Deuxième passage du scheduler juste après E1 | aucune seconde occurrence (prochaine = 19/11, créée à partir du 12/11 20:00) |
| E5 | `weekly`, un seul chunk de la corporation arrive jeudi 2026-11-12 18:30 | occurrence du 12/11 20:00 liée à ce chunk (même jour UTC) |
| E6 | Même cas, aucun chunk le 12/11 ; ou deux chunks le 12/11 | occurrence créée, `chunkId` nul |
| E7 | `chunk` sur la raffinerie 1001, extraction lancée le 2026-11-02 18:00 (après `createdAt`), arrivée le 2026-11-05 20:00, `now` = 2026-11-03 | occurrence `plannedStartAt` 2026-11-05 20:00, chunk lié ; repassage : toujours une seule |
| E8 | `chunk` : extraction lancée avant `createdAt` de la récurrence | ignorée |
| E9 | `chunk` : extraction d'une autre raffinerie ou d'une autre corporation | ignorée |
| E10 | `chunk` : `chunkArrivalTime` déjà passé | ignorée |
| E11 | Récurrence arrêtée, puis passage du scheduler | aucune occurrence ; opérations existantes inchangées, `recurrenceId` conservé |
| E12 | Création avec `chunk` et `structureId` inconnu de la corporation du propriétaire | 400, rien créé (ni opération ni récurrence) |
| E13 | Création sans `recurrence` | aucune récurrence, résultat identique à avant |
| E14 | Opération courante avec Jita/vente/prorata, booster 10 %, corporation 5 %, booster = propriétaire | occurrence : mêmes valeurs, propriétaire booster |
| E15 | Même cas, booster = un autre membre | occurrence : 10 % copiés, booster vide |
| E16 | Propriétaire avec deux personnages, l'un déjà inscrit à une autre opération ouverte | occurrence créée avec le seul personnage éligible |
| E17 | Autre utilisateur : `DELETE` de la récurrence du propriétaire / `GET` des récurrences | 404 / ne voit pas la récurrence |
| E18 | Chaque occurrence créée | un événement Mercure `recurrence_created` au propriétaire |

## 3. Cas limites

- Toutes les opérations d'une récurrence supprimées : la récurrence continue, avec les valeurs par défaut de valorisation (R3).
- Propriétaire qui change de corporation : `corporationId` de la récurrence reste celui de la création ; `chunk` continue sur cette corporation.
- Occurrence `weekly` dont la date est exactement `now` : créée (`now ≥ date − 7 jours`), pas de rattrapage passé.
- Échec de création d'une occurrence (erreur inattendue) : l'exception remonte, pas de `catch` silencieux ; la récurrence n'avance pas, le passage suivant réessaie.

## 4. Hors périmètre

- Créer l'opération initiale depuis un chunk (lien chunk de la première opération), notifications de chunk, valeur du chunk (#308), taxe lunaire (#273), sites (#267).
- Modification d'une récurrence (arrêter puis recréer), récurrence quotidienne ou mensuelle, jour/heure choisis indépendamment de `plannedStartAt`.
- Code Industry.

## 5. Décisions prises en autonomie

- Règles lues sur l'opération la plus récente existante plutôt que figées à la création (la valorisation se règle après la création, elle doit se propager).
- Occurrences manquées non rattrapées ; un chunk par jour exigé pour lier une occurrence `weekly` (pas de choix arbitraire).
- Booster repris seulement s'il est le propriétaire.
- Arrêt par `DELETE` logique (`stoppedAt`) pour garder le lien des opérations existantes.

## 6. Termes proposés au glossaire

| Terme | Définition |
|---|---|
| **Récurrence** (*recurrence*) | Règle qui crée une opération de minage à chaque semaine (`weekly`) ou à chaque chunk d'une raffinerie (`chunk`), avec les mêmes règles. |
| **Occurrence** (*occurrence*) | Opération créée par une récurrence. |
