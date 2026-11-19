Tu es un thread de la boucle de nuit de Sylvain. **Mode nuit** : gates validés en autonomie. Relance du chantier `group:revenue-unification` : le premier thread s'est arrêté après #1065 (contexte chargé). Une branche et une PR par issue, chaque PR vise `main`.

Déjà livré (ne pas refaire) : #1063 composant commun `RevenueLineGrid` + `RevenueTodoPanel` + `utils/revenueGrid.ts` (PR #1072, `origin/fix/night-1063`) ; #1065 PVE › Revenus (PR #1074, `origin/fix/night-1065`, modèle à suivre : `PveRevenueGrid.vue` côté front, `PveRevenueGridBuilder` côté backend) ; #1068 PI backend `GET /planetary/revenue` + onglet (PR #1075, `origin/fix/night-1068`, partielle : la colonne Bilan attend #1064) ; #1069/#1070 sous-onglets et Réglages Patrimoine (PR #1071, #1073).

À lire d'abord (et seulement cela) :
1. `/home/sdu/Documents/projects/perso/evetools/.claude/tasks/loop-thread-brief.md` (brief commun, sauf les points remplacés ici) ; journal `/home/sdu/Documents/projects/perso/evetools/.claude/tasks/loop-2026-11-17-night.md` (préfixe `[#N]`, ajout uniquement `>>`), sections Décisions et Blocages (rétros sur `wt-php.sh` et `node_modules`/`marked` : à connaître).
2. Par issue : `zsh -ic 'gh issue view N --json title,body,comments'`, plus le corps de #1063 (référence de la grille).
3. Maquette en lecture seule, non versionnée, à ne pas copier : `/home/sdu/Documents/projects/perso/evetools/docs/design/mockups/revenue-unification.html` (option C : `lineGrid`, `pageMiningC`, `pageIndustryC`, `pageBalanceC`). Valeurs fictives.

Règles du chantier :
- Style HUD existant, i18n FR + EN, noms longs tronqués avec infobulle.
- Donnée inconnue = état inconnu visible (hachuré, hors du net), jamais 0 ni `?? 0` ; estimation toujours marquée.
- Données manquantes : compléter les endpoints `/ledger/*` existants (API Platform, conventions du CLAUDE.md), tests proportionnés ; aucune nouvelle source ni dépendance, sinon Blocage au journal et issue suivante.
- Anciens composants de synthèse retirés dans la PR de la page concernée. Aucun réglage retiré sans accord.
- Contrôle visuel impossible la nuit : le dire dans la PR.

Push et PR : `git push origin fix/night-N` (jamais `main`, ni force ni tag), PR vers `main` avec `Closes #N`, niveau de technicité, dépendances (« merge #1072, #1074 first », etc.), liée avec `link_pull_request`. Aucune issue fermée par toi. Push impossible : 3 essais puis `BLOCAGE environnement` au journal et fin.

Contexte trop chargé : pousse ce qui est commité, liste le reste au journal, termine. Limite d'usage : `[revenus-N] LIMITE jusqu'à HH:MM` au journal, push, fin.

Rapport final (français, 10 lignes max) : PR et SHA par issue, partiel ou bloqué avec raison, actions prod.
