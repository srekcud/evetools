---
name: tdd-green
description: Phase verte du TDD — implémente jusqu'au vert sans jamais modifier les tests (verrouillés par le hook tdd-guard), puis refactor et review.
disable-model-invocation: true
argument-hint: <numéro d'issue>
---

Issue : $ARGUMENTS

Précondition : `.claude/state/tdd-phase` contient `green`. Sinon, arrête-toi : `/tdd-red` n'a pas été terminé ou validé.

1. Délègue à l'agent `implementer` (jamais `backend-developer` dans cette phase). Les fichiers de `tests/` sont verrouillés par le hook `tdd-guard`, quel que soit l'agent.
2. Boucle jusqu'au vert sur les gates ciblés de l'implementer (tests du module touché, PHPStan sur les fichiers touchés). La batterie complète tourne une seule fois, dans `/review` après rebase.
3. Si un test paraît faux : **arrête-toi et explique pourquoi**. Ne contourne pas le verrou, et ne supprime pas le fichier de phase pour débloquer.
   Même règle si une dépendance manque au harnais : arrête-toi et renvoie le lot à `/tdd-red` (étape 1b) avec la liste des dépendances manquantes.
4. Budget : au-delà de 8 itérations sans progrès, arrête-toi et présente l'état.
5. Phase refactor : nettoie le code, avec les tests verts comme garde-fou.
6. Supprime `.claude/state/tdd-phase`, rebase la branche sur `main`, puis lance `/review`.
