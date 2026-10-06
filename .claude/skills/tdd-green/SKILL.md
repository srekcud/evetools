---
name: tdd-green
description: Phase verte du TDD — implémente jusqu'au vert sans jamais modifier les tests (verrouillés par le hook tdd-guard), puis refactor et review.
disable-model-invocation: true
argument-hint: <numéro d'issue>
---

Issue : $ARGUMENTS

Précondition : `.claude/state/tdd-phase` contient `green`. Sinon, arrête-toi : `/tdd-red` n'a pas été terminé ou validé.

1. Délègue à l'agent `implementer` (jamais `backend-developer` dans cette phase). Les fichiers de `tests/` sont verrouillés par le hook `tdd-guard`, quel que soit l'agent.
2. Boucle jusqu'au vert. Gates obligatoires :
   - PHPUnit, sur la suite complète ;
   - PHPStan ;
   - deptrac ;
   - Infection sur le périmètre touché : le MSI ne doit pas baisser par rapport à `docs/quality/baseline.md` ni au seuil d'`infection.json5`.
3. Si un test paraît faux : **arrête-toi et explique pourquoi**. Ne contourne pas le verrou, et ne supprime pas le fichier de phase pour débloquer.
4. Budget : au-delà de 8 itérations sans progrès, arrête-toi et présente l'état.
5. Phase refactor : nettoie le code, avec les tests verts comme garde-fou.
6. Supprime `.claude/state/tdd-phase`, puis lance `/review`.
