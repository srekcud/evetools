# File de la boucle de nuit (ordre = priorité ; l'état vient de GitHub et des threads, ne pas le dupliquer ici)

scheduled_task_id: scheduled-task:command:mcp:755b9cb9-06d4-4e9e-b0d2-99ca669da8ef:schedule-task:night-orchestrator-2026-11-03
journal: /home/sdu/Documents/projects/perso/evetools/.claude/tasks/loop-2026-11-07-night.md
parallélisme maximal : 2
repo : srekcud/evetools

Issues (boucle du 2026-11-07, troisième vague, vagues 1 et 2 toutes fusionnées ; une issue dont le thread a ouvert une PR est terminée, on lance la suivante) :
353 | s | proposer un projet quand une cible du Stockpile est basse (file d'actions de l'Atelier livrée sur main par la PR 401)
341 | m | lot 2 : tableau Structures du projet et sa ressource API (lot 1 livré par la PR 382, lire le dernier commentaire de l'issue)
360 | m | Revenus : onglet Industrie avec le bilan mensuel
357 | m | bibliothèque de blueprints personnels et corpo
355 | m | modèles de projet partagés avec la corpo (items, ME/TE, liste noire, structures)
351 | m | contrat de courrier pré-rempli et suivi du transport
358 | m | hangars corpo : visibilité des divisions réglée par le directeur
416 | m | arbre des jobs : la page se fige car l'API de la chaîne omet les champs null (bug)
420 | m | batch scan : un volume journalier Jita manquant affiché 0 (bug)
417 | s | chaîne de production : icône de l'item sur chaque ligne et chaque nœud (après la PR de 416, même composant)
423 | s | afficher les noms de stations des contrats de courrier
419 | m | batch scan en timeout avec une structure comme lieu de vente : sync planifiée des volumes régionaux (décision 2026-11-07, option A)
433 | s | invention : supprimer le code mort de calcul et d'options de décrypteurs
394 | s | retirer materialCost et jobInstallCost des options d'invention (décision 2026-11-07)
342 | m | santé des structures prévues : lecture ESI à l'ouverture, seuil carburant bas 3 jours (décision 2026-11-07)
352 | m | surplus de matières affiché sur un projet terminé, lecture seule (décision 2026-11-07)
354 | m | projet de référence à la création, périmètre réduit (décision 2026-11-07)
334 | m | ventes par contrat : région de la capitale de chaque utilisateur (décision 2026-11-07)
421 | m | récompense du courrier = apport dérivé du propriétaire (décision 2026-11-07)
410 | m | recherche ME/TE proposée seulement si rentable sur les runs du projet (décision 2026-11-07)
372 | m | minage : suppression d'un scan par auteur, propriétaire ou admins, masqué sans recalcul (décision 2026-11-07)
6 | l | trois règles différentes pour le bonus de structure (bug)
124 | l | marge T2 : le scanner, Buy vs Build et le Pivot Advisor ignorent le coût d'invention (bug)
132 | m | Pivot Advisor : matrice en quantités ME 0, composants manquants en ME 10 (bug)
136 | m | scanner et Pivot Advisor : un volume manquant compte 0 m³ (bug)
446 | s | ouvrir un nœud de la vue d'ensemble ne fait pas défiler l'arbre des jobs (bug)
409 | m | écran Blueprints (bibliothèque personnelle et corpo)
411 | m | ME/TE possédés dans le plan et plafond de runs d un BPO
422 | m | alerter le propriétaire quand le contrat de courrier d un projet échoue
445 | m | modifier la blacklist d un projet tant qu il est en brouillon
418 | l | arbre des jobs : lignes ordonnées par niveau de fabrication (remplissage)
449 | l | vue d ensemble triée par urgence et statut « Matériaux manquants » (remplissage)
49 | m | saisie de BPC T2 en lot (remplissage)
50 | m | valeur de vente estimée depuis les ordres de vente de la station choisie (remplissage)
51 | m | source de prix des matériaux : meilleur prix ou station de vente principale (remplissage)
167 | s | tests de caractérisation Industry : étiquetage faux, bugs figés à plusieurs, noms contraires à la spec (remplissage)
203 | m | bonus de temps des compétences dupliqué et requêté par étape (remplissage)
295 | m | liste d'achats : déduire les achats d'un produit du besoin (remplissage)
300 | m | barèmes de location de ligne stockés mais jamais appliqués (remplissage)
261 | s | glossaire : section Minage (remplissage)
269 | m | minage : rendement de retraitement depuis les compétences et le lieu (remplissage)
274 | m | minage lunaire : calendrier des chunks de la corporation et opération planifiée (remplissage)
314 | s | minage : aide à la vente du minerai d'une opération (remplissage)
365 | m | minage : lire le collage du Mining Survey Chipset en scan de site (remplissage)
367 | m | minage : badge de compression par type de site et bandeau d'avertissement (remplissage)
369 | l | minage lunaire : écrans de la taxe lunaire (remplissage)
Pas dans cette boucle : #349 (attend le seuil de #346 et le compagnon de #322, décision 2026-11-07), #248, #299, #302 (post-release), #343, #338, #345 (tranches partielles fusionnées, reliquat à trier par Sylvain), #318 à #330, #339, #340 (à relire face au module unifié).

Exclues : circuit:decision, investigation (à vérifier en jeu), PI, #350 (nouveau scope ESI), #359 (bloquée, décision Sylvain), Makefile/.docker/CI/déploiement. Le Minage n'est plus exclu (décision du 2026-11-07 : finir toutes les issues avant la grosse mise en prod), il passe après l'Industrie.
