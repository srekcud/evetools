# Profit et marge : comment EVE Tools les calcule

Cette page explique ce que veulent dire « profit » et « marge » dans les modules Industrie : la formule, d'où vient chaque terme, quelle fraîcheur il a, et ce qui devient inconnu quand une donnée manque. Il n'existe qu'**une seule définition de la marge**, pour l'onglet **Marges**, pour les projets et pour la référence affichée à la création d'un projet.

## La formule

```
revenu net = revenu des ventes − frais de courtage − taxe de vente − frais des contrats
profit     = revenu net − coût de production
marge      = profit ÷ coût de production × 100
```

La marge se divise par le **coût de production**, jamais par le revenu. Une marge de 20 % veut dire que 100 ISK de production rapportent 20 ISK de profit net, soit 120 ISK de revenu net. La formule du code fait foi, quel que soit le texte d'aide affiché à l'écran.

| Terme | Ce que c'est | D'où vient la donnée |
|---|---|---|
| **Revenu des ventes** | Dans un projet : le total des ventes que vous avez enregistrées (quantité × prix unitaire). Dans l'onglet Marges : le prix de vente supposé × la quantité produite. | Projet : vos ventes, saisies ou rattachées à une transaction wallet ou à un contrat. Marges : voir la section « L'onglet Marges ». |
| **Frais de courtage et taxe de vente** | Pourcentages prélevés sur une vente au marché. Un contrat n'en paie pas. | Calculés pour le **vendeur** (voir plus bas). |
| **Frais des contrats** | Frais de création d'un contrat, lus dans le journal wallet du vendeur. | ESI, journal wallet du vendeur. |
| **Coût de production** | Dans un projet : la somme de la valeur des **apports approuvés**. Dans l'onglet Marges : matériaux, installation des jobs, et invention pour un produit T2. | Voir « Le coût de production d'un projet » et le guide [Coût d'un job, bonus de structure et de rig, ME et TE](/help?page=calculations/job-cost-and-bonuses). |

### Frais du vendeur

- **Taxe de vente** = 7,5 % × (1 − 11 % × niveau Accounting).
- **Courtage** (marché NPC) = 3 % − 0,3 % × niveau Broker Relations − 0,03 % × standing envers la faction − 0,02 % × standing envers la corporation.
- Les standings sont ceux de la station Jita 4-4 (Caldari State pour la faction, Caldari Navy pour la corporation). Un standing absent de la liste synchronisée compte 0 (neutre).
- Ils sont calculés pour le **personnage principal** du vendeur, à partir de ses compétences et de ses standings en cache. Les standings sont bruts : ni Connections ni Diplomacy.
- Un projet copie ces pourcentages à sa création (ils restent modifiables) : ce sont eux qui servent ensuite à toutes les vues du projet.

## Le coût de production d'un projet

Le coût d'un projet est la somme de la valeur de ses **contributions** (apports) approuvées. Chaque apport est compté à un **prix réel**, jamais à un prix de catalogue :

| Apport | Valeur comptée |
|---|---|
| Matériau acheté et lié au projet | Le **prix payé** : la somme des achats liés (wallet). |
| Matériau déposé depuis votre stock | Le **prix Jita du jour d'apport** : percentile 5 % des ordres de vente Jita lu au dépôt, puis figé sur l'apport. |
| Installation de job lié | Le **coût réel** lu dans le job ESI. Un job lancé par un autre participant est à son nom. |
| BPC, location de ligne | La valeur saisie à la main (la location propose une suggestion). |
| Courrier | La récompense du contrat de courrier livré. |

Un apport **déclaré** reste en attente de revue (sauf dans un projet solo, ou quand le propriétaire est le seul relecteur possible : il est alors approuvé au dépôt). Seuls les apports approuvés comptent.

## Les trois marges d'un projet

### Marge finale

Quand tous les produits sont vendus, la marge est celle de la formule ci-dessus, calculée sur tout le revenu net et tout le coût. La clôture du projet affiche « Marge finale ».

### Marge provisoire

Avec des ventes partielles, le profit et la marge comptent seulement le revenu **déjà encaissé** : ils sont donc bas, voire négatifs, tant que le coût n'est pas couvert. Ils sont signalés **provisoires** : dans l'Atelier, la colonne Profit affiche « ma part provisoire ». La liste des **invendus** (quantité à vendre moins quantité vendue, par produit) s'affiche à côté.

### Marge projetée au dernier prix de vente

Pour chiffrer ce que donneraient les invendus, EVE Tools les valorise au **prix unitaire de la vente la plus récente** du même produit, avec le même courtage et la même taxe de vente, et recalcule la marge. Elle est inconnue si un produit invendu n'a jamais été vendu (« jamais vendu »), car il n'a pas de dernier prix.

### Avant toute vente

Sans aucune vente, le projet est **en attente des ventes** : revenu net, profit, marge et payouts sont inconnus, jamais −100 % ni 0. L'Atelier affiche à la place une **prévision Jita (projet)** :

```
profit prévisionnel = revenu net Jita des produits − coût du reste à produire − coût déjà engagé
```

- Le revenu net Jita est le prix **pondéré des ordres de vente Jita** pour la quantité à vendre, moins courtage et taxe de vente du propriétaire.
- Le **coût du reste à produire** est la somme, sur les lignes de matières non terminées, de la quantité restante × le prix estimé de la ligne (sans l'installation des jobs non lancés).
- Le coût déjà engagé est celui des apports approuvés.

Dans la fiche du projet, la **prévision de vente** donne un **profit prévu** par colonne (structure de vente ou Jita) : revenu net à vendre moins coût du reste à produire, sans le coût déjà engagé.

## Le seuil de rentabilité et le coût par unité

- **Seuil de rentabilité par unité** = coût ÷ (unités × (1 − courtage − taxe de vente)). En dessous de ce prix de vente, la vente perd de l'argent.
- **Coût par unité** (projet en usage personnel) = coût ÷ unités du produit final.
- Ni l'un ni l'autre ne se calcule tant que les jobs finaux ne sont pas tous livrés, ni avec plusieurs produits finaux, ni sans apport approuvé.

## Plusieurs participants

Le profit est réparti **au prorata des coûts apportés** : la part de profit d'un participant est ses coûts × la marge, et son **payout** est ses coûts × (1 + marge). Dans un projet solo, la part est le profit entier. Les virements en jeu servent ensuite à ce que chacun détienne son payout.

## L'onglet Marges (avant de lancer un projet)

L'onglet **Marges** applique la même formule à un produit, avant de créer un projet. Le coût de production vient du plan de production (matériaux au prix Jita pondéré, installation, invention) et la marge est calculée pour quatre **lieux de vente** :

| Lieu | Prix utilisé | Frais comptés |
|---|---|---|
| Jita (vente) | Prix pondéré des ordres de vente Jita pour la quantité produite | Courtage + taxe de vente |
| Structure (vente) | Le plus bas ordre de vente de la structure choisie | Courtage + taxe de vente |
| Structure (achat) | Le meilleur ordre d'achat de la structure choisie | Taxe de vente seule |
| Contrat | Le plus bas prix unitaire des contrats publics | Aucun |

## Exemple chiffré (fictif)

Les chiffres ci-dessous sont **inventés** pour illustrer le calcul : ils ne viennent ni du jeu ni d'un marché.

Un projet de 10 unités coûte 100 M ISK d'apports approuvés (60 M apportés par A, 40 M par B). Le courtage est de 2 % et la taxe de vente de 3 %.

| Étape | Calcul | Résultat |
|---|---|---|
| Première vente : 6 unités à 15 M | 90 M − 5 % de frais = 85,5 M de revenu net | profit −14,5 M, marge provisoire **−14,5 %** |
| Invendus : 4 unités au dernier prix (15 M) | 60 M × 0,95 = 57 M, soit 142,5 M de revenu net au total | marge projetée **42,5 %** |
| Tout est vendu au même prix | 150 M − 7,5 M de frais = 142,5 M | profit 42,5 M, marge finale **42,5 %** |
| Seuil de rentabilité | 100 M ÷ (10 × 0,95) | environ **10,53 M** par unité |
| Part de A | 60 M × 42,5 % = 25,5 M de profit | payout 85,5 M |
| Part de B | 40 M × 42,5 % = 17 M de profit | payout 57 M |

## Quand le résultat est inconnu

Une donnée absente rend le résultat **inconnu**, affiché comme tel, jamais 0. Une somme qui contient un montant inconnu est inconnue.

| Ce qui manque | Ce qui devient inconnu | Comment l'app l'affiche |
|---|---|---|
| Aucune vente enregistrée | Revenu net, profit, marge, payouts du projet | « En attente des ventes », puis la prévision Jita si elle est calculable (« aucune vente » sinon) |
| Frais de vente (personnage principal, niveau Accounting ou Broker Relations, standings non synchronisés) | Revenu net, profit, marge, seuil de rentabilité, prévision | « Frais inconnus : le propriétaire du projet n'a pas synchronisé ses compétences et ses standings… » |
| Frais d'un contrat absents du journal wallet (contrat de plus de 30 jours, par exemple) | Revenu net, marge, payouts, virements | Les montants restent inconnus, sans valeur par défaut |
| Valeur d'un apport approuvé inconnue (job ESI sans coût, par exemple) | Coût de production, profit, marge, payouts | « Un apport approuvé n'a pas de prix connu… » |
| Coût de production égal à 0 (aucun apport approuvé) | Marge et parts des membres | « Répartition impossible » |
| Produit invendu jamais vendu | Marge projetée seulement | « jamais vendu » |
| Prix de marché, prix ajusté, cost index ou probabilité d'invention absent | Coût de production de l'onglet Marges, donc toutes ses marges | Aucune ligne de comparaison, et la raison en ambre (par exemple « Prix ajusté ESI inconnu ») |
| Prix de vente absent ou nul pour un lieu | La marge de ce lieu seulement | Le lieu n'a pas de ligne ; sans aucun prix de vente : « Pas de donnees marche » |
| Frais de vente inconnus dans l'onglet Marges | Frais, profit et marge des lieux au marché ; le lieu Contrat reste calculé | « Inconnu » dans les cellules concernées |

Un **projet de référence** (dernier projet terminé du même produit, de moins d'un mois, mono-produit, dont vous êtes participant accepté) affiche son coût unitaire et sa marge à la création d'un projet du même produit. Plus ancien ou multi-produits, il est ignoré. Un chiffre inconnu y reste « inconnu ».

## Fraîcheur des données

| Donnée | Synchronisation | Remarque |
|---|---|---|
| Ordres de vente Jita | Toutes les heures | Gardés 2 heures en cache. Un type absent du cache est lu à la demande et gardé 5 minutes. |
| Ordres de la structure de vente | Toutes les heures | Lus depuis le cache de la synchro, jamais en direct. |
| Contrats publics | Toutes les 30 minutes | Prix du plus bas contrat par type. |
| Journal wallet (frais de contrats) | Synchro du wallet, tick de 5 minutes | Fenêtre de 30 jours. |
| Compétences et standings du vendeur | À la synchro des compétences du personnage | Sans synchro, les frais sont inconnus. |
| Prix du jour d'apport | Lu au dépôt de l'apport | Figé ensuite, il ne bouge plus avec le marché. |
| Coût des jobs ESI | Synchro des jobs industriels, toutes les 30 minutes | Un job sans coût rend l'apport inconnu. |
| Ventes et achats d'un projet | Saisie ou transactions wallet rattachées | Pas de valeur par défaut. |

Les prix de matériaux et de vente d'un **projet en cours** ne sont pas figés : la prévision, la marge projetée et le seuil de rentabilité bougent quand le marché bouge ou quand vous enregistrez une vente.
