# Tri de #206 (2026-11-03, main à 8f80d906)

Source : audit en lecture seule. #206 date du 2026-10-22 ; #200, #204, #233 ont changé la couche depuis.

| Constat de l'issue | État | Preuve |
|---|---|---|
| Aucun `uriVariables`/`Link` | résolu (#233) | `ProjectStepResource.php:35-85`, test `IndustrySubResourceUriVariablesTest` ; trou : GroupIndustry non gardé |
| Formes de réponse différentes (CreateStep vs UpdateStep) | partiel | PATCH et split : liste ; merge, link-job, POST purchases : objet ; reste `CreateStepProcessor.php:86-89` (deux formes selon la branche, `{newStep, updatedStep}`) |
| Provider de DELETE qui supprime | vrai | `StepPurchaseDeleteProvider.php:63` (`remove()`), `DeletePurchaseProcessor` ne fait que `flush()` |
| `tests/Functional` vide | caduc en général, vrai pour Industry à parent | 2 fichiers Industry, aucune route à parent testée |
| Processors sans test | surtout caduc | CreateStep, UpdateStep (plusieurs unités par run), CreatePurchase, MatchJobs, SyncCharacterSkills testés ; AdaptStock/ApplyStock supprimés ; PivotAdvisor, CopyCosts, BpcKit providers sans test de `runs` |

## Lots

1. (m) Tests HTTP des routes steps et purchases d'un projet Industry : POST/PATCH/DELETE steps, split, merge, link-job, POST/DELETE purchases.
2. (s) `remove()` sorti de `StepPurchaseDeleteProvider` vers `DeletePurchaseProcessor`.
3. (s) Tests HTTP des GET de projet : shopping-list, cost-estimation, bpc-kit, copy-costs, purchase-suggestions, available-jobs, match-jobs.
4. (s) Tests HTTP GroupIndustry : members, contributions, bom, distribution, container-verification, GET sales.
5. (s) Étendre le test d'architecture des `uriVariables` à `src/ApiResource/GroupIndustry/`.
6. (s) Forme unique de la réponse de POST steps (contrat d'API, spec préalable).
7. (s) Un seul style de provider DELETE (stub + contrôle dans le processor).
8. Hors #206 : valeurs par défaut sur paramètres invalides (`PivotAdvisorProvider.php:44` runs=abc devient 1, `BpcKitProvider.php:48-53`), à ouvrir à part (400 attendu).

Lots lancés dans ce thread : 1, 2, 3, 5.
