# Spec #535 — Module Commerce : Marché, Évaluateur, Contrats ; suppression de /shopping-list

Lot 5 `group:commerce`, `circuit:m`. Frontend seul : aucune migration, aucune ressource API, aucun endpoint. Cible : `.claude/tasks/ux-architecture-2026-11-09.md` §4-5 (C3, D1, D4), maquette `docs/design/mockups/navigation.html`. La barre d'onglets est `ModuleTabBar` (#531), pas de second composant. Rien de visible n'est retiré : tout est déplacé ou fusionné (mode nuit, la question des formats reste ouverte).

## Preuves de l'issue vérifiées
- Vrai : `/shopping-list` (`ShoppingList.vue`) sans lien ni palette ; `components/shopping/*` utilisés par elle seule, par `SharedShoppingList.vue` (`AppraisalResults`) et, pour `MissingVolumeWarning` et `missingVolume.ts`, par `Appraisal.vue`, `StockpileDashboard.vue`, `StockpileImportPanel.vue`.
- Vrai : `/contracts` redirige vers `/assets?tab=contracts` ; le sélecteur d'onglet d'`Assets.vue` a deux onglets (`assets`, `contracts`).
- Vrai : les puces de format ne sont pas envoyées (`Appraisal.vue:289-292` force `multibuy`).
- **Périmé (point 4)** : la comparaison structure passe par `POST /api/shopping-list/parse`, qui reçoit déjà `structureId: selectedStructure.id` (`Appraisal.vue:273`). `POST /appraise` n'a aucun paramètre de structure côté backend (`AppraisalResultResource`). Aucun changement de production : seule une garde de test fige le comportement.
- Aucun lien backend vers `/assets?tab=contracts` ou `/shopping-list` (grep `src/`, notifications comprises).

## Règles

**R1 — Barre d'onglets Commerce.** `components/commerce/CommerceModuleTabs.vue` (prop `active: 'market' | 'appraisal' | 'contracts'`) monte `ModuleTabBar` avec trois onglets dans cet ordre : Marché (`name: 'market'`), Évaluateur (`name: 'appraisal'`), Contrats (`name: 'contracts'`) ; `aria-label` = `nav.commerce`. Elle est montée en tête de `MarketBrowser.vue`, `Appraisal.vue` et `Contracts.vue` (`class="mb-6"` comme `MiningModuleTabs`). Les sous-vues `?tab=` du Marché (`browse`, `detail`, `alerts`) restent inchangées.

**R2 — Page Contrats.** Route `/contracts`, nom `contracts`, `requiresAuth`, vue `views/Contracts.vue` : `MainLayout`, barre Commerce (`active="contracts"`), titre `h1` (`commerce.tabs.contracts`), puis `ContractsTab` inchangé. Le sélecteur d'onglets d'`Assets.vue` disparaît avec l'onglet Contrats (un seul onglet restant) ; l'import de `ContractsTab` y est retiré ; `Assets.vue` ne lit plus `?tab=`.

**R3 — Redirections (query conservée, sauf indication).**

| Ancienne URL | Cible |
|---|---|
| `/assets?tab=contracts` | `/contracts` (le paramètre `tab` est retiré, les autres conservés) |
| `/assets` sans `tab=contracts` | reste la page Inventaire |
| `/shopping-list` | `/appraisal` (query conservée) |
| `/shopping-list/shared/:token` | `/appraisal/shared/:token` (déjà en place, à garder, token conservé) |

La redirection `/assets?tab=contracts` est un `beforeEnter` de la route `/assets` (comme `formerLedgerMiningLocation`).

