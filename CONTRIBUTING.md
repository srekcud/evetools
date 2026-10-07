# Contribuer à evetools

evetools est maintenu par une seule personne. Toute modification passe par une pull request relue par le mainteneur.

## Proposer une modification

Sans droit d'écriture sur le dépôt :

1. Forkez le dépôt sur GitHub.
2. Créez une branche depuis `main` dans votre fork.
3. Ouvrez une pull request vers `main`. Remplissez le modèle de PR.
4. Le mainteneur relit la PR, demande des changements si besoin, puis la fusionne.

Pour une modification importante (nouveau module, changement d'architecture), ouvrez d'abord une issue pour en discuter.

Les problèmes de sécurité ne se signalent pas dans une issue publique : utilisez une [GitHub Security Advisory](https://github.com/srekcud/evetools/security/advisories/new).

## Installation locale

PHP n'a pas besoin d'être installé sur la machine : toutes les commandes PHP passent par le conteneur `app`. Il faut Docker, Docker Compose et Make. Les commandes frontend demandent Node et npm.

```bash
cp .env.local.example .env.local   # puis renseigner les identifiants EVE ESI
make build                         # construit les images
make up                            # démarre les conteneurs
make install                       # dépendances Composer
make jwt-keys                      # paire de clés JWT
make db-create                     # base de développement
make db-migrate                    # migrations
make test-db                       # (re)crée la base de test eve_app_test et son schéma
npm --prefix frontend ci           # dépendances frontend
```

Le schéma de la base de test est construit depuis le mapping des entités, pas depuis les migrations : elles ne se rejouent pas sur une base vide. Relancez `make test-db` après un changement d'entité.

## Avant d'ouvrir une PR

Tout doit passer en local :

| Vérification | Commande | Attendu |
|---|---|---|
| Tests PHP (suites Unit, Integration et Functional) | `make test` | vert |
| PHPStan niveau 8 sur `src/` | `docker compose exec app php vendor/bin/phpstan analyse --memory-limit=1G` | aucune erreur nouvelle |
| deptrac (dépendances entre couches) | `make deptrac` | aucune violation nouvelle |
| Lint frontend | `npm --prefix frontend run lint` | aucune erreur |
| Tests frontend | `npm --prefix frontend run test` | vert |
| Build frontend | `npm --prefix frontend run build` | OK |

PHPStan et deptrac signalent déjà des erreurs sur `main` (voir [`docs/quality/baseline.md`](docs/quality/baseline.md)). La règle est de ne pas en ajouter : comparez la sortie avant et après votre modification. `make deptrac` ne fait jamais échouer la commande : lisez le rapport.

Une modification d'entité Doctrine s'accompagne d'une migration (`make db-diff`).

## Tests d'abord

Un correctif commence par un test qui échoue et qui prouve le bug. Le correctif le fait passer, sans modifier le test. Le code métier (Domain et Application) suit le même principe pour toute nouvelle fonctionnalité ([ADR-0004](docs/adr/0004-tdd-domain-application.md)).

Si un test existant vous semble faux, signalez-le dans la PR plutôt que de l'ajuster au code.

## Commits

Format [Conventional Commits](https://www.conventionalcommits.org/fr/) : `type(scope): message`, par exemple `feat(industry): …`, `fix(sync): …`, `test(industry): …`, `chore: …`. Le scope est le module concerné.

## Anonymisation

Le dépôt est public. Dans le code, les tests, les commits, les issues et les PR :

- aucun pseudo, nom de personnage, de corporation, d'alliance ou de structure ;
- aucune citation de message privé : reformulez le besoin.

Utilisez des valeurs fictives dans les fixtures et les exemples.

## Où vivent les décisions

- [`docs/adr/`](docs/adr/) : décisions d'architecture. Une ADR acceptée n'est pas réécrite ; si une décision change, une nouvelle ADR la remplace.
- [`docs/glossary.md`](docs/glossary.md) : vocabulaire métier (FR/EN). Un symbole métier porte un nom du glossaire. Si un terme manque, ajoutez-le au glossaire dans la même PR au lieu d'inventer un synonyme.
