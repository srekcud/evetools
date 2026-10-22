# Spec #187 — Un job lié à la main n'est plus perdu au matching suivant

## Contexte

`LinkJobProcessor` crée le job matché sans changer le mode de matching de l'étape (`jobMatchMode`, `auto` par défaut), et aucun appel du frontend n'envoie `jobMatchMode`. Au « Matcher les jobs » suivant, `IndustryJobMatcher::clearRecomputableMatches()` supprime tous les jobs matchés des étapes en mode `auto`, dont celui lié à la main ; le matcher automatique peut ensuite rattacher un autre job, ou aucun. La liaison manuelle, seul recours quand le matching automatique se trompe, disparaît. #58 a fait respecter le mode `manual` par le matcher, pas ce chemin.

## Règles métier

- **R1 — Lier à la main passe l'étape en mode `manual`.** Une liaison manuelle acceptée met le mode de matching de l'étape à `manual`, quel que soit son mode d'avant (`auto`, `none`, `manual`).
- **R2 — Conséquence du mode `manual` (inchangée, spec #58).** Le matcher garde tous les jobs matchés de l'étape (y compris ceux matchés automatiquement avant la liaison), ne lui en ajoute plus, et réserve leurs jobs : ils ne sont pas matchés sur une autre étape.
- **R3 — Liaison refusée : rien ne change.** Une liaison refusée (job inconnu, d'un autre utilisateur, blueprint différent, déjà lié, sans production, autre activité : spec #188) laisse le mode de l'étape inchangé.
- **R4 — Délier ne remet pas le mode.** Supprimer un job matché (`UnlinkJobProcessor`) laisse le mode `manual` : l'utilisateur repasse en `auto` explicitement (`PATCH` de l'étape, `jobMatchMode`).

## Exemples chiffrés

Projet P, étape S de fabrication, blueprint 1001, 20 runs, mode `auto`. Jobs de l'utilisateur sur le blueprint 1001, après le début du projet : 305 (`delivered`, 10 runs), 400 (`active`, 10 runs), 401 (`active`, 10 runs).

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Lier 305 à S (mode `auto`) | 305 matché, mode `auto` | 305 matché, mode `manual` |
| E2 | E1 puis « Matcher les jobs » | 305 supprimé, S reçoit 305 et 400 ou d'autres jobs automatiquement | S garde 305 seul ; aucun job ajouté |
| E3 | S déjà matché automatiquement à 400, puis lier 305, puis « Matcher les jobs » | 400 et 305 supprimés puis rematchés automatiquement | S garde 400 et 305 |
| E4 | Étape S en mode `none`, lier 305 | mode `none` | mode `manual` |
| E5 | Étape S en mode `manual`, lier 305 | `manual` | `manual` |
| E6 | Liaison refusée (job 300 `cancelled`) sur S en `auto` | 400, mode `auto` | inchangé |
| E7 | Étape T (même blueprint, mode `auto`) ; E1 puis « Matcher les jobs » | T peut recevoir 305 | T ne reçoit pas 305 (réservé par S) ; T peut recevoir 400 et 401 |
| E8 | E1 puis délier 305 | — | S sans job matché, mode `manual` |

## Cas limites

- Étape achetée : la liaison passe aussi le mode à `manual` ; l'étape achetée gardait déjà ses jobs matchés (spec #59).

## Hors périmètre

- Bouton front pour repasser une étape en `auto` (le `PATCH` existe ; pas de changement front dans ce lot).

## Décisions prises en autonomie (mode nuit)

- R1 passe toute l'étape en `manual` plutôt que de marquer le seul job matché comme manuel (colonne + migration) : c'est la sémantique déjà documentée de « Job matché … à la main (mode `manual`) » dans le glossaire, sans changement de schéma. Effet assumé : l'étape ne reçoit plus de job automatique (E2).
- R4 : délier ne repasse pas en `auto`, pour ne pas relancer un matching automatique que l'utilisateur vient de corriger.

## Glossaire

« Job matché » : préciser « Lier un job à la main passe l'étape en mode `manual` (spec #187). »
