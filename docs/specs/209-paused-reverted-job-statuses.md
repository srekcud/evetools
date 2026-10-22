# Spec #209 — Statuts `paused` et `reverted` des jobs ESI

## Contexte

L'ESI renvoie six statuts de job : `active`, `paused`, `ready`, `delivered`, `cancelled`, `reverted` (enum de `/characters/{id}/industry/jobs/` et `/corporations/{id}/industry/jobs/`, non vérifié sur des données réelles du dépôt). Le code n'en traite que quatre :

- **Production.** Le matcher (`IndustryJobMatcher`) retire seulement les jobs `cancelled` (`CachedIndustryJobRepository.php:75`, `CachedIndustryJob::isCancelled()`, `IndustryJobMatcher.php:193`). Un job `reverted` (annulé par le jeu, par exemple structure détruite ou désancrée) est matché sur une étape et compte comme production.
- **Jobs en cours.** `findActiveJobsByCharacter` ne renvoie que `active` et `ready` (`CachedIndustryJobRepository.php:38`). Un job `paused` (structure hors ligne) n'occupe plus de slot dans le Slot Tracker (`SlotTrackerService.php:75,109-117`) et échappe au marquage « livré » de fin de sync (`IndustryJobSyncService.php:189`) : s'il disparaît de l'ESI, il reste `paused` à jamais.

## Règles métier

- **R1 — Job sans production.** Un job `cancelled` ou `reverted` n'a rien produit : il n'est jamais matché sur une étape (ni runs couverts, ni structure déduite). Les liens manuels existants ne changent pas.
- **R2 — Job en cours.** Un job `active`, `paused` ou `ready` est en cours : il occupe un slot de sa catégorie dans le Slot Tracker, comme aujourd'hui `active` (fin future) et `ready`.
- **R3 — Marquage « livré » d'un job en pause.** Un job `paused` du personnage, absent de l'ESI, date de fin passée, est marqué « livré » comme un job `active` ou `ready`, aux mêmes conditions (specs #122 et #207 : jobs perso et corpo connus). Raison : l'ESI renvoie toujours un job non terminé ; un job qui disparaît n'est plus en pause.
- **R4 — Inchangé.** Un job `paused` n'apparaît pas dans la timeline du Slot Tracker (seuls les jobs `active` à fin future y sont). Les compteurs Mercure de fin de sync ne changent pas.

## Exemples chiffrés

Personnage A, 1 slot de fabrication max ou plus. Blueprint 1001, étape de fabrication de 10 runs.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Job 200 `reverted`, fabrication, blueprint 1001, 10 runs | matché sur l'étape, 10 runs couverts | non matché, 0 run couvert |
| E2 | Job 201 `cancelled` (régression) | non matché | inchangé |
| E3 | Job 202 `delivered` (régression) | matché, 10 runs | inchangé |
| E4 | `findManufacturingJobsByBlueprint` sur jobs 200 `reverted`, 201 `cancelled`, 202 `delivered`, 203 `active` | 200, 202, 203 | 202, 203 |
| E5 | `findActiveJobsByCharacter` sur jobs `active`, `paused`, `ready`, `delivered`, `cancelled`, `reverted` | `active`, `ready` | `active`, `paused`, `ready` |
| E6 | Slot Tracker : 1 job `paused` de fabrication (fin future) + 1 job `active` de fabrication (fin future) | 1 slot de fabrication utilisé | 2 slots utilisés |
| E7 | Slot Tracker : 1 job `paused` de fabrication, fin passée | 0 slot utilisé | 1 slot utilisé (un job en pause garde son slot, sa date de fin n'est plus fiable) |
| E8 | Sync : job 300 `paused` en base, fin passée, absent de l'ESI, jobs perso et corpo connus | reste `paused` | `delivered`, date de complétion = date de fin |
| E9 | Sync : job 301 `paused` en base, fin future, absent de l'ESI | reste `paused` | inchangé |

## Cas limites

- Un job déjà matché puis passé `reverted` garde son match existant tant que les matchs ne sont pas recalculés : le recalcul des matchs (`clearRecomputableMatches`) le retire au prochain « Matcher les jobs ». Le rafraîchissement des jobs matchés est l'audit P1 n° 2, hors périmètre.
- Les jobs `reverted` restent proposés à la liaison manuelle (`findByBlueprintsAndCharacters`, `AvailableJobsProvider`) : le joueur choisit, rien n'est inventé.

## Hors périmètre

- Notification « job terminé » pour une transition `paused` → `ready` (audit n° 12).
- Statut des jobs matchés copié dans `IndustryStepJobMatch` (audit P1 n° 2).
- Compteurs d'admin (`AdminService.php:134`).

## Décisions prises en autonomie (mode nuit)

- R3 : `paused` suit le marquage « livré » plutôt que de rester `paused` indéfiniment (l'ESI ne fait disparaître que les jobs terminés depuis plus de 90 jours).
- E7 : un job `paused` à fin passée occupe toujours son slot (la pause décale la fin réelle) ; seul un job `active` à fin passée est considéré terminé, comme aujourd'hui.
- Nom de l'entité : `CachedIndustryJob::hasNoOutput()` remplace `isCancelled()` (seul appelant : le matcher) ; constantes de statut ajoutées à `CachedIndustryJob`.

## Glossaire

- **Job sans production** (Job without output) : job ESI `cancelled` ou `reverted`, qui n'a rien produit ; jamais matché sur une étape (spec #209). `CachedIndustryJob::hasNoOutput()`.
- **Job en cours** (Running job) : job ESI `active`, `paused` ou `ready` ; il occupe un slot (spec #209). `CachedIndustryJobRepository::findActiveJobsByCharacter()`.
