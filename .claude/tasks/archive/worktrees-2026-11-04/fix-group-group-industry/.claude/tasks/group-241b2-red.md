# Spec #241 LOT B, reprise de revue (4 écarts front + borne de requêtes) : phase rouge

Date : 2026-10-29. Base : 637d84f (vert du lot B). Rien n'est commité ; `frontend/src` et `src/` non modifiés (aucun squelette nécessaire). Pas de commentaire, pas de NBSP littéral (`\s` de `normalized()` absorbe les espaces fines du FR), pas de `.at()`.

## Tests (vitest lancé)

| # | Fichier | Test | Échec actuel (assertion) |
|---|---|---|---|
| 1 | `frontend/tests/views/GroupIndustry/groupIndustryDetailSaleSuggestions.spec.ts` (nouveau, harnais de `groupIndustryDetailSalesFetch.spec.ts`) | un rattachement refusé (409) n'apparaît qu'**une** fois sur la page | `expected 2 to be 1` |
| 1 | idem | une erreur de `SalesTable` (`store.error`) n'apparaît qu'une fois (le panneau ne la répète plus) | `expected 2 to be 1` |
| 1 | `frontend/tests/stores/groupDistributionSaleSuggestions.spec.ts` | test du 409 réécrit : `rejects` attendu, ventes gardées, `store.error` reste `null` (l'erreur va à l'appelant) | `error: 'This transaction…'` au lieu de `null` |
| 2 | `frontend/tests/views/GroupIndustry/groupProjectRefreshes.spec.ts` | test existant réécrit : `sale_recorded`/`sale_updated`/`sale_deleted` → `['distribution', 'saleSuggestions', 'sales']` | `['distribution', 'sales']` |
| 2 | `groupIndustryDetailSaleSuggestions.spec.ts` | propriétaire : chaque événement relit `sale-suggestions` (1 → 2 lectures) | `[1, 1]` au lieu de `[1, 2]` (×3) |
| 2 | idem | membre simple : aucun `sale-suggestions` demandé sur `sale_recorded` (garde-fou contre des 403 inutiles) | vert, garde-fou |
| 3 | idem | projet `completed`, propriétaire : panneau présent, bouton « Record as sale » `disabled` | `[false, []]` au lieu de `[true, [true]]` |
| 3 | idem | projet `selling` : bouton actif ; membre d'un projet terminé : pas de panneau, 0 lecture | verts, garde-fous |
| 4 | `frontend/tests/components/group-industry/saleSuggestionsPanel.spec.ts` | ligne de T1 : `Possible duplicate of the sale of 24/10/26, total 183,750,000 ISK` ; deux doublons → deux mentions (24/10/26 et 25/10/26) ; FR `Doublon possible de la vente du 24/10/26, total 183 750 000 ISK` | texte absent (×3) |
| 4 | idem | doublon absent des ventes listées → badge seul, aucune date ni total inventés | vert, garde-fou |

Vitest complet : `Test Files 4 failed | 130 passed (134)`, `Tests 13 failed | 989 passed (1002)`. Build OK (`frontend/dist`, `tsconfig.tsbuildinfo` restaurés), eslint propre.

## Contrats figés pour le vert

- (1) Le panneau garde sa propre erreur de rattachement et n'affiche plus `store.error` ; `recordSaleFromWalletTransaction()` ne pose plus `store.error` (il rejette avec le message).
- (2) `GroupProjectRefresh` gagne `'saleSuggestions'` ; `GroupIndustryDetail.vue` doit brancher `saleSuggestions` dans `refreshGroupProject` (le `Record<GroupProjectRefresh, …>` l'impose au typage) **seulement pour le propriétaire et les admins** (sinon le test du membre échoue).
- (3) `SaleSuggestionsPanel` affiché pour `isOwnerOrAdmin` même si `isCompleted` ; bouton désactivé sur projet terminé (prop ou lecture du projet : libre).
- (4) Clé à créer `groupIndustry.saleSuggestions.duplicateOf` : en `Possible duplicate of the sale of {date}, total {total} ISK`, fr `Doublon possible de la vente du {date}, total {total} ISK` ; `{date}` = `formatDate(sale.soldAt)` (format `eu` fixé dans le test), `{total}` = `formatNumber(sale.totalPrice, 0)` ; texte **visible** dans la ligne (pas seulement `title`) ; ventes cherchées dans `store.sales` par `possibleDuplicateSaleIds`. Les ventes sont chargées par `fetchSales()` avant le montage, comme sur la page : `fetchSaleSuggestions()` ouvre le même projet et ne les efface pas.

## Preuve

Sonde front jetable (store, `groupProjectRefreshes`, `GroupIndustryDetail`, panneau, deux clés i18n), retirée par `git checkout -- frontend/src` et vérifiée par `git status` : les 13 rouges passent, suite complète verte. La sonde a révélé un défaut du harnais des tests (4) : les ventes posées directement dans le store étaient effacées par `openProject()` au montage ; je les charge désormais par `fetchSales()`.

## PHP (écrit, non lancé : stack arrêtée)

`tests/Integration/State/Provider/GroupIndustry/GroupSaleSuggestionProviderTest.php` (nouveau, `RecordsSqlQueries`) : 2 personnages (4 suggestions) et 4 personnages (8 suggestions) → même nombre de requêtes ; 4 personnages → au plus `MAX_QUERIES = 6` (projet, personnages, items, transactions, ventes, plus une marge). Cible : `Character::$eveToken` (côté inverse du `OneToOne`, chargé une fois par personnage hydraté). Avec 637d84f, `$user->getCharacters()` hydrate les personnages sans leur token ; je m'attends donc à ce que le test échoue (N requêtes de token), mais je ne l'ai pas vérifié. Les personnages du test n'ont pas d'`EveToken` : Doctrine interroge quand même le côté inverse. `php -l` OK.

## Commit proposé

```
test(group-industry): pin a single display of the linking errors, the refresh of the sale suggestions, their read-only state on a completed project and the sales a duplicate points to (#241)
```
