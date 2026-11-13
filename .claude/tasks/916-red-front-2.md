# #916 lot correctif rouge frontend (revue)

6 tests vitest ajoutés, tous VERTS : le code vert est déjà correct sur ces points, ce sont des gardes-fous (aucun rouge à produire). Lint et build (vue-tsc) propres, tsconfig.tsbuildinfo restauré. Aucun fichier de production touché, pas de commit.

## groupDistributionSaleSuggestions.spec.ts (+2)
- forgets the wallet readings of an earlier empty list when a later read fails : succès avec suggestions vides mais puces présentes, puis échec 503 : saleWalletReadings et saleSuggestions vides, erreur = message de l'API.
- rejects a sale suggestions response in the former array format with the explicit store error : tableau en réponse, erreur 'Unexpected sale suggestions response', rien de retenu.

## saleSuggestionsPanel.spec.ts (+2)
- says in French that the wallet is being read between the start and the completion of the reading : absent, puis « Lecture du wallet… » sur started, absent après completed.
- gives the latest next reading time when there is no suggestion and shows no wallet state while loading or in error : empty-hint avec la borne la plus tardive (ALT 21:20) et « manual » ; puis recharge en attente et recharge en échec : ni puce ni empty-hint.

## linePurchasesWalletReading.spec.ts (+2)
- clears the new marks when the window is closed and opened again : 1 « New » ouvert, 0 après fermeture/réouverture, la suggestion reste affichée.
- does not mark as new a suggestion that was already linked before and after the reload : alreadyLinked avant et après, 0 marque, 2 lectures.

## Recherches
- Pas de test existant figeant une valeur touchée (aucune production modifiée). Mutation non jouée (interdiction de toucher au code de production) : les attendus chiffrés visent chaque branche (hold(heldWhenFailed), Array.isArray, isReadingWallet, v-if de chargement/erreur, newTransactionIds remis à zéro).
- Rappel : lien symbolique frontend/node_modules à retirer avant fusion.
