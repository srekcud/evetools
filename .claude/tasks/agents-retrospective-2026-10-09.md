# Rétrospective de l'outillage agentique — 2026-10-09

Périmètre : les agents (`.claude/agents/`), les skills (`.claude/skills/`), `settings.json` et `tdd-guard`, `lessons.md`, `CLAUDE.md`, ADR-0004 et ADR-0005.
Source : la caractérisation des 7 services avant la migration vers le noyau Industry, et les phases rouges et vertes de la journée.
Ce document **propose** des changements, il n'en applique aucun. Aucune proposition n'assouplit les permissions ni le verrou `tdd-guard`.

## Synthèse : les 5 propositions les plus utiles

1. **`/characterize` : les exigences a-e dans le premier prompt, et pas de fusion sans verdict `test-skeptic` « suffisant »** (§1). C'est ce qui aurait évité un aller-retour par service, sept fois de suite.
2. **`test-skeptic` : une grille fixe a-e, avec un verdict binaire** (§3). Le writer et le skeptic travaillent alors sur la même grille.
3. **`/tdd-red` : faire passer l'architecte (ou un contrôle de câblage) avant de verrouiller `tests/`** (§4). Une phase rouge ne doit pas se verrouiller sur un harnais que la phase verte ne pourra pas satisfaire.
4. **Un script de worktree versionné, `.claude/scripts/wt-php.sh`, avec un mode `integration`** (§6). L'agent ne voit jamais d'identifiant, et `.env` n'est plus copié dans le worktree.
5. **`#[Group('issue-N')]` à la place des commentaires `// CARACTÉRISATION`** (§2, §8). Ça règle le conflit avec la règle « pas de commentaire », et ça permet de lancer la migration avec `--exclude-group issue-2`.

Ensuite : la langue des rapports (§7), la file des phases rouges en attente (§5), et la mise à jour de `lessons.md` (§9).

---

## 1. Skill `characterize`

**Ce qui a marché**
- Le principe « figer même si c'est faux » a été respecté : aucun test n'a corrigé un bug en douce.
- Les relectures `test-skeptic` ont un vrai effet. Le MSI de caractérisation a progressé à chaque fois : ProfitMargin 71 → 89 %, BOM Group Industry 71 → 88 %, IndustryProjectFactory 72 → 79 %, BuyVsBuild 81 → 86 %, provider de la liste d'achats 70 → 96 %.
- Le format de nom `…Currently…IssueN` s'est imposé (`ProjectProviderGoldenTreeCharacterizationTest`) et se lit bien.
- Le correctif « snapshot manquant = échec » (`ProjectProviderGoldenTreeCharacterizationTest.php:454-458`, `IndustryProjectFactoryGoldenStepsCharacterizationTest.php:780-784`) est le bon modèle. Il faut l'inscrire dans la skill pour ne plus le redécouvrir.

**Les écarts**
- Les cinq défauts (a-e) sont revenus sur les 7 services. La skill ne les nomme pas. Le writer les découvre donc après coup, par la relecture.
- L'étape 6 (relecture) vient **après** l'étape 4 et ne bloque rien. Le main a fusionné 4 caractérisations sans elle.
- L'étape 3 impose un commentaire, ce qui contredit la règle globale « pas de commentaire » (§8).
- L'étape 4 ne fait pas échouer un test `incomplete` : le code de sortie 0 donne un faux vert.
- Les tests d'intégration ne sont pas mentionnés, alors qu'un échec (id hors int32) n'est apparu qu'au lancement par le main.

**Diff proposé** : `.claude/skills/characterize/SKILL.md`

