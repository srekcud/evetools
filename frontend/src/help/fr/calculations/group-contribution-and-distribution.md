# Valeur des apports et répartition du revenu d'un projet de groupe

Dans un projet de groupe, chaque participant apporte du matériel, des jobs, des BPC, une location de ligne ou un transport. Cette page explique comment chaque apport (« contribution » dans l'interface et le glossaire) est valorisé, pourquoi un apport approuvé ne bouge plus, et comment le revenu net est réparti entre les participants.

Principe : **un apport vaut un prix réel, jamais une estimation de la BOM**. Si ce prix n'existe pas, la valeur est **inconnue** (affichée « — », infobulle « Valeur inconnue »), jamais 0.

## 1. Valeur d'un apport, selon son type

| Type d'apport | Valeur | Source | Fraîcheur |
|---|---|---|---|
| **Matériau acheté par le propriétaire** (apport dérivé) | **Prix payé** : somme du prix total des achats liés à la ligne | Transaction wallet ESI liée à la ligne, ou achat saisi à la main (quantité et prix unitaire) | Les achats sont liés ou saisis ; le wallet se relit toutes les 5 minutes pour les personnages dont le cache ESI est expiré |
| **Matériau déclaré par un participant** | **Prix Jita du jour d'apport** | Percentile 5 % des ordres de vente de la station de Jita (voir plus bas) | Lu à la soumission, puis figé |
| **Installation d'un job** (apport dérivé) | **Coût réel ESI** du job lié, un apport par participant qui a lancé le job | Champ `cost` du job renvoyé par l'ESI | Jobs synchronisés toutes les 30 minutes |
| **Installation de job déclarée** | **Valeur saisie** (supérieure à 0) | Le participant | Figée à la saisie |
| **BPC** | **Valeur saisie** (supérieure à 0) | Le participant | Figée à la saisie |
| **Location de ligne** | **Valeur saisie** (obligatoire) ; une suggestion issue du barème du participant est proposée | Le participant et son barème | Figée à la saisie |
| **Courrier** (apport dérivé) | **Récompense** du contrat de courrier lié, comptée seulement quand le contrat est livré | Contrat de courrier lié au projet | Relue à chaque synchro ; si le contrat n'est plus livré, l'apport disparaît |

Un matériau déclaré **ne prend aucune valeur saisie** : le prix du jour d'apport s'applique d'office. Si Jita n'a aucun prix pour ce type au moment du dépôt, le dépôt est refusé (« No market price for type… ») : il n'y a pas d'apport sans prix, donc pas de valeur à 0.

### Le prix Jita du jour d'apport

C'est un **prix moyen pondéré des 5 % de volume les moins chers** des ordres de vente de Jita :

1. les ordres de vente connus sont triés du moins cher au plus cher (le carnet en garde jusqu'à 20 par type) ;
2. on prend les unités les moins chères jusqu'à atteindre 5 % du volume total de ces ordres ;
3. le prix est la moyenne de ces unités, pondérée par leur quantité ; si 5 % du volume fait moins d'une unité, c'est le prix du meilleur ordre ;
4. valeur de l'apport = prix × quantité apportée.

Fraîcheur : le marché de Jita est resynchronisé toutes les heures et le carnet reste en cache 2 heures. Un type absent du carnet est lu à la demande (cache de 5 minutes). Le prix est **figé sur l'apport au dépôt** : la revue ne le recalcule pas, et un prix de Jita qui bouge ensuite ne change rien.

### Apports dérivés : calculés, pas déclarés

Les achats liés du propriétaire et les jobs liés produisent des apports **approuvés d'office**, recalculés après chaque changement de lien. Si un participant avait déclaré le même apport (même ligne, même type), la déclaration encore en attente est rejetée et remplacée. Une déclaration déjà approuvée reste, et les deux comptent : c'est un **doublon d'apport**, que les relecteurs peuvent rejeter.

Un job lancé par un autre participant est compté à son nom, au coût payé en jeu. Si l'ESI ne donne aucun coût pour ce job, la valeur de l'apport est **inconnue**.

## 2. Pourquoi un apport approuvé ne bouge plus

- Un apport déclaré est **en attente** jusqu'à la revue d'un propriétaire ou d'un administrateur du projet. Il ne peut pas relire le sien, sauf si le propriétaire est le seul relecteur possible (projet solo) : l'apport est alors approuvé au dépôt.
- Une fois **approuvé**, un apport ne peut plus être approuvé ni rejeté de nouveau : seul un apport approuvé qui double un apport dérivé peut encore être rejeté. Un apport déclaré approuvé ne se supprime pas ; seuls les apports dérivés suivent leurs achats et jobs liés.
- Un projet terminé ne se relit plus : aucune revue, et les apports dérivés ne sont plus recalculés.

Raison : la couverture de chaque ligne de la BOM et la part de chacun reposent sur les apports approuvés. Les modifier après coup déplacerait les payouts de tous les participants sans qu'ils le voient.

À l'approbation, la quantité apportée compte dans la quantité servie de la ligne. Une contribution qui dépasserait la quantité restante de la ligne est refusée, et un participant ne peut pas avoir deux apports en attente ou approuvés du même type sur la même ligne.

## 3. Répartition du revenu

Seuls les apports **approuvés** comptent. Les termes sont ceux de l'écran de répartition.

1. **Revenu total** = somme du prix total des ventes du projet (marché et contrat).
2. **Frais** : le **courtage** et la **taxe de vente** s'appliquent au revenu des ventes **marché** seulement, aux taux enregistrés sur le projet. Une vente par **contrat** ne paie ni l'un ni l'autre ; ses frais réels sont lus dans le journal wallet du vendeur.
3. **Revenu net** = revenu total − courtage − taxe de vente − frais de contrat.
4. **Coût de production** = somme de la valeur de tous les apports approuvés.
5. **Marge** = (revenu net − coût de production) ÷ coût de production. C'est le profit rapporté au coût, comme partout dans l'application.
6. Pour chaque participant, avec C = la somme de la valeur de ses apports approuvés :
   - **part** = C ÷ coût de production ;
   - **profit** = C × marge ;
   - **payout** = C + profit = C × (1 + marge).

La somme des payouts est égale au revenu net : chacun récupère ce qu'il a engagé, plus la même marge que les autres. Un participant retiré du projet garde ses apports et son payout.

### Exemple (chiffres ronds, purement illustratif)

Deux participants, A (propriétaire) et B. Frais d'exemple : courtage 3 %, taxe de vente 4 %.

| Apport approuvé | Valeur |
|---|---|
| A : matériaux achetés (prix payé) | 60 000 000 ISK |
| A : installation d'un job (coût ESI) | 10 000 000 ISK |
| B : matériaux déclarés (prix Jita du jour d'apport) | 30 000 000 ISK |

- Coût de production = 60 + 10 + 30 = **100 000 000 ISK**.
- Une vente marché de **150 000 000 ISK** : courtage 4 500 000 + taxe 6 000 000 = 10 500 000 de frais, donc revenu net = **139 500 000 ISK**.
- Marge = (139,5 − 100) ÷ 100 = **39,5 %**.

| Participant | Apporté (C) | Part | Profit (C × 39,5 %) | Payout |
|---|---|---|---|---|
| A | 70 000 000 | 70 % | 27 650 000 | 97 650 000 |
| B | 30 000 000 | 30 % | 11 850 000 | 41 850 000 |
| Total | 100 000 000 | 100 % | 39 500 000 | 139 500 000 |

Si A a vendu seul, les 139 500 000 ISK sont chez A : un **virement** de 41 850 000 ISK vers B solde la répartition.

## 4. Les cas « inconnu »

L'application affiche « — » ou un bandeau plutôt qu'un chiffre plausible dans chacun de ces cas.

| Entrée manquante | Ce qui devient inconnu | Ce que l'écran dit |
|---|---|---|
| Aucune vente enregistrée | Revenu net, marge, profit, payouts | « En attente des ventes » ; coûts engagés et parts restent calculés |
| Taux de courtage ou de taxe du projet absent, avec une vente marché | Revenu net, marge, payouts | Frais inconnus |
| Frais d'un contrat de vente absents du journal wallet du vendeur | Revenu net, marge, payouts | Frais inconnus |
| Un apport approuvé sans valeur (coût ESI d'un job absent, récompense de courrier inconnue) | Coût de production, parts, profit et payouts de tout le monde ; le coût apporté d'un participant reste connu si tous ses apports ont un prix | « Un apport approuvé n'a pas de prix connu… » |
| Coût de production égal à 0 (aucun apport approuvé, ou apports à 0 ISK) | Marge, parts, payouts | « Répartition impossible : aucune contribution approuvée n'a de coût » |

Dès la première vente, la marge se calcule avec ce qui est déjà vendu : le profit est affiché comme **provisoire**, et souvent négatif tant qu'il reste des invendus. La marge projetée valorise les invendus au prix de la vente la plus récente, nette des mêmes frais ; un produit jamais vendu la rend inconnue.

### Les virements

Chaque vendeur garde le revenu net de ses ventes ; l'application calcule le plus petit nombre de virements pour que chacun détienne son payout. Les virements sont **indisponibles**, avec leur raison, tant que : aucune vente n'existe, un produit reste invendu, des frais sont inconnus, un coût est inconnu, ou un vendeur n'est pas un participant accepté du projet. Le destinataire coche le virement « reçu » ; la réception est aussi reconnue quand le journal wallet montre le don du bon montant.

## Termes du glossaire

Contribution (apport), apport dérivé, prix du jour d'apport, doublon d'apport, payout, répartition impossible, en attente des ventes, virement, marge.
