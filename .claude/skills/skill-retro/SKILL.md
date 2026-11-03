---
name: skill-retro
description: Rétrospective de fin de session sur les skills et agents : relève les frictions réelles, propose un diff par skill ou agent concerné, l'applique sur une branche locale après accord. À lancer en fin de session ou de boucle.
disable-model-invocation: true
argument-hint: [journal de la session] (défaut : dernier `.claude/tasks/loop-*.md`)
---

Source des frictions : `$ARGUMENTS` (si vide : le dernier `.claude/tasks/loop-*.md`), plus ce qui s'est passé dans la conversation.

1. **Relève les frictions, pas les impressions.** Cherche seulement des faits vérifiables : un arrêt de skill ou d'agent, un verrou levé ou contourné, un test à corriger après la phase rouge, un échec de gate trouvé trop tard, une consigne que l'agent a mal comprise, un aller-retour évitable, un blocage répété. Pour chacun : ce qui s'est passé, la preuve (commit, ligne du journal, sortie), l'étape de skill ou le fichier d'agent responsable.
2. **Écarte ce qui n'est pas un défaut de skill** : bug du code produit, décision produit, panne réseau ou de service. Écarte aussi ce qui est déjà couvert par `.claude/tasks/lessons.md` ou par la skill concernée (relis-la avant de proposer).
3. **Une proposition par friction**, la plus petite possible : fichier (`.claude/skills/<nom>/SKILL.md` ou `.claude/agents/<nom>.md`), étape visée, diff exact (quelques lignes), le gain attendu en une phrase, et le risque si la règle est mal posée. Pas de réécriture d'une skill entière, pas de règle qui n'a pas de friction datée derrière.
4. **Même règle violée deux fois ou plus** : propose de l'automatiser (hook, règle PHPStan ou deptrac, test d'architecture) plutôt que d'ajouter une phrase de skill.
5. **Présente la liste** (friction, preuve, diff). Les skills et agents sont du code de processus de Sylvain : n'applique rien sans son accord, sauf si le brief du thread le donne explicitement en mode nuit.
6. **Applique ce qui est accepté** sur une branche de travail (`git switch --no-track -c chore/skills-retro-<date> origin/main`), un commit par skill (`chore(skills): <ce qui change>`), sans push. Ajoute une ligne datée par friction à `lessons.md` du checkout principal (non suivi, absent des worktrees : `$(dirname "$(git rev-parse --path-format=absolute --git-common-dir)")/.claude/tasks/lessons.md`), avec la friction, la preuve et la skill modifiée, ajout seulement.
7. **Mesure à la session suivante** : pour chaque règle ajoutée, la friction a-t-elle disparu ? Une règle qui n'a servi à rien ou qui a gêné est retirée. Note le résultat dans `lessons.md`.

Une préférence ou une correction de Sylvain qui dépasse ce dépôt va dans une proposition de ligne pour `~/.claude/CLAUDE.md`, pas dans une skill du projet.
