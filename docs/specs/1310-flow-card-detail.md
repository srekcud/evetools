# #1310 — Card detail gathers what the current tabs show about the item

Circuit `m`, mode nuit : décisions prises sur la recommandation de l'issue, du plan (§26) et du prototype (`detailHtml`, `.det`, `dsec`, `side()`). Front seulement : aucune donnée ni endpoint nouveau, aucun calcul d'argent (les montants sont ceux des stores existants, affichés tels quels). Pas de note architect.

## Contexte

La page projet a une colonne latérale (`xl:grid-cols-[1fr_320px]`) qui porte `ProjectFlowActions` (« À faire »). Le prototype ouvre le détail d'une carte **au-dessus** de « À faire », dans cette colonne : l'en-tête de la carte, sa source et son état, puis des blocs, chacun étiqueté de l'onglet actuel dont il vient (`Approvisionnement › Où acheter`…). Les onglets actuels restent des sections repliées (#1307) : rien n'est retiré, le détail ne fait que réunir.

## Règles

R1. **Sélection.** Un clic, ou Entrée / Espace au clavier, sur une carte de `ProjectFlowGrid` émet `select` avec la clé de la carte. Une carte attendue (pointillé) sélectionne sa **carte source** (`expected.cardKey`, même règle que la surbrillance). Un second clic sur la carte déjà sélectionnée, ou le bouton ✕, ferme le détail. La carte sélectionnée porte `data-selected="true"` et `aria-pressed`. Si la clé n'existe plus après un rechargement de la grille, le détail se ferme. Un clic sur un lien ou un bouton à l'intérieur d'une carte ne sélectionne pas.

R2. **Panneau.** `ProjectFlowCardDetail.vue` (`data-testid="flow-card-detail"`) : icône, nom, quantité, pastille de source et état de la carte (mêmes libellés que la carte), phrase « Ce que les onglets actuels montrent sur cet objet, réuni sur sa carte. », puis les blocs. Aucun bloc applicable : « Rien de plus sur cette carte. ». Un bloc = titre, étiquette d'origine `data-testid="flow-detail-origin"` (clé `groupIndustry.flow.detail.origins.<origine>`, forme « Approvisionnement › Où acheter »), contenu. Le panneau est dans la colonne de droite, au-dessus de « À faire ».

