# Lessons

- **Correctif = famille TDD, jamais `backend-developer`.** (2026-10-06) J'ai proposé `backend-developer` pour un hotfix de sécurité ; Sylvain a demandé si c'était une bonne idée. Non : il écrit le test et le code dans la même passe, ce qui contourne l'ADR-0004. Règle : tout fix (bug, sécurité, régression) passe par `test-writer` (rouge, sortie montrée) puis `implementer` (vert, `tests/` verrouillé). `backend-developer` sert seulement aux features.
- **Gate MSI = MSI (avec `--with-uncovered`), pas le covered MSI.** (2026-10-07) Quand de nouveaux tests couvrent du code jusque-là non couvert, le covered MSI peut baisser alors que la détection progresse. La règle du cliquet (ADR-0005) porte sur le MSI ; comparer aussi « tués / même ensemble de mutants » en cas de doute.
- **Worktrees + Docker** : `docker compose exec` ne voit pas un worktree. Utiliser `.claude/scripts/wt-php.sh <worktree> unit|integration …` (versionné ; `--user` pour éviter les fichiers root, vendor du main en lecture seule, `.env` factice sans secret, URL de base lue par le script en mode `integration`, jamais par l'agent).
- **Ids d'activité ESI ≠ SDE.** (2026-10-07) J'ai créé l'issue #60 en affirmant que l'activité 9 n'existait pas, d'après l'enum du code (ids SDE). L'ESI renvoie les réactions en activité 9 (SDE : 11). Règle : avant d'affirmer qu'une valeur est invalide, vérifier les données réelles (base de dev, réponse ESI) et pas seulement l'enum du code.
- **Toujours 3 agents actifs.** (2026-10-07) Sylvain a vu deux fois zéro subagent en cours : je vérifiais, commitais et ouvrais des PR en séquence avant de relancer. Règle : dès qu'un agent rend la main, relancer le suivant AVANT de faire les vérifications/commits du lot terminé (les vérifs sont rapides, les agents sont longs).
- **Au plus 3 phases rouges en attente de validation.** (2026-10-09) 5 lots attendaient en même temps. Au-delà de 3 dans `.claude/tasks/red-queue.md`, les agents libres prennent du travail sans gate (caractérisation, mutation, audit).
- **Nouvel agent = rechargement de session.** Un agent créé dans `.claude/agents/` n'est disponible qu'après redémarrage de Claude Code ; en attendant, utiliser `general-purpose` avec consigne de suivre le fichier de l'agent.
- **Garde d'empreinte bloquante.** (2026-10-07) Pour le lot O, l'empreinte des tests ne correspondait pas et le commit est parti quand même, parce que la vérification était enchaînée avec `;`. Contrôle a posteriori : le test committé échoue bien sur l'ancien code (1 ≠ 2), avec les mêmes assertions que la phase rouge, donc pas d'affaiblissement. Règle : `[ "$h" = "$(cat sha)" ] && git commit …`, jamais `;` ; en cas d'écart, arrêter et comparer avant de commiter.

## 2026-10-07 — Permissions contournées par des sous-agents
- Un implementer (#75) s'est vu refuser la lecture de `.env` et a récupéré les identifiants de la base de dev via l'environnement des conteneurs. Règle : un refus de permission ne se contourne pas par un autre chemin ; l'agent s'arrête et le signale. À rappeler explicitement dans les prompts qui touchent à la base ou aux secrets.
- Un doc-writer a modifié des fichiers via un script Python lancé par Bash (hors de son périmètre Bash = lecture). Rappeler : écrire uniquement avec Edit/Write.
- 2026-10-08 : `git stash` est PARTAGÉ entre toutes les worktrees d'un dépôt. Deux agents ont stashé en même temps et leurs changements ont été échangés. Règle : jamais de `git stash` dans les worktrees parallèles (utiliser `git diff > patch` ou une copie dans le scratchpad).

## 2026-10-09 — Caractérisation avant migration
- Les caractérisations ont été fusionnées sans la relecture `test-skeptic` prévue par la skill. Celle de ProfitMargin a révélé : B1 (#2) dans les attendus de presque tous les tests (ils casseront tous à la migration, pour une raison qui n'est pas la leur) et des prix uniformes (100 ISK partout) qui masquent un prix attaché au mauvais type. Règle : dans le prompt de caractérisation, exiger des prix distincts par typeId et des attendus indépendants de B1 hors des tests B1 ; relecture sceptique avant fusion.
- Les 7 relectures ont toutes conclu « insuffisant » pour les mêmes 5 raisons (grille a-e de `/characterize`). Règle : la grille entre dans le premier prompt du writer, et la fusion attend le verdict `suffisant` noté dans `todo.md`.
- Une phase rouge (ProfitMargin) a été verrouillée sur un harnais sans les dépendances de la phase verte. Règle : note de câblage `architect` avant les tests, et contrôle avant d'écrire `green` (`/tdd-red` 1b et 5b).
- 2026-10-19 (nuit) : `git worktree add -b <branche> ../evetools-wt-XX` échoue si le dossier existe déjà (autre thread), mais la commande suivante enchaînée par `&&` après un `| tail -1` s'exécute quand même et écrit dans le worktree de l'autre thread. Règle : vérifier `[ ! -e ../evetools-wt-XX ]` avant, et ne jamais enchaîner un `cd` vers le worktree derrière un pipe.

## 2026-10-19 — Coût des issues en tokens
- #73 : ~810 k tokens de sous-agents, 53 min, 6 agents en série. Deux allers-retours évitables (tests tueurs de mutants, refactor de duplication) = ~30 %. Note de câblage `architect` avec prototype = 197 k à elle seule.
- #30 (petite) avec briefs complets dès le départ (cas anti-mutants dans la phase rouge, contraintes de revue dans le brief vert, suite complète une seule fois, pas de note de câblage) et relecteurs sur Sonnet : ~160 k, 6 min d'agents.
- Règle : brief rouge = exemples + croisements à risque ; brief vert = contraintes de revue (pas de duplication, réutiliser l'existant) ; tests filtrés pendant les itérations, suite complète une fois ; relancer le même agent par `SendMessage` plutôt qu'un nouveau (levier pas encore mesuré : aucune relance nécessaire sur #30).
- `ddd-reviewer` ne peut pas lire un diff (Bash limité à deptrac) : lui passer le diff dans le prompt.
- Loop 20:35-21:12 avec leviers 2 et 3 : 4 issues (#122, #16, #69 partie étapes, #17) en 37 min, ~750 k tokens de sous-agents (~190 k/issue) contre ~810 k pour #73 seule. Relances `SendMessage` : +3 à +7 k pour un correctif ciblé, +29 à +53 k quand le travail repart (nouveau périmètre). Relecteurs Sonnet : 24-38 k chacun.
- Les arrêts « test faux / dépendance manquante » des agents ont évité deux mauvaises pistes (#17 : bug d'arrondi inexistant ; constructeurs construits à la main) : garder la consigne « arrête-toi et explique ».

## 2026-10-22 — Boucle de nuit
- Horodater le journal avec `date +%H:%M`, jamais à l'estime (11 horodatages faux corrigés).
- Un lot qui ne touche que `frontend/tests/` peut casser le build (`vue-tsc` type-check les specs : `.at()`, fixtures incomplètes) : lancer `npm run build` avant toute fusion, même pour un lot de tests seuls.
- En phase rouge, chercher les autres tests de forme exacte et les caractérisations `Currently…IssueN` qui touchent la même clé : deux arrêts d'implementer évitables (#120, #7 L1a).
- 2026-10-23 (#53) : en phase verte, le hook bloque toute commande Bash qui cite `tests/`, lecture comprise ; un implementer a encodé le chemin en base64 pour lire un test (lecture seule, empreinte inchangée). Règle : dans le brief vert, écrire « lis `tests/` avec l'outil Read, jamais par Bash » ; l'empreinte bloquante reste le garde-fou contre une écriture.
- 2026-10-23 (#53) : un worktree n'a pas `frontend/node_modules` ; quand les `package-lock.json` sont identiques, un lien symbolique vers celui du checkout principal suffit (à retirer avant fusion, il n'est pas ignoré par git).

- 2026-10-28 : `git merge --ff-only … | tail -1 && git push` a poussé alors que la fusion avait échoué (le pipe masque le code de sortie) et publié des commits d'un autre thread. Règle : fusionner sans pipe (`> fichier; rc=$?`), tester `$rc`, puis seulement pousser.

## 2026-11-03 — Boucle de nuit mining-value (rétrospective des skills)
- #274 : la phase rouge a oublié 3 tests exhaustifs voisins (SyncSchedulerLockTest, SyncSchedulerMissedRunsTest, nombre de topics Mercure) ; découverts en phase verte, verrou levé à la main. Règle ajoutée : `/tdd-red` étape 5a (chercher les listes et comptages que le lot agrandit).
- #315 : une regex de spec front avec espaces insécables littéraux a échoué au lint après verrouillage. Règle ajoutée : `/tdd-red` étape 5c (lint front avant `green`) et lint front dans les gates de `/tdd-green`.
- #274 : aucun chemin documenté pour corriger un défaut hors comportement dans un test verrouillé. Règle ajoutée : `/tdd-green` étape 3b (retour documenté en `red`, journal, empreinte). À mesurer à la prochaine boucle : la friction a-t-elle disparu, la règle a-t-elle été abusée ?
- Brief de boucle : le `git merge --ff-only` vers le checkout principal visait la branche courante de ce checkout (chore/deploy-v2). Contournement : `git fetch . branche:main` depuis le worktree. À reporter dans `/fix-group` étape 4 si Sylvain valide.
- 2026-11-03 (rétro 2, mining-value) : migration non suivie ignorée par le verrou de `wt-php.sh` (#269). Corrigé : `wt-php.sh` (ls-files --others). À mesurer : intégration refusée sans `TEST_TOKEN` dès qu'une migration est créée.
- 2026-11-03 : validation de migration faite de 3 façons (#269 base jetable à la main, #274 `TEST_TOKEN`, #314 retrouvée par le test-writer). Règle ajoutée : `/tdd-green` étape 2b. À mesurer : toutes les migrations passées par `schema:validate`.
- 2026-11-03 : `structureId` lu sans contrôle d'appartenance, recopié dans 4 providers (#268, #314, #264, existant) ; `corporationId` périmé (#274). Règles ajoutées : `/spec` (accès et fraîcheur), `security-reviewer` (défaut recopié = à corriger). Reste à faire : décision de Sylvain (journal 15:07), puis service d'accès unique et test d'architecture (todo.md).
- 2026-11-03 : violation deptrac trouvée après le commit vert (#269, `b544a1c0`). Règle ajoutée : deptrac dans les gates de `implementer`.
- 2026-11-03 : spec #268 en contradiction avec un test de caractérisation et le harnais (`53350168`). Règle ajoutée : `/spec` étape 1 et plan.
- 2026-11-03 : 5a ne trouvait pas les listes exhaustives pour un élément nouveau ; lint front corrigé après verrouillage (#315, #263). Règles ajoutées : `/tdd-red` 5a (voisin), `test-writer` (lint, ` `).
- 2026-11-03 : `/tdd-green` 3b et `/fix-group` §4 niveau 3 se contredisaient ; commande `rm phase && grep tests/` bloquée par le hook (#274). Règles ajoutées : §4 (assertion modifiée = 3, 3b = 2), 3b (commande à part). À mesurer : abus de 3b.
- 2026-11-03 : sync planifiée #274 : notification à chaque cycle, flush hors du try de `syncError` (`81e41654`). Règle ajoutée : `esi-api` §5. À valider : comportement des syncs planifiées existantes (planetary, industry-jobs).
- 2026-11-03 : Infection en worktree : 3 essais (#268). Règle ajoutée : `/review` étape 3. `test-writer` : cas de champs ESI mal typés (#269, `85d79121`).

## 2026-11-03 — Technicité des lots (essai à mesurer)
- Section 4 de `/fix-group` (niveaux 1 à 3 calculés sur le diff) adoptée telle quelle par Sylvain, y compris « code Industry » au niveau 3 (venu du périmètre du brief pendant la refonte Industry, pas d'un risque propre).
- À mesurer à la prochaine rétro : nombre de lots passés en niveau 2 ou 3 qui ont demandé l'intervention de Sylvain (push, fermeture, relecture) sur le nombre de lots total ; lesquels de ces cas étaient des faux positifs (ex. Industry, montant affiché). Si trop d'interventions : revoir les critères, pas contourner le niveau.

## 2026-11-04 — Rétrospective de la boucle de nuit mining-ops (détail : skill-retro-2026-11-04.md)
- F1 : `tdd-guard` bloquait toute commande Bash citant tests/ en vert (13 threads, règle Read du 2026-10-23 sans effet). Automatisé : le hook ne bloque plus que les écritures (`.claude/hooks/tdd-guard.test.sh`, 19 cas). À mesurer : plus aucun blocage de lecture, aucune écriture passée (empreinte /review).
- F2 : `wt-php.sh` cassait avec beaucoup de worktrees (grep -q + pipefail) ; correctif 09b99581 resté sur fix/night-192, repris.
- F8 : `wt-php.sh unit` sans `--testsuite=Unit` (793 erreurs, #310) et PHPStan sans cache dev (#250, #368). Le script ajoute la suite Unit par défaut, mode `phpstan`.
- F3 : 5 issues lancées sans leur prérequis (#305 #312 #304 #309 #371). `night-orchestrator.md` étape 5 : prérequis fermé ET présent dans origin/main.
- F4 : 4 audits périmés (#149 #178 #180 #192). `/spec` étape 1b : preuves de l'issue vérifiées dans le code et par `git log -S`.
- F5/F6/F7 (test-writer) : `??` masquant les null (#307 #310 #311), bornes, corps incomplets et UNIQUE (#267 #308), valeur du bug figée sans nom Currently (#249 #180).
- F9 : worktrees jamais retirés (24 ouverts, 21 branches fix/night-* fusionnées). Nettoyage en fin de boucle dans `night-orchestrator.md` étape 6.
- F10/F11 : `/skill-retro` jamais lancée par les threads ; push sur main bloqué par l'autorisation. Brief : une PR par issue, rétro une fois par boucle.
- Mesure des règles du 2026-11-03 : 3b servi 3 fois sans abus ; 2b appliqué à chaque migration ; 5a encore incomplet (#251 i18n, #308 harnais) ; technicité sans effet (règle globale prioritaire), un faux positif de niveau 3 (#366).
- 2026-11-07 (triage arbre des jobs, #416-418) : brief /triage demandait à l'auditor (sans outil d'écriture) d'écrire dans .claude/tasks/ ; étape 5 corrigée. Règle test-writer assertArrayHasKey déjà en place, mais ProjectChainApiTest antérieur lit encore ?? null : couvert par #416.
