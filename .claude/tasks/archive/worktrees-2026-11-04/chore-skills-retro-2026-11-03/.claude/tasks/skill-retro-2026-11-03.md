# Rétrospective des skills — boucle de nuit du 2026-11-03, thread mining-value (#268 #269 #274 #314 #315)

Statut : propositions seulement. Rien n'est appliqué, rien n'est commité, rien n'est poussé. Aucun diff ne touche `src/`, `tests/`, Makefile, `.docker/` ni la CI. Aucun diff n'a été exécuté.

## Sources lues

- Journal `loop-2026-11-03-evening.md` (lignes `[mining-value]`, Blocages, Actions Sylvain), `lessons.md` (section du 2026-11-03).
- Rapports rouges `268-red.md`, `269-red.md`, `274-red.md`, `314-red.md`, `315-red.md`. Ils ne sont pas dans le checkout principal : ils sont non suivis dans le worktree `fix-night-mining-value/.claude/tasks/` (voir P11).
- Commits de `origin/main` : spec, test, feat, fix et refactor des cinq issues.
- Thread T3 « mining-value valeur du minerai (nuit) », positions 0 à 299 (commandes, sorties, rapports des sous-agents). Les références « thread #N » ci-dessous sont des positions de ce thread.
- Skills et agents relus : `tdd-red` 5a/5b/5c, `tdd-green` 3b, `fix-group` §4 et §5, `skill-retro`, `spec`, `review`, agents `test-writer`, `implementer`, `security-reviewer`, `esi-reviewer`, script `wt-php.sh`.

## Jugement sur ce qui est déjà appliqué (à ne pas re-proposer)

| Règle | Friction visée | Verdict |
|---|---|---|
| `tdd-red` 5a (listes exhaustives) | #274 : 3 tests oubliés (`SyncSchedulerLockTest`, `SyncSchedulerMissedRunsTest`, nombre de topics) | **Couvre en partie.** Le grep demandé porte sur « la classe, la clé ou le nombre touché ». Pour un ajout, la classe n'existe pas encore (`TriggerCorporationMiningExtractionsSync`) : le grep ne trouve rien. Voir P5. |
| `tdd-red` 5c (lint front avant `green`) | #315 : regex avec espaces insécables littéraux (`c606ca79`) ; même défaut en #263 (`331688b9`, autre thread) | **Couvre**, mais la correction arrive après le `test-writer`. Deux occurrences : le garde-fou est le lint (déjà automatisé), il faut le lancer plus tôt. Voir P5. |
| `tdd-green` 3b (retour documenté en `red`) | #274 : verrou levé à la main, 2 tests corrigés après la phase verte | **Couvre le mécanisme.** Mais il entre en conflit avec `fix-group` §4 niveau 3 (« verrou de phase levé », « test verrouillé modifié »). Voir P6. |
| `fix-group` §5.2 (`git fetch . branche:main`) | Brief de boucle : main du checkout principal sur `chore/deploy-v2` (lessons ligne 44) | **Couvre.** La ligne de `lessons.md` « à reporter dans /fix-group » est faite (commit `ce5c9d73`). |
| `fix-group` §4 (technicité) | Push et fermeture autonomes sans critère | **Couvre**, sous réserve de P6. Appliqué à rebours : #274 (migration, sync planifiée, scoping corporation) aurait été niveau 3, #315 (nouvelle ressource exposant des données d'un utilisateur) niveau 2 et n'aurait pas été fermée. |
| `skill-retro` | — | Utilisable. Une lacune de chemin : P11. |

## Propositions, classées par valeur

### P1 — `wt-php.sh` : le verrou de migration ne voit pas une migration non suivie