```diff
 3. Délègue l'écriture à l'agent `test-writer`, dans `tests/` uniquement :
    - mocks seulement aux frontières (ESI, base, cache, horloge) ;
-   - si un comportement est suspecté faux, le test le fige quand même. Son nom et un commentaire le signalent : `// CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #...`.
-4. Lance les tests : `docker compose exec app php vendor/bin/phpunit --no-coverage --filter <Classe>`. Ils doivent tous passer sur le code actuel.
+   - si un comportement est suspecté faux, le test le fige quand même. Il est signalé par son nom (`…Currently…IssueN`) et par `#[Group('issue-N')]`, sans commentaire ;
+   - **copie mot pour mot dans le prompt les exigences a-e ci-dessous**. Le writer les vérifie lui-même avant de rendre la main, et son rapport dit, pour chacune, comment elle est tenue :
+     a. **Un bug connu, un test.** Un bug connu (B1/#2 runs lus comme quantité, etc.) n'apparaît que dans les attendus des tests qui le visent (`…IssueN`, groupe `issue-N`). Les autres tests choisissent des entrées où ce bug ne change pas le résultat (par exemple 1 run, ou un seul niveau), pour qu'ils ne cassent pas à la migration pour une raison qui n'est pas la leur ;
+     b. **Des valeurs distinctes.** Un prix, une quantité et un bonus différents par typeId, sans multiples simples entre eux. Jamais « toutes les feuilles à 100 ISK » ni deux types de même prix et même quantité : une valeur attachée au mauvais type doit changer un attendu ;
+     c. **Toutes les sorties clés assertées**, en valeurs exactes : chaque champ que lit un appelant (cf. le `grep` de l'étape 1). Pas d'affirmation dans un nom de test ou un rapport que ne prouve pas une assertion ;
+     d. **Un snapshot manquant fait échouer le test.** Il ne s'écrit qu'avec `UPDATE_SNAPSHOTS=1` (modèle : `ProjectProviderGoldenTreeCharacterizationTest`). Jamais de `markTestIncomplete` ni de `markTestSkipped` sur une donnée absente ;
+     e. **Tout comportement figé et suspecté faux** porte `Currently` dans son nom, un numéro d'issue et le groupe `issue-N`. Sans issue, le writer le liste en proposition d'issue.
+4. Lance les tests : `docker compose exec app php vendor/bin/phpunit --no-coverage --fail-on-incomplete --fail-on-skipped --filter <Classe>`. Ils doivent tous passer sur le code actuel. Les tests d'intégration, lancés dans un worktree, passent par `.claude/scripts/wt-php.sh <worktree> integration …` (cf. `lessons.md`), jamais par le seul mode unitaire.
 5. Mesure la couverture du service : `docker compose exec app php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text --filter <Classe>`.
-6. Fais relire les tests par `test-skeptic` : quels tests passeraient même si le code était faux ?
+6. **Relecture obligatoire avant fusion** : `test-skeptic`, avec la grille a-e et son mandat habituel. Son verdict est `suffisant` ou `insuffisant`. Si c'est `insuffisant`, renvoie ses points au même `test-writer` (SendMessage, contexte conservé), puis relance la relecture. **Aucune fusion sans un verdict `suffisant`**, noté dans `.claude/tasks/todo.md` avec le MSI de caractérisation.
 7. Présente :
    - les tests ajoutés ;
    - la couverture avant et après ;
+   - le verdict `test-skeptic` et le MSI de caractérisation ;
    - la liste des comportements suspectés faux, en propositions d'issues.
```

---

## 2. Agent `test-writer`

**Ce qui a marché** : il est resté dans `tests/`. Les noms suivent le glossaire, et les renforcements demandés par le skeptic ont été appliqués sans affaiblir les attendus.

**Les écarts**
- La règle des commentaires (ligne 14) contredit la règle globale. Résultat : 102 marqueurs `CARACTÉRISATION` dans `tests/`.
- Le writer ne connaît pas les exigences a-e. Il livre donc « vert » ce que le skeptic jugera insuffisant.
- La ligne 16 ne lui donne que `docker compose exec`, qui ne voit pas un worktree. Il a improvisé avec `wtrun.sh` et une base factice, et n'a pas pu lancer les tests d'intégration.
- La langue de ses rapports varie.

**Diff proposé** : `.claude/agents/test-writer.md`

```diff
 - Noms de tests et de variables tirés de `docs/glossary.md`. Distingue toujours `runs` de `quantity`.
 - Suis les conventions des tests existants : `tests/Unit/`, `tests/Integration/`, `tests/Functional/`, namespace `App\Tests\...`.
-- En caractérisation : fige le comportement actuel **même s'il est faux**. Signale-le dans le nom du test et par un commentaire `// CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #...`.
+- Aucun commentaire dans le code de test, docblocks compris. Le pourquoi va dans ton rapport, qui servira au message de commit.
+- En caractérisation : fige le comportement actuel **même s'il est faux**. Signale-le par le nom (`…Currently…IssueN`) et par `#[Group('issue-N')]`. Applique les exigences a-e de la skill `/characterize` et rends compte de chacune.
 - Utilise des assertions exactes (valeurs chiffrées) plutôt que des assertions de forme.
-- Lance les tests que tu écris : `docker compose exec app php vendor/bin/phpunit --no-coverage --filter <...>`. Montre la sortie.
+- Des données de test distinctes par type : prix, quantités et bonus différents d'un typeId à l'autre.
+- Lance les tests que tu écris et montre la sortie :
+  - dans le dépôt principal : `docker compose exec app php vendor/bin/phpunit --no-coverage --fail-on-incomplete --filter <...>` ;
+  - dans un worktree : `.claude/scripts/wt-php.sh <worktree> unit vendor/bin/phpunit …`, et `… integration …` pour `tests/Integration/`. Si le mode `integration` refuse de démarrer, arrête-toi et signale-le. Ne cherche pas d'identifiants par un autre chemin.
+- Rapport en français ; noms de tests, identifiants et messages de commit proposés en anglais.
```

---

## 3. Agent `test-skeptic`

**Ce qui a marché** : il a trouvé les vrais trous, 7 fois sur 7 : B1 mêlé aux attendus, prix uniformes, snapshot qui s'écrit seul. Sa sortie (`fichier:ligne`, puis le test manquant) est directement exploitable par le writer.

**Les écarts**
- Il n'y a pas de grille commune avec le writer : chaque relecture redécouvre a-e.
- Il n'y a pas de verdict explicite. Le main doit interpréter la liste, et il a fusionné sans relecture faute d'une gate nette.
- Les points d et e ne figurent pas dans ses cibles : le snapshot auto-écrit, `incomplete`, et le bug figé sans marqueur.

**Diff proposé** : `.claude/agents/test-skeptic.md`

```diff
 - les mutants survivants d'Infection : pour chacun, dis si c'est un vrai trou de test ou un mutant équivalent (avec justification), et quel test le tuerait.
+
+En caractérisation, vérifie point par point la grille a-e de `/characterize` :
+- a. un bug connu (ex. B1/#2) présent dans les attendus d'un test qui ne le vise pas ;
+- b. des prix, quantités ou bonus identiques entre types, qui masqueraient une valeur attachée au mauvais type ;
+- c. une sortie lue par un appelant mais non assertée, ou une affirmation (nom, rapport) qu'aucune assertion ne prouve ;
+- d. un snapshot qui s'écrit sans `UPDATE_SNAPSHOTS=1`, ou `markTestIncomplete` / `markTestSkipped` sur une donnée absente (`grep` les deux) ;
+- e. un comportement figé et suspecté faux sans `Currently`, sans numéro d'issue ou sans `#[Group('issue-N')]`.
 
-Sortie : une liste priorisée. Chaque point donne `fichier:ligne`, le problème et le test manquant proposé (nom et assertion clé).
+Sortie, en français :
+1. **Verdict** : `suffisant` ou `insuffisant`. `insuffisant` dès qu'un point a, b, d ou e est trouvé, ou qu'une sortie clé (c) n'est pas assertée ;
+2. la grille a-e, avec ok ou ko et la preuve ;
+3. une liste priorisée. Chaque point donne `fichier:ligne`, le problème et le test manquant proposé (nom et assertion clé).
```

---

## 4. Skill `tdd-red` (et l'agent `architect`)

**Ce qui a marché** : le verrou a tenu. La phase rouge ProfitMargin (13 tests) a été bloquée proprement au lieu d'être réécrite en phase verte. ADR-0004 a fonctionné comme prévu.

**L'écart** : la phase rouge a été écrite contre un harnais (fixtures, mocks, constructeurs) qui ne fournissait pas ce dont la phase verte aurait besoin. Il manquait les rigs, `IndustryStructureConfig` vers `ProductionStructure` et les décrypteurs, et 4 tests hors `Currently` allaient casser. Une fois `tests/` verrouillé, rien ne pouvait plus le corriger. L'architecte est intervenu **après** coup (`.claude/tasks/industry-core/profit-margin-wiring.md`). Le problème ne vient pas du verrou : il vient de la place du contrôle.

**Diff proposé** : `.claude/skills/tdd-red/SKILL.md`

```diff
 1. Lis la spec et `docs/glossary.md`. N'utilise que les termes du glossaire.
+1b. **Câblage avant les tests**, obligatoire quand la spec touche un service existant (migration vers le noyau, nouvelle dépendance) : délègue à `architect` une note `.claude/tasks/<lot>-wiring.md`. Elle liste, pour l'implémentation cible, chaque dépendance (service, VO, config, données SDE ou ESI), la façon dont le harnais de test la fournit (fixture, mock aux frontières, golden), et les tests existants qui casseront à cause d'un changement de comportement voulu. Le `test-writer` reçoit cette note avec la spec.
 2. Délègue à l'agent `test-writer` l'écriture des tests :
    - Domain et Application en priorité, sans base de données ;
    - un test par exemple chiffré de la spec, plus les cas limites.
 3. Lance les tests : `docker compose exec app php vendor/bin/phpunit --no-coverage --filter <...>`.
 4. **Prouve que chaque test échoue pour la bonne raison** : une assertion métier, pas une erreur de syntaxe ni une classe manquante imprévue. Montre la sortie. Si une classe ou une méthode n'existe pas encore, crée seulement le squelette minimal nécessaire pour que le test échoue sur l'assertion. Signale-le.
 5. Vérifie que les autres tests du dépôt sont toujours verts.
+5b. **Contrôle avant verrouillage** : chaque dépendance de la note de câblage a sa place dans le harnais, et chaque test existant appelé à casser est listé avec sa raison. S'il manque une dépendance, complète le harnais maintenant ou arrête-toi. N'écris pas `green`.
 6. Écris `green` dans `.claude/state/tdd-phase` (crée le dossier si besoin). À partir de là, le hook `tdd-guard` bloque toute modification de `tests/`.
-7. 🚦 Présente les tests à Sylvain. Ils sont la spec exécutable : rien ne passe à `/tdd-green` sans son accord.
+7. 🚦 Ajoute le lot à `.claude/tasks/red-queue.md` (cf. §5), puis présente les tests à Sylvain. Ils sont la spec exécutable : rien ne passe à `/tdd-green` sans son accord.
```

Note : un correctif de bug sur un seul service n'a pas besoin de l'étape 1b. Elle sert aux migrations et aux nouvelles dépendances. C'est la condition « quand la spec touche un service existant » qui fait le tri.

**Diff proposé** : `.claude/agents/architect.md`. Il ajoute un mode dédié, pour que l'architecte ne produise pas de maquette ni un plan de module complet dans ce cas.

```diff
 ## Format de sortie
 
+En mode câblage (appelé par `/tdd-red`, étape 1b), ne produis que la note `.claude/tasks/<lot>-wiring.md` :
+- les dépendances de l'implémentation cible ;
+- pour chacune, comment le harnais de test la fournit ;
+- les tests existants appelés à casser, avec leur raison ;
+- les questions de spec ouvertes.
+Pas de maquette, pas de plan d'implémentation.
+
 Structure tes réponses avec :
```

**Skill `tdd-green`** : on ne change pas l'étape 3, l'arrêt quand un test paraît faux est le bon comportement. On ajoute seulement le cas observé :

```diff
 3. Si un test paraît faux : **arrête-toi et explique pourquoi**. Ne contourne pas le verrou, et ne supprime pas le fichier de phase pour débloquer.
+   Même règle si une dépendance manque au harnais : arrête-toi et renvoie le lot à `/tdd-red` (étape 1b) avec la liste des dépendances manquantes.
```

---

## 5. File des phases rouges en attente (gate de `/tdd-red`)

**Constat** : 5 lots rouges attendaient la validation de Sylvain en même temps. La règle « 3 agents actifs » (`lessons.md`) pousse à ouvrir de nouveaux rouges plutôt que d'attendre. Les worktrees verrouillés s'accumulent alors, chacun avec son contexte à relire.

**Proposition** : un fichier unique, une gate par lot, et un plafond.

- `.claude/tasks/red-queue.md`, une ligne par lot : branche et worktree, issue, nombre de tests, empreinte des tests (la garde de `lessons.md`), raison d'échec en une ligne, tests existants appelés à casser, questions de spec. Sylvain valide en une passe, ligne par ligne.
- **Plafond de 3 lots rouges en attente.** Au-delà, les agents libres passent à du travail sans gate humaine : caractérisation, `/mutation` sur un périmètre déjà validé, audits en lecture seule. La règle des 3 agents actifs reste respectée.

**Diff proposé** : `.claude/tasks/lessons.md`

```diff
 - **Toujours 3 agents actifs.** (2026-10-07) Sylvain a vu deux fois zéro subagent en cours : je vérifiais, commitais et ouvrais des PR en séquence avant de relancer. Règle : dès qu'un agent rend la main, relancer le suivant AVANT de faire les vérifications/commits du lot terminé (les vérifs sont rapides, les agents sont longs).
+- **Au plus 3 phases rouges en attente de validation.** (2026-10-09) 5 lots attendaient en même temps. Au-delà de 3 dans `.claude/tasks/red-queue.md`, les agents libres prennent du travail sans gate (caractérisation, mutation, audit).
```

---

## 6. Script de worktree versionné

**Constat**
- `wtrun.sh` vit dans un scratchpad de session (`/tmp/claude-1000/…/438d1897…/scratchpad/wtrun.sh`). Il est perdu au prochain nettoyage de `/tmp`, et une nouvelle session ne le trouve pas.
- Il **copie le `.env` du dépôt principal dans le worktree** (ligne 7). Ce fichier n'est pas versionné, il contient des secrets, et sa copie devient lisible par l'agent dans le worktree.
- Il n'a pas de réseau ni de base. Les tests d'intégration ne peuvent donc tourner que dans le main, et l'échec int32 n'est apparu qu'à ce moment-là.

**Emplacement** : `.claude/scripts/wt-php.sh`, versionné. C'est de l'outillage d'agent, pas un outil de l'application : `bin/` contient déjà `console` et `phpunit`, et le script n'y a pas sa place.

**Contenu proposé** (nouveau fichier `.claude/scripts/wt-php.sh`, exécutable)

```diff
+#!/usr/bin/env bash
+set -euo pipefail
+
+readonly main_repo="$(cd "$(dirname "$0")/../.." && pwd)"
+readonly compose_network="evetools_evetools_net"
+readonly dev_image="evetools-app-dev"
+
+usage() {
+  echo "usage: $0 <worktree> unit|integration <php args...>" >&2
+  exit 64
+}
+
+[[ $# -ge 3 ]] || usage
+worktree="$(cd "$1" && pwd)"
+mode="$2"
+shift 2
+
+git -C "$main_repo" worktree list --porcelain | grep -qx "worktree $worktree" \
+  || { echo "$worktree n'est pas un worktree de $main_repo" >&2; exit 65; }
+
+[[ -e "$worktree/.env" ]] || : > "$worktree/.env"
+
+run_args=(
+  --rm --user "$(id -u):$(id -g)"
+  -e HOME=/tmp -e APP_ENV=test
+  -e DEFAULT_URI=https://evetools.local
+  -e ESI_BASE_URL=https://esi.evetech.net/latest
+  -e CORS_ALLOW_ORIGIN=x
+  -e JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem
+  -e JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem
+  -e MESSENGER_TRANSPORT_DSN=in-memory://
+  -e LOCK_DSN=flock
+  -v "$worktree":/app -v "$main_repo/vendor":/app/vendor:ro -w /app
+  --entrypoint php
+)
+
+case "$mode" in
+  unit)
+    run_args+=(
+      -e DATABASE_URL='postgresql://unit:unit@127.0.0.1:1/unit?serverVersion=16'
+      -e REDIS_URL=redis://127.0.0.1:1
+    )
+    ;;
+  integration)
+    if ! git -C "$worktree" diff --quiet "$(git -C "$main_repo" rev-parse main)" -- migrations/; then
+      echo "Le worktree modifie migrations/ : la base eve_app_test partagée ne correspond pas. Lancement par la session principale." >&2
+      exit 66
+    fi
+    DATABASE_URL="$(docker compose --project-directory "$main_repo" exec -T app printenv DATABASE_URL)"
+    export DATABASE_URL
+    run_args+=(--network "$compose_network" -e DATABASE_URL -e REDIS_URL=redis://redis:6379)
+    ;;
+  *) usage ;;
+esac
+
+exec docker run "${run_args[@]}" "$dev_image" "$@"
```

Choix et garde-fous :
- **Aucun secret côté agent.** En mode `integration`, l'URL de la base est lue par le script dans le conteneur `app`, comme le fait `make test-integration`. Elle est passée par `-e DATABASE_URL` sans valeur, donc absente de la ligne de commande et de `ps`, et elle n'est jamais affichée. L'agent ne lit ni `.env` ni l'environnement du conteneur. Le refus de permission vu le 2026-10-07 (`lessons.md`) reste la règle pour lui.
- **`.env` vide au lieu d'une copie.** `tests/bootstrap.php` exige le fichier, mais toutes les valeurs utiles sont passées en `-e`. `.env.test` (versionné) complète le reste, et les variables réelles l'emportent.
- **La base `eve_app_test` est partagée.** dama isole par transaction. En revanche, une branche qui modifie `migrations/` ferait diverger le schéma : le script refuse (code 66) et renvoie à la session principale.
- **Aucune règle de permission ajoutée.** L'appel au script reste soumis aux permissions actuelles. Si Sylvain veut l'ajouter à `allow`, c'est sa décision : ce document ne le propose pas.
- **Interaction avec `tdd-guard`** : le hook n'exempte que `docker compose exec app php vendor/bin/(phpunit|infection)`. En phase verte, un appel au script avec un chemin `tests/…` est bloqué. Il faut passer par `--filter` ou `--testsuite`. C'est voulu : on ne propose pas d'exemption.
- **À vérifier avant adoption** : le nom de réseau `evetools_evetools_net` (constaté via `docker network ls`) dépend du nom de projet compose. Il faut aussi lancer une fois les deux modes sur un worktree existant (`evetools-wt-CS`, par exemple), sur un test unitaire puis sur `tests/Integration/Industry`.

---

## 7. Langue des rapports

**Constat** : les rapports d'agents arrivent tantôt en français, tantôt en anglais. Les prompts du main et le code sont en anglais, alors que les agents et les skills sont rédigés en français, et rien ne tranche.

**Proposition** : une règle unique dans `CLAUDE.md`, que tous les sous-agents reçoivent, plutôt qu'une ligne dans chacun des 14 agents. Le §2 et le §3 la répètent pour `test-writer` et `test-skeptic`, les deux plus sollicités.

**Diff proposé** : `CLAUDE.md`

```diff
 ### Règles
 
 - **Un symbole métier porte un nom du glossaire.** Si le terme manque, proposer de l'ajouter au glossaire plutôt qu'inventer un synonyme.
 - **Pas de `|| true`, pas de `?? 0` ni de catch silencieux sur une donnée métier.** Une donnée ESI manquante doit remonter une erreur explicite ou un état « inconnu », jamais une valeur par défaut plausible.
+- **Langue** : rapports d'agents, documents de `.claude/tasks/` et échanges avec Sylvain en français. Code, noms de tests, identifiants, messages de commit et descriptions de PR en anglais.
+- **Pas de commentaire dans le code, tests compris.** Le pourquoi va dans le commit ou la PR. Un test de caractérisation qui fige un bug porte `Currently…IssueN` dans son nom et `#[Group('issue-N')]`.
```

---

## 8. Conflit « commentaires CARACTÉRISATION » contre « pas de commentaire »

**Question** : le nom `…Currently…IssueN` suffit-il ?

**Réponse proposée** : oui pour la lecture, mais il faut un outil en plus pour la migration. Le nom dit déjà tout ce que disait le commentaire : comportement actuel, suspecté faux, numéro d'issue. Le commentaire n'apporte rien et contredit la règle globale. En revanche, au moment de migrer, on a besoin de **sélectionner** ces tests, et un nom de test se filtre mal. D'où l'attribut `#[Group('issue-2')]`, qui existe déjà dans le dépôt (`tests/Integration/Migrations/MigrationsReplayTest.php:29`, `#[Group('slow')]`, PHPUnit 12.5) :
- `--group issue-2` liste tout ce qui doit changer quand B1 sera corrigé ;
- `--exclude-group issue-2` donne le filet de non-régression pendant la migration (exigence a de §1).

Ce n'est pas un commentaire : c'est une métadonnée exécutable.

**Existant** : 102 occurrences de `CARACTÉRISATION` / `CHARACTERIZATION` dans `tests/`, plus des docblocks d'explication (snapshots). Deux options pour Sylvain :
1. un lot mécanique dédié : retirer les commentaires et ajouter `#[Group('issue-N')]`, avec une relecture `test-skeptic` limitée au point e ;
2. la règle « boy scout » : convertir un fichier quand on y touche.

L'option 1 est préférable avant la migration, puisque la migration s'appuiera sur les groupes.

---

## 9. `lessons.md`

**Diff proposé** : `.claude/tasks/lessons.md`. La leçon worktree pointe vers un fichier éphémère, et une leçon manque pour la fusion sans relecture.

```diff
-- **Worktrees + Docker** : `docker compose exec` ne voit pas un worktree. Utiliser `scratchpad/wtrun.sh` (`docker run --user $(id -u)` + vendor du main en lecture seule + variables d'env factices pour `cache:warmup`). Sans `--user`, le conteneur crée des fichiers root impossibles à supprimer.
+- **Worktrees + Docker** : `docker compose exec` ne voit pas un worktree. Utiliser `.claude/scripts/wt-php.sh <worktree> unit|integration …` (versionné ; `--user` pour éviter les fichiers root, vendor du main en lecture seule, `.env` vide, URL de base lue par le script en mode `integration`, jamais par l'agent).
```

```diff
 - Les caractérisations ont été fusionnées sans la relecture `test-skeptic` prévue par la skill. Celle de ProfitMargin a révélé : B1 (#2) dans les attendus de presque tous les tests (ils casseront tous à la migration, pour une raison qui n'est pas la leur) et des prix uniformes (100 ISK partout) qui masquent un prix attaché au mauvais type. Règle : dans le prompt de caractérisation, exiger des prix distincts par typeId et des attendus indépendants de B1 hors des tests B1 ; relecture sceptique avant fusion.
+- Les 7 relectures ont toutes conclu « insuffisant » pour les mêmes 5 raisons (grille a-e de `/characterize`). Règle : la grille entre dans le premier prompt du writer, et la fusion attend le verdict `suffisant` noté dans `todo.md`.
+- Une phase rouge (ProfitMargin) a été verrouillée sur un harnais sans les dépendances de la phase verte. Règle : note de câblage `architect` avant les tests, et contrôle avant d'écrire `green` (`/tdd-red` 1b et 5b).
```

---

## 10. Sans changement proposé

- **`tdd-guard` et `settings.json`** : le verrou a tenu, y compris contre Bash. Aucun assouplissement n'est proposé. Le seul lien avec ce document : la commande `phpunit … --fail-on-incomplete --fail-on-skipped` reste autorisée par l'expression de la ligne 25 du hook, puisqu'elle ne contient aucun métacaractère.
- **`/mutation`**, **`/audit`**, **`/triage`**, **`auditor`**, **`triager`** : aucun écart observé dans cette session. Seule retouche possible : la sortie en français, déjà couverte par la règle `CLAUDE.md` du §7.
- **ADR-0004 et ADR-0005** : inchangés. Le §4 ajoute un contrôle *avant* le verrou, sans rien changer au verrou lui-même. Si Sylvain adopte l'étape 1b, une ligne « Conséquences » dans ADR-0004 peut la mentionner (via `/adr`).
