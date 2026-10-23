# Spec #144 — Valeur saisie obligatoire pour une contribution « Job Install »

## Contexte

Une contribution `job_install` est toujours enregistrée à 0 ISK : `ContributeModal.vue` n'affiche le champ de valeur et n'envoie `estimatedValue` que pour matériau, BPC et location de ligne (`requiresEnteredValue`, `:79-81`), et `GroupIndustryContributionService::submit()` remplace une valeur absente par `0.0` (`:51`). Le coût d'installation avancé par un membre compte donc pour 0 dans le coût total du projet et dans sa part de la distribution. La détection automatique prévue (`submitAutoDetected()`, jobs ESI) n'a aucun appelant.

Le coût d'installation d'un job est connu du joueur (fenêtre d'installation, journal du portefeuille). La valeur saisie par le membre est donc la seule source disponible sans détection automatique ; elle reste soumise à l'approbation du propriétaire ou d'un admin comme toute contribution.

## Règles métier

- **R1 — Jamais de 0 par défaut.** Une contribution manuelle d'un type autre que matériau (`job_install`, `bpc`, `line_rental`) sans `estimatedValue` est refusée (400, `DomainException` du service traduite par le processor). Aucune contribution n'est créée.
- **R2 — Job Install et BPC : valeur positive.** Pour `job_install` et `bpc`, une valeur `≤ 0` est refusée (400). Un job installé coûte toujours des ISK ; un BPC contribué a une valeur.
- **R3 — Location de ligne : 0 permis s'il est saisi.** Pour `line_rental`, une valeur saisie `0` est acceptée (location offerte), seule l'absence est refusée.
- **R4 — Matériau inchangé.** Valeur saisie > 0 retenue, sinon prix Jita, sinon refus (#8).
- **R5 — Formulaire.** `ContributeModal` affiche le champ de valeur pour `job_install`, l'envoie, et bloque la soumission tant que la valeur est `≤ 0` (comme pour BPC).

## Exemples chiffrés

| # | Contribution | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | `job_install`, quantité 1, sans `estimatedValue` | créée, 0,0 ISK | 400, aucune contribution |
| E2 | `job_install`, quantité 1, `estimatedValue` 0,0 | créée, 0,0 ISK | 400 |
| E3 | `job_install`, quantité 2, `estimatedValue` 1 834 250,5 | créée, 1 834 250,5 | créée, 1 834 250,5 ISK, `pending` |
| E4 | `bpc`, quantité 1, sans valeur | créée, 0,0 | 400 |
| E5 | `bpc`, quantité 1, valeur 50 000 | créée, 50 000 | inchangé (garde-fou) |
| E6 | `line_rental`, sans valeur | créée, 0,0 | 400 |
| E7 | `line_rental`, valeur 0,0 | créée, 0,0 | créée, 0,0 (R3) |
| E8 | `line_rental`, valeur 75 000 | créée, 75 000 | inchangé |
| E9 | Formulaire : type `job_install` choisi | pas de champ valeur, envoi sans `estimatedValue` | champ affiché ; valeur 0 → bouton désactivé ; valeur 1 834 250,5 → envoyée telle quelle |
| E10 | Formulaire : `material` avec prix connu | valeur auto envoyée | inchangé (garde-fou) |

## Hors périmètre

- Détection automatique des contributions Job Install / Line Rental depuis les jobs ESI (`submitAutoDetected()` sans appelant) : touche la sync des jobs, à traiter à part.
- Barème de location de ligne jamais appliqué (#45).
- Contributions `job_install` déjà enregistrées à 0 ISK en base : laissées telles quelles (voir Blocages du journal).

## Décisions prises en autonomie

- Refus plutôt qu'un état « valeur inconnue » : le refus ne demande ni migration ni règle de distribution pour une valeur inconnue, et l'issue l'admet.
- 0 permis pour la seule location de ligne.
