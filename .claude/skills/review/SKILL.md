---
name: review
description: Review de la branche courante par les reviewers spécialisés (API Platform, ESI, sécurité, DDD, tests) ; rapport consolidé, aucune modification.
disable-model-invocation: true
argument-hint: [base] (défaut : main)
---

Base de comparaison : `$ARGUMENTS` (si vide : `main`).

1. Récupère le diff : `git diff <base>...HEAD` et `git diff` (non commité). Liste les fichiers touchés.
2. Lance **en parallèle** les reviewers pertinents selon les fichiers touchés, en leur passant le diff et la liste des fichiers (ils lisent le contexte par plages, pas les fichiers entiers) :
   - `api-platform-reviewer` : `src/ApiResource/`, `src/State/` ;
   - `esi-reviewer` : `src/Service/ESI/`, `src/Service/Sync/`, handlers de sync ;
   - `security-reviewer` : toujours ;
   - `ddd-reviewer` : tout fichier de Domain ou Application, et tout fichier touchant un terme du glossaire ;
   - `test-skeptic` : si `tests/` est touché, ou si du code de production change sans test associé.
3. Lance les gates et rapporte leur sortie. C'est la seule exécution complète du lot : la branche doit être rebasée sur `main` avant. Dans un worktree, passe par `.claude/scripts/wt-php.sh <worktree> unit|integration`.
   - `docker compose exec app php vendor/bin/phpunit --no-coverage` ;
   - `docker compose exec app php vendor/bin/phpstan analyse` ;
   - `docker compose exec app php vendor/bin/deptrac analyse` ;
   - `docker compose exec app php vendor/bin/infection --threads=max --filter=<fichiers src/ touchés>` : le MSI ne baisse ni sous `docs/quality/baseline.md` ni sous le seuil d'`infection.json5` ;
   - si le frontend est touché : `npm --prefix frontend run lint`, `npm --prefix frontend run test` et `npm --prefix frontend run build`, puis restaurer `frontend/dist` et `frontend/tsconfig.tsbuildinfo`.
   Si `main` avance entre ces gates et la fusion, rebase et relance seulement PHPUnit.
4. Consolide : dédoublonne les constats et classe-les par sévérité (bloquant, à corriger, suggestion). Chaque constat donne `fichier:ligne`, le problème et la correction proposée.
5. Si une même règle est violée deux fois ou plus, propose de l'automatiser (règle PHPStan, règle deptrac, test d'architecture ou hook).

Ne modifie aucun fichier.
