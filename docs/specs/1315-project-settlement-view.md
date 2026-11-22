# #1315 — Bilan du projet dans la page en flux

Circuit `m`, mode nuit. Prototype : `industry-prototype-ishtar-2026-11-21.html` (`bilan(p)`, `bilanExtra()`, carte `Surplus`, action `stash`). Pas de note architect : une migration de deux colonnes, un processeur, un bloc front.

## Contexte

Cascade (`FinancialCascade`), répartition (`DistributionTable`), virements avec référence (`ProjectTransfers`, `ProjectSettlementResource`), récap Discord (`ProjectDiscordRecap`) et surplus en lecture seule (`ProjectSurplus`) existent déjà, mais dans les sections repliées « Finances » et « Matières ». La page en flux (#1307) n'a pas de fin de projet. Le surplus n'est lu que pour un projet terminé et rien ne dit qu'il a été rangé : le prototype le range au stock corpo et le garde compté au projet.

## Règles backend

B1. **Surplus lisible dès la vente.** `ProjectSurplusBuilder::build` calcule pour un projet `selling` ou `completed` (aujourd'hui `completed` seulement) ; autre statut : lignes vides, total 0.0. La production est finie à `selling`, les quantités ne bougent plus.

B2. **Rangement déclaré.** `IndustryProject` gagne `surplusStoredAt` (nullable) et `surplusStoredBy` (`User`, nullable, FK `ON DELETE SET NULL`), colonnes `surplus_stored_at`, `surplus_stored_by_id`. Migration réversible, rien à transformer. Le rangement est une déclaration : l'application ne déplace aucun asset et ne choisit pas de station (voir D2).

B3. **`POST /industry/projects/{projectId}/surplus-storage`** (`input: EmptyInput`, processeur `StoreSurplusProcessor`, réponse = la ressource `ProjectSurplus`). Propriétaire ou admin du projet seulement (`ProjectAccessChecker::isAdminOrOwner`, 403 sinon ; non-membre : même refus que les autres accès). 404 projet inconnu. 409 si le projet n'est ni `selling` ni `completed`, 409 s'il n'y a aucune ligne de surplus, 409 s'il est déjà rangé. Pas d'annulation (D3). Publie `surplus_stored` par `publishGroupProjectEvent` (aucun nouveau topic).

B4. **`GET …/surplus`** gagne `stored` (bool), `storedAt` (ISO 8601 ou null), `storedBy` (nom de pilote via `PilotName`, null si le compte est supprimé). `lines` et `totalValue` ne changent pas.

B5. **Surplus compté au projet.** `GET …/distribution` gagne `surplusStored` (bool) et `storedSurplusValue` (`?float`). `storedSurplusValue` = `totalValue` du surplus quand il est rangé, `null` sinon ; rangé avec un total inconnu (une ligne de matière sans prix) : `surplusStored = true` et valeur `null`, jamais 0. Les virements, les parts, `payoutTotal` et `marginPercent` ne changent pas (D1).

B6. **Stock des projets (#1314).** `FactoryProjectStock` traite un projet comme réglé s'il est `completed` **ou** rangé : consommé / rendu au lieu de réservé. Le surplus de production n'y entre toujours pas.

## Règles front

F1. **Type et store.** `ProjectSurplus` gagne `stored`, `storedAt`, `storedBy` ; `Distribution` gagne `surplusStored` et `storedSurplusValue`. Le store `surplus` expose `stored`, `storedAt`, `storedBy`, et `store(projectId)` (POST, remplace l'état par la réponse, erreur affichée, jamais d'état supposé). Une réponse sans `stored` booléen est une erreur explicite.

F2. **Module pur `components/group-industry/projectSettlementBlock.ts`.**
- `settlementProfitOf(distribution)` : `null` si `netRevenue` ou `totalProjectCost` est `null` ; rangé avec `storedSurplusValue` `null` : `null` ; rangé : `net − coût + valeur` ; sinon `net − coût`.
- `settlementCascadeOf(distribution)` : lignes `netRevenue`, `materials` (somme des `materialCosts` des membres), `installations` (somme de `jobInstallCosts + bpcCosts + lineRentalCosts + courierCosts`), `surplus` (valeur rangée, `null` si non rangé), `profit`. Une somme est `null` dès qu'un terme est `null`. Jamais `?? 0`.
- `participantRowsOf(distribution, settlement)` : une ligne par membre de `distribution.members` : `memberId`, nom, `sharePercent`, `payoutTotal` et `transfers` (virements dont il est destinataire ; ceux dont il est émetteur dans `sends`). `holdsSales` quand il n'est destinataire de rien, émetteur d'au moins un et `settlement.transfers` connu.
- `profitOf` du récap Discord (`projectDiscordRecap.ts`) appelle `settlementProfitOf` (un seul calcul de profit).

F3. **Composant `ProjectSettlementBlock.vue`** (`projectId`, `canManage`, `canClose`), émet `close`. Rendu du prototype : section `bilan` à bordure verte sous la grille de flux, en-tête « Répartition » avec la mention « au prorata des apports réels (achats, stock, apports, installations) », tampon CLÔTURÉ si le projet est terminé, sinon bouton « Clôturer le projet » (`canClose`) qui émet `close` ; tableau Participant / Part / Reçoit / Virement ; dessous deux colonnes : « Cascade » (liste clé-valeur de F2, Profit en gras vert) et « Virements et récap ».
- Cellule Virement : pour chaque virement reçu, « reçu » (vert) ou « en attente de <émetteur> » ; la case « reçu » reste celle de `ProjectTransfers` (destinataire seul, `canConfirm`, désactivée si `journalVerified`), via `settlementStore.setReceived` ; « détient les ventes » pour `holdsSales`. `settlement.transfers === null` : le motif `transfersUnavailableReason` (clés `groupIndustry.transfers.unavailable.*` existantes), jamais une ligne vide.
- « Virements et récap » : la référence de paiement avec « Copier » ; « Copier pour Discord » copie `buildProjectDiscordRecap` quand `settlement.transfers` est connu (rien n'est posté) ; échec du presse-papiers : message (`role="alert"`). Un composable `useCopyFeedback` porte l'état copié / échec / minuteur.
- Valeur inconnue : `UnknownValue` avec sa raison, jamais 0 ni « — » muet.
- Bloc « Surplus » (carte du prototype, dans le bloc et pas dans la grille, D4) : lignes du surplus (type, quantité, valeur ou « non valorisé »), total ; non rangé et `canManage` : bouton « Ranger au stock corpo » ; rangé : « rangé au stock corpo par <nom> le <date> · <valeur> comptée au projet » (valeur inconnue : « valeur inconnue »). Pas de surplus : bloc absent.
- États : chargement, erreur de lecture (`role="alert"`) ; jamais un bloc vide trompeur. Noms de pilote ou de type très longs tronqués avec `title`.

F4. **Page.** `GroupIndustryDetail.vue` monte le bloc sous la grille de flux quand le projet est `selling` ou `completed` et n'est pas `personalUse`. Projet solo (`isWorkedOnAlone`) : cascade et surplus seulement, ni tableau de répartition ni virements. `canManage` = propriétaire ou admin ; `canClose` = propriétaire ou admin et statut `selling`, `close` ouvre la confirmation de clôture existante (`showCompleteConfirm`). Les sections « Finances » et « Matières » restent (D5).

F5. **Mercure.** `surplus_stored` recharge `surplus` et `distribution` (nouveau rafraîchissement `surplus` dans `groupProjectRefreshes.ts`, toujours visible tant que le bloc est monté) ; `transfer_received` recharge aussi `settlement` pour le bloc. Pas de topic déclaré : l'événement passe par le topic du projet.

F6. **i18n** FR et EN (`groupIndustry.settlementBlock.*`), parité vérifiée par les tests existants.

## Exemples chiffrés

| Cas | Entrée | Sortie |
|---|---|---|
| Cascade complète | net 1 000, matières 600, installations 100, surplus rangé 50 | profit 350 |
| Surplus non rangé | net 1 000, coût 700 | profit 300, ligne surplus `null` |
| Rangé, valeur inconnue | net 1 000, coût 700, `surplusStored`, valeur `null` | profit `null` |
| Coût inconnu | `totalProjectCost` `null` | profit `null` |
| Membre sans virement | destinataire de rien, émetteur d'un virement | `holdsSales` |
| Surplus rangé deux fois | déjà `surplusStoredAt` | 409 |

## Cas limites

- Projet `in_progress` ou `published` : POST 409, GET vide, bloc non monté.
- Surplus de production seul (aucune valeur) : rangeable, total 0.0, `storedSurplusValue` 0.0 (un total de lignes valorisées vide vaut 0.0, c'est le calcul actuel de `totalValueOf`).
- Compte du rangeur supprimé : `storedBy` null, « rangé le <date> ».
- Un membre simple ne voit pas le bouton de rangement ; le POST le refuse (403).

## Hors périmètre

Choix d'une station pour le surplus, annulation du rangement, redistribution de la valeur du surplus entre membres, valorisation du surplus de production, retrait des sections « Finances » et « Matières ».

## Décisions (prises sur le prototype et sur l'existant)

D1. La valeur du surplus rangé entre dans la ligne Profit du bilan, pas dans les parts : les membres ont déjà été payés de leurs apports à leur prix, le stock corpo n'est pas un membre. Redistribuer est une décision de Sylvain (« À relire à la fin »).
D2. Rangement = déclaration, sans station : l'application ne sait pas où le joueur range, le prototype range à la station de la carte.
D3. Pas d'annulation : aucun effet de bord hors affichage ; à rouvrir si une erreur de clic gêne.
D4. Le surplus est dans le bloc de fin et pas dans la grille : la grille est partagée avec la chaîne A (conflits), et le surplus n'a pas de station dans le modèle.
D5. « Finances » et « Matières » restent : aucun écran retiré sans accord.
D6. Le destinataire coche « reçu » (règle de l'application), au lieu de « Marquer viré » côté propriétaire du prototype.
