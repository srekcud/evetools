---
name: tdd-red
description: Phase rouge du TDD — écrit uniquement les tests à partir d'une spec validée, et prouve qu'ils échouent pour la bonne raison.
disable-model-invocation: true
argument-hint: <numéro d'issue>
---

Issue : $ARGUMENTS

Entrée : une spec validée dans `docs/specs/$ARGUMENTS-*.md`. Sans spec validée, arrête-toi et propose `/spec $ARGUMENTS`.

1. Lis la spec et `docs/glossary.md`. N'utilise que les termes du glossaire.
1b. **Câblage avant les tests**, obligatoire en `circuit:l` (migration vers le noyau, nouvelle dépendance), à sauter en `circuit:m` : délègue à `architect` une note `.claude/tasks/<lot>-wiring.md`. Elle liste, pour l'implémentation cible, chaque dépendance (service, VO, config, données SDE ou ESI), la façon dont le harnais de test la fournit (fixture, mock aux frontières, golden), et les tests existants qui casseront à cause d'un changement de comportement voulu. Le `test-writer` reçoit cette note avec la spec.
2. Délègue à l'agent `test-writer` l'écriture des tests :
   - Domain et Application en priorité, sans base de données ;
   - un test par exemple chiffré de la spec, plus les cas limites.
3. Lance les tests : `docker compose exec app php vendor/bin/phpunit --no-coverage --filter <...>`.
4. **Prouve que chaque test échoue pour la bonne raison** : une assertion métier, pas une erreur de syntaxe ni une classe manquante imprévue. Montre la sortie. Si une classe ou une méthode n'existe pas encore, crée seulement le squelette minimal nécessaire pour que le test échoue sur l'assertion. Signale-le.
5. Vérifie que les autres tests du module touché sont toujours verts (son dossier de tests, plus les tests trouvés par la recherche des formes figées du `test-writer`). La suite complète tourne dans `/review`.
5b. **Contrôle avant verrouillage** : chaque dépendance de la note de câblage a sa place dans le harnais, et chaque test existant appelé à casser est listé avec sa raison. S'il manque une dépendance, complète le harnais maintenant ou arrête-toi. N'écris pas `green`.
6. Écris `green` dans `.claude/state/tdd-phase` (crée le dossier si besoin). À partir de là, le hook `tdd-guard` bloque toute modification de `tests/`.
7. 🚦 Ajoute le lot à `.claude/tasks/red-queue.md`, puis présente les tests à Sylvain. Ils sont la spec exécutable : rien ne passe à `/tdd-green` sans son accord.
