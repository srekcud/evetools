# Boucle de nuit 2026-11-12 : une chaîne

Une issue par thread, Sonnet, mode nuit, PR par issue (Sylvain relit et fusionne). Chaque thread lance le suivant de la file en fin de tour (voir `night-chain-thread-prompt.md`). Journal : `loop-2026-11-12-night.md`.

## Chaîne A : toutes les issues éligibles, une par une
- [x] #746 Compagnon détaché : le contenu s'affiche puis la fenêtre devient vide (s) : PR #751 (partielle, sans Closes)
- [x] #747 Planning: copy the blueprint name to the clipboard to start the job in game (s) : PR #753
- [x] #675 chore(logging): log info-level worker messages in production (m, décision du 2026-11-11 : monolog-bundle, JSON stderr, info pour messenger et app) : PR #754
- [x] #643 feat(admin): RabbitMQ consumers and rates on the admin page (m, décision du 2026-11-11 : pas d'API de management ; worker mort détecté dans le tableau de santé ; si déjà couvert par #637/#674, commentaire sur l'issue et fin) : déjà couvert par #637/#674, commentaire sur l issue, pas de PR

## Hors boucle (attendent Sylvain)
- circuit:decision : #750
- #248 : PostgreSQL 18, infra (images, prod, CI)
