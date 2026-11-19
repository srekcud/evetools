# Déploiement big bang V0.14

Préparé le 2026-11-11 sur `origin/main` = `21fd78f2` (CI verte, aucune PR ouverte).
Écart avec le dernier tag `V0.13` (2026-02-26) : 1398 commits, 73 migrations, premier déploiement par tag (`docs/deploy.md`).

## Bloquants avant le tag (constatés via `gh`)

- [x] **Nettoyé le 2026-11-11 : 42 Go libres (12 %).** Disque serveur à 98 % (cause : log json d un conteneur à 31 Go, build cache 7,7 Go, images 7,5 Go récupérables ; base 423 Mo) (1,3 Go libres sur 50 Go, relevé 2026-11-11)** : pull des images V0.14 + `pg_dump` x2 ne passent pas. Faire de la place avant tout.
- [x] **`ADMIN_CHARACTER_IDS` absente du `.env` serveur** : ajoutée, `95020822` (personnage admin donné par Sylvain ; « Alpha » résolu via ESI = 144583475, autre joueur).
- État relevé : checkout `/opt/evetools` à root, propre, sur `59a2bee` (2026-05-10) ; 77 migrations exécutées, 46 en attente ; Compose v5.0.2, `flock` OK, clés JWT OK, VAPID rempli, structures raitaru/tatara/sotiyo uniquement (#141 OK).

- [x] **Fait 2026-11-11 (secrets, variables, reviewer srekcud, sudoers + clé forcée sur le serveur).** Environnement GitHub `production` absent : le job `deploy` de `release.yml` échouera. Créer l'environnement avec secrets `DEPLOY_SSH_KEY`, `DEPLOY_SSH_KNOWN_HOSTS`, variables `DEPLOY_HOST`, `DEPLOY_USER` (clé à commande forcée, `docs/deploy.md` § Mise en place). Alternative : laisser le job échouer et lancer `make deploy VERSION=V0.14` sur le serveur.
- [x] **Posée 2026-11-11.** Variable de dépôt `VAPID_PUBLIC_KEY` absente : lue au build du frontend ; sans elle les notifications push sont coupées côté navigateur dans l'image V0.14.
- [ ] **Application EVE SSO (developers.eveonline.com)** : 3 scopes ajoutés depuis V0.13, à cocher sur l'application sinon le login échoue :
  `esi-characters.read_standings.v1`, `esi-markets.read_character_orders.v1`, `esi-wallet.read_corporation_wallets.v1`.

- [x] **PR #611 (Mercure en mémoire) mergée** : `caddy_data` = 1,4 Go, tout dans `caddy/mercure.db` (transport bolt par défaut, sans limite). Après le deploy : `rm /var/lib/docker/volumes/evetools_caddy_data/_data/caddy/mercure.db`.

## Serveur, avant le deploy

- [ ] `.env` : ajouter `ADMIN_CHARACTER_IDS` (eveCharacterId du main admin, virgules si plusieurs). Sans elle, plus d'accès admin.
- [ ] `.env` contient bien `APP_SECRET`, `JWT_PASSPHRASE`, `EVE_CLIENT_ID`, `EVE_CLIENT_SECRET`, `EVE_CALLBACK_URL`, `ESI_TOKEN_ENCRYPTION_KEY`, `VAPID_*` (lues par `docker-compose.prod.yml`). `LOCK_DSN` est désormais fixé par le compose, rien à faire.
- [ ] `config/jwt/*.pem` présents (montés en lecture seule, plus dans l'image).
- [ ] `git status` propre sur le checkout (sinon `bin/deploy` s'arrête au checkout), remote en https : `git remote set-url origin https://github.com/srekcud/evetools.git`.
- [ ] Docker Compose ≥ 2.17, `flock` installé.
- [ ] Contrôle #141 : `select structure_type, count(*) from industry_structure_configs group by 1;` ; les valeurs `engineering_complex` / `refinery` font lever l'écran Profit Margins, à basculer vers raitaru/azbel/sotiyo/athanor/tatara.

## Répétition des migrations : écartée par Sylvain le 2026-11-11 (rollback = pg_dump auto de bin/deploy)

Sur le serveur, dump de la prod actuelle :
```bash
docker compose exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -Fc "$POSTGRES_DB"' > prod-pre-V0.14.dump
```
En local sur `origin/main` :
```bash
docker compose exec -T postgres sh -c 'createdb -U "$POSTGRES_USER" eve_app_rehearsal'
docker compose exec -T postgres sh -c 'pg_restore -U "$POSTGRES_USER" -d eve_app_rehearsal --no-owner' < prod-pre-V0.14.dump
docker compose run --rm -e DATABASE_URL='postgresql://<user>:<pass>@postgres:5432/eve_app_rehearsal?serverVersion=16&charset=utf8' app php bin/console doctrine:migrations:migrate --no-interaction
docker compose run --rm -e DATABASE_URL='…eve_app_rehearsal…' app php bin/console doctrine:schema:validate
```
Attendu : migrations OK, schéma « in sync ».

## Ce que le deploy efface ou transforme (assumé)

- `Version20261103200000` et `Version20261105150000` : **tous les projets industrie**, solo et groupe (étapes, jobs liés, achats, membres, BOM, contributions, ventes). Décision #362, pas de migration de données ; liens d'invitation et favoris cassés.
- `Version20261105100000` : taux de taxe et courtage saisis supprimés (calculés depuis le vendeur).
- `Version20261019010000` : `auth_status` passe des users aux personnages.
- `Version20261110040000` : renomme les rigs obsolètes dans `industry_structure_configs`.
- `Version20261110050000` : crée une ligne `pve_income` par escalation vendue.
- Le `pg_dump` automatique de `bin/deploy` couvre le retour arrière.

## Déployer

1. Premier passage : `git fetch --tags && git checkout V0.14` sur le serveur (le checkout actuel n'a pas `bin/deploy`).
2. `git tag V0.14 && git push origin V0.14` (tag : à valider par Sylvain).
3. Après la première release : paquets GHCR `evetools-app` et `evetools-proxy` en Public (ou `docker login ghcr.io` sur le serveur).
4. Si le job `deploy` n'a pas de secrets : `make deploy VERSION=V0.14` sur le serveur.

## Après le deploy

- [ ] `export APP_VERSION=$(cat .deployed-version)` puis `docker compose -f docker-compose.yaml -f docker-compose.prod.yml exec app php bin/console doctrine:schema:validate`.
- [ ] `… logs -f worker` : le worker consomme `async` et `scheduler_default` ; nouvelles tâches planifiées (ordres 5 min, chunks 5 min, épuisement de site 5 min, courrier 30 min, opérations récurrentes 30 min, extractions 30 min, blueprints 1 h, taxe lunaire 1 h, volumes régionaux 24 h, purge listes partagées 1 j).
- [ ] Panneau admin accessible avec le main.
- [ ] Chaque joueur se reconnecte (nouveaux scopes) puis lance la synchro des compétences ; d'ici là frais, marges et répartitions de groupe affichés « inconnus » (voulu).
- [ ] Prévenir les beta-testeurs du wipe industrie.
- [ ] File `failed` RabbitMQ vide après une heure.

Aucune commande console de normalisation à lancer : `reset-reaction-me-levels` et `regenerate-multi-unit-steps` sont caduques avec le wipe.

## Commandes dans l'ordre

Serveur : `debian@79.137.120.121`, checkout `/opt/evetools`. `debian` n'a pas accès à Docker : toutes les commandes serveur se lancent dans un shell root (`sudo -i`), y compris `bin/deploy` (dont le `git checkout`) et la sauvegarde (`/root/backups/evetools`). Les dumps manuels vont dans `/home/debian` pour le `scp`.

### 0. Prérequis GitHub et EVE

Clés VAPID générées sur le serveur avec le conteneur actuel :
```bash
ssh -t debian@79.137.120.121 sudo -i
cd /opt/evetools
docker compose -f docker-compose.yaml -f docker-compose.prod.yml exec app php bin/console app:generate-vapid-keys
```
Dans `/opt/evetools/.env`, remplir `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` et `VAPID_SUBJECT=mailto:<adresse de contact>`. Depuis le poste, la seule clé publique :
```bash
gh variable set VAPID_PUBLIC_KEY -R srekcud/evetools --body '<VAPID_PUBLIC_KEY>'
```
- developers.eveonline.com → application → cocher les 3 scopes.
- Environnement GitHub `production` : pas pour ce passage (deploy manuel, étape 6).

### 1. Serveur : contrôles et dump (checkout actuel, avant V0.14)

```bash
ssh -t debian@79.137.120.121 sudo -i
cd /opt/evetools
stat -c '%U' /opt/evetools
git config --global --add safe.directory /opt/evetools
git status --short
git rev-parse HEAD | tee /root/pre-V0.14.commit
git remote set-url origin https://github.com/srekcud/evetools.git
docker compose version
command -v flock
ls config/jwt/
grep -E '^(ADMIN_CHARACTER_IDS|VAPID_PUBLIC_KEY|VAPID_SUBJECT)=' .env
PROD_OLD="docker compose -f docker-compose.yaml -f docker-compose.prod.yml"
$PROD_OLD exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "select structure_type, count(*) from industry_structure_configs group by 1"'
$PROD_OLD exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --format=custom' > /home/debian/prod-pre-V0.14.dump
chown debian: /home/debian/prod-pre-V0.14.dump
```
`ADMIN_CHARACTER_IDS` absent : l'ajouter dans `.env` (eveCharacterId du main, virgules si plusieurs).

### 2. Poste : répétition des migrations sur le dump

```bash
scp debian@79.137.120.121:prod-pre-V0.14.dump /tmp/
cd ~/Documents/projects/perso/evetools
git fetch origin && git checkout --detach origin/main
docker compose up -d postgres
docker compose exec -T postgres sh -c 'dropdb -U "$POSTGRES_USER" --if-exists eve_app_rehearsal && createdb -U "$POSTGRES_USER" eve_app_rehearsal'
docker compose exec -T postgres sh -c 'pg_restore -U "$POSTGRES_USER" -d eve_app_rehearsal --no-owner --no-acl' < /tmp/prod-pre-V0.14.dump
docker compose run --rm app sh -c 'export DATABASE_URL=$(echo "$DATABASE_URL" | sed "s#/[^/?]*?#/eve_app_rehearsal?#"); php bin/console doctrine:migrations:migrate --no-interaction && php bin/console doctrine:schema:validate'
```
Attendu : toutes les migrations passent, `[OK] The database schema is in sync`. Sinon : stop, on corrige avant le tag.

### 3. Tag (poste)

```bash
git tag V0.14 origin/main
git push origin V0.14
gh run watch -R srekcud/evetools
```
Attendre le job `images` vert ; le job `deploy` échoue faute d'environnement `production`, c'est attendu.

### 4. Paquets GHCR publics

github.com/srekcud?tab=packages → `evetools-app` et `evetools-proxy` → Package settings → Change visibility → Public.

### 5. Serveur : passage sur V0.14

```bash
ssh -t debian@79.137.120.121 sudo -i
cd /opt/evetools
git fetch --tags
git checkout V0.14
APP_VERSION=V0.14 docker compose -f docker-compose.yaml -f docker-compose.prod.yml pull app proxy
```

### 6. Déployer (même shell root)

```bash
make deploy VERSION=V0.14
```
Sortie attendue : `==> V0.14 deployed (previous: none, backup: /root/backups/evetools/…)`.

### 7. Vérifications (même shell root)

```bash
export APP_VERSION=$(cat .deployed-version)
PROD="docker compose -f docker-compose.yaml -f docker-compose.prod.yml"
$PROD ps
$PROD exec app php bin/console doctrine:migrations:status | grep -E 'New|Executed'
$PROD exec app php bin/console doctrine:schema:validate
$PROD logs --since 10m worker | grep -iE 'error|critical' | head
$PROD exec rabbitmq rabbitmqctl list_queues name messages
```
Puis navigateur : login (écran de consentement avec les nouveaux scopes), panneau admin, une page Industrie vide, une notification push test.

### Rollback (shell root, `/opt/evetools`)

```bash
PROD="docker compose -f docker-compose.yaml -f docker-compose.prod.yml"
APP_VERSION=V0.14 $PROD stop worker app
APP_VERSION=V0.14 $PROD exec -T postgres sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --clean --if-exists' < /home/debian/prod-pre-V0.14.dump
git checkout $(cat /root/pre-V0.14.commit)
$PROD up -d --build
```

### Clé de déploiement GitHub (option sudo, avant le tag)

`debian` hors groupe docker ; la clé lance `bin/deploy` en root via une règle sudo limitée à ce script.

Poste :
```bash
ssh-keygen -t ed25519 -N '' -C evetools-deploy -f ~/.ssh/evetools_deploy
cat ~/.ssh/evetools_deploy.pub
```
Serveur (`ssh -t debian@79.137.120.121 sudo -i`) :
```bash
echo 'debian ALL=(root) NOPASSWD: /opt/evetools/bin/deploy *' > /etc/sudoers.d/evetools-deploy
chmod 440 /etc/sudoers.d/evetools-deploy
visudo -c
echo 'command="sudo -n /opt/evetools/bin/deploy \"$SSH_ORIGINAL_COMMAND\"",restrict <contenu de evetools_deploy.pub>' >> /home/debian/.ssh/authorized_keys
```
Poste, environnement GitHub :
```bash
gh api -X PUT repos/srekcud/evetools/environments/production
gh secret set DEPLOY_SSH_KEY -R srekcud/evetools -e production < ~/.ssh/evetools_deploy
ssh-keyscan -t ed25519 79.137.120.121 | gh secret set DEPLOY_SSH_KNOWN_HOSTS -R srekcud/evetools -e production
gh variable set DEPLOY_HOST -R srekcud/evetools -e production --body 79.137.120.121
gh variable set DEPLOY_USER -R srekcud/evetools -e production --body debian
```
Settings → Environments → production → Required reviewers = soi-même (le job `deploy` attend le bouton).

Test sans risque, une fois `bin/deploy` présent sur le serveur (après l'étape 5) : `ssh -i ~/.ssh/evetools_deploy debian@79.137.120.121 nimporte` doit répondre une erreur de format de version sans rien toucher.

Déroulé avec la clé : tag (étape 3) → le job `deploy` attend la validation → étapes 4 et 5 → *Approve* dans Actions, qui remplace l'étape 6. Ensuite : `rm ~/.ssh/evetools_deploy` (la clé privée ne vit plus que dans GitHub).