R3. **Blocs applicables** (module pur `projectFlowCardDetail.ts`, `detailBlockKindsOf({ card, line, isFinalProduct, personalUse })`, ordre fixe). `line` = ligne de BOM de la carte (règle de #1308 : ligne de job d'abord, sinon première ligne) ; `isFinalProduct` = `typeId` de la carte dans `project.items`.

| Cas | Blocs, dans l'ordre |
|---|---|
| `line` absente | aucun |
| matériau (`!line.isJob`), carte `toBuy`, `toBring` ou `inTransit` | `whereToBuy`, `courier`, `contributions` |
| matériau, source `stock` ou apports en attente (`line.pendingContributions` non vide) | `useMyStock`, puis `depositToCheck` si des apports sont en attente |
| matériau, tout autre cas | `contributions` |
| job (`line.isJob`) | `jobs`, `reservation`, `planning`, `structure` |
| job dont la carte est `bpc` | `blueprints`, `research` avant `jobs` |
| job d'activité `invention` | `decryptors` après `structure` |
| `isFinalProduct` et projet non `personalUse` | `whereToSell`, `sellOrders`, `salesToMatch` en dernier (après les blocs de job éventuels) |

Un matériau en stock ET en attente de dépôt ne reçoit pas `contributions`. Un projet `personalUse` n'a aucun bloc de vente (pas de ventes, #1307).

R4. **Origines** (`DETAIL_BLOCK_ORIGINS[kind]` = `groupIndustry.flow.detail.origins.<kind>`, libellés fr et en) : `whereToBuy` Approvisionnement › Où acheter ; `courier` Approvisionnement › Contrats de courier ; `contributions` Contributions ; `useMyStock` Approvisionnement › Utiliser mon stock ; `depositToCheck` Contributions › Apports à vérifier ; `blueprints` Approvisionnement › BPC possédés ; `research` Production › Recherche ; `jobs` Production › Chaîne, Lier un job ; `reservation` Production › Réservation, location de lignes ; `planning` Planning ; `structure` Production › Structures, Santé des structures ; `decryptors` Invention › Choix du décrypteur ; `whereToSell` Finances › Prévision de vente ; `sellOrders` Ventes › Mes ordres ; `salesToMatch` Ventes › Ventes à rattacher.

R5. **Contenu des blocs** (données déjà exposées par les stores, filtrées sur `typeId` ou `line.id`) :
- `whereToBuy` : ligne de `useGroupMaterialSourcingStore().sourcing.rows` du `typeId` : Jita avec import, structure (nom du projet) et meilleur côté (`side`), volume. Totaux nuls = « inconnu ».
- `courier` : `useGroupCourierStore().courier` (projet entier, libellé « pour tout le projet ») : volume m³, collatéral, récompense, trajet, contrat lié ou non.
- `contributions` et `depositToCheck` : `line.purchases` (acheteur, quantité, prix total, source), `line.pendingContributions` (personnage, quantité), `fulfilledQuantity` sur `requiredQuantity`. Le propriétaire est renvoyé à l'onglet Contributions pour confirmer : lien qui ouvre la section (`?tab=contributions`).
- `useMyStock` : `containerQuantity` (`containerStockState` `known`, `unknown` ou `none`), `reservedQuantity`, `freeQuantity`.
- `blueprints` : `useGroupLineBlueprintsStore().blueprints` de `bomLineId === line.id` : genre, runs, ME/TE, place (`here` / `elsewhere` / `unknown`), nom du lieu.
- `research` : ligne de `useGroupResearchStore().rows` du `typeId` : niveau actuel, cible, verdict, net.
- `jobs` : `line.jobMatches` (installateur, runs, statut, structure, lien manuel) ; bouton « Lier un job » pour un participant accepté (`useProjectJobActions` + `JobLinkModal`, comme la chaîne) ; sans job lié, la phrase du prototype (« Aucun job lié… »).
- `reservation` : `line.reservations` (personnage, quantité, expiration, la mienne) ; contributions `line_rental` de `useGroupContributionStore().contributions` pour `bomItemId === line.id`.
- `planning` : jobs de `useGroupPlanningStore().planning.characters[].jobs` de `lineId === line.id` (début et fin prévus, réel s'il existe, attente). `lanesShown` faux : « Planning par personnage masqué sur ce projet ».
- `structure` : structures des `line.jobMatches` (`facilityName`) croisées par nom avec `useGroupStructureHealthStore().rows` : état, jours de carburant, services hors ligne. Sans job lié : « Aucune structure : pas de job lié ».
- `decryptors` : produit de `useGroupInventionStore().products` de même `typeId` ; une ligne par option : décrypteur, probabilité, runs, ME/TE, tentatives attendues, coût par unité. Produit absent : « Pas de données d'invention ».
- `whereToSell` : produit de `useGroupSellForecastStore().forecast.products` du `typeId` : structure et Jita, net unitaire, couverture, jours pour vendre.
- `sellOrders` : `useProjectSellOrdersStore().sellOrders.orders` du `typeId` : marché, restant, prix, rang ou état, prix suggéré.
- `salesToMatch` : `distributionStore.saleSuggestions` et candidats de `contractSalesStore.suggestions` du `typeId` ; ventes déjà enregistrées (`distributionStore.sales`) du `typeId`.

R6. **Données manquantes.** Une valeur `null` ou une donnée non chargée s'affiche « inconnu » (clé `flow.detail.unknown`), jamais 0 ni vide. Une erreur de lecture d'un store s'affiche dans le bloc concerné (`role="alert"`) sans masquer les autres. Un store encore en chargement : « Chargement… » dans le bloc.

R7. **Chargement paresseux.** À l'ouverture d'un détail, seuls les stores des blocs applicables sont lus (`sourcing`, `courier`, `lineBlueprints`, `research`, `planning`, `structureHealth`, `sellForecast`, `sellOrders`, `contributions`, `saleSuggestions` et `contractSales` si le participant peut enregistrer des ventes), une fois par projet et par ouverture (ces stores suivent déjà `openedProjectId`). Un événement Mercure du projet (`useCoalescedGroupProjectEvents`) relit les blocs ouverts, comme il relit la grille.

R8. **Libellés.** fr et en sous `groupIndustry.flow.detail.*` (titres de bloc, `origins.*`, `unknown`, `closeDetail`, `nothingMore`, `intro`, `linkJob`, `openTab`). Parité couverte par `projectFlowTranslations`. Le glossaire gagne **Détail de carte / Card detail** dans le même commit.

## Contrat des composants

- `ProjectFlowCard` : émet `select` (sans argument) au clic ou à Entrée / Espace ; prop `selected?: boolean` (`data-selected`, `aria-pressed`).
- `ProjectFlowGrid` : prop `selectedKey?: string | null` (clé de la carte source sélectionnée) ; émet `select` avec la clé de la carte, ou `expected.cardKey` pour une attendue ; un second clic sur la sélectionnée émet `select` avec `null`.
- `ProjectFlowCardDetail` : props `projectId: string`, `card: FlowCard`, `line: BomItem | undefined`, `project: GroupProject`, `canLinkJobs: boolean`, `canRecordSales: boolean` ; émet `close` ; lit les stores par `useXxxStore()` (pas de props de données).
- `projectFlowCardDetail.ts` exporte `DetailBlockKind`, `DETAIL_BLOCK_ORIGINS: Record<DetailBlockKind, string>` (valeur = clé i18n du libellé d'origine), `detailBlockKindsOf`, `lineOfCard(card, bomItems)` (règle R3 de #1308).

## Hors périmètre

- Boutons d'action du prototype (Copier le contrat, Confirmer, Refuser, Copier le prix, Exclure) : l'action reste dans la section d'origine, le bloc l'ouvre par un lien (`?tab=`). Seul « Lier un job » est repris, la chaîne le fait déjà par la même composable.
- Durée de trajet des transferts, stock par origine, santé « carburant » fictive du prototype, boutons de recherche : non repris.
- Aucun onglet ni section retiré (les blocs doublent les sections repliées jusqu'à #1312, #1315 et #1317).

## Décisions

D1. Détail dans la colonne de droite au-dessus de « À faire » (prototype `side()`), pas de tiroir ni de modale. D2. Courier au niveau du projet (aucun courier par ligne côté API), dit dans le bloc. D3. Planning lu sur `characters[].jobs` ; tant que `lanesShown` est faux pour un projet de groupe, le bloc le dit (le Gantt par station est #1312). D4. Actions déportées par lien d'onglet, sauf « Lier un job ». D5. `contributions` d'un matériau acheté sans stock ni dépôt en attente : bloc d'information (apports, achats), pas de formulaire.

## Tests attendus (plafond : 15 `it` hors table)

Module pur : une table `it.each` des lignes de R3 (≈14 lignes) et `DETAIL_BLOCK_ORIGINS` complet (1 test). Composant et page, 15 `it` au plus : clic carte, carte attendue vers sa source, fermeture, `data-selected`, étiquette d'origine par bloc (fr et en), « inconnu » jamais 0, erreur d'un store isolée, `personalUse` sans bloc de vente, panneau au-dessus de « À faire ».
