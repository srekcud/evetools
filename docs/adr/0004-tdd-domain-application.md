# ADR-0004 — TDD en deux phases sur Domain et Application

- **Statut** : Acceptée
- **Date** : 2026-10-06

## Contexte

Une grande partie du code a été produite sur de nombreuses sessions d'agent. On y trouve des tests complaisants, écrits après coup pour passer sur le comportement existant. Quand un même agent écrit le test et le code dans la foulée, rien n'empêche d'ajuster le test au code.

## Décision

- TDD obligatoire sur Domain et Application, en deux phases séparées **mécaniquement** :
  1. **Rouge** (`/tdd-red`) : tests seuls, avec la preuve qu'ils échouent pour la bonne raison, puis validation humaine.
  2. **Vert** (`/tdd-green`) : implémentation. Toute modification de `tests/` est bloquée par le hook `.claude/hooks/tdd-guard.sh`, quel que soit l'agent.
- L'infrastructure garde des tests d'intégration classiques.

## Conséquences

- Les tests rouges validés deviennent la spec exécutable.
- Si un test paraît faux en phase verte, on s'arrête et on le signale, au lieu de le contourner.
- Le verrou repose sur le fichier `.claude/state/tdd-phase` (non versionné).
