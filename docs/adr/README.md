# Architecture Decision Records

Une ADR consigne une décision d'architecture : son contexte, la décision et ses conséquences. Une ADR acceptée n'est pas réécrite : si la décision change, on crée une nouvelle ADR qui la remplace (`Statut : Remplacée par ADR-XXXX`).

| # | Titre | Statut |
|---|---|---|
| [0001](0001-outillage.md) | Outillage : GitHub, Claude Code en local, `gh` | Acceptée |
| [0002](0002-ddd-pragmatique.md) | DDD pragmatique, limité aux domaines riches | Acceptée |
| [0003](0003-mapping-doctrine-attributs.md) | Mapping Doctrine par attributs dans le Domain | Acceptée |
| [0004](0004-tdd-domain-application.md) | TDD en deux phases sur Domain et Application | Acceptée |
| [0005](0005-mutation-testing.md) | Mutation testing avec Infection et cliquet de MSI | Acceptée |
| [0006](0006-image-php-sans-multi-stage.md) | Pas de multi-stage pour l'image PHP pour l'instant | Acceptée (révisable) |
| [0007](0007-symfony-lts.md) | Rester sur Symfony 7.4 LTS, cible suivante 8.4 LTS | Acceptée |
| 0008 | Structure de code | En attente (Phase 3) |

## Gabarit

```markdown
# ADR-XXXX — Titre

- **Statut** : Proposée | Acceptée | Remplacée par ADR-YYYY
- **Date** : AAAA-MM-JJ

## Contexte
## Décision
## Conséquences
```
