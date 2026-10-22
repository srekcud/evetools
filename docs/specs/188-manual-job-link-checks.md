# Spec #188 — La liaison manuelle refuse un job sans production ou d'une autre activité

## Contexte

`LinkJobProcessor` (`POST /industry/projects/{id}/steps/{stepId}/link-job`) vérifie la propriété du job, le blueprint et l'absence de lien existant, mais ni le statut ni l'activité du job : un job `cancelled` ou `reverted` (« job sans production », spec #209) ou un job d'invention sur une étape de fabrication du même blueprint est accepté. La liste des jobs proposés (`AvailableJobsProvider` via `CachedIndustryJobRepository::findByBlueprintsAndCharacters()`) ne filtre pas le statut, et l'écran des étapes affiche un job annulé comme « terminé » (`StepsTab.vue:~253`, tout statut autre que `active`). Le processor recrée aussi le job matché à la main (copie, structure, correction de structure) au lieu de réutiliser `IndustryJobMatcher::createJobMatch()`.

## Règles métier

- **R1 — Job sans production refusé.** Lier un job `cancelled` ou `reverted` répond 400, aucun job matché n'est créé.
- **R2 — Activité compatible.** L'activité ESI du job doit correspondre à celle de l'étape : `manufacturing` ↔ 1, `reaction` ↔ 9 (ESI) ou 11 (SDE, comme le matcher), `copy` ↔ 5, `invention` ↔ 8. Sinon 400, aucun job matché.
- **R3 — Jobs proposés.** La liste des jobs disponibles n'inclut plus les jobs sans production.
- **R4 — Une seule création.** La liaison manuelle crée le job matché par la même méthode que le matcher automatique (copie de l'état du job, structure du job, correction de la structure de l'étape et recalcul des quantités si la structure change). Comportement inchangé pour un job valide.
- **R5 — Contrôles existants inchangés.** Job inconnu ou d'un autre utilisateur, blueprint différent, job déjà lié : mêmes réponses qu'aujourd'hui.

## Exemples chiffrés

Étape S de fabrication, blueprint 1001, structure prévue Raitaru (location 60001). Jobs de l'utilisateur, blueprint 1001, 10 runs, coût 1 250 000,75.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Job 300 `cancelled`, activité 1 | 200, job matché créé | 400, aucun job matché |
| E2 | Job 301 `reverted`, activité 1 | 200 | 400 |
| E3 | Job 302 `active`, activité 8 (invention) sur S | 200 | 400 |
| E4 | Étape de réaction (formule 2001), job 303 activité 9 ; job 304 activité 11 | 200 | 200 pour les deux |
| E5 | Job 305 `delivered`, activité 1 sur S | 200, job matché | inchangé (statut, coût, runs, fin copiés) |
| E6 | Étape de copie, job 306 activité 5 ; étape d'invention, job 307 activité 8 | 200 | 200 |
| E7 | Jobs disponibles du projet : 300 `cancelled`, 301 `reverted`, 305 `delivered`, 308 `active` | les quatre | 305 et 308 |
| E8 | Job 305 installé dans l'Azbel (location 60002), configurée par l'utilisateur | structure de S corrigée vers l'Azbel, structure prévue « Raitaru » enregistrée, quantités recalculées | inchangé |
| E9 | Job 302 `active`, activité 3 (recherche TE) sur une étape de fabrication | 200 | 400 |

## Cas limites

- Activité d'étape inconnue (valeur hors des quatre) : 400 (aucune activité compatible).
- Les jobs sans production déjà liés avant ce correctif restent liés (pas de suppression de données) ; « Matcher les jobs » les retire des étapes en mode `auto`.

## Hors périmètre

- Liaison manuelle perdue au matching suivant (#187, lot suivant).
- Le matcher automatique ne compare pas l'activité d'une étape d'invention aux jobs de fabrication du même blueprint (à vérifier, ticket séparé si confirmé).
- Libellé d'un job `paused` dans la liste des jobs disponibles (affiché « terminé ») : front, ticket séparé.

## Décisions prises en autonomie (mode nuit)

- Réponse 400 comme les autres refus du processor, plutôt que 422.
- R3 côté serveur plutôt qu'un filtre front : la liste ne peut plus proposer ce que le processor refuse ; pas de changement front.

## Glossaire

Aucun terme ajouté (« Job sans production », « Job matché » existent).
