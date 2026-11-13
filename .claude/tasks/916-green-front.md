# #916 phase verte frontend

## Résultat
- 7 specs touchées : 50 verts, 0 rouge (walletReadingChips, linePurchasesWalletReading, saleSuggestionsPanel, groupDistributionSaleSuggestions, groupIndustryDetailSaleSuggestions, groupProjectUrls, jobsTablePurchases). groupIndustryDetailSalesFetch (51 verts avec les voisines) passe aussi.
- `npm run lint` : propre. `npm run build` (vue-tsc + vite) : OK, `tsconfig.tsbuildinfo` restauré.
- Suite complète frontend : 3545 verts, 17 rouges hors périmètre :
  - `typographicScale` « no text-[10px] » : `src/components/assets/AssetLocationGroup.vue:140` (fichier non touché, déjà en échec).
  - `industryModuleRoutesQuery` (16) et `projectCockpitRoutes` : timeouts de 5 s sous charge, verts en isolation.
  - `projectJournal` : flaky sous charge (timers réels 3,4 s), vert en isolation.

## Fichiers
- src/components/group-industry/WalletReadingChips.vue : puces par personnage, `data-emphasized="true"` sur `missing_scope`, phrase d'état vide bornée par la plus tardive des `nextReadAt` des personnages `read`.
- src/components/group-industry/LinePurchases.vue : puces, « Lecture du wallet… » sur `started`, rechargement sur `completed` si la fenêtre est ouverte, marque « Nouveau » (absent avant ou devenu lié), remise à zéro à l'ouverture.
- src/components/group-industry/SaleSuggestionsPanel.vue : puces, indicateur de lecture, rechargement sur `completed`.
- src/stores/group-industry/distribution.ts : réponse enveloppée (`suggestions` + `walletReadings`), `fetchForProject` prend une fonction `hold` (helper `assignTo`), erreur explicite si l'enveloppe est mal formée (le test `groupIndustryDetailSalesFetch` renvoie `{}` pour sale-suggestions : sans ce garde, le rendu plantait).
- src/composables/useFormatters.ts : `formatTime` (HH:mm 24 h, heure locale).
- src/i18n/locales/{en,fr}.json : bloc `groupIndustry.walletReading` (mêmes clés).

## Décisions
- `stores/sync.ts` inchangé : `walletTransactionsProgress` existe déjà, le topic `wallet-transactions` est déjà dans le jeton Mercure ; les deux composants l'observent sans second abonnement.
- `status: read` avec `readAt` nul est rendu « jamais lu » (contrat violé, pas de date inventée) ; `nextReadAt` nul donne « Wallet lu à HH:mm » seul (clé `readWithoutNext`).
- `project.ts` : aucun changement (le type `PurchaseSuggestions.walletReadings` était déjà dans types.ts).

## À faire avant fusion
- Retirer le lien symbolique `frontend/node_modules` (ignoré par git).
