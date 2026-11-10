# #324 — Versements entre membres, référence de paiement, vérifications de clôture

Décision du 2026-11-09 (commentaire de l'issue) : réduit à trois choses, le récapitulatif Discord ayant été livré avec #361. Maquette : `industry-project-v3.html` (carte « Versements », modale « Terminer le projet », recap Discord `S.discordTransfers`).

## Règles

1. **Détenteur** d'une vente : l'utilisateur du personnage vendeur (`seller`), sinon `recordedBy`. Il doit être participant accepté du projet. Revenu net d'une vente de canal marché = `totalPrice × (1 − (courtage % + taxe %) / 100)` ; une vente de canal contrat (#325) n'a aucun frais : `totalPrice` (même règle que `ProjectDistributionService`). Le revenu détenu d'un participant = somme des revenus nets de ses ventes.
2. **Dû** d'un participant = son `payoutTotal` de la distribution (0 s'il n'a aucun apport approuvé).
3. **Solde** = détenu − dû, en centimes entiers (`round(x × 100)`). Positif : doit verser. Négatif : doit recevoir. L'écart d'arrondi = somme des soldes (centimes, signé) ; il est absorbé par le participant au dû le plus élevé (son solde diminue de l'écart) pour que la somme soit nulle. Il est exposé (`roundingGap`, en ISK) mais n'est pas porté par un virement (écart avec l'issue : un virement n'a pas d'écart propre, voir Décisions).
4. **Virements** : plus petit nombre pour solder tous les participants à solde non nul. Jusqu'à `MAX_EXACT_MEMBERS` (12) participants à solde non nul : partition exacte en un maximum de sous-groupes de somme nulle, puis dans chaque sous-groupe versements glouton (plus gros débiteur vers plus gros créancier, égalités départagées par identifiant de participant). Au-delà : glouton sur l'ensemble. Un virement = de, à, montant en centimes entiers > 0.
5. **Disponibilité** : les virements ne sont calculés que lorsque les ventes couvrent tous les produits et que tout est connu. Sinon `transfers` est `null` et `transfersUnavailableReason` vaut, dans l'ordre : `awaiting_sales` (aucune vente), `unsold_products` (invendus), `unknown_fees` (courtage ou taxe inconnus alors qu'une vente de canal marché existe), `unknown_cost` (marge ou payout inconnus : coût total 0 ou contribution sans valeur), `unknown_seller` (vente sans détenteur participant accepté). Jamais 0 par défaut.
6. **Référence de paiement** : `IP-` + les 6 premiers caractères hexadécimaux de l'identifiant du projet, en majuscules (ex. `IP-4C81D2`). Dérivée, rien de stocké. Exposée dans `reference` et à mettre en motif du virement en jeu.
7. **Reçu** : le destinataire (et lui seul) coche « versé » : `POST /api/industry/projects/{projectId}/transfers/{fromParticipantId}/{toParticipantId}/receipt` avec `{ "received": true|false }`. Réponse = le règlement du projet. Accepté sur un projet de statut quelconque, mais seulement pour un virement présent dans le plan courant (sinon 404) ; un autre utilisateur que le destinataire reçoit 403 ; un non-membre 403 ; `received` absent ou non booléen : 422. Le reçu est enregistré avec le montant en centimes : si les ventes changent et que le montant du virement change, le reçu ne compte plus (le virement redevient non versé). `received: false` supprime le reçu. Mercure : action `transfer_received` sur `/group-project/{id}/events`, données `{ fromParticipantId, toParticipantId, received }`.
8. **Lecture** : `GET /api/industry/projects/{projectId}/settlement` (membre accepté). Champs : `reference`, `transfers` (liste de `fromParticipantId`, `fromName`, `toParticipantId`, `toName`, `amount` en ISK au centime, `received`, `receivedAt` ISO ou null, `canConfirm` = le lecteur est le destinataire) ou `null`, `transfersUnavailableReason`, `roundingGap`, `checks`.
9. **Vérifications de clôture** (`checks`) : `pendingContributionCount` (contributions `pending`), `unsoldProducts` (liste `typeId`, `typeName`, `quantity` ; sans vente, tous les produits du projet sont invendus), `marginPercent` (marge finale de la distribution, `null` si inconnue), `transfersTotal` et `transfersReceived` (`null` tant que `transfers` est `null`).
10. **Front** : la carte « Versements » (onglet Répartition, sous la répartition) affiche la référence avec un bouton copier, les virements et la case « versé » (active pour le destinataire seulement), l'écart d'arrondi s'il est non nul, ou la raison d'indisponibilité. La modale « Terminer le projet » liste les vérifications : contributions en attente, invendus par item, marge finale (inconnue : dit inconnue), versements cochés `n / total`. Une vérification non satisfaite est en ambre ; elle n'empêche pas de terminer (le propriétaire décide). Le récap Discord (projet terminé) ajoute la référence en pied de sous-titre et un bloc « Virements » quand `transfers` n'est pas `null`. Actualisation par `transfer_received` : règlement seul.

## Exemples chiffrés

Courtage 3 %, taxe 2 %. Alpha vend 100 000 000 (net 95 000 000), Bravo vend 40 000 000 (net 38 000 000). Dus : Alpha 50 000 000, Bravo 33 000 000, Charlie 50 000 000. Soldes : Alpha +45 000 000, Bravo +5 000 000, Charlie −50 000 000. Virements : Alpha → Charlie 45 000 000, Bravo → Charlie 5 000 000 (2 virements, pas 3).

Soldes +10, −10, +7, −7 : 2 virements (sous-groupes {+10, −10} et {+7, −7}), le glouton en ferait 3.

Détenu 100,00, dus 33,33 / 33,33 / 33,33 : écart d'arrondi +0,01, absorbé par le premier dû le plus élevé (égalité : plus petit identifiant).

## Cas limites

- Tous les soldes à 0 : `transfers` = liste vide (connue), `transfersTotal` 0.
- Un participant détenteur sans apport : dû 0, il verse tout ce qu'il détient.
- Participant retiré après sa vente : détenteur introuvable, `unknown_seller`.

## Hors périmètre

- Virement depuis evetools (pas d'endpoint ESI d'écriture).
- Frais réels d'un contrat par le journal wallet (même besoin que ci-dessous).
- PNG ou export du récap ; blocage de la clôture.

## Décisions ouvertes

- Écart d'arrondi par virement (issue) remplacé par un écart global du projet.
- Vérification par le journal wallet : décidée, voir « Vérification par le journal wallet » (reliquat, 2026-11-10).

## Vérification par le journal wallet (reliquat, 2026-11-10)

Décision : la sync du journal livrée par #500 sert aussi aux virements (commentaire de #500 : « une seule sync du journal »). Aucune nouvelle sync, aucun nouveau message.

1. **Lignes gardées en plus des frais de contrat** : `ref_type` `player_donation`, montant positif (entrant) et `reason` contenant une référence de paiement de projet (`ProjectPaymentReference::appearsIn`, `IP-` + 6 hexadécimaux, casse libre). Les autres dons du personnage ne sont jamais stockés. Colonnes `reason` (500) et `first_party_id` (expéditeur) sur `cached_wallet_journal_entries`, nulles pour les frais de contrat (migration `Version20261110220000`).
2. **Virement vérifié** (`TransferJournalVerifier`) : les lignes du journal d'un personnage du destinataire dont le motif contient la référence du projet (`ProjectPaymentReference::isMentionedIn`) et dont l'expéditeur est un personnage du payeur ; leur somme en centimes couvre le montant du virement du plan courant (un virement payé en plusieurs fois compte, un virement trop faible non). `receivedAt` = date de la dernière ligne.
3. **Calcul** : `ProjectSettlementCalculator` marque le virement reçu si le journal le vérifie ou si le reçu manuel existe pour le même montant ; le journal prime. `SettlementTransfer::$journalVerified` et `journalVerified` dans `transfers[]` de `GET /settlement`.
4. **Front** : badge « Vérifié dans le journal wallet » sur la ligne, case cochée et désactivée (un virement vu dans le journal ne se décoche pas) ; compteur de la modale de clôture inchangé (il compte les reçus).
5. **Limites** : un journal non synchronisé (personnage sans jeton ou sans scope wallet, avant la prochaine sync horaire) laisse le virement non vérifié, la case manuelle reste possible ; un virement envoyé depuis un personnage inconnu d'evetools n'est pas reconnu.
