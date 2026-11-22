# Spec — Une réservation d'achat expire après 48 heures (#1301)

## Contexte

« Je m'en charge » (`IndustryProjectLineReservation`) réserve une quantité d'une ligne de BOM pour un participant ; elle n'expire jamais et bloque donc la quantité libre des autres tant que le participant ne la libère pas. Le flux (prototype Ishtar) attend 48 h : passé ce délai la réservation disparaît, les autres membres peuvent reprendre la ligne. Les lecteurs existants (liste de la BOM, quantité libre, matcher de jobs, bloc « Où acheter », droit de marquer une ligne) ne doivent plus voir une réservation échue, même avant la purge.

## Règles

- **R1.** `IndustryProjectLineReservation` porte `expiresAt` (`datetime_immutable`, non nul) = `reservedAt` + 48 h, constante `LIFETIME_IN_HOURS = 48`. Le constructeur garde ses trois arguments obligatoires et accepte en 4e un `?\DateTimeImmutable $reservedAt` (défaut : maintenant) ; `getExpiresAt()` et `isExpiredAt(\DateTimeImmutable $moment): bool` (vrai quand `expiresAt <= $moment`).
- **R2.** `renew()` remet `reservedAt` à maintenant et `expiresAt` à maintenant + 48 h.
- **R3.** `LineReservationService::reserve` : si le participant a déjà une réservation sur la ligne (échue ou non, y compris échue non encore purgée), elle est mise à jour (quantité) et renouvelée (`renew`), jamais une seconde ligne (contrainte d'unicité `bom_line_id, participant_id`). Sinon création, échéance +48 h.
- **R4.** Repository, seules les réservations non échues (`expiresAt > maintenant`, horloge `Symfony\Component\Clock\ClockInterface` injectée dans le repository) sont lues par : `findByProject`, `findByProjectAndParticipant`, `sumQuantityOfOthers`, `findOneByLineAndParticipant`. Une réservation échue d'un autre participant ne réduit donc plus la quantité libre de `reserve`.
- **R5.** Nouvelle méthode `findOneIncludingExpiredByLineAndParticipant` (réservée à `reserve`, R3) et `findExpired(): list<IndustryProjectLineReservation>` (échues à l'instant de l'horloge).
- **R6.** Purge : message `PurgeExpiredLineReservations`, handler `PurgeExpiredLineReservationsHandler` (modèle `PurgeExpiredSharedListsHandler`) : charge `findExpired()`, supprime chaque réservation, flush, et publie pour chacune `MercurePublisherService::publishGroupProjectEvent` sur le projet de la ligne, action `line_reserved`, charge `{bomItemId, bomItemName, characterName (PilotName::of), quantity: 0, released: true}` (même forme que `ReleaseLineProcessor`). Aucune réservation échue : aucune suppression, aucune publication, tâche complétée avec « 0 reservation(s) deleted ».
- **R7.** Suivi : `SyncTracker` start/complete (message `<n> reservation(s) deleted`) ou fail avec relance de l'exception, clé `line-reservations-purge`, fréquence attendue 1800 s dans `SyncTracker::EXPECTED_INTERVALS`.
- **R8.** Planification : `SyncScheduler` ajoute `PurgeExpiredLineReservations` toutes les 30 minutes (via `everyViaAsync`) ; routage `async` dans `config/packages/messenger.yaml` ; déclenchable depuis l'admin (`TriggerableSyncs` : `line-reservations-purge`) ; libellé d'aide `HelpDescriptions` (« Purge des réservations de lignes expirées » / « Purge of expired line reservations ») et ligne « 30 min » dans les tables d'aide régénérées par `app:help:generate-tables` ; libellé admin `line-reservations-purge` dans `en.json` et `fr.json` (à côté de `shared-lists-purge`) ; mise à jour de la liste des clés de `SchedulerHealthTable.spec.ts`.
- **R9.** API : chaque entrée de `reservations` d'une ligne de la BOM (`ProjectBomProvider::mapToResource`) gagne `expiresAt` (chaîne `\DateTimeInterface::ATOM`). Aucune autre forme n'est modifiée.
- **R10.** Migration réversible : ajoute `expires_at TIMESTAMP(0) WITHOUT TIME ZONE` ; les lignes existantes reçoivent `NOW() + interval '48 hours'` (délai de grâce, aucune réservation existante n'expire au déploiement), puis `NOT NULL` ; `down` supprime la colonne. Type SQL copié de `reserved_at` (migration `Version20261109210000`), sans commentaire.
- **R11.** Glossaire : l'entrée « Réservation de ligne » ajoute l'échéance de 48 h, le renouvellement par une nouvelle réservation, le filtre de lecture et la purge `PurgeExpiredLineReservations` ; `CLAUDE.md` : ligne du tableau Scheduler.

## Exemples

| # | Situation (maintenant = T) | Résultat |
|---|---|---|
| E1 | Sana réserve 10 sur une ligne de 10 à T | `expiresAt` = T + 48 h |
| E2 | Réservation de Sana faite à T − 47 h 59 | lue par `findByProject`, compte dans `sumQuantityOfOthers` |
| E3 | Réservation de Sana faite à T − 48 h pile | non lue, ne compte plus (échéance atteinte) |
| E4 | Sana (10, échue non purgée), Maya réserve 10 sur la ligne de 10 | acceptée : quantité libre 10 |
| E5 | Sana réserve de nouveau, sa réservation de T − 50 h existe encore | même ligne en base, quantité mise à jour, `expiresAt` = T + 48 h |
| E6 | Sana réserve 4 à T − 10 h, puis 6 à T | une seule réservation (6), échéance T + 48 h |
| E7 | Purge avec 2 échues (projets A et B) et 1 active | les 2 supprimées, 2 événements `line_reserved` released, l'active intacte |
| E8 | Purge sans échue | 0 suppression, 0 publication, `complete('0 reservation(s) deleted')` |
| E9 | Échue d'un participant sans utilisateur (anonymisé) | supprimée, `characterName` donné par `PilotName::of` (jamais de défaut inventé) |

## Cas limites

- Échéance exactement égale à maintenant : échue (`<=`).
- La suppression d'un participant, d'une ligne ou d'un projet garde ses cascades existantes.
- `settleApproval` ne voit pas une réservation échue : elle reste jusqu'à la purge, sans effet sur les quantités.
- `LineMarkAccess::assertMayMark` : une réservation échue ne donne plus le droit de marquer la ligne (conséquence de R4 sur `findOneByLineAndParticipant`).
- `ReleaseLineProcessor` cherche par id (`find`) : libérer une réservation échue non purgée reste possible.

## Hors périmètre

- Affichage de l'échéance dans l'interface (colonne du kanban, compagnon) : issues #1307 et suivantes, le champ API est livré ici.
- Rappel ou notification avant l'échéance ; durée réglable.
- Toute autre réservation (compagnon, stock).

## Décisions

- Purge toutes les 30 minutes (pas quotidienne) : les écrans ouverts reçoivent la libération par Mercure peu après l'échéance.
- Délai de grâce de 48 h pour les réservations existantes à la migration : aucune ne disparaît au déploiement.
- Horloge dans le repository : les lecteurs existants gardent leurs signatures et leurs constructeurs.
