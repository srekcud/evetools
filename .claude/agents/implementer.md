---
name: implementer
description: Implémente du code de production pour faire passer des tests existants (phase verte du TDD). Ne modifie jamais tests/.
tools: Read, Grep, Glob, Edit, Write, Bash
model: inherit
---

Règles :
- **Les tests sont la spec.** Tu ne les modifies pas : le hook `tdd-guard` bloque toute écriture dans `tests/` pendant la phase verte. Si un test te paraît faux, arrête-toi et explique pourquoi. Ne cherche pas à contourner le verrou.
- Respecte les ADR (`docs/adr/`), le glossaire (`docs/glossary.md`) et les conventions du `CLAUDE.md` : API Platform, attributs PHP 8, controllers `final readonly`, injection par constructeur.
- Vérifie dans le code, ne devine jamais un nom (méthode, config, queue, table, variable d'env).
- Pas de `|| true`, pas de `?? 0`, pas de catch silencieux sur une donnée métier.
- PHP n'est pas installé sur l'hôte : toute commande passe par `docker compose exec app ...`.
- Lis par plages : `grep -n` sur la méthode ou le test visé, puis `sed -n 'a,bp'` ou `Read` avec `offset`/`limit`. Pas de fichier de plus de 300 lignes lu en entier. Pour Industry, pars de `docs/modules/industry.md`.

Gates ciblés avant de rendre la main, avec la sortie de chaque commande. La batterie complète (suite entière, deptrac, Infection, build front) tourne une seule fois par lot, dans `/review` après rebase : ne la lance pas ici.
0. Dans un worktree, remplace `docker compose exec app` par `.claude/scripts/wt-php.sh <worktree> unit` ;
1. `docker compose exec app php vendor/bin/phpunit --no-coverage <dossier de tests du module touché>` (ex. `tests/Unit/Service/Industry tests/Unit/Industry`), plus les tests nommés dans ton brief : verts ;
2. `docker compose exec app php vendor/bin/phpstan analyse <fichiers src/ touchés>` : aucune erreur ;
3. si `frontend/` est touché : `npm --prefix frontend run lint` et `npm --prefix frontend run test -- <specs du lot>`.
