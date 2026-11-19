# File de la boucle de nuit (ordre = priorité ; l'état vient de GitHub et des threads, ne pas le dupliquer ici)

scheduled_task_id: scheduled-task:command:mcp:755b9cb9-06d4-4e9e-b0d2-99ca669da8ef:schedule-task:night-orchestrator-2026-11-03
journal: /home/sdu/Documents/projects/perso/evetools/.claude/tasks/loop-2026-11-16-night.md
parallélisme maximal : 2
repo : srekcud/evetools

Boucle du 2026-11-16 (relance 12:40) : reliquats du week-end débloqués par Sylvain. Couloirs = préfixe avant « : ». Tout est sur l'écran projet de groupe : exception validée pour cette relance, Compagnon (#1004) et Projet stock (#1006) tournent en parallèle (fichiers distincts : chaîne Mercure/compagnon d'un côté, migration + badge de ligne de l'autre) ; sinon jamais deux couloirs Projet/Compagnon en même temps.

Issues :
1004 | m | Compagnon : réservation prise dans le compagnon détaché sans rafraîchissement de la page projet (investigation, repro de Sylvain dans le corps) décision 2026-11-16, lancée à la main
1006 | l | Projet stock : lot 1, prix Jita figé au passage en cours + stock non réclamé par ligne en lecture seule (périmètre dans le dernier commentaire) décision 2026-11-16, lancée à la main
1011 | m | Projet achats : Where to buy limité aux matériaux réservés par le participant (après #1004)
1050 | l | Projet stock : lot 2, réclamer, attribuer ou donner le stock non réclamé au prix de lancement (après #1006)
1051 | l | Projet stock : lot 3, coût incomplet et répartition bloquée tant que du stock n'est pas réclamé (après #1050)
1013 | l | Projet étapes : jobs intermédiaires couverts par le container restent dans Jobs to launch (après #1050)
995 | m | Inventaire : vue By type dans l inventaire de corporation (décision 2026-11-16 dans le dernier commentaire)
989 | s | CI : vue-tsc sur chaque PR (exception CI validée par Sylvain 2026-11-16, PR seulement)
990 | m | Déploiement : bin/deploy met à jour tous les services compose (exception deploy validée par Sylvain 2026-11-16, PR seulement, rien lancé en prod)

Pas dans cette boucle : #933 (PI, en attente d un export réel en jeu), #902 (2e passage de Sylvain), #248 (PostgreSQL 18, gardé par Sylvain), #955 (laissée de côté).

Remplissage autorisé (étape 6), avec les exclusions ci-dessus.

Exclues : circuit:decision, PI, Makefile/.docker/CI/déploiement, sauf #989 et #990 (exception du 2026-11-16).
