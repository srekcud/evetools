---
name: spec
description: Transforme une issue en spec validée (docs/specs/<n>-<slug>.md) par une série de questions courtes, avant toute phase TDD.
disable-model-invocation: true
argument-hint: <numéro d'issue>
---

Issue : $ARGUMENTS

1. Lis l'issue (`gh issue view $ARGUMENTS`), `docs/glossary.md`, les ADR pertinentes et le code concerné. Vérifie tout ce qui est vérifiable avant de poser une question.
2. **Interroge Sylvain** par petits groupes de questions (2 ou 3 à la fois), en adaptant les suivantes aux réponses. Couvre :
   - le comportement attendu, avec des exemples chiffrés (entrée → sortie attendue) ;
   - les cas limites (produit à plusieurs unités par run, arrondis, données ESI manquantes, réactions…) ;
   - ce qui est explicitement hors périmètre ;
   - les termes du glossaire : s'il en manque un, propose une définition.
3. Rédige `docs/specs/$ARGUMENTS-<slug>.md` :
   - contexte (anonymisé) ;
   - règles métier ;
   - exemples chiffrés sous forme de tableau (ce seront les tests rouges) ;
   - cas limites ;
   - hors périmètre ;
   - termes du glossaire ajoutés ou modifiés.
4. Mets à jour `docs/glossary.md` si des termes ont été validés.
5. 🚦 Présente la spec. Elle n'est validée qu'avec l'accord explicite de Sylvain. Ce n'est qu'ensuite que `/tdd-red` peut démarrer.

N'écris ni test ni code.
