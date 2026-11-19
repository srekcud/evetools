Tu es un thread de la boucle de nuit de Sylvain. **Mode nuit** : gates validés en autonomie. **Thread Revenus : chaîne de 4 issues du label `group:revenue-unification`, une branche et une PR par issue** (consigne de Sylvain du 2026-11-17, elle remplace « une issue par thread » du brief commun).

Ordre : #1063 (composant commun : grille des revenus sur une ligne + bloc « À traiter ») puis #1065 (PVE › Revenus), #1066 (Minage › Revenus), #1067 (Industrie › Revenus). Hors périmètre : #1064 (Bilan, vague suivante) et #1068 (PI, autre thread, qui réutilisera ton composant).

Branches : `fix/night-1063` depuis origin/main (`git fetch origin && git switch --no-track -c fix/night-1063 origin/main`). Chaque page part de la branche précédente de la chaîne (`fix/night-1065` depuis `fix/night-1063`, etc.), mais **chaque PR vise `main`** (jamais une autre branche) et dit « merge #1063 first » (ou la PR précédente). Pousse `fix/night-1063` dès que le composant est vert : le thread PI attend `origin/fix/night-1063`.

À lire d'abord (et seulement cela) :
1. `/home/sdu/Documents/projects/perso/evetools/.claude/tasks/loop-thread-brief.md` (brief commun, sauf les points remplacés ici) ; journal : `/home/sdu/Documents/projects/perso/evetools/.claude/tasks/loop-2026-11-17-night.md` (préfixe `[#N]`, ajout uniquement `>>`), section Décisions déjà remplie (Saison, colonnes Industrie).
2. Par issue : `zsh -ic 'gh issue view N --json title,body,comments'` (gh n'est authentifié que via `zsh -ic`). Le corps de #1063 décrit la grille en détail : c'est la référence, pas une maquette.
3. Maquette de référence, en lecture seule et non versionnée (dépôt public, ne la copie pas dans le dépôt) : `/home/sdu/Documents/projects/perso/evetools/docs/design/mockups/revenue-unification.html`, fonctions `lineGrid`, `contributorBar`, `stripCells`, `todoPanel` (option C). Les valeurs y sont fictives.

Règles propres au chantier :
- Style HUD existant de l'app (pas de cartes KPI, pas de nouveau thème), libellés i18n FR + EN, noms longs tronqués avec infobulle.
- Donnée inconnue = état inconnu visible (hachuré, hors du net), jamais 0 ni `?? 0` ; estimation toujours marquée.
- Données manquantes côté backend (écart vs période précédente, revenu par jour pour la régularité, contribution par personnage) : ajoute-les aux endpoints `/ledger/*` existants (API Platform, conventions du CLAUDE.md), tests proportionnés. Ne crée pas de nouvelle source de donnée ni de dépendance : si c'est nécessaire, Blocage au journal et issue suivante.
- Les anciens composants de synthèse remplacés (cartes, graphes de `LedgerDashboardTab` et `MiningStatsCards` si plus utilisés) sont retirés dans la PR de la page concernée, pas avant. Aucun réglage retiré sans accord (l'onglet Paramètres du Bilan est traité par #1070, autre thread).
- Contrôle visuel impossible la nuit : dis-le dans la PR.

Push et PR : `git push origin fix/night-N` (jamais `main`, jamais de force ni de tag), PR avec `Closes #N`, niveau de technicité, dépendance (« merge #M first »), liée avec `link_pull_request`. Tu ne fermes aucune issue. Push impossible : 3 essais au plus puis `BLOCAGE environnement` au journal et fin.

Gestion du contexte : si le contexte devient trop chargé avant la fin de la chaîne, pousse ce qui est commité, liste les issues restantes au journal et termine ; l'orchestrateur relancera un thread.

Limite d'usage (« 5-hour limit, resets HH:MM ») : `[revenus] LIMITE jusqu'à HH:MM` au journal, push de ce qui est commité, fin du tour sans boucler.

Rapport final (français, 10 lignes max) : PR et SHA par issue, issues partielles ou bloquées avec raison, tokens, actions prod.
