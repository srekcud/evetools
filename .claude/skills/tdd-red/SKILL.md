---
name: tdd-red
description: Phase rouge du TDD — écrit uniquement les tests à partir d'une spec validée, et prouve qu'ils échouent pour la bonne raison.
disable-model-invocation: true
argument-hint: <numéro d'issue>
---

Issue : $ARGUMENTS

Entrée : une spec validée dans `docs/specs/$ARGUMENTS-*.md`. Sans spec validée, arrête-toi et propose `/spec $ARGUMENTS`.

1. Lis la spec et `docs/glossary.md`. N'utilise que les termes du glossaire.
2. Délègue à l'agent `test-writer` l'écriture des tests :
   - Domain et Application en priorité, sans base de données ;
   - un test par exemple chiffré de la spec, plus les cas limites.
3. Lance les tests : `docker compose exec app php vendor/bin/phpunit --no-coverage --filter <...>`.
4. **Prouve que chaque test échoue pour la bonne raison** : une assertion métier, pas une erreur de syntaxe ni une classe manquante imprévue. Montre la sortie. Si une classe ou une méthode n'existe pas encore, crée seulement le squelette minimal nécessaire pour que le test échoue sur l'assertion. Signale-le.
5. Vérifie que les autres tests du dépôt sont toujours verts.
6. Écris `green` dans `.claude/state/tdd-phase` (crée le dossier si besoin). À partir de là, le hook `tdd-guard` bloque toute modification de `tests/`.
7. 🚦 Présente les tests à Sylvain. Ils sont la spec exécutable : rien ne passe à `/tdd-green` sans son accord.
