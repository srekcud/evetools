# Spec #216 — Notification de job terminé : une fois, et une seule

## Contexte

`IndustryJobSyncService::syncCharacterJobs()` n'envoie la notification « job terminé » (hub + Mercure `industry-job-completed` + avancement projet) que sur la transition `active` → `ready` d'un job déjà en cache (`IndustryJobSyncService.php:~145-160`). Trous : un job vu pour la première fois déjà `ready`, un job passé `active` → `delivered` entre deux syncs, un job `paused` → `ready`. Les notifications partent après le flush, à partir d'une liste en mémoire : si le dispatcher lève une exception, celles qui restent sont perdues pour toujours (la transition est déjà enregistrée). Une sync manuelle (« Matcher les jobs », conteneur `app`) et la sync planifiée (conteneur `worker`) qui voient la même transition notifient deux fois.

## Règles métier

- **R1 — Job à notifier.** Un job du personnage ou d'un de ses alts lu dans cette sync (pas un job de corpmate) est à notifier quand son statut ESI est `ready` ou `delivered` et que sa notification de fin n'a pas encore été réservée. Un job `ready` vu pour la première fois est à notifier ; un job `paused` ou `active` qui passe `ready` ou `delivered` aussi.
- **R2 — Historique jamais notifié.** Un job créé par la sync directement `delivered` (historique `include_completed`, première sync d'un personnage) n'est pas notifié : sa notification est réservée à la création, sans envoi. Un job marqué « livré » par la sync parce qu'il a disparu de l'ESI n'est pas notifié non plus (réservée sans envoi). Les jobs `cancelled` et `reverted` ne sont jamais notifiés.
- **R3 — Réservation atomique.** Avant l'envoi, la sync réserve la notification du job en base par une mise à jour conditionnelle (`completion_notified_at` passe de NULL à maintenant). Seule la sync qui réussit la réservation envoie la notification : deux syncs concurrentes n'en envoient qu'une.
- **R4 — Échec d'envoi : rien de perdu.** Si l'envoi d'une notification lève une exception, sa réservation est annulée (remise à NULL), l'exception remonte (`syncError` publié, Messenger refait la tentative) ; la sync suivante la renvoie (R1). Les jobs déjà notifiés dans cette sync restent réservés.
- **R5 — Contenu.** Titre et données inchangés. Message : `ready to deliver` pour un job `ready`, `delivered` pour un job `delivered`. L'avancement projet (`notifyProjectProgress`) porte sur les jobs effectivement notifiés.
- **R6 — Reprise de l'existant.** La migration réserve la notification de tous les jobs déjà en base au statut `ready`, `delivered`, `cancelled` ou `reverted` (`completion_notified_at = cached_at`) : aucun envoi en masse au déploiement. Les jobs `active` et `paused` restent à NULL.

## Exemples chiffrés

Personnage A, utilisateur U. Job 100 de A : fabrication, produit 34 (Tritanium dans le SDE), 10 runs.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | 100 en cache `active`, l'ESI le renvoie `ready` | 1 notification | 1 notification « … (10x) ready to deliver », réservée |
| E2 | 100 inconnu en base, l'ESI le renvoie `ready` | 0 | 1 notification, réservée |
| E3 | 100 inconnu en base, l'ESI le renvoie `delivered` | 0 | 0, réservée à la création |
| E4 | 100 en cache `active`, l'ESI le renvoie `delivered` | 0 | 1 notification « … (10x) delivered » |
| E5 | 100 en cache `ready`, déjà réservé, l'ESI le renvoie `ready` | 0 | 0 |
| E6 | 100 en cache `ready`, non réservé (envoi précédent en échec), l'ESI le renvoie `ready` | 0 | 1 notification |
| E7 | 100 en cache `paused`, l'ESI le renvoie `ready` | 0 | 1 notification |
| E8 | 100 en cache `active` → `ready`, mais la réservation échoue (autre sync l'a prise) | 1 | 0 |
| E9 | Deux jobs 100 et 101 à notifier, l'envoi de 101 lève une exception | 100 notifié, 101 perdu | 100 notifié et réservé ; 101 non réservé ; l'exception remonte, `syncError` publié |
| E10 | 100 en cache `active`, fin passée, absent de l'ESI : marqué « livré » | 0 | 0, réservé |
| E11 | Job 200 d'un corpmate renvoyé `ready` par l'endpoint corpo | 0 | 0 |
| E12 | 100 en cache `active`, l'ESI le renvoie `cancelled` puis (sync suivante) `reverted` | 0 | 0 |
| E13 | Réservation en base : deux réservations successives du même job | — | la première réussit, la seconde échoue ; après annulation, une nouvelle réservation réussit |

## Cas limites

- Préférence de notification industrie désactivée : la réservation est faite, le dispatcher n'envoie rien (comportement actuel du dispatcher) ; pas de renvoi si la préférence est réactivée.
- `userId` null (utilisateur non persisté) : aucune notification, aucune réservation, comme aujourd'hui.

## Hors périmètre

- Compteurs Mercure de fin de sync (audit n° 5), un cycle Mercure par personnage (audit n° 11).
- Doublon possible entre la notification et le message Mercure `industry-job-completed` côté front.

## Décisions prises en autonomie (mode nuit)

- Colonne `cached_industry_jobs.completion_notified_at` (nullable) + migration, plutôt qu'un verrou : la sync manuelle et la planifiée tournent dans deux conteneurs (`app`, `worker`), un verrou `flock` ne les sépare pas ; la mise à jour conditionnelle en base est atomique.
- R2 : pas de notification pour l'historique `delivered` ni pour un job disparu, pour ne pas inonder le hub à la première sync et ne pas annoncer une livraison déduite.
- R4 : l'exception remonte plutôt que d'être journalisée et avalée (règle « pas de catch silencieux »).
- R5 : message `delivered` distinct, le message `ready to deliver` serait faux pour un job livré.

## Glossaire

- **Notification de fin de job** (Job completed notification) : notification envoyée une seule fois pour un job du joueur devenu `ready` ou `delivered`, réservée en base avant l'envoi (spec #216). `CachedIndustryJob::$completionNotifiedAt`.
