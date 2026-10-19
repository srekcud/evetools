# Spec #122 — Un échec de lecture des jobs corpo n'est plus avalé

## Contexte

`IndustryJobSyncService::syncCharacterJobs()` lit les jobs perso du personnage puis, s'il a le scope `esi-industry.read_corporation_jobs.v1` et que sa corporation n'a pas encore été lue dans la sync, les jobs de la corporation. Toute erreur de cette lecture est rattrapée (`catch (\Throwable)`, log en debug) et la sync continue. Les jobs corpo restent alors inconnus, mais le marquage « livré » (jobs actifs ou prêts du personnage, absents de l'ESI, date de fin passée) s'applique quand même : un job corpo prêt à livrer peut passer « livré » à tort. Depuis #30, chaque message personnage relit l'endpoint corpo.

## Règles métier

- **R1 — 403 : rôles manquants.** Une `EsiApiException` 403 sur la lecture des jobs corpo est un cas attendu (le personnage n'a pas les rôles corpo). Warning dans les logs, la sync des jobs perso continue.
- **R2 — Autres échecs : l'erreur remonte.** Toute autre erreur de la lecture des jobs corpo (420 après la relance d'`EsiClient`, 5xx, réseau, autre statut) remonte de `syncCharacterJobs()`, sans rien écrire (pas de flush). Messenger refait la tentative ; `MatchJobsProcessor` et `MatchProjectJobsCommand` gèrent déjà l'exception par personnage.
- **R3 — Jobs corpo inconnus : pas de marquage « livré ».** Après un 403 (R1), aucun job du personnage n'est marqué « livré » dans cette sync : on ne sait pas lesquels sont des jobs corpo encore présents dans l'ESI. Les jobs perso lus sont créés et mis à jour normalement.
- **R4 — Jobs corpo connus : inchangé.** Corporation lue dans cette sync (par ce personnage ou un autre avant lui), ou personnage sans le scope corpo : marquage « livré » comme aujourd'hui.

## Exemples chiffrés

Personnage A, scope corpo, job corpo 900 en base (statut `ready`, fin passée, installé par A), absent des jobs perso ; job perso 100 en base (`active`, fin passée), absent de l'ESI.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Endpoint corpo en 403 | 900 et 100 marqués `delivered` | aucun marqué `delivered` ; jobs perso de l'ESI enregistrés ; warning loggé |
| E2 | Endpoint corpo en 503 | 900 et 100 marqués `delivered`, aucune erreur | l'exception remonte, aucun flush, rien marqué |
| E3 | Endpoint corpo en 420 (après relance du client) | idem E2 | idem E2 |
| E4 | Endpoint corpo OK, 900 présent | 900 conservé, 100 `delivered` | inchangé |
| E5 | Corporation déjà lue dans la sync par un autre personnage | 100 `delivered` | inchangé |
| E6 | Personnage sans scope corpo | 100 `delivered` | inchangé |
| E7 | 403 pour A puis B (même corporation) dans la même sync | B relit l'endpoint corpo | inchangé (la corporation n'est pas marquée lue après un 403) |

## Cas limites

- Erreur non ESI (`\Throwable` quelconque) pendant la lecture corpo : traitée comme R2.
- Un 403 laisse la corporation « non lue » : un autre personnage de la même corporation, avec les rôles, la lit ensuite dans la même sync.

## Hors périmètre

- Personnage sans scope corpo dont un job corpo est marqué « livré » (R4, comportement actuel).
- Déduplication de la lecture corpo entre messages personnage (#30).
- `?? 0` sur le coût d'un job (`IndustryJobSyncService.php:164`).

## Décision prise en autonomie

- R3 plutôt que « marquer seulement les jobs perso » : l'entité `CachedIndustryJob` ne distingue pas un job corpo d'un job perso ; sauter le marquage est l'option qui n'invente rien.
- R1 limité au 403 : un 401 ou un 404 remonte (R2), ce n'est pas l'absence de rôles.

## Glossaire

Aucun terme ajouté.
