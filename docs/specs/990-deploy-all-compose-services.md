# #990 — bin/deploy tient tous les services compose à jour

Décision de Sylvain (2026-11-16) : #990 entre dans la boucle (exception déploiement, PR seulement, aucune commande lancée en prod). Circuit `m`, pas de note de câblage, mode nuit.

## Contexte vérifié dans le code

- `bin/deploy` arrête `worker app`, migre, puis `up -d --force-recreate app worker` et `proxy`. `worker-on-demand` (#838, `docker-compose.yaml` + `docker-compose.prod.yml`) n'est jamais arrêté, recréé ni créé : il garde l'ancienne image, ou n'existe pas.
- Les services d'infra (`postgres`, `rabbitmq`, `redis`) ne suivent leur définition que s'ils sont dans la chaîne `depends_on` de `app` ou `worker` (constat du commentaire de #990 sur redis, V0.22).
- Files RabbitMQ et exchanges durables (flags `AMQP_DURABLE` par défaut), messages persistants (`delivery_mode` 2) : une recréation ne perd pas de message tant que le volume `rabbitmq_data` est relu.

## Règles

1. **Classement dérivé de la config compose.** `bin/deploy` lit `docker compose -f docker-compose.yaml -f docker-compose.prod.yml config` (variables d'environnement déjà exportées) et classe chaque service par son image : `ghcr.io/srekcud/evetools-app:*` = services applicatifs (`app`, `worker`, `worker-on-demand`, tout futur worker), `ghcr.io/srekcud/evetools-proxy:*` = proxy, tout autre = infra. Aucune liste de services en dur dans le script.
2. **Arrêt.** Après la sauvegarde, tous les services applicatifs dont un conteneur existe sont arrêtés (une seule commande `stop`). Si aucun n'existe, pas de `stop` (un `stop` sans service arrêterait tout).
3. **Infra.** Après l'arrêt et avant les migrations, `up -d --no-build --pull never --wait` sur les services d'infra, sans `--force-recreate` : Compose ne recrée que si la configuration a changé. Le log dit, par service d'infra, `==> infra <service>: recreated|created|unchanged` (comparaison de l'identifiant de conteneur avant et après, `ps -aq`).
4. **Échec des migrations.** Retour au tag précédent puis `start` des seuls services applicatifs qui existaient avant l'arrêt (le premier déploiement d'un nouveau worker n'a pas de conteneur à redémarrer).
5. **Démarrage.** Un seul `up -d --no-build --pull never --force-recreate --remove-orphans --wait --wait-timeout 180` sur tous les services applicatifs (un nouveau service est créé par ce déploiement), puis `proxy` inchangé (`--force-recreate --wait`).
6. Le reste du déroulé ne bouge pas : verrou, checkout du tag, pull des images, sauvegarde, `.deployed-version`, purge des images.

## Cas limites

- Compose ne liste aucun service applicatif ou ne trouve aucune image `evetools-app` : le script s'arrête avec une erreur avant de toucher un conteneur.
- Infra sans changement : aucun conteneur d'infra n'est touché, le log affiche `unchanged` pour chacun.

## Hors périmètre

- Recréation du `proxy` (déjà forcée), `docker-compose*.yml`, Makefile, CI.
- Un hostname fixe pour RabbitMQ (voir « Décisions ouvertes »).

## Décisions ouvertes

- RabbitMQ stocke ses données sous le nom de nœud, par défaut le hostname du conteneur, qui change à chaque recréation : sans `hostname:` fixe dans le compose, une recréation de `rabbitmq` repart d'un nœud vide (files redéclarées par Symfony, messages en file perdus). Non traité ici (hors périmètre du script) ; documenté dans `docs/deploy.md`.

## Documentation

`docs/deploy.md` : tableau des étapes (arrêt de tous les services applicatifs, `up` de l'infra, démarrage des services applicatifs), section « Worker des syncs à la demande » sans la mise en garde sur `bin/deploy` ni le démarrage manuel, risque RabbitMQ.
