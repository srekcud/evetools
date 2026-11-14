# #981 (#953 lot 3) — Achats liés par un participant, proposition par acheteur

Spec écrite en mode nuit, validée en autonomie, option B tranchée par Sylvain sur l'issue : lien manuel par un participant, pas d'automatisme (le rattachement automatique des achats des participants est une issue séparée). Suite des lots 1 (`953-count-contributions-on-delivery.md`) et 2 (`953-lot2-confirm-deliveries.md`).

## Périmètre

Dans ce lot : (A) participant acheteur sur `IndustryProjectPurchase` (migration), (B) un participant accepté lie un achat wallet de ses propres personnages à une ligne, (C) apport dérivé matériau par acheteur, toujours une proposition pour un acheteur qui n'est pas le propriétaire, valorisée au prix payé de cet acheteur, (D) suppression d'un achat par son acheteur, (E) front de la modale Achats ouverte aux participants, (F) glossaire, carte du module, aide.

Hors lot : rattachement automatique des achats des participants (issue séparée), achat lié pour le compte d'un autre, achat manuel (saisie ISK) d'un participant autre que le propriétaire.

## Règles

- **R1 — Acheteur.** `IndustryProjectPurchase::$buyer` : `IndustryProjectParticipant` nullable (`buyer_id`, `onDelete: CASCADE`, comme les apports du participant). `null` se lit « le participant propriétaire » (achat saisi avant la migration ou sans acheteur renseigné). `getBuyer()` / `setBuyer()`.
- **R2 — Création par un participant.** `CreatePurchaseProcessor` : `assertAcceptedMember` au lieu de `assertOwner`. Propriétaire : comportement actuel (transaction wallet ou saisie manuelle), acheteur = son participant. Autre participant accepté : `transactionId` obligatoire, la transaction doit venir d'un de ses personnages (404 sinon, comme aujourd'hui), acheteur = son participant ; une saisie manuelle (`typeId`, `quantity`, `unitPrice`) est refusée 403 « Only the project owner can add a manual purchase ». Non participant : 403. Le reste des contrôles ne change pas (transaction déjà liée 409, pas un achat 400, quantité au-dessus de la transaction 400).
- **R3 — Suggestions.** `PurchaseSuggestionProvider` : `assertAcceptedMember`. Il lit déjà seulement les personnages du demandeur ; `alreadyLinked` reste vrai quand la transaction est liée à une ligne de ce projet.
- **R4 — Suppression.** `PurchaseDeleteProvider` : participant accepté. Le propriétaire supprime n'importe quel achat du projet ; un autre participant ne supprime que les siens (403 « Only the buyer or the project owner can delete this purchase » sinon). L'apport dérivé est recalculé (`refreshLine`).
- **R5 — Apport dérivé par acheteur.** `DerivedContributionService::refreshLine` : les achats de la ligne sont regroupés par acheteur (`null` = participant propriétaire) ; pour chaque acheteur, un apport dérivé matériau (`isAutoDetected` vrai) de quantité = somme des quantités de ses achats du type de la ligne et de valeur = somme de leurs `totalPrice` (prix payé). Un acheteur qui n'a plus d'achat voit son apport dérivé supprimé (`fulfilledQuantity` baisse seulement s'il était approuvé, règle R4 du lot 1). L'apport du propriétaire garde sa règle actuelle (approuvé et vérifié d'office s'il est le seul relecteur, sinon proposition). Les apports d'installation de job, le courrier et l'anti-doublon de #510 (par participant) ne changent pas.
- **R6 — Jamais approuvé d'office pour un autre que le propriétaire.** Pour un acheteur qui n'est pas le propriétaire du projet, l'apport dérivé matériau est toujours `pending`, non vérifié, `reviewedBy` et `reviewedAt` vides, même si le propriétaire est le seul relecteur. La `fulfilledQuantity` ne bouge pas tant qu'il n'est pas approuvé.
- **R7 — Confirmation.** La proposition d'un participant est confirmée par `ContainerDeliveryConfirmer` (inchangé : plus ancien d'abord, stock du container couvre le cumul, stock inconnu = rien) et par la confirmation manuelle (relecteur autre que l'auteur). `CreatePurchaseProcessor` appelle `ContainerDeliveryConfirmer::confirmProject` après le flush, tous acheteurs.
- **R8 — Achats du propriétaire.** `AutomaticPurchaseLinker` et la création du propriétaire renseignent l'acheteur (participant propriétaire). Aucun autre comportement du lien automatique ne change.
- **R9 — Prix payé, sans double compte.** `ContributorPaidPurchaseReader` exclut déjà les transactions liées au projet : un participant qui lie un achat n'est plus valorisé deux fois s'il dépose aussi à la main. Aucun changement.
- **R10 — API de la BOM.** Chaque achat de `ProjectBomLineResource::$purchases` gagne `isMine` (le demandeur est l'acheteur, ou le propriétaire pour un acheteur `null`) et `buyerName` (`PilotName::of` de l'utilisateur de l'acheteur, `null` si l'acheteur est supprimé). Aucun id de personnage ni de transaction supplémentaire.
- **R11 — Migration.** Colonne `buyer_id` (uuid nullable, clé étrangère `industry_project_participants`, `ON DELETE CASCADE`, index). Reprise : chaque achat existant reçoit le participant propriétaire de son projet (seul le propriétaire a pu lier jusqu'ici). `down()` retire la colonne.
- **R12 — Front.** `LinePurchases.vue` : le bouton « Achats » et la modale (suggestions, bouton d'actualisation du wallet, `WalletReadingChips`) sont visibles de tout participant accepté (`myRole` non nul) ; la section de saisie manuelle reste réservée au propriétaire ; le bouton de suppression d'un achat s'affiche si `purchase.isMine` ou si le demandeur est propriétaire ; `buyerName` est affiché après la quantité quand l'achat n'est pas à moi (texte brut, pas de nouvelle clé i18n hors un libellé « acheté par »). Pas de nouvel écran ni onglet.
- **R13 — Docs.** Glossaire **Achat lié** (plus « réservé au propriétaire » : un participant lie ses propres achats, saisie manuelle au propriétaire), **Proposition d'apport** (un achat lié d'un participant autre que le propriétaire est toujours une proposition) ; `docs/modules/industry.md` (lignes `AutomaticPurchaseLinker` / `DerivedContributionService` / `ProjectAccessChecker` : achats) ; aide `calculations/group-contribution-and-distribution.md` FR et EN (un participant peut lier ses achats wallet, ils comptent à la confirmation de livraison).

## Exemples (vérifiables par test)

| Cas | Entrée | Résultat |
|---|---|---|
| Membre lie | projet de groupe, membre M lie 100 Tritanium (transaction de M) | achat avec acheteur M ; apport dérivé de M : 100, prix payé, `pending`, non vérifié ; `fulfilledQuantity` 0 |
| Acheteur propriétaire seul relecteur | projet avec un participant `member`, propriétaire seul relecteur, M lie | proposition `pending` (jamais approuvée d'office) |
| Deux acheteurs | propriétaire 60, M 40 sur la même ligne | deux apports dérivés distincts, 60 et 40, chacun à son prix payé |
| Acheteur retiré | M supprime son achat | apport dérivé de M supprimé, celui du propriétaire inchangé |
| Confirmation container | proposition de M 100, container 100 | `approved`, vérifié, `reviewedBy` vide |
| Transaction d'un autre | M lie la transaction d'un personnage d'un autre utilisateur | 404 |
| Saisie manuelle de M | M envoie `typeId`, `quantity`, `unitPrice` | 403 |
| Non participant | utilisateur étranger lie | 403 |
| Suppression | M supprime l'achat de P (propriétaire ou autre membre) | 403 ; le propriétaire peut supprimer celui de M |
| Reprise | achat existant sans acheteur | acheteur = participant propriétaire du projet |
| Acheteur `null` | achat sans acheteur | apport du propriétaire, comme avant |

## Cas limites

- Le participant retiré du projet ou dont le compte est supprimé : achats et apports partent par cascade (même comportement que ses apports).
- Un apport déclaré à la main par M sur la même ligne est rejeté s'il est en attente quand l'apport dérivé de M apparaît (règle existante par participant et type).
- La quantité d'achat d'un participant au-delà du reste de la ligne n'est pas bornée (comme la création manuelle du propriétaire aujourd'hui) ; la confirmation par le container reste bornée par le stock.
- Un achat sans transaction (saisie manuelle du propriétaire) a un acheteur propriétaire, jamais d'autre.

## Accès

Création et suppression : `assertAcceptedMember` puis contrôle de propriété (transaction d'un personnage du demandeur ; achat du demandeur ou propriétaire). La lecture du wallet d'un participant ne sort pas : la BOM n'expose que quantité, prix, nom de pilote et `isMine`.

## Migration, prod

Une migration (colonne `buyer_id` et reprise des achats existants sur le participant propriétaire). Aucune commande console, aucun scope ESI.
