# Spec #1316 : stocker les runs réussis des jobs d'invention et afficher le nombre réel de BPC

Mode nuit : spec validée en autonomie, décisions sur l'option recommandée (voir « Décisions »). Circuit m (l'issue est labellisée s, mais elle porte une migration).

## Contexte

L'ESI renvoie `successful_runs` pour un job d'invention terminé : une réussite = un BPC T2 obtenu. `CachedIndustryJob` ne le stocke pas (#1193 rapproche les jobs d'invention aux lignes, sans résultat). L'étape d'invention du plan porte les tentatives (`runs`) ; le nombre de BPC attendu n'est exposé nulle part.

## Règles métier

1. **Stockage du job** : `CachedIndustryJob::successfulRuns` (`?int`, colonne `successful_runs` nullable). La synchro (création et mise à jour) y met `successful_runs` de la réponse ESI tel quel ; champ absent de la réponse = `null` (inconnu), jamais 0. Un job d'une autre activité que l'invention le stocke aussi s'il le reçoit, sans l'interpréter.
2. **Copie sur la correspondance** : `IndustryProjectJobMatch::successfulRuns` (`?int`, colonne `successful_runs` nullable sur `industry_project_job_matches`). `copyJobState()` la renseigne avec les runs réussis du job **seulement si la correspondance couvre tous les runs du job** (`runs === runs du job`). Part d'un job partagé (#1250, moins de runs que le job) : `null`, car une réussite aléatoire ne se répartit pas ; jamais une proportion.
3. **Ressource** : `ProjectJobMatchResource::successfulRuns` (`?int`) alimente les `jobMatches` de la BOM (`ProjectBomProvider`), les résultats de lien et de rapprochement. `null` sérialisé comme `null`.
4. **BPC attendus d'une ligne d'invention** : `ProjectBomLineResource::expectedBlueprintCopies` (`?float`) = tentatives de la ligne (`runs`) × probabilité du plan. La probabilité est celle du plan : probabilité de base du SDE, décrypteur choisi sur le projet pour ce produit (`IndustryProject::inventionDecryptorsByProductTypeId`), sans compétences (comme `InventionAttempts::needed`). Pas d'arrondi. `null` pour toute ligne qui n'est pas une invention, une ligne sans `runs`, une invention sans recette ou sans probabilité de base (> 0), jamais 0.
5. **Domaine** : `InventionAttempts::expectedBlueprintCopies(int $attempts, float $baseProbability, ?Decryptor $decryptor): float`, même formule de probabilité que `needed()` (une seule règle).
6. **Écran** (`ProductionChainJobTree.vue`, étape d'invention) : sous l'étape, « ≈ X BPC attendus » quand `expectedBlueprintCopies` n'est pas `null` (une décimale, séparateur de la langue). Pour chaque job lié, « N BPC obtenus » quand `successfulRuns` n'est pas `null` (0 inclus, c'est un résultat réel) ; rien quand il est `null`. Aucun texte sur une étape qui n'est pas une invention. i18n fr et en.
7. **Glossaire** : « BPC obtenus » / « BPC attendus » (invention).

## Exemples chiffrés (tests rouges)

| # | Cas | Résultat |
|---|---|---|
| 1 | synchro ESI : job d'invention livré, `successful_runs: 21` | `CachedIndustryJob::getSuccessfulRuns() === 21` (création et mise à jour d'un job existant) |
| 2 | synchro ESI : job de fabrication, pas de `successful_runs` | `null` |
| 3 | synchro ESI : job qui avait 4 réussites, réponse sans le champ | `null` (la mise à jour n'invente pas) |
| 4 | `copyJobState` : correspondance 59 runs, job 59 runs, 21 réussites | 21 |
| 5 | `copyJobState` : correspondance 30 runs sur un job de 59 runs, 21 réussites | `null` |
| 6 | `copyJobState` : job sans résultat | `null` ; un résultat déjà copié puis effacé côté job repasse à `null` |
| 7 | `ProjectJobMatchResource::fromEntity` | `successfulRuns` = valeur de la correspondance (21, 0, `null`) |
| 8 | domaine : 59 tentatives, probabilité de base 0,34, sans décrypteur | 20,06 |
| 9 | domaine : 10 tentatives, base 0,34, décrypteur à ×1,1 de probabilité (0,374) | 3,74 |
| 10 | `BOM` : ligne d'invention de 59 tentatives, produit T2 de recette 0,34 | `expectedBlueprintCopies` 20,06 |
| 11 | BOM : même ligne, décrypteur choisi sur le produit du projet | utilise sa probabilité |
| 12 | BOM : ligne de fabrication, de copie ; recette sans probabilité ; produit sans recette ; ligne sans runs | `null` |
| 13 | tree : étape d'invention, `expectedBlueprintCopies` 20,06 | texte « ≈ 20,1 BPC attendus » (fr) |
| 14 | tree : job lié livré avec `successfulRuns` 21 ; avec 0 ; avec `null` | « 21 BPC obtenus » ; « 0 BPC obtenus » ; rien |
| 15 | tree : étape de fabrication avec `expectedBlueprintCopies` `null` | aucun texte de BPC |

## Cas limites

- Job d'invention encore actif : l'ESI n'envoie pas le champ, `null`, rien d'affiché pour ce job.
- Plusieurs jobs liés à une ligne : un texte « obtenus » par job, aucune somme (un `null` rendrait la somme inconnue).
- Les jobs existants du cache reçoivent leur valeur à la prochaine synchro (l'ESI garde les jobs terminés 30 jours) ; aucune donnée transformée.

## Accès

Aucun nouvel endpoint ni identifiant : le champ voyage dans les ressources existantes déjà limitées aux membres acceptés.

## Migration

Une migration réversible : deux colonnes nullables (`cached_industry_jobs.successful_runs`, `industry_project_job_matches.successful_runs`), aucune donnée transformée, à valider sur base isolée (`schema:validate`).

## Hors périmètre

- Vue « Comprendre l'invention » (issue suivante du bloc 5), colonne du kanban, BPC en trop au stock, alerte quand le résultat s'écarte de l'attendu.
- Probabilité avec les compétences de l'inventeur ; sémantique `runs` / `licensed_runs` d'un job de copie (#1193).
- Crédit automatique des BPC obtenus au projet (le BPC reste déclaré à la main).

## Décisions (nuit)

1. Part d'un job partagé : `null` plutôt qu'une proportion (aucune valeur plausible inventée).
2. Pas de somme par ligne côté serveur : un affichage par job.
3. Le nombre attendu est calculé à la lecture (service, pas de colonne), pour que le décrypteur choisi après coup soit pris en compte sans migration de données.
