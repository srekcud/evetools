# Spec #1100 — wallet buy linked to the oldest open project

Issue : #1100 (décisions du propriétaire en commentaire). Code : `AutomaticPurchaseLinker::linkNewPurchases`. Liée : #750, #981, #1014, #1039.

## Règles

1. Les achats de membres restent manuels (option B de #981) : le linker n'essaie que les projets ouverts du propriétaire du personnage (`findOpenOwnedBy`). Rien ne change ici.
2. Candidates = lignes achetables (`isPurchasable`) du type de la transaction, avec un restant, des projets ouverts du propriétaire créés avant la transaction (inchangé).
3. Une seule candidate : rattachement comme avant, si la quantité tient dans le restant à acheter de la ligne (`requis - acheté`).
4. Plusieurs candidates réparties sur plusieurs projets : l'achat va à la ligne du projet **le plus ancien** (`createdAt` croissant) dont le restant à acheter couvre la quantité de la transaction. Un projet plus ancien dont le restant ne couvre pas la transaction est sauté ; si aucun projet ne couvre, l'achat n'est pas rattaché (suggestion, comme avant).
5. Le projet retenu porte plusieurs lignes candidates du type : l'achat reste non rattaché (ambiguïté intra-projet inchangée, #1039).
6. Égalité de `createdAt` : l'ordre de `findOpenOwnedBy` fait foi (tri stable).
7. Un achat déjà rattaché (`IndustryProjectPurchase` sur la transaction, dont ceux posés par `PendingContributionRevaluer` pour un apport déclaré dans un projet précis) n'est jamais déplacé ni revu : le test `findOneBy(['transaction' => …])` précède tout (inchangé).
8. Les achats d'un lot sont traités du plus ancien au plus récent ; le restant à acheter est relu après chaque rattachement : un second achat qui ne tient plus dans le projet le plus ancien passe au suivant dont le restant le couvre.
9. Après rattachement : `refreshLine`, flush, `confirmProject` des projets touchés, inchangés.

## Exemples

| Projets ouverts (créé le) | Restant à acheter | Achat | Résultat |
|---|---|---|---|
| A (11-01), B (11-05) | A 1000, B 1000 | 400 | A |
| A, B | A 100, B 1000 | 400 | B (A ne couvre pas) |
| A, B | A 100, B 100 | 400 | non rattaché |
| A, B | A 1000, B 1000 | 600 puis 600 (lot) | A, puis B |
| A avec 2 lignes du type, B | tout couvre | 400 | non rattaché |
| B créé après l'achat, A avant | | 400 | A (B exclu, règle 2) |

## Hors périmètre

- Achats de membres, projet « cible d'achat », suggestion en un clic (autres options de l'issue, non retenues).
- Aucun réglage, champ ou écran retiré. Pas de migration, pas de commande console, pas d'écran.

## Décisions

- D1 intra-projet : un projet à plusieurs lignes candidates du type reste ambigu (règle 5), le propriétaire n'ayant tranché que le cas de plusieurs projets.
- D2 un projet plus ancien dont la ligne est en cours de couverture par stock (`fulfilledQuantity`) reste éligible selon le restant à acheter (`requis - acheté`, #1014).
