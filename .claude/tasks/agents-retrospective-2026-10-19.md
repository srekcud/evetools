# Autocritique des agents — nuit du 2026-10-19

Faits observés sur 9 lots TDD (#110, #112, #100, #99, #101, #98, #19, #108, #15, #21). Aucun diff n'est appliqué : à valider par Sylvain.

## 1. Valeurs attendues recopiées de commentaires (test-writer, skill /spec)

- Fait : #112, E7 (98 au lieu de 100) et Issue16 (324 au lieu de 355) venaient de commentaires de caractérisation jamais vérifiés, repris par la spec puis par le test-writer. L'implementer s'est arrêté à juste titre.
- Diff proposé, `.claude/agents/test-writer.md`, après « Utilise des assertions exactes » :

```diff
+- Une valeur attendue reprise d'un commentaire, d'une spec ou d'un autre test est recalculée (recette golden, formule) ou mesurée sur le code ; indique dans le rapport comment chaque chiffre a été obtenu.
```

- Diff proposé, `.claude/skills/spec/SKILL.md`, étape 3 :

```diff
-   - exemples chiffrés sous forme de tableau (ce seront les tests rouges) ;
+   - exemples chiffrés sous forme de tableau (ce seront les tests rouges) ; chaque chiffre vient du code, du SDE ou d'un calcul refait, jamais d'un commentaire de test ;
```

## 2. Tests appelés à casser découverts trop tard (test-writer)

- Fait : #98, trois tests existants comptaient encore la Morphite à 0 ; #112, le test Issue16 du Nomad. Repérés en fin de phase rouge, alors que la spec ne les listait pas.
- Diff proposé, `.claude/skills/tdd-red/SKILL.md`, étape 5 :

```diff
-5. Vérifie que les autres tests du dépôt sont toujours verts.
+5. Vérifie que les autres tests du dépôt sont toujours verts, puis cherche ceux qui casseront en phase verte : chaque assertion existante sur une valeur que la règle change (grep du champ, des produits du harnais). Mets-les à jour maintenant, avant le verrou.
```

## 3. Commentaires retirés hors du changement (implementer)

- Fait : #21, l'implementer a supprimé trois commentaires sans rapport avec le fix (restaurés à la main).
- Diff proposé, `.claude/agents/implementer.md`, règles :

```diff
+- Ne touche à aucun commentaire hors des lignes que tu modifies ; supprime seulement un commentaire devenu faux par ton changement.
```

## 4. Gates de l'implementer inadaptées aux worktrees

- Fait : les gates citent `docker compose exec app …`, inutilisable dans un worktree ; chaque brief a dû redonner `wt-php.sh`, `cache:warmup --env=dev` avant PHPStan, et la référence de 63 erreurs.
- Diff proposé, `.claude/agents/implementer.md`, gates :

```diff
+Dans un worktree : `.claude/scripts/wt-php.sh <worktree> unit …` (et `integration`), avec `bin/console cache:warmup --env=dev` avant PHPStan. Compare le nombre d'erreurs PHPStan à celui de `main`, pas à zéro.
```

## 5. Création de worktree et empreinte des tests (session principale)

- Fait : `git worktree add` a échoué sur un dossier déjà pris par un autre thread, et la commande suivante a écrit dans son worktree (commit retiré aussitôt, cf. `lessons.md`). L'empreinte des tests est recalculée à la main à chaque lot (`/tmp/red-N.sha`).
- Proposition : deux scripts versionnés.
  - `.claude/scripts/wt-new.sh <branche>` : prend le premier nom `evetools-wt-XX` libre, `git worktree add --no-track -b <branche> … origin/main`, échoue sans rien créer si le dossier ou la branche existe.
  - `.claude/scripts/tests-fingerprint.sh <worktree> <commit-rouge>` : empreinte des fichiers de `tests/` et `frontend/tests/` modifiés par le commit rouge, à comparer avant le commit vert.

## 6. Ce qui a bien marché

- test-skeptic après la phase verte de #112 : a trouvé un `usort` non protégé et un cas « besoin épuisé » manquant ; deux tests ajoutés.
- ddd-reviewer : renommage vers `outputPerRun` (terme du glossaire) et suppression d'une duplication.
- Les rouges qui échouent sur une `TypeError` ont été signalés et justifiés (#100), pas masqués.
