Tu es un thread de la boucle de nuit de Sylvain. **Mode nuit** : gates validés en autonomie. **Thread compagnon de groupe : 2 issues, une branche et une PR par issue.**

Ordre : #1089 (pluriel de `groupIndustry.companion.runs`, `circuit:s`), puis #1088 (compagnon : achats séparés des lignes de craft, compteurs séparés, accordéons, `circuit:s`). Les deux touchent `frontend/src/components/group-industry/GroupCompanion.vue` et les locales : #1088 part de la branche de #1089.

Branches : `fix/night-1089` depuis origin/main (`git fetch origin && git switch --no-track -c fix/night-1089 origin/main`), puis `fix/night-1088` depuis `fix/night-1089`. La PR de #1088 vise **main** et dit « merge #1089 first ».

Un autre thread a traité #1084/#1085 (PR #1086, #1087 : panneau d'achats Industry) : ne touche pas à ces fichiers. Un commit local non poussé de Sylvain renomme la clé `groupIndustry.companion.deliver` en « Deliver All » : ne modifie pas cette clé.

À lire d'abord (et seulement cela) :
1. `/home/sdu/Documents/projects/perso/evetools/.claude/tasks/loop-thread-brief.md` (brief commun, sauf les points remplacés ici) ; journal : `/home/sdu/Documents/projects/perso/evetools/.claude/tasks/loop-2026-11-19-night.md` (préfixe `[#N]`, ajout uniquement `>>`).
2. Par issue : `zsh -ic 'gh issue view N --json title,body,comments'` (gh n'est authentifié que via `zsh -ic`). Le corps de #1088 fait foi (décisions de Sylvain déjà prises : 6 lignes par sous-groupe, état replié en localStorage).
3. Maquette en lecture seule, non versionnée (ne la copie pas dans le dépôt) : `/tmp/claude-1000/-home-sdu-Documents-projects-perso-evetools/a6b096a8-1ec9-4085-beb3-56dcb9b3f1a7/scratchpad/companion-1088.html`. Elle montre 4 achats et 3 crafts : la règle est 6 par sous-groupe.

Règles propres :
- #1089 : forme plurielle vue-i18n en FR et EN, partout où la clé est rendue (lignes libres, « My jobs (ESI) »).
- #1088 : frontend seul, `BomItem.isJob` distingue achat et craft ; sous-groupes To buy / To build dans « To assign » et « My lines to deliver », chacun avec son compte et son « + N more · see the BOM » ; compteurs d'en-tête `To assign: X to buy · Y to build` et `To deliver: X to buy · Y to build`, côté à zéro masqué ; sous-groupe vide masqué ; accordéons sur sections et sous-groupes, compte visible replié, état en localStorage (try/catch, ouvert par défaut). Tests vitest du composant mis à jour (testids existants conservés quand c'est possible).
- Contrôle visuel impossible la nuit : dis-le dans la PR.

Push et PR : `git push origin fix/night-N` (jamais `main`, jamais de force ni de tag), PR avec `Closes #N`, niveau de technicité, liée avec `link_pull_request`. Tu ne fermes aucune issue. Push impossible : 3 essais au plus puis `BLOCAGE environnement` au journal et fin.

Limite d'usage (« 5-hour limit, resets HH:MM ») : `[companion] LIMITE jusqu'à HH:MM` au journal, push de ce qui est commité, fin du tour sans boucler.

Rapport final (français, 10 lignes max) : PR et SHA par issue, issues partielles ou bloquées avec raison, tokens, actions prod (aucune attendue).
