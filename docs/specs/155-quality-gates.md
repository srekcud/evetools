# Spec #155 — Garde-fous qualité bloquants pour le noyau Industrie

## Contexte

L'ADR-0005 et l'ADR-0009 fixent un cliquet de MSI sur le noyau dès sa première mesure (critère 7 de la [spec du noyau](industry-engine.md)). Au 2026-10-26, rien ne bloque :

- `infection.json5` n'a ni `minMsi` ni `minCoveredMsi` ; la baseline ne mesure pas `src/Industry/` ;
- `make deptrac` ignore le code de retour (préfixe `-`) ; deptrac relève 33 violations, toutes hors des couches `Industry*` ;
- aucun workflow de CI ;
- les goldens existent aussi dans `.claude/tasks/industry-core/golden/` (non versionné, dépôt principal), copie identique octet par octet des six JSON de `tests/Fixtures/Golden/`. Aucun test ne lit cette copie.

Mesures (détail dans [la baseline](../quality/baseline.md#noyau-industrie-155)) : Domain + Application, suite Unit seule, 431 mutants, MSI 94,66 %, covered MSI 99,27 %.

## Règles

- **R1 — Cliquet du noyau.** `infection-core.json5` couvre `src/Industry/Domain` et `src/Industry/Application`, avec la suite Unit seule (le noyau se teste sans base, ADR-0009). Il porte `minMsi` et `minCoveredMsi` égaux à la dernière mesure arrondie vers le bas. Un seuil ne baisse jamais ; il monte quand une mesure le dépasse d'un point entier.
- **R2 — Rapport global inchangé.** `infection.json5` reste sans seuil : `make infection FILTER=…` mesure n'importe quel dossier, y compris ceux à 0 %, sans échouer.
- **R3 — deptrac bloquant.** `make deptrac` propage le code de retour. Les violations existantes sont listées dans `skip_violations` de `deptrac.yaml` ; toute autre violation fait échouer la commande. Une violation corrigée sort de la liste.
- **R4 — Un seul jeu de goldens.** Les goldens vivent dans `tests/Fixtures/Golden/` ; la documentation n'en cite pas d'autre exemplaire.
- **R5 — CI.** Le choix et la création du workflow restent à Sylvain (décision structurelle) ; il lancera tests, `make deptrac` et `make infection-core` sur chaque PR.

## Critères d'acceptation

1. `make infection-core` sort en 0 sur l'état mesuré ; avec `--min-msi=95` ajouté, la même commande sort en 1 (`The minimum required MSI percentage should be 95%`).
2. `make -n infection FILTER=src/Service/Planetary` donne la commande d'avant, sans `--configuration` ni seuil.
3. `make deptrac` sort en 0 (`Violations 0`, `Skipped violations 33`) ; une classe de `src/Industry/Domain` qui instancie `App\Entity\User` le fait sortir en 1.
4. `git ls-files '*.json' | grep -E 'golden|Golden'` ne liste que `tests/Fixtures/Golden/` ; `git grep -n "industry-core/golden"` ne présente plus ce dossier comme source des goldens.
5. `phpunit --testsuite=Unit --filter='Golden|InstallCostTest|EstimatedItemValueTest|StructureMaterialModifiersTest'` est vert (incomplet D11a admis).
6. `docs/quality/baseline.md` donne MSI et covered MSI de `src/Industry/Domain`, `Application` et `Infrastructure`.

## Hors périmètre

- Seuils par dossier pour le reste de `src/` (Phase 1, périmètre par périmètre).
- Cliquet de `src/Industry/Infrastructure` : une partie de ses tests exige la base.
- Correction des 33 violations deptrac et des mutants survivants (dont `ProductionCostCalculator.php:263`).
- Suppression de la copie non versionnée dans le dépôt principal (hors worktree, à faire par Sylvain).
- Création du workflow CI.
