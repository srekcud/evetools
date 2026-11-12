---
name: review
description: Review de la branche courante par les reviewers spécialisés (API Platform, ESI, sécurité, DDD, tests) ; rapport consolidé, aucune modification.
argument-hint: [base] (défaut : main)
---

Base de comparaison : `$ARGUMENTS` (si vide : `main`).

1. Récupère le diff : `git diff <base>...HEAD` et `git diff` (non commité). Liste les fichiers touchés.
2. Lance **en parallèle** les reviewers pertinents selon les fichiers touchés, en leur passant le diff et la liste des fichiers (ils lisent le contexte par plages, pas les fichiers entiers) :
   - `api-platform-reviewer` : `src/ApiResource/`, `src/State/` ;
   - `esi-reviewer` : `src/Service/ESI/`, `src/Service/Sync/`, handlers de sync ;
   - `security-reviewer` : **une fois par groupe**, avant la fusion de la dernière issue du groupe, sur le diff cumulé des chemins d'accès (Processor, Provider, service d'accès, migration) ; pour une issue isolée du groupe, seulement si elle touche un contrôle d'accès, un secret ou une donnée personnelle ;
   - `ddd-reviewer` : tout fichier de Domain ou Application, et tout fichier touchant un terme du glossaire ;
   - `test-skeptic` : si `tests/` est touché, ou si du code de production change sans test associé.
3. Lance les gates et rapporte leur sortie. C'est la seule exécution complète du lot : la branche doit être rebasée sur `main` avant. Dans un worktree, passe par `.claude/scripts/wt-php.sh <worktree> unit|integration` (`unit` ajoute `--testsuite=Unit` à PHPUnit si aucune suite ni chemin n'est donné), et `… phpstan` pour PHPStan (cache dev préparé par le script).
   - `docker compose exec app php vendor/bin/phpunit --no-coverage` ;
   - `docker compose exec app php vendor/bin/phpstan analyse` ;
   - `docker compose exec app php vendor/bin/deptrac analyse` ;
   - `docker compose exec app php vendor/bin/infection --threads=max --filter=<fichiers src/ touchés>` : le MSI ne baisse ni sous `docs/quality/baseline.md` ni sous le seuil d'`infection.json5`. Dans un worktree : `.claude/scripts/wt-php.sh <worktree> unit vendor/bin/infection --threads=4 --no-progress --filter=<fichiers> --test-framework-options="--testsuite=Unit"` (l'option s'écrit avec le tiret après `test`) ;
   - si le frontend est touché : `npm --prefix frontend run lint`, `npm --prefix frontend run test` et `npm --prefix frontend run build`, puis restaurer `frontend/dist` et `frontend/tsconfig.tsbuildinfo`.
   Si `main` avance entre ces gates et la fusion, rebase et relance seulement PHPUnit.
4. Consolide : dédoublonne les constats et classe-les par sévérité (bloquant, à corriger, suggestion). Chaque constat donne `fichier:ligne`, le problème et la correction proposée.
5. Si une même règle est violée deux fois ou plus, propose de l'automatiser (règle PHPStan, règle deptrac, test d'architecture ou hook).

Ne modifie aucun fichier.
