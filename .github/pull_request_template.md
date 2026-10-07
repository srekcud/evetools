## Quoi et pourquoi

<!-- Le changement en une ou deux phrases, et l'issue liée (Closes #…). -->

## Vérifications

Voir [CONTRIBUTING.md](https://github.com/srekcud/evetools/blob/main/CONTRIBUTING.md).

- [ ] `make test` est vert.
- [ ] PHPStan (`docker compose exec app php vendor/bin/phpstan analyse --memory-limit=1G`) : aucune erreur nouvelle.
- [ ] `make deptrac` : aucune violation nouvelle.
- [ ] Frontend touché : `npm --prefix frontend run lint`, `npm --prefix frontend run test` et `npm --prefix frontend run build` passent.
- [ ] Correctif : un test échouait avant le correctif et passe après, sans avoir été modifié.
- [ ] Entité Doctrine modifiée : migration incluse.
- [ ] Commits au format Conventional Commits (`feat(scope): …`, `fix(scope): …`).
- [ ] Aucun pseudo, nom de personnage, de corporation, d'alliance ou de structure, ni citation de message privé.
- [ ] Nouveau terme métier : ajouté à `docs/glossary.md`. Décision d'architecture : ADR dans `docs/adr/`.

## Action après déploiement

<!-- Commande console, seed ou opération manuelle à lancer en production, sinon « aucune ». -->
