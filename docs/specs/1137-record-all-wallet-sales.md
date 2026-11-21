# #1137 — Record all wallet sale suggestions in one confirmed action

Mode nuit, circuit:m. Front seul : `SaleSuggestionsPanel.vue`, clés i18n `groupIndustry.saleSuggestions.*` (FR + EN). Aucun endpoint, aucune migration, aucun topic Mercure (l'action est une suite d'appels synchrones, pas une tâche asynchrone).

## Contexte

Le panneau « Transactions to link » d'un projet de groupe liste les **transactions wallet** de vente à rattacher à une **vente**. Chaque ligne a son bouton « Record as sale » et son champ de quantité. Les achats ont déjà « Tout lier » (#1015, `LinePurchases.vue`, `linkAllPlan`). Le propriétaire ou un admin veut le même geste pour les ventes.

## Règles

1. **Sélection** : le bouton agit sur les suggestions affichées sans `possibleDuplicateSaleIds`. Une ligne « Possible duplicate » n'est jamais enregistrée par l'action groupée ; elle reste enregistrable une par une.
2. **Quantité par ligne** : la quantité saisie dans le champ de la ligne si c'est un entier de 1 à `suggestion.quantity` ; champ vide : `suggestion.quantity`. Une quantité saisie invalide (0, négative, décimale, supérieure à `suggestion.quantity`) exclut la ligne du plan (le backend la refuserait), comme un doublon.
3. **Montant d'une ligne** : quantité retenue × `unitPrice` (jamais `totalPrice` quand la quantité saisie est inférieure).
4. **Affichage du bouton** : « Record all as sales (N) » au-dessus du tableau, seulement si le plan retient au moins 2 lignes (`MIN_TRANSACTIONS_FOR_RECORD_ALL = 2`). Désactivé quand `recordingDisabled` (projet terminé) ou pendant un enregistrement.
5. **Confirmation** (même forme que les achats) : un clic sur le bouton ouvre un bloc avec le nombre de transactions, la quantité totale, le total ISK (`formatIsk(total, 1)`), le nombre de lignes laissées de côté (doublons et quantités invalides) si > 0, et deux boutons Confirmer / Annuler (`common.actions.cancel`). Annuler ferme le bloc sans appel API. Le plan est recalculé en direct : si une saisie change pendant la confirmation, les chiffres suivent, et le bloc se ferme si le plan tombe sous 2 lignes.
6. **Enregistrement** : à la confirmation, les lignes du plan sont enregistrées une par une, de la plus ancienne à la plus récente (date puis `transactionId`), par `store.recordSaleFromWalletTransaction(projectId, { transactionId, quantity })` ; `quantity` n'est envoyée que si la ligne a une saisie valide (comme `recordAsSale`).
7. **Échec partiel** : une ligne en échec n'interrompt pas les suivantes. À la fin, les suggestions sont rechargées une seule fois (`fetchSaleSuggestions`), puis le panneau liste chaque ligne en échec (date, personnage, message de l'erreur ; message `recordFailed` si l'erreur n'est pas une `Error`). Une action groupée sans échec n'affiche aucun bloc d'erreur. Un nouvel enregistrement (groupé ou unitaire) efface la liste précédente.
8. **Verrou** : pendant l'action groupée, tous les boutons « Record as sale » et le bouton groupé sont désactivés (`isRecordingAll`).
9. **Quantité restant à vendre (point ouvert de l'issue)** : l'action groupée **ne s'arrête pas** à une quantité restant à vendre, contrairement aux achats. Raisons : le panneau ne reçoit aucune quantité restant à vendre (la liste mêle tous les produits du projet ; `unsoldQuantity` de la distribution est un autre calcul, par produit, chargé ailleurs), et le bouton unitaire, que le propriétaire peut déjà répéter ligne par ligne, n'a pas ce plafond non plus. Le bouton groupé ne fait donc que ce que l'on peut faire à la main.

## Exemples (ce seront les tests rouges)

| Suggestions | Saisies | Plan | Confirmation |
|---|---|---|---|
| T7 (12 × 60 M), T3 (3 × 61,25 M), sans doublon | aucune | 2 lignes | 2 transactions, 15 unités, 903,75 M ISK (720 M + 183,75 M) |
| idem | T7 = 5 | 2 lignes | 8 unités, 483,75 M ISK (5 × 60 M + 183,75 M) |
| T7, T3 + T1 avec `possibleDuplicateSaleIds` | aucune | 2 lignes, 1 laissée de côté | mention « 1 left out » |
| T7 seule | aucune | 1 ligne | pas de bouton groupé |
| T7, T3 | T7 = 13 (> 12) | 1 ligne | pas de bouton groupé |
| T7, T3, T4 ; T3 refusée par l'API | aucune | 3 lignes | T7 et T4 enregistrées, T3 listée en échec, un seul rechargement |

## Cas limites

- Saisie `''` (champ effacé) = quantité entière ; `0` ou négatif = invalide.
- `recordingDisabled` : bouton visible mais désactivé.
- La confirmation ouverte se ferme à la fin de l'action et après Annuler.
- Le rechargement des suggestions après l'action : une transaction enregistrée disparaît de la liste (comportement existant de `fetchSaleSuggestions`).

## Tests existants à ne pas casser

`frontend/tests/components/group-industry/saleSuggestionsPanel.spec.ts` (enregistrement unitaire, doublon, lecture du wallet) et `frontend/tests/views/GroupIndustry/groupIndustryDetailSaleSuggestions.spec.ts`.

## Hors périmètre

Les suggestions de contrats (`ContractSaleSuggestions.vue`), le backend, tout plafond par quantité à vendre, l'enregistrement des lignes « Possible duplicate » en groupe.

## Glossaire

Aucun terme nouveau : **Vente**, **Transaction wallet**.
