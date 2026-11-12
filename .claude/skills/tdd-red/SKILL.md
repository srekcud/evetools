---
name: tdd-red
description: Phase rouge du TDD — écrit uniquement les tests à partir d'une spec validée, et prouve qu'ils échouent pour la bonne raison.
argument-hint: <numéro d'issue>
---

Issue : $ARGUMENTS

Entrée : une spec validée dans `docs/specs/$ARGUMENTS-*.md`. Sans spec validée, arrête-toi et propose `/spec $ARGUMENTS`.

1. Lis la spec et `docs/glossary.md`. N'utilise que les termes du glossaire.
1b. **Câblage avant les tests**, obligatoire en `circuit:l` (migration vers le noyau, nouvelle dépendance), à sauter en `circuit:m` : délègue à `architect` une note `.claude/tasks/<lot>-wiring.md`. Elle liste, pour l'implémentation cible, chaque dépendance (service, VO, config, données SDE ou ESI), la façon dont le harnais de test la fournit (fixture, mock aux frontières, golden), et les tests existants qui casseront à cause d'un changement de comportement voulu. Le `test-writer` reçoit cette note avec la spec.
2. Délègue à l'agent `test-writer` l'écriture des tests (modèle Sonnet par défaut ; `model: opus` seulement pour un calcul métier pur : répartition, dette, quantité, prix) :
   - Domain et Application en priorité, sans base de données ;
   - un test par exemple chiffré de la spec, plus les cas limites ;
   - **proportionnés** : pas de data provider qui répète une règle déjà couverte (routes, `uriVariables`, un cas de sécurité par processor), les contrôles d'accès par un seul test fonctionnel par rôle ; au plus 15 tests front par lot, sur les comportements visibles.
3. Lance les tests : `docker compose exec app php vendor/bin/phpunit --no-coverage --filter <...>`.
4. **Prouve que chaque test échoue pour la bonne raison** : une assertion métier, pas une erreur de syntaxe ni une classe manquante imprévue. Montre la sortie. Si une classe ou une méthode n'existe pas encore, crée seulement le squelette minimal nécessaire pour que le test échoue sur l'assertion. Signale-le.
5. Vérifie que les autres tests du module touché sont toujours verts (son dossier de tests, plus les tests trouvés par la recherche des formes figées du `test-writer`). La suite complète tourne dans `/review`.
5a. **Tests exhaustifs voisins** (oubli constaté en #274 : listes de messages du scheduler, nombre de topics Mercure, routes, `getAll` du `SyncTracker`) : cherche les tests qui listent ou comptent l'ensemble que le lot agrandit (`grep -rn` dans `tests/` et `frontend/tests/` sur la classe, la clé ou le nombre touché, et sur un voisin déjà présent de l'élément ajouté, qui n'existe pas encore : `SyncPublicContracts::class` pour un nouveau déclencheur du scheduler, `sync/mining` pour un nouveau topic, le nombre actuel de topics ou de lignes). Mets-les à jour dans ce lot, avec leur nouvelle valeur exacte, et vérifie qu'ils échouent maintenant sur cette valeur.
5b. **Contrôle avant verrouillage** : chaque dépendance de la note de câblage a sa place dans le harnais, et chaque test existant appelé à casser est listé avec sa raison. S'il manque une dépendance, complète le harnais maintenant ou arrête-toi. N'écris pas `green`.
5c. **Lint des tests** : si `frontend/tests/` est touché, lance `npm --prefix frontend run lint` et corrige les erreurs maintenant (espaces insécables littéraux dans une regex, variables inutilisées) : après le verrouillage, le lint ne peut plus être corrigé sans rouvrir la phase.
6. Écris `green` dans `.claude/state/tdd-phase` (crée le dossier si besoin). À partir de là, le hook `tdd-guard` bloque toute modification de `tests/`.
7. 🚦 Ajoute le lot à `.claude/tasks/red-queue.md`. **Mode nuit, par défaut** : la phase rouge est validée en autonomie, résumé des tests au journal, puis `/tdd-green`. **Mode jour**, seulement si Sylvain le demande : présente-lui les tests, rien ne passe à `/tdd-green` sans son accord.
