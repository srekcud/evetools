# Spec — Bornes des runs et de la durée maximale de job d'un projet

## Contexte

Relevé par la revue sécurité de #21 : `runs` et `maxJobDurationDays` de `CreateProjectInput` et `UpdateProjectInput` n'ont qu'une contrainte `Positive`. Un très grand nombre de runs ou une durée minuscule fait générer un très grand nombre de jobs découpés (mémoire, CPU). Bornes décidées par Sylvain le 2026-10-23 ; pas de plafond séparé du nombre de jobs.

## Règles

- **B1.** Runs d'un projet : de 1 à 100 000, à la création et au PATCH ; au-delà, 422.
- **B2.** Durée maximale de job : au moins 0,5 jour, à la création et au PATCH ; en dessous, 422.
- **B3.** Formulaire : le champ des runs de création porte `max=100000` ; la durée maximale de job par défaut saisie sous 0,5 est ramenée à 0,5 (le champ porte déjà `min="0.5"`).

## Exemples

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Création, runs 100 000 | accepté | accepté |
| E2 | Création, runs 100 001 | accepté | 422 sur `runs` |
| E3 | PATCH runs 100 001 | accepté | 422 sur `runs` |
| E4 | Création, durée 0,5 | accepté | accepté |
| E5 | Création, durée 0,4 | accepté | 422 sur `maxJobDurationDays` |
| E6 | PATCH durée 0,4 | accepté | 422 sur `maxJobDurationDays` |
| E7 | Formulaire, durée par défaut saisie 0,2 | 0,2 envoyé | 0,5 |
