# #1180 One entry point per BOM line to declare a purchase

Statut : validée en autonomie (mode nuit, circuit:m, sans sous-agent).

## Contexte
Une ligne matière du BOM a deux portes : « Purchases » (`LinePurchases.vue`, panneau de transactions wallet à lier) et « I supplied it » (`ContributeModal.vue`, source « Wallet purchase » ou prix Jita). Côté serveur, une déclaration par la modale rattache déjà les unités payées appariées (`ProjectContributionService::linkPaidPurchases`) : elles ne produisent donc plus de contribution dérivée en double. Ce qui manque est le parcours : le déclarant ne voit pas ses transactions wallet avant de déclarer son stock.

## Décision
Aucun retrait : le bouton Purchases et la source « Wallet purchase » restent. Les deux portes mènent au même parcours en deux temps (transactions wallet à lier, puis stock personnel au prix Jita).

## Règles
1. Modale de contribution, type matière, ligne non-job : une section « Link your wallet transactions first » liste, avant la source de valeur et la quantité, les transactions wallet non liées de l'item (date, personnage, quantité, total) avec un bouton Lier par ligne.
2. Lier une transaction crée l'achat de la ligne (`createPurchase`, transactionId = uuid de la suggestion), retire la ligne de la liste, puis ramène la quantité au reste à fournir de la ligne rechargée.
3. Tant qu'il reste des transactions non liées, la section du stock personnel porte l'avertissement « Declaring stock now would duplicate these purchases once the wallet sync links them. Link them first. » (non bloquant : le déclarant garde la main).
4. Sans transaction non liée (liste vide), ou pour un type autre que matière, ou une ligne job : la modale est inchangée, aucune section ni avertissement, et aucune requête de suggestions n'est émise pour une ligne job ou un type autre que matière.
5. Échec du chargement des suggestions : une note « Wallet transactions could not be loaded. » ; la modale reste utilisable (pas de valeur par défaut plausible, pas de silence).
6. Panneau Purchases d'une ligne non-job : une section « Your own stock » avec un bouton « Declare from my stock (Jita price) » qui ferme le panneau et émet `contribute` avec la ligne ; absente pour une ligne job et pour un non-participant.
7. `BomTable` relaie cet événement vers `contribute` (même chemin que « I supplied it »).

## Exemples
| Situation | Résultat |
|---|---|
| Tritanium, 2 transactions non liées (40 et 60) | section listée avec 2 lignes, avertissement affiché |
| Lier la transaction de 40 | 1 ligne restante, POST `/industry/projects/{id}/bom/{lineId}/purchases` `{transactionId}` |
| Toutes liées | section et avertissement disparaissent |
| Ligne job | pas de requête de suggestions |

## Hors périmètre
Retirer le bouton Purchases ou la source « Wallet purchase » (question à Sylvain), changement du service de contributions, corriger une contribution déclarée (#1159), second dépôt refusé (#1169).

## Décisions ouvertes
Masquer « Purchases » sur les lignes matière maintenant que la modale couvre le parcours : non fait, attend l'accord de Sylvain.
