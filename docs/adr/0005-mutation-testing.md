# ADR-0005 — Mutation testing avec Infection et cliquet de MSI

- **Statut** : Acceptée
- **Date** : 2026-10-06

## Contexte

La couverture de lignes ne dit pas si un test vérifie réellement quelque chose : un test sans assertion utile couvre autant qu'un bon test. Le mutation testing mesure si les tests détectent une modification du code (MSI, Mutation Score Indicator).

## Décision

- Infection (`infection/infection`), en dépendance de dev.
- **Cliquet** : une fois un périmètre caractérisé, son seuil `minMsi` est fixé à la valeur atteinte. Le MSI d'un périmètre ne doit jamais baisser.
- Phase 0 : rapport seulement, sans seuil. Les seuils sont posés en Phase 1, périmètre par périmètre.

## Conséquences

- Les tests complaisants sont démasqués par les mutants survivants (analysés par l'agent `test-skeptic`).
- Infection exige un driver de couverture. pcov est fourni par `Dockerfile.dev`, en dev uniquement.
- Le temps d'exécution impose de lancer Infection par périmètre (`--filter`), pas sur tout `src/` à chaque fois.
