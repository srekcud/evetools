# File de la boucle de nuit (ordre = priorité ; l'état vient de GitHub et des threads, ne pas le dupliquer ici)

scheduled_task_id: scheduled-task:command:mcp:755b9cb9-06d4-4e9e-b0d2-99ca669da8ef:schedule-task:night-orchestrator-2026-11-03
journal: /home/sdu/Documents/projects/perso/evetools/.claude/tasks/loop-2026-11-13-night.md
parallélisme maximal : 2
repo : srekcud/evetools

Boucle du 2026-11-13 soir : refonte Inventaire (#874-#897, voir .claude/tasks/inventory-redesign-2026-11-13.md) et bugs Marché/Industrie. Deux couloirs de fait : « Inventaire : » (un à la fois, même écran) et « Marché : »/« Industrie : ». Les décisions de la refonte sont dans inventory-redesign-2026-11-13.md et dans les commentaires des issues ; les points 5 et 15 de ce document restent des décisions de structure : Blocage si une issue en dépend.

Issues :
876 | m | Inventaire : garder l inventaire affiché pendant le rechargement, ignorer les réponses périmées (bug)
880 | l | Marché : la structure de marché choisie dans les réglages est ignorée (bug)
877 | m | Inventaire : lieux corporation enregistrés sans nom de structure (bug)
884 | m | Industrie : le scanner de profit ne renvoie rien avec C-J6MT comme lieu de vente (bug, après #880)
895 | s | Inventaire : chaînes non traduites et mauvais lien de réglages sur les onglets Patrimoine (bug)
874 | m | Inventaire : réponses de liste d assets sans le sérialiseur API Platform (perf)
894 | l | Industrie : un projet surveille plusieurs conteneurs (décision 2026-11-13 : table enfant, correspondance insensible à la casse)
875 | m | Inventaire : rendu paresseux de l inventaire (perf)
878 | l | Inventaire : résumé par lieu d abord, contenu à l ouverture (décision 2026-11-14 : trois niveaux, hydratation en tableaux reprise de #874 fermée)
897 | s | Inventaire : apiRequest dans Assets.vue
896 | s | Inventaire : filtres dans l URL
882 | s | Inventaire : recherche dans les noms et contenus de conteneurs, sans casse ni accents
883 | s | Inventaire : en-têtes de lieu avec tri, comptes, volume et fraîcheur (après #877)
891 | s | Inventaire : bloc Asset Safety en haut
879 | m | Inventaire : divisions de hangar corporation
881 | m | Inventaire : vue « Par type » des assets personnage
887 | m | Inventaire : BPC/BPO, runs et ME/TE sur les lignes de plans
886 | m | Inventaire : vaisseaux assemblés groupés par fit, copie EFT
885 | m | Inventaire : sélection de lignes, multibuy et Appraisal
892 | m | Inventaire : destination et info en jeu depuis l inventaire
893 | m | Inventaire : conteneurs épinglés, conteneurs de projet épinglés d office (décision 2026-11-13 : propriétaire ou participant, après #894)
888 | l | Inventaire : ce qui a changé depuis la sync précédente (décision 2026-11-13 : diff dernière sync)
890 | l | Inventaire : volumes et contrat de courrier (décision 2026-11-13 : table de volumes reconditionnés mise à jour 1x/semaine)
905 | s | Industrie : BOM, pourcentage de remplissage non arrondi (bug)
899 | s | Industrie : l onglet ventes liste un produit une fois par BPC
902 | s | Industrie : le bouton Evaluate in Appraisal du BOM ne marche plus
906 | m | Industrie : copier le nom du blueprint depuis l arbre de production
916 | m | Industrie : transactions wallet en retard de 2 h dans achats et ventes de projet (bug)
907 | m | Industrie : copier le nom du blueprint depuis le compagnon de projet
903 | m | Industrie : créer un projet brouillon depuis les blueprints sélectionnés
920 | s | Inventaire : groupes de vaisseaux recherchables par type et nom
896 | s | Inventaire : division et tri dans l URL (reliquat 2026-11-14 : #879 et #883 fusionnées)
885 | m | Inventaire : case par type de la vue Par type, volume total et contrat de courrier (reliquat 2026-11-14, après #890)
891 | s | Inventaire : volume du bloc Asset Safety (reliquat 2026-11-14, après #890)
881 | s | Inventaire : volume et tri par volume de la vue Par type (reliquat 2026-11-14, après #890)
934 | m | Aide : route publique /help, pages FR et EN (décisions dans l issue, plan help-center-plan-2026-11-13.md)
935 | m | Aide : tables des scopes ESI et des fréquences de sync générées depuis le code (après #934)
936 | s | Aide : FAQ (après #934)
937 | s | Aide : playbook premiers pas (après #934)
938 | s | Aide : playbook produit T2 de A à Z (après #934)
944 | s | Aide : calcul du coût de job, bonus de structure et rigs, ME et TE (après #934)
946 | s | Aide : calcul du profit et de la marge (après #934)
953 | l | Industrie : compter les apports de matériaux de projet de groupe à la livraison, valorisés au prix payé pour tous
939 | s | Aide : playbook propriétaire d un projet de groupe (après #934)
940 | s | Aide : playbook membre d un projet de groupe (après #934)
941 | s | Aide : playbook ordres de marché (après #934)
943 | s | Aide : playbook opération de minage et taxe lunaire (après #934)
945 | s | Aide : calcul des frais de vente (après #934)
947 | s | Aide : calcul de la valeur des apports et de la distribution des revenus (après #934)
948 | s | Aide : calcul du prix pondéré et de l Appraisal (après #934)

Pas dans cette boucle : #898 (export CSV écarté de la maquette, à reconfirmer par Sylvain), #840 (configuration Redis de prod : Docker/compose), #838 (2e worker, compose prod, thread de jour), #248 (Docker, CI et migration prod), chaîne UX #531 à #539 (pilotée par Sylvain).

Exclues : circuit:decision, investigation, PI, Makefile/.docker/CI/déploiement.
