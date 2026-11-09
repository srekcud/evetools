# Rétrospective des skills : boucle de nuit du 2026-11-03 au 2026-11-04

Source : `.claude/tasks/loop-2026-11-03-night.md` (25 lignes « rétro » de threads). Comparé à `origin/main` (skills, agents, hook, scripts) et à `lessons.md`.

## Mesure des règles de la rétro précédente (étape 7)

| Règle (2026-11-03) | Résultat |
|---|---|
| `/tdd-red` 5a, listes exhaustives voisines | marche à moitié : la liste i18n (#251) et le harnais d'un provider (#308) ont encore été oubliés |
| `/tdd-red` 5c, lint front avant `green` | marche, mais l'outil Write remplace ` ` par le caractère littéral (#368) |
| `/tdd-green` 3b, retour documenté en `red` | servi 3 fois (#307, #311, #366), sans abus : aucune assertion affaiblie |
| `/tdd-green` 2b, `schema:validate` | appliqué à chaque migration (#364, #366, #310) |
| Technicité niveaux 1 à 3 | sans effet pour l'instant : la règle globale (« pas de push ») a primé partout. Un faux positif de niveau 3 : #366 (assertion d'une liste d'onglets mise à jour) |

## Frictions et propositions

### F1. Le hook `tdd-guard` bloque les lectures Bash de `tests/` en phase verte (13 threads)
Preuve : #307 #310 #316 #317 #267 #273 #306 #251 #192 #180 #178 #179 #366. Les commandes bloquées : grep, sed -n, git show, git add, filtre vitest, ligne de journal, `rm` de la phase chaîné à une autre commande. Déjà dans `lessons.md` (2026-10-23) sans effet : c'est la même règle violée plus de deux fois, donc à automatiser (étape 4).
Diff : `.claude/hooks/tdd-guard.sh`, branche Bash : ne bloquer que les écritures (redirection `>`/`>>` vers `tests/`, `sed -i`, `tee`, `cp`/`mv`/`rm`/`touch` sur `tests/`, `git checkout|restore|apply|stash`, `patch`) et laisser passer le reste. Test du hook : un cas bloqué par forme d'écriture et un cas autorisé par forme de lecture.
Gain : environ 13 contournements par boucle en moins, plus de passage par base64.
Risque : une forme d'écriture oubliée dans la liste rouvre le contournement. Le garde-fou reste l'empreinte des tests vérifiée par `/review`.

### F2. `wt-php.sh` échoue 1 fois sur 3, et en continu au-delà d'environ 70 worktrees (#306 #249 #192)
Cause : `grep -qx` sous `pipefail` ferme le tube de `git worktree list`. Le correctif `09b99581` existe sur `fix/night-192`, mais il n'est pas dans main (#192 a été fermée sans lui).
Diff : cherry-pick de `09b99581` (`grep -x … >/dev/null`).

### F3. Des issues sont lancées alors que leur prérequis manque (5 threads, dont 4 pour rien)
Preuve : #305 #312 #304 #309 #371. Le prérequis était ouvert, partiellement livré ou seulement sur une branche voisine.
Diff : `night-orchestrator.md` étape 5. Avant de lancer une issue, lire son « Dépend de » (`gh issue view N --json body`). Chaque issue citée doit être fermée et son commit présent dans `origin/main` (`git log origin/main --grep "#M"`). Sinon, la noter « bloquée (prérequis #M) » sans la lancer.
Gain : 4 threads évités sur cette boucle.

### F4. Des audits périmés ont produit des issues déjà corrigées (#149 en totalité, #192 #180 #178 à moitié)
Diff : `/spec` étape 1. « Pour chaque preuve citée par l'issue (fichier::méthode, valeur), vérifie-la dans le code actuel et lance `git log -S '<symbole>' --oneline -- <fichier>`. Si une preuve est fausse, la ligne va dans le journal et le point sort de la spec. »
Gain : #149 aurait été fermée sans thread.

### F5. Les attendus `null` sont masqués par `??` dans les helpers de test (#307 #310 #311 ; 4 tests faux corrigés en phase verte)
API Platform omet les clés nulles. Un helper qui fait `?? 'missing'` ou `?? null` rend la différence entre clé absente et `null` invisible.
Diff : `.claude/agents/test-writer.md`. « Un attendu `null` se vérifie avec `assertArrayHasKey` ou `array_key_exists`, puis `assertNull`. Jamais de `??` dans un helper de lecture de réponse. »

### F6. Le brief du test-writer oubliait les bornes d'entrée et les corps incomplets (#267 #308 ; 2 bloquants de revue)
Les bornes d'entrée, les corps incomplets (propriété typée non initialisée, d'où une 500) et la concurrence sur `UNIQUE` manquaient. Une fois ajoutés au brief à la main en #306, la revue n'a plus rien trouvé.
Diff : `.claude/agents/test-writer.md`. « Tout endpoint d'écriture : un cas par borne d'entrée (taille de liste, int4), un corps auquel manque chaque champ requis (attendu 422, jamais 500), et un doublon sur chaque contrainte UNIQUE. »

### F7. Une valeur de bug est figée sans nom `Currently…Issue` (#249 #180)
Diff : `.claude/agents/test-writer.md` ligne 25. Ajouter : « et `grep -rn '<valeur fausse attendue>' tests` : un test qui fige la valeur du bug sans le nom Currently est à mettre à jour dans le lot. »

### F8. Commandes PHP en worktree : trois pièges récurrents (#310 #250 #180 #368)
Les trois pièges : `unit` sans `--testsuite=Unit` lance les suites Functional et Integration sans base (793 erreurs trompeuses) ; PHPStan demande d'abord `cache:warmup --env=dev` ; `--filter` trop large capte les tests Functional.
Diff : `wt-php.sh`. En mode `unit` avec `bin/phpunit`, ajouter `--testsuite=Unit` si aucun `--testsuite` n'est donné. Ajouter un mode `phpstan` qui fait le warmup puis l'analyse.

### F9. Les worktrees ne sont pas nettoyés (observation de Sylvain à 12:02)
Constat : 24 worktrees ouverts. 28 branches `fix/night-*`, dont 21 déjà fusionnées dans `origin/main`.
Diff : `night-orchestrator.md` étape 6 (fin de boucle). Pour chaque branche `fix/night-*` fusionnée dans `origin/main` dont le thread est terminé : `git worktree remove` puis `git branch -d` (jamais `-D`).

### F10. `/skill-retro` n'a jamais été lancée par les threads (absente du worktree, puis « budget », 6 threads)
Diff : `loop-issue-thread-prompt.md`. Retirer `/skill-retro` du thread. Le thread garde sa ligne « rétro » dans le journal, et la rétro est faite une fois par boucle par la session principale, comme ici.

### F11. Le brief du thread dit encore « fusion et push sur main »
Le thread demandait une autorisation à chaque push, ou n'avait pas de clé SSH (#250 #249). Décision du 2026-11-04 déjà prise (mémoire `night-loop-use-prs`) mais pas encore appliquée.
Diff : `loop-issue-thread-prompt.md` ligne 14 et `night-orchestrator.md` étape 4. Push de `fix/night-N`, PR avec `Closes #N`, `link_pull_request`, pas de fermeture d'issue. Vérifier la clé SSH en écriture avant de lancer la boucle.

### Écartées
- Labels `circuit:s` ou `circuit:m` trompeurs (#307 #317 #271) : c'est une affaire de tri, pas de skill. À revoir au moment de créer l'issue.
- `loginUser` sur le même client (#273), `ClipboardItem` défini par happy-dom (#316), vitest instable sous charge : un seul cas chacun, notés dans `lessons.md`.
- `git add -A` en phase rouge : conseillé en #180, cause d'un incident en #368. Les deux constats se contredisent, rien à changer avant un deuxième cas.