- **Friction.** Le script refuse `integration` quand `migrations/` diffère de `main`, sauf avec `TEST_TOKEN`. Il le teste avec `git diff --quiet main -- migrations/`, qui ignore les fichiers non suivis. Une migration que l'implementer vient de créer ne déclenche donc pas le verrou : les tests d'intégration tournent sur la base partagée `eve_app_test`, sans le nouveau schéma.
- **Preuve.** Thread #122, rapport de la phase verte #269 : « L'intégration `InvTypeRepositoryOreProcessingSkillsTest` passe aussi. Elle a démarré parce que `migrations/Version20261103150000.php` est non suivi (`git diff main` ne le voit pas). » Le test passait par hasard (il ne lit pas la nouvelle table). Une intégration qui en dépend serait rouge, ou verte à tort selon le cas.
- **Fichier.** `.claude/scripts/wt-php.sh`, mode `integration`, ligne 73.
- **Diff.**
```diff
-    if [[ -z "${TEST_TOKEN:-}" ]] && ! git -C "$worktree" diff --quiet "$(git -C "$main_repo" rev-parse main)" -- migrations/; then
+    if [[ -z "${TEST_TOKEN:-}" ]] && { ! git -C "$worktree" diff --quiet "$(git -C "$main_repo" rev-parse main)" -- migrations/ || [[ -n "$(git -C "$worktree" ls-files --others --exclude-standard -- migrations/)" ]]; }; then
```
- **Gain.** Le garde-fou fonctionne dans le cas où il sert (migration créée en phase verte).
- **Risque.** Un worktree qui garde une migration non suivie sans rapport avec le lot est bloqué sans `TEST_TOKEN` : c'est voulu. Syntaxe bash non exécutée ici.

### P2 — `tdd-green` : procédure de validation d'une migration, absente des skills

- **Friction.** Une migration ne peut pas être validée en worktree sans base. Trois façons différentes en une soirée, aucune documentée :
  - #269 : l'implementer calcule les noms d'index à la main (crc32) et écrit « non validée, à confirmer par `schema:validate` côté session principale » (thread #122). L'orchestrateur crée une base jetable avec deux tables factices (`users`, `characters`), extrait le SQL de la migration avec une regex Python et l'exécute (thread #126). Pas de `schema:validate` (journal ligne 29 : « pas de schema:validate Doctrine possible dans le worktree »).
  - #274 : même rapport de l'implementer (thread #192). L'orchestrateur cherche `TEST_TOKEN` dans `lessons.md` (rien, thread #219), lit le script, lance d'abord l'intégration sans base : 106 erreurs sur 107 (thread #221), puis crée la base, migre (95 migrations), `schema:validate` OK, intégration 415 OK, supprime la base (thread #223 à #226).
  - #314 : le `test-writer` retrouve seul la procédure (« après création et migration de la base `eve_app_testmv314` »).
- **Fichier et étape.** `.claude/skills/tdd-green/SKILL.md`, nouvelle étape 2b après la boucle de gates.
- **Diff.**
```diff
 2. Boucle jusqu'au vert sur les gates ciblés de l'implementer (...).
+2b. **Migration créée ou modifiée** (`migrations/`) : l'implementer ne peut pas la valider et ne calcule pas les noms d'index à la main. Avant le commit vert, l'orchestrateur la valide sur une base isolée : `export TEST_TOKEN=<issue>; r(){ .claude/scripts/wt-php.sh <worktree> integration bin/console "$@" --env=test; }; r doctrine:database:create; r doctrine:migrations:migrate -n; r doctrine:schema:validate`, puis `.claude/scripts/wt-php.sh <worktree> integration vendor/bin/phpunit --no-coverage --testsuite Integration`, puis `r doctrine:database:drop --force`. Pas de fusion avec un `schema:validate` en échec ou non lancé ; le résultat va au journal (« Actions Sylvain » garde la commande de prod).
```
- **Gain.** Une validation complète (mapping et schéma) identique pour toutes les issues, sans que chaque thread la redécouvre. Le #269 aurait eu un vrai `schema:validate`.
- **Risque.** `wt-php.sh integration` lit `DATABASE_URL` dans le conteneur `app` : stack arrêtée = échec explicite (exit 67). Une base `eve_app_test<jeton>` oubliée si le thread s'arrête avant le `drop` : le jeton unique par issue limite le dégât.

### P3 — `spec` et `security-reviewer` : le contrôle d'accès d'un identifiant reçu est décidé trop tard

