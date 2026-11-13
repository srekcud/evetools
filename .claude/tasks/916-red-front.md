# #916 phase rouge frontend

Sortie : 34 rouges, 16 verts sur 7 specs touchées (vitest run). `npm run build` (type-check) et `npm run lint` passent.

## Contrat de test que l'implémentation doit satisfaire (identifiants choisis par les tests)
- `data-testid="wallet-reading"` : une puce par personnage (nom du personnage + phrase d'état). `data-emphasized="true"` sur la puce `missing_scope` seulement.
- Phrases en : `Wallet read at HH:mm · next read around HH:mm`, `... never read`, `... wallet scope missing`, `... no token` (insensible à la casse). fr : `Wallet lu à HH:mm · prochaine lecture vers HH:mm`.
- Heures 24 h `HH:mm` en heure locale, via useFormatters (aucun formatTime n'existe encore : à ajouter).
- `WalletReadingChips` : props `readings`, `emptyHint?: boolean` ; avec `emptyHint`, `data-testid="wallet-reading-empty-hint"` contient la plus tardive des `nextReadAt` des personnages `read` et le mot « manual » (en).
- LinePurchases : `data-testid="wallet-reading-in-progress"` texte exact `Reading the wallet…` entre syncStarted et syncCompleted de `wallet-transactions` ; `data-testid="purchase-suggestion-new"` texte `New` ; rechargement sur completed seulement fenêtre ouverte ; l'état « completed » antérieur à l'ouverture ne déclenche rien.
- Store distribution : `saleSuggestions` = `suggestions` de la liste enveloppée, `saleWalletReadings` = `walletReadings` (vidé à l'ouverture d'un autre projet et en cas d'échec).
- SaleSuggestionsPanel : puces `wallet-reading` ; rechargement sur completed.

## Tests nouveaux (14)
- tests/components/group-industry/walletReadingChips.spec.ts (5) : puce lue en, puce lue fr, scope manquant mis en avant, jamais lu + pas de token, phrase d'état vide (borne tardive + saisie manuelle). Rouges : aucune puce rendue (`no chip for ...`, `Unable to get ...empty-hint`).
- tests/components/group-industry/linePurchasesWalletReading.spec.ts (5) : puces à l'ouverture, phrase d'état vide avec borne, « Reading the wallet… », rechargement sur completed (fermée : 0 lecture, ouverte : 2 lectures + puces rafraîchies), « nouveau » sur la suggestion apparue et sur celle devenue liée. Rouges sur assertions ou sur éléments absents.
- tests/components/group-industry/saleSuggestionsPanel.spec.ts (+2) : puces ; rechargement des suggestions et des puces sur completed.
- tests/stores/groupDistributionSaleSuggestions.spec.ts (1 modifié, rouge) : `reads the sale suggestions of the project newest first` attend `suggestions` + `walletReadings` (reçoit l'objet enveloppé).

## Tests existants modifiés (forme de réponse des ventes : liste enveloppée)
- groupDistributionSaleSuggestions.spec.ts : 3 tests passés à l'enveloppe, avec `saleWalletReadings` ; seul le premier est rouge, les deux autres restent verts (déjà vides).
- saleSuggestionsPanel.spec.ts : `responses.suggestions` renvoie l'enveloppe ; 14 tests existants rouges tant que le store lit le tableau (attendu).
- views/GroupIndustry/groupIndustryDetailSaleSuggestions.spec.ts : réponse enveloppée ; 9 tests rouges pour la même raison.
- stores/groupProjectUrls.spec.ts et components/group-industry/jobsTablePurchases.spec.ts : fixture de purchase-suggestions avec `walletReadings` ; restent verts (le store renvoie la réponse telle quelle). Le typage de `PurchaseSuggestions.walletReadings` est couvert par le type-check du build, pas par vitest : aucun test rouge dédié à project.ts.

## Squelettes (src/ frontend, signalés)
- frontend/src/components/group-industry/WalletReadingChips.vue : props déclarées, template `<div />`.
- frontend/src/stores/group-industry/types.ts : `WalletReadingStatus`, `WalletReading`, `GroupSaleSuggestionList`, champ `walletReadings` sur `PurchaseSuggestions`.
- frontend/src/stores/group-industry/distribution.ts : `saleWalletReadings = ref<WalletReading[]>([])` exposé, non alimenté.

## Recherches de tests figeant la forme
- grep sale-suggestions / saleSuggestions / purchase-suggestions dans tests : les 5 specs ci-dessus ; groupProjectRefreshes.spec.ts et groupIndustryDetailTabs.spec.ts ne figent pas la forme.

## Notes
- Heures attendues calculées avec getHours/getMinutes locaux (indépendant du fuseau).
- Lien symbolique frontend/node_modules vers le checkout principal créé (ignoré par git), à retirer avant fusion. tsconfig.tsbuildinfo remis à l'état d'origine après le build.
- Pas de test d'absence/mauvais type ESI : aucun code front ne lit directement ESI ; le contrat est celui de l'API interne.
