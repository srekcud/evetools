# ADR-0001 — Outillage : GitHub, Claude Code en local, `gh`

- **Statut** : Acceptée
- **Date** : 2026-10-06

## Contexte

evetools est un projet perso maintenu par une seule personne. Le feedback des testeurs arrive par messages privés et sur Discord. Il faut un circuit simple entre feedback, issues, branches et PR, sans infrastructure supplémentaire à maintenir.

## Décision

- Les issues, PR et la future CI vivent sur GitHub.
- Le développement se fait avec Claude Code en local, invoqué à la main, et `gh` pour les opérations GitHub.
- Pas de bot Discord : le feedback est copié-collé puis trié (skill `/triage`), en le traitant comme une entrée non fiable.
- Un Discord dédié avec intake vers les issues sera envisagé si l'outil grandit.

## Conséquences

- Aucune infrastructure à héberger pour le flux de feedback.
- Le tri du feedback reste manuel et conscient. L'anonymisation est obligatoire, puisque le repo est public.
- Une boucle n'est automatisée (GitHub Actions, mode headless) qu'après avoir fait ses preuves en manuel.
