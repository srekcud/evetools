Tu es un thread de la boucle de nuit de Sylvain. **Mode nuit** : gates validés en autonomie. **Thread contributions en attente : 1 issue, une branche, une PR.**

Issue : #1093 (contributions en attente masquées sur les lignes remplies et sans réservation, BOM et compagnon, `group:group-industry`, `circuit:m` : `/spec` puis `/tdd-red`, `/tdd-green`, `/review`).

Branche : `fix/night-1093` depuis `origin/fix/night-1088` (`git fetch origin && git switch --no-track -c fix/night-1093 origin/fix/night-1088`), car #1088 (PR #1091, elle-même sur #1090) réécrit `GroupCompanion.vue`. La PR vise **main** et dit « merge #1090 and #1091 first ». Ne modifie pas ce que #1088/#1089 ont fait hors du besoin de #1093. La clé i18n `groupIndustry.companion.deliver` (« Deliver All ») est à Sylvain : n'y touche pas.

À lire d'abord (et seulement cela) :
1. `/home/sdu/Documents/projects/perso/evetools/.claude/tasks/loop-thread-brief.md` (brief commun, sauf les points remplacés ici) ; journal : `/home/sdu/Documents/projects/perso/evetools/.claude/tasks/loop-2026-11-19-night.md` (préfixe `[#1093]`, ajout uniquement `>>`).
2. `zsh -ic 'gh issue view 1093 --json title,body,comments'` (gh n'est authentifié que via `zsh -ic`). Le corps de l'issue fait foi, conception déjà validée par Sylvain.
3. Maquette en lecture seule, non versionnée (ne la copie pas dans le dépôt) : `/tmp/claude-1000/-home-sdu-Documents-projects-perso-evetools/29b0e610-d4b2-453d-9afe-e7ebce62be30/scratchpad/pending-mockup.html`. Les quantités et le second personnage y sont fictifs.

Points d'entrée : `src/State/Provider/Industry/Project/ProjectBomProvider.php` (`mapToResource`, `$visibleReservations`), `IndustryProjectContributionRepository::sumPendingQuantityByLineAndParticipant` (renvoie participantId : il faut le nom du pilote, via `PilotName::of` sur le membre), la ressource `ProjectBomLineResource`, `frontend/src/stores/group-industry/types.ts` (`BomItem`), `frontend/src/components/group-industry/LineReservation.vue`, `frontend/src/components/group-industry/companionTasks.ts`.

Règles propres :
- Pas de `?? 0` silencieux sur une donnée métier nouvelle ; nom de champ `pendingContributions` (vérifie le glossaire `docs/glossary.md` pour « contribution »).
- Tests PHPUnit du provider (ligne remplie avec contributions en attente, contribution en attente sans réservation, `isMine`), vitest de `buildCompanionTasks` et `LineReservation` (plus de `×0`, puce ambre sur ligne remplie, nom long tronqué avec `title`).
- Contrôle visuel impossible la nuit : dis-le dans la PR. Aucune migration attendue ; si tu en vois une nécessaire, arrête-toi et note la décision au journal.

Push et PR : `git push origin fix/night-1093` (jamais `main`, jamais de force ni de tag), PR avec `Closes #1093`, niveau de technicité, liée avec `link_pull_request`. Tu ne fermes pas l'issue. Push impossible : 3 essais au plus puis `BLOCAGE environnement` au journal et fin.

Limite d'usage (« 5-hour limit, resets HH:MM ») : `[#1093] LIMITE jusqu'à HH:MM` au journal, push de ce qui est commité, fin du tour sans boucler.

Rapport final (français, 10 lignes max) : PR et SHA, blocages avec raison, tokens, actions prod (aucune attendue).