**R4 — Suppression de `/shopping-list`.** `views/ShoppingList.vue`, `components/shopping/ShoppingListResults.vue` et la route `shopping-list` sont supprimés ; `components/shopping/` n'existe plus. Déplacements (`git mv`, contenu inchangé hormis les imports) : `shopping/AppraisalResults.vue` → `components/appraisal/AppraisalResults.vue` ; `shopping/MissingVolumeWarning.vue` et `shopping/missingVolume.ts` → `components/appraisal/`. Tous les importeurs (`Appraisal.vue`, `SharedShoppingList.vue`, `StockpileDashboard.vue`, `StockpileImportPanel.vue`, tests) sont mis à jour. Les clés i18n `shopping.*` sont conservées (réutilisées par l'Évaluateur, le Stockpile et la page partagée). L'API `/api/shopping-list/*` est inchangée.

**R5 — Page partagée.** `SharedShoppingList.vue` (route `shared-appraisal`, `/appraisal/shared/:token`, `requiresAuth: false`) affiche exactement le même résultat qu'avant (prix split et copie du tableau conservés) via `components/appraisal/AppraisalResults.vue`. L'appel `GET /api/shopping-list/shared/:token` et les liens `/appraisal` sont inchangés. Un jeton expiré (404) affiche toujours `shopping.sharedExpired`.

**R6 — Palette** (`useNavigationEntries.ts`). L'entrée `assets` › Contrats (`/assets?tab=contracts`) disparaît de `TABS_BY_MODULE`. Une entrée `/contracts` (`isTab: true`, libellé `nav.commerce › commerce.tabs.contracts`, alias anglais idem) est émise quand le module `market` n'est pas masqué (`isModuleHidden`). Les entrées Marché, Alertes et Évaluateur existantes sont conservées.

**R7 — Libellés.** Clés `nav.commerce` (en « Commerce », fr « Commerce »), `commerce.tabs.market` (« Market » / « Marché »), `commerce.tabs.appraisal` (« Appraisal » / « Évaluateur »), `commerce.tabs.contracts` (« Contracts » / « Contrats »). En français, `nav.appraisal` et `appraisal.title` passent de « Appraisal » à « Évaluateur » ; l'anglais reste « Appraisal » (le sélecteur d'alias de la palette lit l'anglais).

**R8 — Évaluateur : formats et structure.** Les puces de format, l'envoi de `structureId` à `/parse` et le reste de la page sont inchangés. Une garde de test fige que `analyze()` poste `structureId` (structure choisie) à `/parse` et ne l'ajoute pas à `/appraise`.

**R9 — Sidebar inchangée.** `constants/sidebarNavigation.ts` n'est pas modifié (regroupement et dernière page visitée du module Commerce : #536). Contrats reste joignable par la barre Commerce et la palette ; `MODULE_PAGES` de `useModuleLastPage` n'est pas étendu.

## Cas limites
- `/assets?tab=contracts&foo=1` → `/contracts?foo=1` ; `/assets?tab=other` reste sur Inventaire.
- Module `market` masqué (réglage utilisateur) : l'entrée de palette Contrats est masquée aussi, la route reste accessible.
- `/shopping-list/shared/<token>` enchaîne deux redirections et arrive sur la page partagée.

## Hors périmètre
- Implémentation des parseurs EFT, dscan, killmail ou retrait des puces : voir Décisions.
- Réorganisation de la sidebar et du routeur au-delà des routes ci-dessus (#536).
- Prix pondéré de l'Évaluateur (chantier prix commun, #538 et suivants).

## Décisions
- **Formats (point 3, à trancher avec Sylvain).** Nuit : puces conservées, rien retiré. Question ouverte pour Sylvain : (a) retirer les puces décoratives et garder la détection automatique multibuy, ou (b) implémenter un parseur par format (EFT d'abord : la ligne `[Ship, fit]` finit en « non trouvé »). Recommandation : (a), puis (b) à la demande car chaque format est un parseur distinct à tester.
- La page partagée garde son tableau (split, copie) déplacé dans `components/appraisal/` plutôt que réécrit sur `AppraisalResultsTable` (qui ne porte ni split ni copie du résultat partagé) : rien de visible ne disparaît.
- Libellé anglais « Appraisal » conservé, libellé français « Évaluateur » (R7).
