# Spec #207 — Sans le scope des jobs perso, aucun job n'est marqué « livré »

## Contexte

`IndustryJobSyncService::syncCharacterJobs()` lit les jobs perso seulement si le token a le scope `esi-industry.read_character_jobs.v1` (`IndustryJobSyncService.php:62`). Sans ce scope, les jobs perso du personnage sont inconnus, mais le marquage « livré » de fin de sync (`:187-206`) s'applique quand même : tout job `active` ou `ready` du personnage, absent des jobs lus et à date de fin passée, passe `delivered`. Seul `SyncIndustryJobsHandler` (sync planifiée) écarte ces personnages ; « Matcher les jobs » (`MatchJobsProcessor`) et `app:match-project-jobs` (`MatchProjectJobsCommand`) appellent la sync pour tous les personnages de l'utilisateur. Variante : personnage avec le scope corpo mais sans le scope perso, ses jobs corpo sont lus mais ses jobs perso disparaissent quand même.

## Règles métier

- **R1 — Jobs perso inconnus : pas de marquage « livré ».** Token sans le scope `esi-industry.read_character_jobs.v1` : aucun job du personnage n'est marqué « livré » dans cette sync, que les jobs corpo soient lus ou non.
- **R2 — Jobs corpo lus : enregistrés normalement.** Dans le cas R1, si le personnage a le scope corpo et que la lecture corpo réussit, les jobs corpo de ses personnages sont créés et mis à jour comme aujourd'hui.
- **R3 — Scope perso présent : inchangé.** Le marquage suit les règles de la spec #122 (pas de marquage après un 403 corpo ; marquage sinon, y compris sans scope corpo).
- **R4 — Correction dans le service.** La règle vit dans `IndustryJobSyncService` : elle couvre la sync planifiée, « Matcher les jobs » et la commande, sans filtre ajouté dans les appelants.

## Exemples chiffrés

Personnage A : job perso 100 en base (`active`, fin passée d'un jour, installé par A), absent de l'ESI ; job corpo 900 renvoyé par l'endpoint corpo (`ready`, installé par A).

| # | Scopes de A | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | ni perso ni corpo | 100 `delivered` | 100 reste `active`, aucun appel ESI |
| E2 | corpo seul, lecture corpo OK | 100 `delivered`, 900 enregistré | 100 reste `active`, 900 enregistré (`ready`) |
| E3 | corpo seul, corporation déjà lue dans la sync par un autre personnage | 100 `delivered` | 100 reste `active` |
| E4 | perso seul (régression, spec #122 E6) | 100 `delivered` | inchangé |
| E5 | perso + corpo, lecture OK (régression) | 100 `delivered` | inchangé |

## Cas limites

- Le job 100 reste `active` tant que A n'a pas le scope perso : c'est un état inconnu, pas une valeur inventée. Il redevient à jour quand le joueur reconnecte A avec le scope.
- Les compteurs Mercure de fin de sync ne changent pas (#5 de l'audit, hors périmètre).

## Hors périmètre

- Personnage sans scope corpo dont un job corpo est marqué « livré » (spec #122, R4, inchangé).
- Statuts `paused` et `reverted` (#209, lot suivant).
- Jobs matchés des étapes non rafraîchis (audit P1 n° 2).

## Décision prise en autonomie (mode nuit)

- R1 sur tout le marquage plutôt que « marquer seulement les jobs corpo » : `CachedIndustryJob` ne distingue pas un job corpo d'un job perso, sauter le marquage n'invente rien (même raisonnement que la spec #122, R3).
- Pas de filtre ajouté dans `MatchJobsProcessor` ni dans la commande : un personnage sans scope perso garde la lecture de ses jobs corpo (R2).

## Glossaire

« Job livré » : ajouter « ou quand les jobs perso sont inconnus (token sans le scope des jobs perso, spec #207) ».
