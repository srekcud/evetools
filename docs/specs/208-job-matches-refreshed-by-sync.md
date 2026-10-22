# Spec #208 — La sync rafraîchit les jobs matchés des étapes

## Contexte

À la création d'un job matché (`IndustryJobMatcher::createJobMatch()`, `LinkJobProcessor`), le statut, la date de fin, le coût et les runs du job en cache sont copiés dans `IndustryStepJobMatch`. L'écran des étapes lit ces copies (`IndustryResourceMapper.php:~258-263`). La sync des jobs (`IndustryJobSyncService`) met à jour le cache des jobs mais jamais ces copies, et elle ne rafraîchit pas non plus la date de fin du job en cache. Seul « Matcher les jobs » recrée les matchs, et seulement ceux des étapes en mode `auto` : les matchs des étapes achetées ou en mode `manual` restent figés (spec #59, `keepsItsJobMatches`). Une étape affiche donc un job « actif » déjà livré.

## Règles métier

- **R1 — Rafraîchissement par la sync.** Après une sync d'un personnage, chaque job matché dont le job ESI a été lu dans cette sync (job du personnage ou d'un de ses alts lu par l'endpoint corpo, créé ou mis à jour) ou marqué « livré » par la sync reprend du job en cache : statut, date de fin, coût, runs. Quel que soit le mode de l'étape (`auto`, `manual`, `none`) et même si l'étape est achetée.
- **R2 — Date de fin du job en cache.** La mise à jour d'un job existant reprend la date de fin `end_date` renvoyée par l'ESI (elle peut changer, par exemple après une pause).
- **R3 — Rien d'autre ne change.** Les autres champs du job matché (personnage, structure, structure prévue, bonus prévu, date de match) ne changent pas. Un job matché dont le job n'a pas été lu dans cette sync (job d'un corpmate, job d'un alt non lu, jobs perso inconnus sans scope, spec #207) n'est pas touché.
- **R4 — Une seule copie.** La copie des quatre champs du job vers le job matché est la même à la création (matcher, liaison manuelle) et au rafraîchissement.

## Exemples chiffrés

Personnage A. Job 100 de A en cache et matché sur une étape : `active`, fin 2026-10-20T12:00Z, coût 1 500 000,50, 10 runs.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Étape en mode `manual`, l'ESI renvoie 100 `delivered` | job matché `active` | job matché `delivered` |
| E2 | Étape achetée (`purchased`), l'ESI renvoie 100 `ready` | `active` | `ready` |
| E3 | Étape en mode `auto`, aucun « Matcher les jobs », l'ESI renvoie 100 `delivered` | `active` | `delivered` |
| E4 | L'ESI renvoie 100 `active` avec fin 2026-10-21T08:30Z | job en cache et job matché : fin 2026-10-20T12:00Z | les deux : fin 2026-10-21T08:30Z |
| E5 | 100 absent de l'ESI, fin passée, jobs connus : marqué « livré » par la sync | job matché `active` | job matché `delivered` |
| E6 | Job matché 200 d'un corpmate, renvoyé par l'endpoint corpo `delivered` | `active` | inchangé (`active`) |
| E7 | Token de A sans scope des jobs perso (spec #207), 100 absent | `active` | inchangé |
| E8 | Coût et runs du job matché divergents (900 000, 5) du job en cache (1 500 000,50, 10), l'ESI renvoie 100 `active` | 900 000, 5 | 1 500 000,50, 10 |
| E9 | Personnage, structure prévue et date de match du job matché | — | inchangés après E1 |

## Cas limites

- Un job matché sur deux étapes (données anciennes) : les deux sont rafraîchis.
- Le coût du job en cache vient de la création (`cost ?? 0`, audit n° 10, hors périmètre) : le job matché le reprend tel quel.

## Hors périmètre

- Recalcul des matchs (`matchEsiJobs`) et liaison manuelle perdue (#187), job annulé accepté par la liaison manuelle (#188).
- Notifications « job terminé » (#216).
- Rafraîchissement du coût et des runs du job en cache depuis l'ESI (audit n° 10).

## Décisions prises en autonomie (mode nuit)

- R1 couvre les jobs lus par l'endpoint corpo pour un alt de l'utilisateur : la sync les met déjà à jour dans le cache (`installerLookup`), le job matché suit le cache.
- R4 : une méthode du job matché (par exemple `IndustryStepJobMatch::copyJobState(CachedIndustryJob)`) remplace les quatre setters dupliqués dans le matcher et `LinkJobProcessor` ; les autres contrôles de `LinkJobProcessor` (#188) ne changent pas.

## Glossaire

« Job matché » : ajouter « La sync des jobs lui recopie le statut, la date de fin, le coût et les runs du job ESI, quel que soit le mode de l'étape (spec #208). »
