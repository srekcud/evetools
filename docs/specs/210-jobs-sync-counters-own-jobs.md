# Spec #210 — Les compteurs de fin de sync ne comptent que les jobs du joueur

## Contexte

À la fin de `IndustryJobSyncService::syncCharacterJobs()`, l'événement Mercure `syncCompleted` (`industry-jobs`) porte `total`, `active`, `ready` et le message `%d active jobs, %d ready to deliver`. Ils sont calculés sur `$jobsByJobId`, c'est-à-dire tous les jobs lus, y compris les jobs corpo des autres membres de la corporation, alors que la sync n'enregistre que les jobs installés par les personnages de l'utilisateur (filtre `installerLookup`).

## Règles métier

- **R1 — Périmètre.** `total`, `active` et `ready` comptent les jobs lus dans l'ESI pendant cette sync et installés par un personnage de l'utilisateur (le personnage synchronisé ou un de ses alts lu par l'endpoint corpo), chacun une seule fois. Les jobs des corpmates ne comptent pas.
- **R2 — Statuts.** `active` = statut ESI `active`, `ready` = statut ESI `ready` ; les autres statuts (`paused`, `delivered`, `cancelled`, `reverted`) comptent seulement dans `total`.
- **R3 — Jobs non lus.** Un job marqué « livré » par la sync parce qu'il a disparu de l'ESI n'est pas compté (il n'a pas été lu).
- **R4 — Inchangé.** Le log `Industry jobs synced` garde son `jobCount` (tous les jobs lus, diagnostic) ; les autres événements Mercure ne changent pas.

## Exemples chiffrés

Personnage A (utilisateur U, alt B dans la même corporation). Endpoint corpo : 5 jobs — 900 (A, `active`), 901 (A, `ready`), 902 (corpmate, `active`), 903 (corpmate, `active`), 904 (corpmate, `ready`). Endpoint perso de A : 100 (A, `active`).

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Jobs ci-dessus | total 6, active 4, ready 2 | total 3, active 2, ready 1, message « 2 active jobs, 1 ready to deliver » |
| E2 | Corpo seule : 900, 901, 902, 903, 904 (A sans scope perso) | total 5 | total 2, active 1, ready 1 |
| E3 | Job 905 de l'alt B (`ready`) dans l'endpoint corpo, en plus de E1 | total 7 | total 4, active 2, ready 2 |
| E4 | 900 renvoyé à la fois par l'endpoint perso et l'endpoint corpo | compté une fois | compté une fois |
| E5 | Job 110 de A en cache `active`, fin passée, absent de l'ESI (marqué « livré ») ; ESI : 100 seul | total 1 | total 1, active 1, ready 0 |
| E6 | 906 (A, `paused`) et 907 (A, `delivered`) ajoutés à E1 | — | total 5, active 2, ready 1 |
| E7 | Aucun job du joueur, 3 jobs de corpmates | total 3 | total 0, active 0, ready 0, message « 0 active jobs, 0 ready to deliver » |

## Hors périmètre

- Un cycle Mercure par personnage sur un topic par utilisateur (audit n° 11).
- Relecture des jobs corpo par chaque personnage (#213).

## Décisions prises en autonomie (mode nuit)

- R4 : le log garde le nombre total de jobs lus, utile pour diagnostiquer la lecture corpo ; seul l'affichage joueur change.

## Glossaire

Aucun terme ajouté.
