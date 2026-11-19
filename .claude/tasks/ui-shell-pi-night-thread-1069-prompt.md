Tu es un thread de la boucle de nuit de Sylvain. **Mode nuit** : gates validés en autonomie. **Thread UI shell + PI : chaîne de 3 issues, une branche et une PR par issue** (consigne de Sylvain du 2026-11-17, elle remplace « une issue par thread » du brief commun).

Ordre : #1069 (composant de sous-onglets commun, `group:ui-shell`), #1070 (raccourci Réglages sur la barre d'onglets Patrimoine, `group:ui-shell`), puis #1068 (Planétaire › Revenus : revenu PI réel tiré des ventes du wallet, `group:revenue-unification`). Un autre thread traite #1063, #1065, #1066, #1067 (grille des revenus et pages PVE, Minage, Industrie) : ne touche pas à ces pages.

Branches : `fix/night-1069` et `fix/night-1070` depuis origin/main (`git fetch origin && git switch --no-track -c fix/night-N origin/main`). Pour #1068 : commence par la partie backend (endpoint du revenu PI réalisé, tests) sur `fix/night-1068` depuis origin/main ; la page frontend a besoin du composant de grille de #1063 : dès que `origin/fix/night-1063` existe (`git fetch origin`), rebase ta branche dessus en local (elle n'est pas encore poussée) et réutilise le composant tel quel. S'il n'existe toujours pas quand le backend est fini, pousse le backend seul, PR sans `Closes` (« frontend after #1063 »), commentaire sur l'issue, fin. La PR de #1068 vise **main** et dit « merge #1063 first » si elle contient ses commits.

À lire d'abord (et seulement cela) :
1. `/home/sdu/Documents/projects/perso/evetools/.claude/tasks/loop-thread-brief.md` (brief commun, sauf les points remplacés ici) ; journal : `/home/sdu/Documents/projects/perso/evetools/.claude/tasks/loop-2026-11-17-night.md` (préfixe `[#N]`, ajout uniquement `>>`). Les décisions de #1068 sont déjà dans la section Décisions (P1 à P4 seulement, ventes hors marché « non suivies »).
2. Par issue : `zsh -ic 'gh issue view N --json title,body,comments'` (gh n'est authentifié que via `zsh -ic`).
3. Pour #1068, maquette en lecture seule, non versionnée (ne la copie pas dans le dépôt) : `/home/sdu/Documents/projects/perso/evetools/docs/design/mockups/revenue-unification.html`, option C, fonction `lineGrid`.

Règles propres :
- #1069 : migre au moins les barres de sous-onglets existantes hors pages revenus (les pages revenus suivront dans l'autre thread) ; pas de changement visuel non demandé.
- #1070 : l'onglet Paramètres du Bilan (comptabilité des projets corpo, côté PVE ou côté minage) déménage dans une section Patrimoine des Réglages ; le réglage est conservé à l'identique (aucun réglage retiré).
- #1068 : source = `CachedWalletTransaction` (`isBuy = false`), types = sorties des schémas planétaires (SDE `PlanetSchematic`/`PlanetSchematicType`) ; la production théorique reste une colonne estimation séparée, jamais additionnée au réel ; donnée inconnue jamais à 0. La page PI reçoit une barre d'onglets (vue actuelle + Revenus). API Platform, conventions du CLAUDE.md.
- Contrôle visuel impossible la nuit : dis-le dans la PR.

Push et PR : `git push origin fix/night-N` (jamais `main`, jamais de force ni de tag), PR avec `Closes #N`, niveau de technicité, liée avec `link_pull_request`. Tu ne fermes aucune issue. Push impossible : 3 essais au plus puis `BLOCAGE environnement` au journal et fin.

Limite d'usage (« 5-hour limit, resets HH:MM ») : `[ui-pi] LIMITE jusqu'à HH:MM` au journal, push de ce qui est commité, fin du tour sans boucler.

Rapport final (français, 10 lignes max) : PR et SHA par issue, issues partielles ou bloquées avec raison, tokens, actions prod (migration éventuelle).
