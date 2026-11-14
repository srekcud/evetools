# Contributions de groupe : valeur d'un apport et répartition du revenu

Cette page explique deux calculs d'un projet industriel : la valeur de chaque contribution, et la façon dont le revenu net des ventes est réparti entre les participants (payouts, puis virements). Le déroulé pas à pas est dans les playbooks [propriétaire](/help?page=playbooks/group-project-owner) et [participant](/help?page=playbooks/group-project-member). La marge du projet est définie dans [profit et marge](/help?page=calculations/margin), les frais de vente dans [frais de vente](/help?page=calculations/market-fees).

Les libellés entre guillemets « » sont ceux de l'écran ; plusieurs sont en anglais quelle que soit la langue.

## Où les chiffres apparaissent

| Écran | Ce qui est affiché |
|---|---|
| Onglet « Contributions » | Colonnes « Valuation » (valeur de la contribution) et « Method » (d'où elle vient) ; pied de tableau « Total Engaged Costs », « Approved Value », « Pending Value » |
| Onglet « Finances », tableau « Profit Distribution » | Par participant : « Costs Engaged », « Share % », « Profit Part », « Payout Total » ; « Total Payout » au centre de l'anneau |
| Onglet « Finances », section « Virements entre membres » | Les virements à faire, la « Référence de paiement », et parfois « Écart d'arrondi absorbé par le plus gros payout : … » |

L'onglet « Contributions » est masqué tant que vous êtes seul sur le projet, sans demande ni contribution à relire.

## La valeur d'une contribution

| Contribution | Au nom de | Valeur | « Method » |
|---|---|---|---|
| « Material » déclaré (« Contribuer », « J'ai fourni », « Add Contribution ») | Celui qui déclare | Prix Jita du jour d'apport × quantité | « prix Jita du jour d'apport » |
| « Material » issu d'un achat lié (« Achats ») | Le propriétaire | Somme des totaux des achats liés à la ligne, pour le type de la ligne | « prix payé (wallet) » |
| « Job Install » issu d'un job lié | Le participant qui a installé le job | Somme des coûts ESI de ses jobs liés à la ligne | « coût réel (ESI) » |
| « Job Install » ou « BPC » déclaré | Celui qui déclare | « Valeur estimee » saisie, strictement positive | « valeur saisie » |
| « Line Rental » | Celui qui déclare | « Valeur estimee » saisie, obligatoire ; 0 est accepté | « valeur saisie » |
| « Courier » | Le propriétaire | Récompense du contrat de courrier lié, une fois livré | « valeur saisie » (voir Pièges) |

- **Prix Jita du jour d'apport** : moyenne pondérée des 5 % les moins chers du volume des ordres de vente de Jita. Il est lu une fois, à la soumission, et gardé tel quel : la revue ne le relit pas. Sans prix Jita ce jour-là, la contribution est refusée (« No market price for type N: no Jita price for the deposit day. »), jamais valorisée à 0.
- **Prix payé** : pour un achat rattaché depuis le wallet, prix unitaire de la transaction × quantité rattachée ; pour un « Achat manuel », prix unitaire saisi × quantité. Seul le propriétaire lie des achats : un achat d'un autre participant ne compte que s'il le déclare en « Material ».
- **Coût ESI** : le coût que l'ESI donne pour le job. Un job installé par un personnage qu'EVE Tools ne rattache à aucun participant est compté au propriétaire.

### Quand une valeur ne bouge plus

- **Contribution déclarée** : sa valeur est fixée au dépôt. Une fois « Approved », elle ne se rejette plus, sauf si elle double une contribution détectée (« Doublon avec un apport ESI »).
- **Contribution détectée** (achat lié, job lié, courrier) : elle arrive « Approved » et suit ses liens. Lier ou supprimer un achat, lier ou délier un job la recalcule ; la synchro des jobs (toutes les 30 minutes) recopie le coût ESI. Le courrier est relu à l'affichage du courrier du projet : livré, la récompense compte ; en attente, en route, échoué ou délié, il est retiré ; statut illisible, la dernière valeur reste.
- **Projet « Termine »** : plus aucun recalcul. Les valeurs sont figées.
- Quand une contribution détectée apparaît, une contribution déclarée encore « Pending » du même participant, sur la même ligne et du même type, est rejetée d'office (note « Replaced by the ESI/wallet contribution »).

## La répartition du revenu

### Revenu net et marge

revenu marché = somme des ventes au canal « Ordre de marché » (quantité × prix unitaire)
revenu contrat = somme des ventes au canal « Contrat »
courtage = revenu marché × « Frais courtier % » ÷ 100
taxe = revenu marché × « Taxe de vente % » ÷ 100
revenu net = revenu marché + revenu contrat − courtage − taxe − frais des contrats rattachés
coût total = somme des valeurs des contributions « Approved » (« Cout total du projet »)
marge = (revenu net − coût total) ÷ coût total

Les frais d'un contrat rattaché (« Rattacher un contrat ») sont lus dans le journal wallet du vendeur. Une vente saisie à la main au canal « Contrat » n'en porte aucun.

### Payout de chaque participant

Seuls les participants qui ont au moins une contribution « Approved » sont dans « Profit Distribution » :

Costs Engaged = somme de ses contributions « Approved »
Share % = Costs Engaged ÷ coût total × 100
Profit Part = Costs Engaged × marge
Payout Total = Costs Engaged + Profit Part = Costs Engaged × revenu net ÷ coût total

Le payout rembourse donc vos apports et y ajoute votre part du profit, ou en retire votre part de la perte. Les contributions « Pending » et « Rejected » n'entrent dans aucun de ces chiffres. Rien n'est arrondi à cette étape.

### Revenu détenu et virements

EVE Tools ne déplace aucun ISK. Il part du principe que chaque vente reste dans le wallet de celui qui la détient, et calcule les virements qui donnent à chacun son payout.

1. **Détenteur d'une vente** : le joueur du « Vendeur », sinon l'auteur de la saisie. Le « Vendeur » ne peut être qu'un personnage de l'auteur : le détenteur est donc toujours celui qui a enregistré la vente.
2. **Revenu détenu** de chaque participant = ses ventes au marché × (1 − (« Frais courtier % » + « Taxe de vente % ») ÷ 100) + ses ventes par contrat − leurs frais de contrat, arrondi au centime.
3. **Dû** = « Payout Total », arrondi au centime.
4. **Solde** = revenu détenu − dû. Positif : le participant doit virer ; négatif : il doit recevoir.
5. **Écart d'arrondi** : la somme des soldes devrait valoir 0 ; les arrondis au centime peuvent laisser quelques centimes. Cet écart est retiré du solde du participant au plus gros payout, et l'écran affiche « Écart d'arrondi absorbé par le plus gros payout : … ».
6. **Plan de virements.** Le plus gros débiteur paie le plus gros créancier, du plus petit des deux montants, puis on recommence jusqu'à ce que tout soit soldé. Jusqu'à 12 participants à solde non nul, EVE Tools découpe d'abord les participants en groupes dont les soldes s'annulent, le plus de groupes possible, et solde chaque groupe à part : le plan a le plus petit nombre de virements possible. Au-delà de 12, tout le monde est soldé d'un bloc, et le plan peut compter plus de virements que le minimum.

Chaque virement porte en motif la **référence de paiement** du projet : `IP-` suivi des 6 premiers caractères hexadécimaux de l'identifiant du projet, en majuscules. Le plan est recalculé à chaque affichage.

### Quand les payouts sont exacts

- **Payouts** : calculés dès la première vente, sur les ventes enregistrées à date. Tant qu'il reste des invendus (« Marge provisoire »), ils sont provisoires.
- **Virements** : calculés seulement quand toutes les unités prévues des produits finaux sont vendues, que les frais sont connus, que chaque coût l'est et que chaque détenteur de vente est un participant accepté.
- **Terminer le projet n'est pas exigé**, et ne garantit rien de plus : il arrête les recalculs, les ventes et les revues. Les payouts sont définitifs quand tout est vendu, que toutes les ventes sont enregistrées et que plus aucune contribution ne change.

## D'où vient chaque terme

| Terme | Source | Fraîcheur |
|---|---|---|
| Prix Jita du jour d'apport | Ordres de vente de Jita (ESI) ; un type absent du cache est lu à la demande | Cache synchronisé toutes les heures ; lu à la soumission, puis figé |
| Prix payé | Transaction wallet d'un personnage du propriétaire (ESI), ou « Achat manuel » | Au rattachement ; wallet relu par tick de 5 minutes |
| Coût d'un job lié | Jobs industriels du personnage (ESI) | Synchro des jobs toutes les 30 minutes, jusqu'à la fin du projet |
| « Valeur estimee » | Votre saisie | À la soumission |
| Récompense du courrier | Contrats du personnage (ESI) | À l'affichage du courrier du projet |
| Ventes | Saisie du propriétaire ou d'un admin (« Record Sale », transaction ou contrat rattaché) | À la saisie |
| « Frais courtier % », « Taxe de vente % » | Réglage du projet, copié des frais du propriétaire à la création | Jusqu'à la fin du projet |
| Frais d'un contrat | Journal wallet du vendeur | Avec la synchro wallet |

## Exemple

Valeurs d'exemple, rondes, qui ne viennent pas du jeu. Trois participants : Pilote A (propriétaire), Pilote B (admin), Pilote C. « Frais courtier % » 2, « Taxe de vente % » 4.

Contributions approuvées :

| Participant | Contributions | Costs Engaged |
|---|---|---|
| Pilote A | achat lié 280 000 000 (« prix payé (wallet) ») + job lié 20 000 000 (« coût réel (ESI) ») | 300 000 000 |
| Pilote B | « Material », 1 000 unités × 100 000 (« prix Jita du jour d'apport ») | 100 000 000 |
| Pilote C | « BPC », valeur saisie | 100 000 000 |
| Total | | 500 000 000 |

Tout est vendu au marché : Pilote A enregistre 400 000 000 (vendeur : son personnage), Pilote B enregistre 200 000 000.

- revenu 600 000 000 ; courtage 12 000 000 ; taxe 24 000 000 ; revenu net 564 000 000
- marge = (564 000 000 − 500 000 000) ÷ 500 000 000 = 12,8 %

| Participant | Share % | Profit Part | Payout Total | Revenu détenu | Solde |
|---|---|---|---|---|---|
| Pilote A | 60 % | 38 400 000 | 338 400 000 | 400 000 000 × 0,94 = 376 000 000 | +37 600 000 |
| Pilote B | 20 % | 12 800 000 | 112 800 000 | 200 000 000 × 0,94 = 188 000 000 | +75 200 000 |
| Pilote C | 20 % | 12 800 000 | 112 800 000 | 0 | −112 800 000 |

Écart d'arrondi : 0. Plan : Pilote B vire 75 200 000 à Pilote C (plus gros débiteur d'abord), puis Pilote A vire 37 600 000 à Pilote C.

Si Pilote A avait enregistré les deux ventes, il détiendrait 564 000 000 (solde +225 600 000) et le plan serait : Pilote A vire 112 800 000 à Pilote B et 112 800 000 à Pilote C.

## Quand le chiffre est inconnu

Une donnée manquante rend le résultat **inconnu**, jamais 0. Un montant inconnu s'affiche « — » dans un cadre pointillé (infobulle « Valeur inconnue »).

| Donnée manquante | Effet | À l'écran |
|---|---|---|
| Prix Jita d'un matériau au dépôt | La contribution est refusée | « No market price for type N: no Jita price for the deposit day. » |
| Coût ESI d'un job lié | Valeur du « Job Install » inconnue, donc coût total, marge et **tous** les payouts inconnus, pas seulement celui de l'installateur | « Un apport approuvé n'a pas de prix connu : … » ; virements : « Un coût est inconnu ou le coût total est nul : … » |
| Aucune vente | Revenu net, marge, payouts inconnus | « En attente des ventes » |
| Frais du projet, avec une vente au marché | Revenu net, marge, payouts inconnus | « Répartition impossible : … » (voir Pièges) ; virements : « Le courtage ou la taxe de vente du projet est inconnu : … » |
| Frais d'un contrat rattaché, absents du journal wallet | Revenu net, marge, payouts inconnus | Comme la ligne précédente |
| Coût total à 0 | Marge et payouts inconnus | « Répartition impossible : … » |
| Produit fini encore invendu | Payouts provisoires, virements non calculés | « Marge provisoire » ; « Des produits sont encore invendus : … » |
| Détenteur d'une vente qui n'est plus participant accepté (parti, retiré, compte supprimé) | Virements non calculés | « Une vente a été enregistrée par quelqu'un qui n'est plus participant accepté : … » |

## Pièges

- **Une vente faite par un autre participant est comptée chez celui qui l'enregistre.** Seuls le propriétaire et les admins enregistrent, et seulement pour leurs propres personnages. Si un membre a vendu, l'enregistreur est compté comme détenteur de cet argent, et le plan lui demande de verser des ISK qu'il n'a pas.
- **Projet seul : « J'ai fourni » puis un achat lié comptent deux fois.** Sur une même ligne, la contribution déclarée (au prix Jita) et celle de l'achat (au prix payé) sont toutes deux approuvées et toutes deux dans le coût. En groupe, un relecteur rejette la déclarée (« Doublon avec un apport ESI », « Reject »). Seul sur le projet, l'onglet « Contributions » est masqué et ce bouton n'est pas accessible : le seul moyen de retirer le doublon est de supprimer l'achat dans « Achats ».
- **Courtage et taxe du projet s'appliquent à tout le revenu du marché**, aux taux copiés du propriétaire, quel que soit le vendeur, ses compétences ou le type de vente (une vente à un ordre d'achat ne paie pas de courtage en jeu).
- **« Répartition impossible » s'affiche aussi quand les frais sont inconnus.** Le message dit « aucune contribution approuvée n'a de coût », mais la cause peut être un « Frais courtier % » ou une « Taxe de vente % » vide.
- **Le message des virements « Le courtage ou la taxe de vente du projet est inconnu »** apparaît aussi quand ce sont les frais d'un contrat rattaché qui manquent.
- **« Total Engaged Costs » n'est pas le coût du projet.** Ce pied de l'onglet « Contributions » additionne toutes les contributions, en attente et rejetées comprises. Le coût du projet est « Approved Value ».
- **« Method » se trompe dans deux cas.** Une contribution « Courier » affiche « valeur saisie », alors que sa valeur est la récompense du contrat. Un achat manuel affiche « prix payé (wallet) ». Sous toute valeur de « Material », même un prix payé, le badge de prix indique le prix Jita P5.
- **Un participant sans contribution approuvée** n'apparaît pas dans « Profit Distribution », mais peut devoir virer s'il a enregistré des ventes.
- **Un admin qui a enregistré des ventes, puis quitte le projet ou en est retiré, bloque les virements**, même une fois le projet terminé.

## Note technique

Valeur déclarée : `ProjectContributionService::submit()` (`resolveMaterialValue()`, constante `DEPOSIT_DAY_PERCENTILE` = 0,05), prix par `JitaMarketService::getCheapestPercentilePrices()`. Contributions détectées : `DerivedContributionService::refreshLine()` (`paidPriceOf()`, `installCostOf()`) et `syncCourier()`. Répartition : `ProjectDistributionService::calculateDistribution()` et `buildMemberDistributions()`. Virements : `ProjectSettlementCalculator::calculate()` (`heldCentsByParticipant()`, `dueCentsByParticipant()`, `unavailableReason()`), `TransferPlanner::plan()` (constante `MAX_EXACT_PARTICIPANTS` = 12). Référence : `ProjectPaymentReference::of()`.
