# Mise en production

## Vue d'ensemble

```
git tag V0.14 && git push origin V0.14
        │
        ▼
Release (.github/workflows/release.yml)
  0. release-notes : RELEASE_NOTES.txt contient une section pour la version
  1. ci       : suites Unit + Integration (ci.yml réutilisé)
  2. images   : build base → app → proxy, push sur ghcr.io/srekcud/evetools-{app,proxy}:V0.14
  3. deploy   : environnement GitHub "production", ssh vers le serveur
        │
        ▼
Serveur : bin/deploy V0.14 (commande forcée de la clé SSH)
  checkout du tag → pull des images → pg_dump → arrêt worker + app (page de maintenance)
  → migrations dans un conteneur jetable → démarrage app + worker (attente healthcheck) → proxy
```

Aucun build sur le serveur. Une image par version : revenir en arrière consiste à redéployer un tag précédent.

## Déployer

- **Avant le tag** : ajouter en tête de `frontend/public/RELEASE_NOTES.txt` une section `Version 0.14 (AAAA-MM-JJ)`, rédigée pour les joueurs (lien « Release notes » du bas de page). Sans elle, le job `release-notes` échoue et rien n'est construit. La version affichée dans l'app vient du tag (`VITE_APP_VERSION`), sans fichier à modifier.
- **Nouvelle version** : `git tag V0.14 && git push origin V0.14`. Le déploiement ne part que si la CI et le build des images sont verts.
- **Redéployer / revenir à une version déjà construite** : Actions → Release → *Run workflow*, `version = V0.13`. Pas de rebuild.
- **Depuis le serveur, sans GitHub** : `make deploy VERSION=V0.14` (ou `bin/deploy V0.14`).

Si l'environnement `production` a un *required reviewer*, le job `deploy` attend une validation dans l'onglet Actions.

## Ce que fait `bin/deploy`

| Étape | Échec |
|---|---|
| Vérifie le format `V<maj>.<min>[.<patch>]`, prend un verrou (`.deploy.lock`) | arrêt, rien n'est touché |
| `git checkout` du tag (compose et scripts alignés sur la version) | arrêt, rien n'est touché (ex. modifications locales sur le serveur) |
| `docker pull` des deux images | arrêt, rien n'est touché |
| `pg_dump` (format custom) dans `$BACKUP_DIR` (défaut `~/backups/evetools`), 10 derniers gardés (`BACKUPS_KEPT`) | arrêt, rien n'est touché |
| Arrêt de `worker` puis `app` : le proxy sert `maintenance.html` | — |
| `doctrine:migrations:migrate` avec la nouvelle image, conteneur `--rm` | redémarre les anciens conteneurs, affiche le chemin de la sauvegarde, sort en erreur |
| `up --wait` de `app` et `worker` (healthchecks), puis `proxy` | sort en erreur, le site reste en maintenance : voir *Rollback* |
| Écrit la version dans `.deployed-version`, purge les images orphelines | — |

Une migration Doctrine sur PostgreSQL tourne dans une transaction, mais plusieurs migrations d'un même déploiement ne sont pas atomiques entre elles : après un échec, la base peut contenir une partie des migrations.

## Rollback

1. Code seul (migrations compatibles avec l'ancienne version) : redéployer le tag précédent (*Run workflow* ou `bin/deploy V0.13`).
2. Schéma incompatible : restaurer la sauvegarde prise avant le déploiement, puis redéployer le tag précédent.

```bash
export APP_VERSION=$(cat .deployed-version)
PROD="docker compose -f docker-compose.yaml -f docker-compose.prod.yml"
$PROD stop worker app
$PROD exec -T postgres sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --clean --if-exists' < ~/backups/evetools/<fichier>.dump
bin/deploy V0.13
```

## Commandes manuelles en prod

`docker-compose.prod.yml` exige `APP_VERSION` :

```bash
export APP_VERSION=$(cat .deployed-version)
docker compose -f docker-compose.yaml -f docker-compose.prod.yml logs -f worker
docker compose -f docker-compose.yaml -f docker-compose.prod.yml exec app php bin/console <commande>
```

## Mise en place (une fois)

### Serveur

Prérequis : Docker Compose ≥ 2.17 (`up --wait-timeout`), `flock` (util-linux), le checkout git actuel avec son `.env` et `config/jwt/*.pem`. Le `git fetch` lancé par la commande forcée n'a ni agent SSH ni saisie de passphrase : le dépôt étant public, `git remote set-url origin https://github.com/srekcud/evetools.git` suffit. Les clés JWT ne sont plus dans l'image : elles sont montées en lecture seule depuis `./config/jwt`.

1. Mettre le checkout sur une version qui contient `bin/deploy` : `git fetch --tags && git checkout V0.14`.
2. Générer une clé dédiée (sur un poste, pas sur le serveur) : `ssh-keygen -t ed25519 -N '' -C evetools-deploy -f evetools_deploy`.
3. Dans `~/.ssh/authorized_keys` de l'utilisateur propriétaire du checkout, la clé publique avec une commande forcée :
   ```
   command="/chemin/vers/evetools/bin/deploy",restrict ssh-ed25519 AAAA... evetools-deploy
   ```
   Cette clé ne peut rien faire d'autre que lancer `bin/deploy` avec une version validée.
4. Récupérer l'empreinte d'hôte : `ssh-keyscan -t ed25519 <host>`.

### GitHub

- Environnement `production` (Settings → Environments), avec de préférence *Required reviewers* = soi-même pour garder un bouton de validation :
  - secrets `DEPLOY_SSH_KEY` (clé privée de l'étape 2) et `DEPLOY_SSH_KNOWN_HOSTS` (sortie de `ssh-keyscan`)
  - variables `DEPLOY_HOST` et `DEPLOY_USER`
- Variable de dépôt `VAPID_PUBLIC_KEY` (clé publique, lue au build du frontend ; sans elle les notifications push sont désactivées côté navigateur).
- Après la première release : passer les paquets `evetools-app` et `evetools-proxy` en *Public* (Package settings → Visibility). Sinon, `docker login ghcr.io` sur le serveur avec un token `read:packages`.

Les images ne contiennent aucun secret : `.env` et `config/jwt/*.pem` sont exclus par `.dockerignore`, l'image embarque un `.env` vide et reçoit sa configuration par l'environnement de `docker-compose.prod.yml`.