- **Friction.** `structureId` arrive de la requête et sert à lire le cache de marché d'une structure sans vérifier que l'utilisateur la suit. Le défaut est recopié par chaque nouvelle ressource, et la revue le classe « préexistant, ne s'aggrave pas ».
  - Existant : `MiningEntryCollectionProvider` (journal ligne 22).
  - #268 `OreValueTableProvider` : journal ligne 22, constat de la revue (thread #80).
  - #314 `OreSaleProvider` : revue « Le défaut connu reste identique : lecture du cache de n'importe quelle structure… Ça ne s'aggrave pas » (thread #286, validation `FILTER_VALIDATE_INT` seulement, `OreSaleProvider.php:56-67`).
  - #264 (thread mining-ops) : journal ligne 63, constat (1).
  - Quatre providers au total.
  - Même famille, #274 : `Character.corporationId` n'est rafraîchi qu'à la connexion, un ex-membre garde l'accès au calendrier (journal ligne 42 (b), thread #229). La spec de #274 n'a pas posé la question de la fraîcheur de la source d'appartenance.
- **Étape 4 de `skill-retro` (règle violée 3 fois ou plus).** Automatiser, pas ajouter une phrase. Proposition en deux temps : (1) tu tranches la décision ouverte du journal ligne 22 (restreindre aux structures de l'utilisateur ?) ; (2) un service d'accès unique et un test d'architecture (ou règle PHPStan) qui interdit de lire `structureId` dans `src/State/` hors de ce service. Ce second temps touche `src/` et `tests/` : hors de ce lot, à ouvrir en issue après ta décision.
- **Diffs de processus, utiles dès maintenant.**
  - `.claude/skills/spec/SKILL.md`, étape 2 (liste « Couvre ») et étape 3 (plan de la spec) :
```diff
    - ce qui est explicitement hors périmètre ;
+   - l'accès : pour chaque identifiant reçu d'un autre périmètre (`structureId`, `corporationId`, id d'opération) et chaque donnée de corporation, le contrôle d'appartenance exact, et la fraîcheur de sa source (ex. `Character.corporationId` ne se rafraîchit qu'à la connexion). Un défaut d'accès préexistant que la spec reprend est écrit comme tel, avec l'issue ou la décision qui le traite ;
```
```diff
    - cas limites ;
+   - accès et fraîcheur (contrôles, source, ex-membre, structure non suivie) ;
```
  - `.claude/agents/security-reviewer.md`, liste « Vérifie » :
```diff
 - **Rôles** : ...
+- **Défaut d'accès recopié** : un contrôle absent qu'un nouveau provider ou processor reprend du code existant (ex. `structureId` lu dans le cache de marché sans vérifier que l'utilisateur la suit) est un constat « à corriger » du lot, pas « inchangé » ; liste les providers qui partagent le défaut.
```
- **Gain.** Le défaut remonte à la spec (une question) au lieu d'une revue qui le laisse passer quatre fois. Les décisions « accès » arrivent en Blocages avant le code.
- **Risque.** Chaque lot qui lit `structureId` sortira avec un constat « à corriger » tant que la décision du journal ligne 22 n'est pas prise, donc possiblement plus de niveau 2 sans push autonome. Acceptable : c'est un vrai trou d'accès, public repo.

### P4 — `implementer` : deptrac dans les gates ciblés

- **Friction.** Le `implementer` ne lance pas deptrac (réservé à `/review`). En #269, deux violations (un mapper de `State\Provider` utilisé par des `State\Processor`) sont apparues après le commit vert : refactor de 5 fichiers, puis relance de toute la suite Unit, PHPStan, deptrac et Infection. En #274 et #314, le brief de l'orchestrateur demandait deptrac et le rapport affiche « Deptrac : 0 violation » : la consigne existe donc de fait, mais seulement quand le brief la porte.
- **Preuve.** Commit `b544a1c0` « Removes the provider-layer mapper that processors depended on (deptrac violation) » ; thread #146 à #167 (11 étapes, relance complète des gates et d'Infection) ; rapports verts #274 (thread #192) et #314 (thread #281). Durée d'un deptrac : non mesurée.
- **Fichier.** `.claude/agents/implementer.md`, gates ciblés et phrase finale.
- **Diff.**
```diff
-La batterie complète (suite entière, deptrac, Infection, build front) tourne une seule fois par lot, dans `/review` après rebase : ne la lance pas ici.
+La batterie complète (suite entière, Infection, build front) tourne une seule fois par lot, dans `/review` après rebase : ne la lance pas ici.
 ...
 2. `docker compose exec app php vendor/bin/phpstan analyse <fichiers src/ touchés>` : aucune erreur ;
+2b. `docker compose exec app php vendor/bin/deptrac analyse --no-progress` : 0 violation (les violations de `skip_violations` ne comptent pas) ;
```
- **Gain.** Une violation de couche se corrige quand le code est encore en tête de l'agent, pas après un commit et une batterie.
- **Risque.** Si deptrac est long, il pèse sur chaque boucle : à mesurer. Il a tourné trois fois sans problème dans le thread.

### P5 — `tdd-red` 5a et `test-writer` : grep sur un voisin, lint du front par l'agent

- **Friction.** 5a demande de chercher « la classe, la clé ou le nombre touché ». Pour un nouveau déclencheur de scheduler, la classe n'existe pas : seuls les tests d'un élément voisin déjà présent le listent. Les trois oublis de #274 (`3ae7f114`, thread #192) sont de ce type.
- **Fichier A.** `.claude/skills/tdd-red/SKILL.md`, 5a.
```diff
-(`grep -rn` sur la classe, la clé ou le nombre touché, dans `tests/` et `frontend/tests/`)
+(`grep -rn` dans `tests/` et `frontend/tests/` sur la classe, la clé ou le nombre touché, et sur un voisin déjà présent de l'élément ajouté, qui n'existe pas encore : `SyncPublicContracts::class` pour un nouveau déclencheur du scheduler, `sync/mining` pour un nouveau topic, le nombre actuel de topics ou de lignes)
```
- **Fichier B.** `.claude/agents/test-writer.md`, ligne Frontend (même défaut, #315 `c606ca79` et #263 `331688b9`) :
```diff
-- Frontend : si tu touches `frontend/tests/`, lance aussi `npm --prefix <racine>/frontend run build` (type-check des specs). Pas de `Array.prototype.at()` ...
+- Frontend : si tu touches `frontend/tests/`, lance aussi `npm --prefix <racine>/frontend run build` (type-check des specs) et `npm --prefix <racine>/frontend run lint`. Écris les espaces insécables d'un format de nombre avec ` ` et ` `, jamais littéralement (`no-irregular-whitespace`). Pas de `Array.prototype.at()` ...
```
- **Gain.** Moins de correction après verrouillage (deux commits `test(...)` de correction en #274 et #315).
- **Risque.** Faible. Le lint de l'agent double le 5c de l'orchestrateur : garder les deux, 5c reste le contrôle avant `green`.

### P6 — `fix-group` §4 et `tdd-green` 3b : la correction documentée d'un test pose-t-elle le niveau 3 ?

- **Friction.** 3b dit : écris `red`, corrige sans changer d'assertion, note au journal. §4 niveau 3 dit : « verrou de phase levé ; … ; test verrouillé modifié » → jamais de push ni de fermeture autonomes. Une correction par 3b est à la fois l'un et l'autre. Deux lots sur cinq de ce thread ont une correction de ce type (#274 listes exhaustives, #315 lint), plus #262 et #263 dans l'autre thread (journal lignes 28 et 47) : la porte d'autonomie serait inerte sur ces lots.
- **Friction annexe.** Tant que la phase vaut `green`, le hook bloque toute commande Bash qui cite `tests/`. En #274 la commande `rm .claude/state/tdd-phase && grep … tests/…` a été bloquée en entier (thread #194).
- **Fichier A.** `.claude/skills/fix-group/SKILL.md`, §4.
```diff
-| 3 critique | ... verrou de phase levé ; gate rouge non expliqué ; test verrouillé modifié | ...
+| 3 critique | ... verrou de phase levé hors de l'étape 3b de `/tdd-green` ; gate rouge non expliqué ; assertion d'un test verrouillé modifiée (`git diff main...HEAD -U0 -- tests frontend/tests` ajoute ou retire une ligne `assert*`, `expect(` ou `#[DataProvider]`) | ...
```
```diff
-| 2 sensible | migration ; ... ; reste volontairement ouvert (lot partiel) | ...
+| 2 sensible | migration ; ... ; reste volontairement ouvert (lot partiel) ; défaut hors comportement corrigé par l'étape 3b de `/tdd-green` (liste exhaustive, lint, conflit de rebase), fichier et raison au journal | ...
```
- **Fichier B.** `.claude/skills/tdd-green/SKILL.md`, 3b :
```diff
-Écris `red` dans `.claude/state/tdd-phase` (retour documenté en rouge), corrige uniquement ce défaut
+Écris `red` dans `.claude/state/tdd-phase` (retour documenté en rouge), dans une commande à part : tant que la phase vaut `green`, le hook bloque toute commande Bash qui cite `tests/`. Corrige uniquement ce défaut
```
- **Gain.** La règle d'autonomie garde un sens ; une correction mécanique ne bloque plus tout. Une commande en moins perdue.
- **Risque.** Assouplit un critère de sécurité du processus. Le critère reste calculé sur le diff, doute entre deux niveaux = le plus haut. À toi de dire si 3b doit rester niveau 3 (alors ne pas appliquer le diff A).

### P7 — `spec` : contradictions avec les tests de caractérisation et le harnais existants

- **Friction.** La spec #268 a été écrite sans lire les tests existants du service qu'elle réutilise. Le `test-writer` a trouvé deux conflits en phase rouge : rendement 0 voulu « connu » alors que `OreValueServiceCharacterizationTest::testLeavesTheReprocessValuesUnknownWhenMineralsAreWorthNothing` fige `null` ; Compressed Fullerite-C50 à 2 813 dans le harnais alors que la spec attend un compressé `null` pour le gaz.
- **Preuve.** `268-red.md` « Points à trancher » 1 et 2 ; commit `53350168` « gas has a compressed price and a zero yield is rejected » (spec, `OreValueService`, harnais +129 lignes) ; thread #52 à #54 (réécriture de la spec, `SendMessage` au `test-writer`, tests T3, T3b, V5 refaits).
- **Fichier.** `.claude/skills/spec/SKILL.md`, étape 1 et plan de l'étape 3.
```diff
-1. Lis l'issue (...), `docs/glossary.md`, les ADR pertinentes et le code concerné. Vérifie tout ce qui est vérifiable avant de poser une question.
+1. Lis l'issue (...), `docs/glossary.md`, les ADR pertinentes et le code concerné. Pour chaque service existant que la spec modifie ou réutilise, lis ses tests de caractérisation et son harnais (`grep -rln "<Classe>\|Currently.*Issue" tests`). Vérifie tout ce qui est vérifiable avant de poser une question.
```
```diff
    - hors périmètre ;
+   - tests existants contredits ou modifiés par la spec (fichier, raison) ;
```
- **Gain.** Un aller-retour spec → rouge → spec → rouge en moins (une relance du `test-writer` et un commit de spec).
- **Risque.** Allonge l'étape 1 de quelques lectures ; en mode nuit la spec est écrite en Sonnet : une consigne de plus à tenir.

### P8 — `esi-api` : checklist d'une sync planifiée (notifications, persistance dans le `try`)

- **Friction.** La sync planifiée de #274 (30 min) a été ajoutée à `SYNC_TITLE_KEYS` du fil de notifications : une notification par cycle. Dans la même revue, le flush de l'état de sync était hors du `try` qui publie `syncError` : un échec laissait le spinner front sans erreur.
- **Preuve.** Revue sécurité/ESI, thread #229 (« Bloquant 1 … Une erreur de flush laisse le spinner front sans `syncError` ») ; commit `81e41654` (`SILENT_SYNC_TYPES`, déplacement de `recordOutcome` dans le `try`) ; le rapport rouge #274 listait pourtant les points de câblage « à faire en phase verte » sans ces deux-là ; l'`esi-reviewer` a la règle `syncError` dans un catch englobant, mais le test rouge n'avait pas de cas « échec de persistance ».
- **Fichier.** `.claude/skills/esi-api/SKILL.md`, §5, après « Registering the topic » (le skill est en anglais).
```diff
+**Scheduled sync** (Scheduler → Trigger): add its syncType to `SILENT_SYNC_TYPES` in `frontend/src/composables/useNotificationFeed.ts` and give it no `syncTitles` key, otherwise the notification feed gets one entry per cycle. The final state write (flush) stays inside the `try` that publishes `syncError`, and the red phase includes a test where that flush fails.
```
- **Gain.** Deux défauts relevés en revue, corrigés en amont (une relance de correctif et ses gates en moins).
- **Risque.** **Décision produit sous-jacente non vérifiée** : les syncs planifiées existantes (planetary, industry-jobs, 30 min) ne sont pas dans `SILENT_SYNC_TYPES` et notifient probablement à chaque cycle. La règle fixe un comportement différent pour les nouvelles. À valider avant d'appliquer.

### P9 — `review` : la commande Infection qui fonctionne en worktree

- **Friction.** Trois essais en #268 pour lancer Infection en worktree : le premier inclut l'intégration (thread #90 : « l'initial run inclut les tests d'intégration »), le deuxième utilise l'option mal orthographiée (`--testframework-options` n'existe pas, thread #91), le troisième passe (thread #92). Le thread le sait ensuite (#269, thread #167, du premier coup), mais la skill donne seulement la commande du dépôt principal.
- **Fichier.** `.claude/skills/review/SKILL.md`, étape 3, ligne Infection.
```diff
-   - `docker compose exec app php vendor/bin/infection --threads=max --filter=<fichiers src/ touchés>` : ...
+   - `docker compose exec app php vendor/bin/infection --threads=max --filter=<fichiers src/ touchés>` : ... Dans un worktree : `.claude/scripts/wt-php.sh <worktree> unit vendor/bin/infection --threads=4 --no-progress --filter=<fichiers> --test-framework-options="--testsuite=Unit"` (l'option s'écrit avec le tiret après `test`).
```
- **Gain.** Deux essais ratés de moins par issue dans un worktree ; les runs Infection sont longs (timeouts de 540 s).
- **Risque.** Aucun connu ; la commande est celle qui a rendu 92 % et 89 % de MSI.

### P10 — `test-writer` : champs ESI mal typés

- **Friction.** #269 : la phase rouge couvrait les champs absents d'une réponse ESI (R10) mais pas les champs de mauvais type. Une entrée `skill_id` ou `active_skill_level` en chaîne levait un `TypeError` ; corrigé après la phase verte (`85d79121`, test ajouté `a level given as a string`). La spec #274 contenait ce cas (K6 : id en chaîne ou flottant), pas la #269 : la couverture dépend de la spec.
- **Fichier.** `.claude/agents/test-writer.md`.
```diff
+- Tout code qui lit une réponse ESI : un cas par champ lu pour l'absence ET pour un mauvais type (chaîne, flottant, `null`), attendu = erreur explicite, jamais une valeur par défaut.
```
- **Gain.** Un commit de correction en moins. **Risque.** Faible ; fait gonfler un peu les tests (une ligne de DataProvider par champ). Une seule occurrence datée : à appliquer seulement si tu le veux.

### P11 — `skill-retro` et `tdd-red` : où vivent `lessons.md`, le journal et les rapports rouges

- **Friction.** `lessons.md`, le journal de boucle et `<n>-red.md` ne sont pas suivis par git. Seuls deux fichiers de `.claude/tasks/` le sont. Un worktree ne voit donc pas `lessons.md` (thread #7 : « No such file »), et les rapports rouges de #268 à #315 sont écrits dans le worktree du thread, pas dans le checkout principal. Cette rétro a dû les chercher dans `fix-night-mining-value/.claude/tasks/`. Le worktree supprimé, ils disparaissent.
- **Fichier.** `.claude/skills/skill-retro/SKILL.md`, étape 6.
```diff
-Ajoute une ligne datée par friction à `.claude/tasks/lessons.md` (friction, preuve, skill modifiée), ajout seulement.
+Ajoute une ligne datée par friction à `lessons.md` du checkout principal (non suivi, absent des worktrees : `$(dirname "$(git rev-parse --path-format=absolute --git-common-dir)")/.claude/tasks/lessons.md`), ajout seulement.
```
- **Gain.** La rétro depuis un worktree sait où écrire. **Risque.** Faible. Le déplacement des rapports rouges (`tdd-red` étape 7) n'est pas proposé : à toi de dire si tu veux qu'ils soient écrits dans le checkout principal ou commités.

## Coût des agents : ce qui est mesuré, ce qui ne l'est pas

- **Chiffre de 150 à 200 k tokens par phase : [À CONFIRMER].** Le thread T3 ne donne aucun compteur de tokens (champs `subagent` : titre, texte, heure de fin). Je ne l'affirme pas.
- **Mesuré (heures UTC du thread).**
  - #269 : rouge ≈ 15 min (spec commitée 14:09, fin 14:24), verte ≈ 7 min.
  - #274 : rouge ≈ 17 min, avec une relance du `test-writer` pour 4 tests existants ; verte ≈ 10 min.
  - Chaque issue : 4 à 5 runs d'agents (rouge, relance éventuelle, vert, revue sécurité/ESI).
  - Deux relances du `test-writer` : #268 (spec réécrite, P7) et #274 (tests existants du C6 de la spec, laissés en suspens).
  - La limite d'usage de 5 h a été atteinte dans les deux threads (journal ligne 46 pour mining-ops ; thread #255 « You've hit your 5-hour limit » après #274).
- **Leviers que les propositions touchent.** P2 et P4 évitent des gates relancés ; P5 et P7 évitent des relances du `test-writer` ; P9 évite des runs Infection ratés (jusqu'à 9 minutes chacun).
- **Non proposé, à décider par toi.** `a6aead59` (88 lignes de tests tueurs de mutants sur #268, après la fusion, par un agent à part) vient d'un MSI de 92 % sur un périmètre report-only (`infection.json5`, ADR-0005 : cliquet seulement pour les périmètres caractérisés). Les modules neufs doivent-ils viser un MSI ? Sans réponse, la dépense en tokens reste ad hoc. #269 (89 %) n'a pas eu ce suivi.

## Écarté (pas un défaut de skill, ou déjà couvert)

- **Réseau coupé à ~17:00** (fetch et `gh` en échec) : panne de service. Le commentaire sur #314 non posté a été repris au journal (ligne 54) et posté (ligne 59). Le thread avait conclu « #263/#264 absents de main » sans pouvoir vérifier : #263 était dans `main` depuis 17:56, #314 a été repris, puis #315 à 18:02. Pas de règle : la conclusion était notée comme non revérifiable.
- **`08f36314`** (`void load()` retiré puis remis 1 minute plus tard) : un conseil de revue contredit par la suite de tests, un tour. Pas de défaut de skill.
- **Décisions produit** à ouvrir ou trancher par toi : défaut 78 % des entrées de minage (journal ligne 31), rendement effacé en cas d'échec (ligne 30), 403 chez tous les candidats = `esi_error` ou `denied` (ligne 42 (a)), `corporationId` périmé (ligne 42 (b), recoupe P3), `getStockpileStatus` appelé à chaque GET (ligne 60), POST `/sync` sans verrou et `findAll` du déclencheur (ligne 42 (c)(d)).

## Mesure à la prochaine boucle (étape 7)

- 5a et 5c : les lots mining-value ont eu lieu avant leur application, rien à mesurer ici. À la prochaine boucle : combien de tests exhaustifs ou de lint corrigés après verrouillage (cible : 0) ?
- 3b : combien de fois utilisée, et la règle a-t-elle servi à contourner une vraie erreur de comportement (abus) ? Voir P6 pour son niveau de technicité.
- P1 et P2 si appliquées : nombre de migrations validées par `schema:validate` sur nombre de migrations créées (cible : toutes).
