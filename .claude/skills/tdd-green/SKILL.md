---
name: tdd-green
description: Phase verte du TDD — implémente jusqu'au vert sans jamais modifier les tests (verrouillés par le hook tdd-guard), puis refactor et review.
argument-hint: <numéro d'issue>
---

Issue : $ARGUMENTS

Précondition : `.claude/state/tdd-phase` contient `green`. Sinon, arrête-toi : `/tdd-red` n'a pas été terminé ou validé.

1. Délègue à l'agent `implementer` (jamais `backend-developer` dans cette phase). Les fichiers de `tests/` sont verrouillés par le hook `tdd-guard`, quel que soit l'agent.
2. Boucle jusqu'au vert sur les gates ciblés de l'implementer (tests du module touché, PHPStan sur les fichiers touchés, lint front si le front est touché). La batterie complète tourne une seule fois, dans `/review` après rebase.
2b. **Migration créée ou modifiée** (`migrations/`) : l'implementer ne peut pas la valider et ne calcule pas les noms d'index à la main. Avant le commit vert, l'orchestrateur la valide sur une base isolée : `export TEST_TOKEN=<issue>; r(){ .claude/scripts/wt-php.sh <worktree> integration bin/console "$@" --env=test; }; r doctrine:database:create; r doctrine:migrations:migrate -n; r doctrine:schema:validate`, puis `.claude/scripts/wt-php.sh <worktree> integration vendor/bin/phpunit --no-coverage --testsuite Integration`, puis `r doctrine:database:drop --force`. Pas de fusion avec un `schema:validate` en échec ou non lancé ; le résultat va au journal.
3. Si un test paraît faux : **arrête-toi et explique pourquoi**. Ne contourne pas le verrou, et ne supprime pas le fichier de phase pour débloquer.
   Même règle si une dépendance manque au harnais : arrête-toi et renvoie le lot à `/tdd-red` (étape 1b) avec la liste des dépendances manquantes.
3b. **Défaut hors comportement dans un test verrouillé** (liste exhaustive oubliée, erreur de lint, conflit de rebase dans un test) : ne lève pas le verrou en supprimant le fichier de phase. Écris `red` dans `.claude/state/tdd-phase` (retour documenté en rouge), dans une commande à part : tant que la phase vaut `green`, le hook bloque toute commande Bash qui cite `tests/`. Corrige uniquement ce défaut sans changer d'assertion métier, note-le dans le journal (fichier, raison, « aucune assertion affaiblie »), recalcule l'empreinte, puis réécris `green`. Un test faux sur le comportement reste un arrêt (étape 3).
4. Budget : au-delà de 8 itérations sans progrès, arrête-toi et présente l'état.
5. Phase refactor : nettoie le code, avec les tests verts comme garde-fou.
6. Supprime `.claude/state/tdd-phase`, rebase la branche sur `main`, puis lance `/review`.
