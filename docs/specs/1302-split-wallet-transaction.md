# Spec — Ventiler une transaction du wallet entre lignes et projets (#1302)

## Contexte

Un multibuy pour deux projets est une seule transaction du wallet. `AutomaticPurchaseLinker` ne la rattache aujourd'hui que si sa quantité tient dans le restant à acheter d'**une** ligne ; sinon elle reste une suggestion. `IndustryProjectPurchase` accepte déjà plusieurs achats par transaction (aucune contrainte d'unicité sur `transaction_id`) : la part de chaque ligne et de chaque projet est la `quantity` de son achat, créée au moment du rattachement. Aucune migration, aucune nouvelle entité.

## Règles

- **R1. Cas inchangé.** Le premier projet ouvert (le plus ancien, ordre actuel) dont une ligne candidate a un restant à acheter ≥ la quantité de la transaction la reçoit en entier, comme avant ; s'il a plusieurs lignes candidates du type, la transaction reste une suggestion (ambiguïté, comme avant). Tout achat rattaché avant ce changement l'est de la même façon après.
- **R2. Ventilation.** Quand aucun projet ne couvre la transaction à lui seul, la transaction est répartie entre les projets ouverts du propriétaire, du plus ancien au plus récent : chaque projet reçoit `min(quantité encore à placer, restant à acheter de sa ligne)`, un achat par ligne, `quantity` = la part, prix unitaire de la transaction, acheteur = participant propriétaire du projet. Un projet dont le restant est nul n'est pas concerné.
- **R3. Tout ou rien.** La ventilation n'a lieu que si la somme des restants à acheter des lignes candidates couvre toute la quantité de la transaction ; sinon aucun achat n'est créé (la transaction reste une suggestion, comme un achat qui dépasse un restant aujourd'hui).
- **R4. Ambiguïté.** Un projet concerné par la ventilation (restant > 0) qui a plusieurs lignes candidates du type rend toute la transaction ambiguë : aucun achat.
- **R5. Une seule passe.** La sync ne rattache que les transactions sans aucun achat lié (règle actuelle) : une transaction ventilée n'est jamais complétée plus tard ; une transaction ventilée avec reste (impossible par R3) n'existe pas.
- **R6. Lignes candidates.** Inchangées : ligne achetable, même type, restant à livrer > 0, transaction datée à partir de la création du projet. Le restant à acheter de chaque ligne tient compte des achats déjà ajoutés dans la même passe.
- **R7. Effets.** Pour chaque ligne touchée : `DerivedContributionService::refreshLine` ; pour chaque projet touché : `ContainerDeliveryConfirmer::confirmProject`, une fois, après le flush (comportement actuel).
- **R8. Lien manuel** (`CreatePurchaseProcessor`, `transactionId` donné) : la transaction peut porter plusieurs achats tant que la somme de leurs quantités ≤ la quantité de la transaction. Le disponible = quantité de la transaction − somme des achats déjà liés (tous projets). Disponible nul : 409 `ALREADY_LINKED_MESSAGE` (comportement actuel). `quantity` absente : le disponible. `quantity` > quantité de la transaction : 400 (message actuel). `quantity` > disponible : 409, message `The quantity exceeds what is left of the wallet transaction.`.
- **R9. Suggestions** (`PurchaseSuggestionProvider`) : `PurchaseSuggestionResource` gagne `remainingQuantity` (int, quantité de la transaction non liée, tous projets). Une transaction est écartée quand son disponible est nul et qu'elle n'est pas liée au projet courant (avant : liée ailleurs, quel que soit le disponible). `quantity`, `totalPrice` et `alreadyLinked` gardent leur sens (transaction entière ; liée au projet courant).
- **R10. Lecture du disponible.** `IndustryProjectPurchaseRepository::linkedQuantityOf(CachedWalletTransaction): int` et un équivalent par lot `linkedQuantitiesOf(list<CachedWalletTransaction>): array<string,int>` (clé = uuid de la transaction, toutes les transactions demandées présentes, 0 par défaut) servent le lien manuel, les suggestions et le linker.
- **R11. Glossaire.** « Achat lié » : la sync ventile une transaction entre plusieurs projets quand aucun ne la couvre seul (#1302), plusieurs achats par transaction, disponible d'une transaction.

## Exemples (projets ouverts, ordre du plus ancien : A puis B)

| # | Situation | Résultat |
|---|---|---|
| E1 | Achat 50 ; A a une ligne à acheter 60 | 50 sur A (R1) |
| E2 | Achat 50 ; A à acheter 30, B à acheter 50 | A couvre 30 < 50, B couvre 50 : R1, 50 sur B (comportement actuel inchangé) |
| E3 | Achat 50 ; A à acheter 30, B à acheter 20 | achats 30 sur A, 20 sur B |
| E4 | Achat 50 ; A à acheter 30, B à acheter 10 | rien (R3), suggestion |
| E5 | Achat 50 ; A à acheter 30 (une ligne), B à acheter 20 (une ligne) mais A a une 2e ligne du type | rien (R4) |
| E6 | Achat 50 ; A à acheter 0, B à acheter 20, C à acheter 30 | 20 sur B, 30 sur C |
| E7 | Achat 50 déjà ventilé en 30 + 20 | jamais relié (R5) |
| E8 | Lien manuel 40 d'une transaction de 100 ; puis lien manuel sans quantité | 2e achat de 60 |
| E9 | Lien manuel 70 d'une transaction de 100 déjà liée pour 40 | 409 (R8) |
| E10 | Transaction de 100 liée 100 à B, vue depuis A | écartée des suggestions de A |
| E11 | Transaction de 100 liée 40 à B, vue depuis A | suggérée, `remainingQuantity` 60, `alreadyLinked` faux |

## Cas limites

- Prix par achat = prix unitaire × part ; la somme des parts d'une transaction ventilée égale sa quantité.
- Transaction de vente ou antérieure à la création du projet : jamais ventilée.
- Participant propriétaire sans utilisateur : cas déjà traité par le linker actuel (`findOpenOwnedBy` ne renvoie que les projets du propriétaire).

## Hors périmètre

- Enregistrer une répartition déclarée avant l'achat (le multibuy de la page « À faire », #1303, pourra appeler le lien manuel une fois par ligne avec `quantity`).
- `ContributorPaidPurchaseReader` (unités payées d'un apport) ne retire toujours que les liens du projet courant, pas ceux des autres projets.
- Écrans et libellés front.

## Décisions

- Ventilation seulement si aucun projet ne couvre la transaction seul : tout rattachement d'hier reste identique, la ventilation n'ajoute que des cas qui n'étaient pas rattachés.
- Tout ou rien (R3) : un achat plus grand que tous les restants ne s'attribue jamais en partie aux projets (stock personnel probable).
