# Spec #59 — Une étape achetée garde ses jobs matchés

## Contexte

`IndustryJobMatcher::matchEsiJobs()` commence par supprimer tous les jobs matchés des étapes qui ne sont pas en mode `manual` (`clearAutomaticMatches()`), puis ne rematche que les étapes `isAutoMatchable()`. Une étape marquée « achetée » n'est pas rematchable : à la relance suivante du matcher, ses jobs matchés sont supprimés et jamais recréés. Une étape seulement en stock reste matchée.

Cas réel : un joueur lance un job pour un intermédiaire, puis décide d'en acheter le reste et coche « Acheté ». Au refresh suivant, la trace du job lancé (coût, structure, date de fin) disparaît du projet.

## Règles métier

- **R1 — Une étape achetée garde ses jobs matchés.** Le matcher ne supprime pas les jobs matchés d'une étape achetée, quel que soit le mode de matching (`auto` ou `manual`).
- **R2 — Une étape achetée ne reçoit aucun nouveau job.** Elle n'est pas rematchée (inchangé) et ses runs ne sont pas adaptés aux jobs matchés.
- **R3 — Les jobs d'une étape achetée restent réservés.** Un job matché à une étape achetée ne peut pas être matché à une autre étape du projet, comme un job lié à la main à une étape `manual`.
- **R4 — Décocher « Acheté » rend l'étape au matching normal.** À la relance suivante, ses jobs matchés automatiques sont recalculés comme pour toute étape `auto`.
- **R5 — Inchangé.** Une étape en stock reste matchée normalement ; une étape en mode `none` perd ses jobs matchés ; une étape de copie n'est pas matchée.

## Exemples chiffrés

Étape de 10 runs, blueprint B ; job ESI 4242 de 10 runs déjà matché ; job ESI 5001 de 10 runs disponible pour B.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Étape achetée (`auto`), job 4242 déjà matché, relance du matcher | jobs matchés `[]` | `[4242]`, runs 10, aucun appel à `findManufacturingJobsByBlueprint` pour cette étape |
| E2 | Étape achetée (`auto`) avec 4242, et une seconde étape `auto` non achetée de 10 runs sur le même blueprint ; jobs ESI 4242 et 5001 | étape achetée `[]`, seconde étape `[4242]` | étape achetée `[4242]`, seconde étape `[5001]` |
| E3 | Étape achetée en mode `manual` avec un lien à la main 7777 | `[7777]` | `[7777]` (garde-fou) |
| E4 | Étape achetée sans job matché | `[]` | `[]`, aucun job ajouté (garde-fou) |
| E5 | Étape non achetée (`auto`), job 4242 déjà matché, seul 5001 disponible | `[5001]` | `[5001]` (garde-fou R4) |
| E6 | Étape en stock (`auto`), 5001 disponible | `[5001]` | `[5001]` (garde-fou R5) |
| E7 | Étape achetée avec 4242 matché de 6 runs alors que l'étape en prévoit 10 | runs 10, matches `[]` | runs 10, matches `[4242]` (pas d'adaptation des runs) |

## Cas limites

- Le test existant `testPurchasedStepIsNotMatchedAndLosesItsPreviousMatches` fige le comportement actuel : remplacé par E1.
- `LinkJobProcessor` (lien à la main) n'est pas modifié.

## Hors périmètre

- Prise en compte des jobs d'une étape achetée dans la liste d'achats, l'approvisionnement (#52) ou les coûts du projet.
- Réduction des runs par le matcher (#3).
- Étapes en mode `none` qui perdent leurs jobs matchés : comportement voulu (l'utilisateur a désactivé le matching).

## Décision prise en autonomie

L'issue laissait ouvert le choix entre « comportement voulu » et « incohérence ». Option la plus conservatrice retenue : ne jamais perdre de donnée. L'étape achetée garde ses jobs matchés (R1) sans en recevoir de nouveaux (R2), et ces jobs restent réservés (R3) pour ne pas être comptés deux fois. Aucun changement d'affichage.

## Glossaire

Terme ajouté à [`docs/glossary.md`](../glossary.md), section industrie :

- **Job matché** (Job match) : job ESI rattaché à une étape d'un projet, automatiquement par le matcher ou à la main (mode `manual`). Une étape achetée garde ses jobs matchés sans en recevoir de nouveaux (spec #59). Code : `IndustryStepJobMatch`, `IndustryJobMatcher`, `LinkJobProcessor`.
