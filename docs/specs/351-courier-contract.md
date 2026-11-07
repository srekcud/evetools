# #351 — Contrat de courrier pré-rempli et suivi du transport

Maquette : `industry-project-v3.html#p=27` (carte « Transport Jita → atelier », onglet Matériaux) et `#p=35` (suivi). Circuit:m, mode nuit. Tranche : contrat pré-rempli, lien du contrat au projet, statut lu dans l'ESI, état de transport par ligne. Hors tranche : la récompense dans le coût et le profit (maquette : « comptée à la livraison »), l'alerte d'un contrat échoué (restent ouverts, voir « Hors périmètre »).

## Règles

1. **Achats rattachés** : tous les `IndustryProjectPurchase` des lignes de BOM du projet (pas de filtre de lieu ni de source). Aucun achat : `quoteStatus = no_purchases`, tous les chiffres `null`.
2. **Volume** = somme de `ItemVolumes::volumeOf(typeId) × quantité` par achat, en m³. Un type sans volume : volume, récompense inconnus (`null`), `quoteStatus = volume_unknown`, `missingVolumeTypeIds` listés. Jamais 0.
3. **Collatéral** = somme des `totalPrice` payés (prix réel), arrondie à l'ISK supérieur. Connu dès qu'il y a un achat.
4. **Sauts** = `JumpDistances::jumpsBetween(Jita 30000142, système de fabrication favori du propriétaire)`. Pas de système favori : `quoteStatus = workshop_unknown` ; route inconnue : `route_unknown` ; récompense `null` dans les deux cas. Ordre de priorité des statuts : `no_purchases`, `volume_unknown`, `workshop_unknown`, `route_unknown`, `ready`.
5. **Récompense** = `volume × sauts × 150` (`StructureTotalCostComparator::TRANSPORT_ISK_PER_CUBIC_METER_JUMP`), arrondie à l'ISK le plus proche. Durée du contrat : 3 jours pour livrer, expire dans 7 jours (constantes nommées, exposées `daysToComplete`, `expirationDays`).
6. **Lien** : le propriétaire (`assertOwner`, un admin reçoit 403) lie un contrat par `POST /industry/projects/{projectId}/courier-contract` `{contractId}`. Le contrat doit figurer dans `/characters/{id}/contracts/` d'un personnage du propriétaire (jeton valide), être de type `courier` et émis après la création du projet : sinon 400. Un seul lien par projet : un nouveau POST remplace l'ancien. `DELETE` (propriétaire seul, provider qui renvoie la ressource) supprime le lien. Lien persisté : `IndustryProjectCourierContract` (projet, `contractId`, `characterId` EVE, `linkedAt`), supprimé avec le projet, avec le propriétaire (`AccountDeletionService`).
7. **Statut ESI → état** (`CourierContractState`) : `outstanding` → `waiting` ; `in_progress` → `in_transit` ; `finished`, `finished_issuer`, `finished_contractor` → `delivered` ; `failed`, `rejected`, `cancelled`, `deleted`, `reversed` → `failed` ; tout autre statut, contrat absent de la réponse ESI, personnage sans jeton ou erreur ESI → `unknown` avec une `unknownReason`, jamais `delivered` par défaut. Sans lien : `contract = null`.
8. **État par ligne** : chaque ligne de BOM qui a au moins un achat sort `{bomLineId, typeId, purchasedQuantity, transportState}`. Sans lien : `not_shipped`. Avec lien : l'état du contrat (`waiting`, `in_transit`, `delivered`, `failed`, `unknown`).
9. **Candidats** (propriétaire seulement, `canLink` vrai ; sinon liste vide) : contrats `courier` des personnages du propriétaire, émis après la création du projet, 20 au plus, du plus récent au plus ancien, chacun `{contractId, characterId, state, volume, reward, collateral, startLocationId, endLocationId, issuedAt, matchesQuote}`. `matchesQuote` : volume et récompense à moins de 1 % de ceux du contrat pré-rempli ; faux si le contrat pré-rempli n'est pas `ready`.
10. **Lecture** : `GET /industry/projects/{projectId}/courier` (`ProjectCourierResource`, `skip_null_values` false, `assertAcceptedMember`), les contrats sont ceux des personnages du **propriétaire**, lus à la demande (un appel ESI par personnage au plus, pas de sync planifiée). Aucune action asynchrone : pas de topic Mercure.
11. **Front** : carte `ProjectCourier.vue` dans l'onglet Matériaux (`bom`) de `GroupIndustryDetail.vue`, store `stores/group-industry/courier.ts`. Contrat en bloc copiable (route Jita → système de fabrication, volume, collatéral, récompense, durée), bouton « Copier le contrat », puce d'état (en attente, en route, livré, échoué, inconnu), états par ligne, pour le propriétaire la liste des candidats (marqués « correspond ») avec « Lier » et « Délier ». Un chiffre inconnu s'affiche « — » avec sa cause, jamais 0. i18n FR et EN, style HUD EVE.

## Exemples

| Achats | Sauts | Volume | Collatéral | Récompense |
|---|---|---|---|---|
| 10 000 Tritanium (0,01 m³) à 4,10 ISK, 100 Zydrine (0,01 m³) à 1 390 ISK | 9 | 101 m³ | 180 000 ISK | 136 350 ISK |
| 953,5 m³ payés 22 632 500 ISK | 9 | 953,5 | 22 632 500 | 1 287 225 |
| 953,5 m³, route inconnue | — | 953,5 | 22 632 500 | `null` (`route_unknown`) |
| un type sans volume SDE | 9 | `null` | connu | `null` (`volume_unknown`) |

## Cas limites

- Projet sans achat : carte vide « aucun achat rattaché », lignes vides, pas d'appel ESI pour le lien si aucun lien.
- Contrat lié disparu de l'ESI (plus de 30 jours) : `unknown`, raison `contract_not_found`.
- Quantité d'achat 0 ignorée ; deux achats du même type s'additionnent pour la ligne.
- Un participant non propriétaire lit le statut, ne voit aucun candidat, et reçoit 403 sur POST et DELETE.
- Projet inconnu : 404 « Project not found » avant tout contrôle de droits (comme les autres routes du projet).

## Hors périmètre

- Récompense dans le coût du projet et le profit, alerte d'un contrat échoué, notification : questions pour Sylvain (aucun modèle de coût de transport dans `ProjectDistributionService`).
- Création du contrat en jeu (impossible via l'ESI), contrats de corporation, suivi des contrats de plusieurs courriers par projet, destination par structure.

## Décisions prises en autonomie

- Destination = système de fabrication favori du propriétaire, faute de structure de projet.
- Collatéral = prix payé des achats (prix réel partout), la maquette ne donne pas de formule.
- Lien manuel avec candidats proposés plutôt qu'appariement automatique : l'ESI ne dit pas à quel projet un courrier appartient et un lieu de fin peut être une structure sans système connu.

## Tests (cible 36, borne dure 40)

Unit 16 (calculateur de devis 7, états ESI 4, lecteur de contrats et candidats 5), Functional 8 (GET devis, lien, remplacement, délien, accès participant et propriétaire, 400 contrat non courrier), Integration 2 (repository, cascade de suppression), front 10 (carte : devis, inconnu, copie, états de ligne, candidats, lier, délier).
