# Participer à un projet de groupe en tant que membre

Ce guide suit un projet dans l'ordre où un membre agit : rejoindre, voir ce qui manque, déclarer un apport, suivre son approbation, lire sa part, récupérer son argent. Chaque étape nomme l'écran et le bouton tels qu'ils s'affichent.

Solo et groupe sont le même projet : un projet de groupe est un projet auquel d'autres joueurs ont été acceptés. Le propriétaire et les admins mènent le projet, voir [Mener un projet de groupe en tant que propriétaire](/help?page=playbooks/group-project-owner). Pour la production elle-même, voir [Produire un item T2 de A à Z : de l'analyse à la marge réelle](/help?page=playbooks/t2-product-a-to-z).

> Plusieurs libellés de l'application sont en anglais dans les deux langues (**Add Contribution**, **Material**, **Job Install**, **BPC**, **Line Rental**, **Pending**, **Approved**, **Rejected**, **Approve**, **Reject**, ainsi que les en-têtes des tableaux **Contributions** et **Finances**). D'autres s'affichent sans accent en français (**Publie**, **Termine**, **Etape BOM**, **Quantite**). Ils sont cités ici tels quels.

## Ce que peut faire un membre

| Un membre peut | Un membre ne peut pas |
|---|---|
| Réserver une ligne (**Je m'en charge**) et contribuer | Relire les contributions (**Approve**, **Reject**) |
| Lire les contributions, la répartition, les virements et le **Journal** de tout le projet | Enregistrer ou corriger une vente |
| Cocher **Reçu** sur un virement qui lui est destiné | Lier un job ou un achat à une ligne, changer le statut, modifier le projet |
| Quitter le projet tant qu'il n'a aucune contribution | Accepter ou retirer d'autres membres |

Les autres participants voient votre personnage principal, jamais un de vos alts.

## 1. Rejoindre le projet

Il faut un compte EVE Tools connecté avec EVE SSO. Il y a deux entrées.

### Par le lien d'invitation

Le propriétaire vous envoie un lien de la forme `/industry/join/<code>` (la forme `/join/<code>` redirige vers la même page). Si vous n'êtes pas connecté, l'application vous envoie sur la page de connexion, puis sur le tableau de bord : **rouvrez le lien après la connexion**, il n'est pas rejoué automatiquement.

À l'ouverture, ce que vous voyez dépend de votre cas :

| Votre cas | Résultat |
|---|---|
| Votre personnage principal est dans la corporation du propriétaire | Vous êtes accepté d'office et le projet s'ouvre. |
| Vous êtes dans une autre corporation | Écran **Demande envoyée** : la demande attend l'approbation d'un admin du projet (étape suivante). |
| Le projet est privé, ou le code est inconnu | Écran **Adhésion impossible** avec le message « Project not found » (message de l'API, en anglais). |
| Le projet n'est ni **Publie** ni **En cours** (**Brouillon**, **En vente**, **Termine**) | **Adhésion impossible** avec « This project is no longer accepting new members ». |
| Vous êtes déjà accepté | Le projet s'ouvre. |
| Votre demande est déjà en attente | Vous arrivez sur la liste des projets, sans message. |

Un projet **Privé** est le réglage par défaut du propriétaire : s'il vous renvoie « Project not found », demandez-lui de décocher **Projet privé** dans **Modifier le projet**.

### Par l'onglet Disponibles

Menu latéral : **Production** › **Industrie**, onglet **Projets**, puis l'onglet **Disponibles** (mention « meme corp »). Il liste les projets **Publie** et non privés de votre corporation, sauf les vôtres et ceux où vous avez déjà une demande. Un projet **En cours** n'y figure pas : il se rejoint seulement par le lien. **Rejoindre le projet** vous accepte sans approbation et ouvre le projet.

### Attendre l'approbation

Un admin ou le propriétaire voit votre demande dans l'onglet **Équipe** (bloc **Demandes en attente**) et clique sur **Accepter** ou **Rejeter**. Tant qu'elle n'est pas acceptée, vous ne pouvez pas ouvrir le projet, et il n'apparaît pas dans **Mes projets** (qui ne liste que les projets où vous êtes accepté).

L'écran **Demande envoyée** annonce « Vous serez notifié dès qu'elle sera acceptée », mais l'acceptation n'envoie aucune notification dans l'application : revenez voir. Rouvrez le lien : accepté, le projet s'ouvre ; toujours en attente, vous retombez sur la liste des projets. Si la demande est rejetée, elle disparaît ; le même lien permet d'en refaire une.

## 2. Voir ce qui manque

Ouvrez le projet depuis **Mes projets** (les pastilles filtrent par statut : **Brouillon**, **Publie**, **En cours**, **En vente**, **Termine**). Sous l'en-tête, deux repères :

- le bandeau **Phases du projet** : **Préparer**, **Approvisionner**, **Produire**, **Vendre**, **Clôturer**, chacune **Terminée**, **En cours**, **Bloquée** ou **À venir**. Une phase bloquée dit pourquoi : « N matériaux à couvrir » ou « N jobs à terminer » ;
- la carte **Prochaine action**. Pour un membre, elle propose :

| Carte | Ce qu'elle dit | Bouton |
|---|---|---|
| **Approvisionner un matériau** | « Il manque {quantité} {item} pour couvrir la BOM » : la ligne à qui il manque le plus | **Contribuer** (ouvre la fenêtre d'ajout sur cette ligne) |
| **Lancer un job** | Les runs d'un produit restent à lancer, ou « le nombre de runs restants est inconnu » | **Voir les jobs** |
| **Rien à faire pour vous** | Le projet avance sans vous : passage de statut, vente et clôture sont pour le propriétaire et les admins | aucun |

**Approvisionner un matériau** n'apparaît que pour un projet **Publie** ou **En cours**.

### L'onglet Approvisionnement & jobs

- **Materiaux a sourcer** : une ligne par matériau avec **Requis**, **Fourni**, **Restant**, **Progression**, **Réservé par** et **Cout est.**. Quand le projet nomme des containers, chaque ligne indique le stock du container (**Vérifié**, **Partiel**, **Absent du container**, **Non vérifiable**).
- **Jobs a lancer** : les composants, blueprints et produits finaux à produire, avec leurs runs et leur statut.
- **Fourni** ne bouge que quand une contribution est **approuvée** (ou qu'un apport détecté par l'ESI est compté). Une contribution en attente ne le fait pas bouger.

Sur chaque ligne ouverte, trois boutons :

1. **Je m'en charge** réserve une quantité (champ **Quantité à prendre**, au plus ce qui reste libre : « N libres » indique le libre). Votre réservation s'affiche sous votre nom avec « (vous) ». **Libérer** la retire.
2. **Contribuer** ouvre la fenêtre d'ajout sur cette ligne (étape 3).
3. Une réservation informe les autres sans les bloquer : un autre membre peut contribuer sur la même ligne. Elle disparaît quand la ligne est complète, quand vous quittez ou êtes retiré, ou quand le projet est **Termine**. Une contribution approuvée la réduit de sa quantité.

Le bouton **Compagnon** de l'en-tête ouvre une fenêtre détachée avec **À attribuer** (lignes libres, **Je m'en charge**), **Mes lignes à livrer** (**Livrer** ouvre le projet sur la fenêtre d'ajout de la ligne, **Libérer**) et **Mes jobs (ESI)**.

## 3. Déclarer un apport

1. Onglet **Contributions**, bouton **Add Contribution** (il disparaît quand le projet est **Termine**). Vous pouvez aussi cliquer **Contribuer** sur une ligne, ou sur la carte **Prochaine action** : la fenêtre s'ouvre alors déjà réglée sur la ligne.
2. Dans la fenêtre **Ajouter une contribution**, choisissez le **Type de contribution** : **Material**, **Job Install**, **BPC** ou **Line Rental**. Ouverte depuis une ligne, la fenêtre choisit le type : **Material** pour un matériau, **BPC** pour une ligne d'invention ou de copie, **Job Install** pour les autres jobs.
3. Choisissez l'**Item** (type **Material**) ou l'**Etape BOM** (les autres types) dans la liste « Selectionnez un item... ». Elle ne propose que les lignes non complètes et indique « (N remaining) » (quantité restante).
4. Réglez la **Quantite**. Ouverte depuis une ligne, elle reprend votre réservation, sinon la quantité restante. Elle ne peut pas dépasser le restant de la ligne.
5. Renseignez la valeur si le type l'exige (tableau ci-dessous), puis une **Notes** facultative.
6. Cliquez sur **Soumettre la contribution**. La fenêtre rappelle : « Votre contribution sera examinee par un admin du projet. »

| Type | Valeur de l'apport |
|---|---|
| **Material** | Pas de saisie. L'apport est valorisé au **Prix Jita du jour d'apport (percentile 5 %)**, lu sur le marché quand vous soumettez et conservé tel quel à la revue. Sans prix Jita pour cet item, la soumission est refusée (« No market price for type N: no Jita price for the deposit day. », N étant l'identifiant du type). |
| **Job Install**, **BPC** | **Valeur estimee (ISK)**, à saisir, strictement supérieure à 0 : sinon la fenêtre affiche « Saisissez une valeur supérieure à 0 ISK pour envoyer cette contribution. » |
| **Line Rental** | Valeur saisie. Quand elle est calculable, **Suggestion issue de votre barème** s'affiche et pré-remplit la valeur ; sinon **Aucune suggestion** dit pourquoi (pas de catégorie, aucun de vos jobs lié à la ligne, job absent des jobs synchronisés). |

Règles de la soumission, avec le message de l'API quand elle les refuse :

- une seule contribution **Pending** ou **Approved** par ligne et par type pour vous (« A contribution of this type is already pending or approved for this item ») : une contribution **Rejected** vous laisse en soumettre une nouvelle ;
- jamais plus que la quantité restante de la ligne (« The contribution exceeds the remaining quantity of the line ») ;
- dans un projet de groupe, un composant intermédiaire ne se déclare pas en **Material** : il passe par son job ;
- le type **Courier** ne se soumet pas : il vient du contrat de courrier lié par le propriétaire.

### Ce que vous ne déclarez pas à la main

Certains apports sont comptés par l'ESI et approuvés d'office, avec le badge **Auto-detected** (colonne Method : « coût réel (ESI) » ou « prix payé (wallet) ») :

- **Vos jobs de fabrication et de réaction.** Après la synchro de vos jobs (toutes les 30 minutes), un job de l'un de vos personnages, démarré depuis la création du projet, qui correspond à une ligne du projet est lié tout seul et compté à votre nom en **Job Install**, au coût d'installation payé en jeu. Un coût que l'ESI ne donne pas reste **inconnu**, jamais 0. Les lignes d'invention et de copie ne sont pas liées par l'ESI : déclarez-les en **BPC**.
- **Les achats wallet.** Seul le propriétaire les lie, et ils comptent à son nom. Un matériau que vous avez acheté vous-même se déclare en **Material** et est valorisé au prix Jita du jour d'apport, pas au prix que vous avez payé.

Si vous aviez déjà déclaré un **Job Install** en attente sur la même ligne, l'apport détecté le remplace : le vôtre passe en **Rejected** avec la note « Replaced by the ESI/wallet contribution » (en anglais). S'il était déjà **Approved**, les deux comptent et le badge **Doublon avec un apport ESI** apparaît : un relecteur peut rejeter le doublon.

## 4. Suivre l'approbation

Votre contribution apparaît dans l'onglet **Contributions** avec le statut **Pending** et le badge **Unverified**. Colonnes : **Character**, **Type**, **Item / Description**, **Qty**, **Valuation**, **Method**, **Status**, **Actions**.

- Un admin ou le propriétaire clique sur **Approve** : le statut passe à **Approved**, la quantité compte dans **Fourni** et la valeur dans le coût du projet.
- Ou sur **Reject** : le statut passe à **Rejected**, la ligne ne change pas, vous pouvez soumettre à nouveau.
- Ni l'approbation ni le rejet ne vous envoie de notification. Suivez le statut dans **Contributions** : les filtres de type et de statut, et la barre de bilan (**Total Engaged Costs**, **Approved Value**, **Pending Value**, avec les compteurs **Approved**, **Pending**, **Rejected**) donnent l'état de tout le projet. L'onglet **Journal** garde une ligne **Apport approuvé** ou **Apport rejeté** avec le personnage, l'item et la quantité.
- Vous ne modifiez ni ne retirez une contribution vous-même : la colonne **Actions** ne contient des boutons que pour les relecteurs. Une contribution en attente se règle auprès d'un admin (qui la rejette) ; une contribution approuvée ne diminue plus, sauf un doublon d'un apport ESI.
- Seuls les apports **approuvés** entrent dans la répartition. Plus aucune contribution ne s'accepte quand le projet est **Termine**.
- Un apport sans prix connu rend inconnus les totaux qui l'incluent, jamais un total partiel (« Un apport n'a pas de prix connu »).

## 5. Lire sa part

Onglet **Finances**. De haut en bas : **Du revenu au profit**, le **Résumé financier**, la prévision de vente, le tableau des membres avec son anneau (la légende donne le **Share %** de chacun), **Virements entre membres** et **Récapitulatif Discord**. Vous voyez la répartition de tous les participants, pas seulement la vôtre.

Dans le tableau des membres, cliquez sur votre ligne pour dérouler le détail de vos apports par type (**Materials**, **Job Install**, **BPC**, **Line Rental**, **Couts courrier**). Les colonnes :

| Colonne | Contenu |
|---|---|
| **Costs Engaged** | Somme de vos apports approuvés. |
| **Share %** | Vos apports divisés par le **Cout total du projet**. |
| **Profit Part** | Vos apports multipliés par la marge du projet. |
| **Payout Total** | Vos apports plus votre part de profit. |

La marge est le profit divisé par le coût de production. Les formules sont dans [Profit et marge : comment EVE Tools les calcule](/help?page=calculations/profit-and-margin) et le coût d'un job dans [Coût d'un job, bonus de structure et de rig, ME et TE](/help?page=calculations/job-cost-and-bonuses).

Exemple fictif, en valeurs rondes : 100 M ISK d'apports approuvés sur un coût total de 400 M ISK, avec une marge de 10 %. **Share %** vaut 25 %, **Profit Part** 10 M ISK, **Payout Total** 110 M ISK. Une marge de -10 % donnerait un **Payout Total** de 90 M ISK : en cas de perte, le bloc **Loss Scenario** rappelle que chacun absorbe sa part au prorata.

Les cas « inconnu » de cet écran :

- **En attente des ventes** : aucune vente enregistrée, donc ni revenu net, ni marge, ni payouts. Une **Marge provisoire** s'affiche quand des produits restent invendus ; un produit « jamais vendu » n'a pas de projection ;
- **Répartition impossible** : aucune contribution approuvée n'a de coût ;
- des frais de courtage ou de taxe de vente inconnus rendent le revenu net, la marge et les payouts inconnus ;
- un apport approuvé sans prix : coût de production, profit et payouts inconnus.

Ces montants changent tant que des ventes s'enregistrent ou que des contributions sont relues. Ils ne sont définitifs qu'une fois le projet **Termine**.

## 6. Récupérer son argent

Onglet **Ventes** : la liste est en lecture seule pour vous. La case **Mes ventes** ne garde que les ventes de vos personnages (« Aucune vente de vos personnages » s'il n'y en a pas). Chaque **Vendeur** garde le revenu net de ses ventes : les virements rééquilibrent ensuite les payouts.

Dans l'onglet **Finances**, le bloc **Virements entre membres** liste « payeur → destinataire » et le montant. Il se calcule quand **tous** les produits sont vendus et que les frais et les coûts sont connus. Sinon il dit pourquoi, par exemple « Des produits sont encore invendus : les virements se calculent une fois tous les produits vendus. »

1. Si vous devez de l'argent, cliquez sur **Copier** à côté de la **Référence de paiement** et mettez-la en motif du virement en jeu.
2. Si l'argent vous est destiné, vous cochez **Reçu** une fois le virement arrivé. La case se coche aussi seule, avec la mention **Vérifié dans le journal wallet**, quand votre journal wallet montre le virement avec la référence et le montant complet.
3. Seul le destinataire coche. Pour les autres, la case est grisée (« Seul le destinataire peut cocher ce virement »).
4. Si rien n'est à virer, le bloc l'écrit : chacun détient déjà son payout. Un écart d'arrondi est absorbé par le plus gros payout.

## Quitter le projet

Dans l'onglet **Équipe**, le bouton **Quitter** de votre ligne retire votre participation (le propriétaire ne peut pas quitter). Il n'apparaît que tant que vous n'avez soumis aucune contribution. Vos réservations sont libérées ; vos contributions restent dans le projet. Pour revenir, rouvrez le lien d'invitation : la demande repart en attente d'approbation, même si vous êtes de la corporation du propriétaire.

## Pièges connus

- **Le lien dit « Project not found »** : le projet est privé ou le code est faux. Demandez au propriétaire de décocher **Projet privé**.
- **« Adhésion impossible » sur un projet qui existe** : il n'est plus ni **Publie** ni **En cours**.
- **Pas de notification à l'acceptation** : rouvrez le lien ou regardez **Mes projets**.
- **Fourni n'augmente pas après votre contribution** : elle est **Pending**. Seule l'approbation la compte.
- **Votre matériau est valorisé autrement que ce que vous avez payé** : un **Material** déclaré prend le prix Jita du jour d'apport.
- **Votre Job Install déclaré est passé en Rejected** : l'ESI a détecté le job, l'apport détecté le remplace.
- **Montants « inconnus » dans Finances** : cherchez un frais de vente manquant côté propriétaire, un apport approuvé sans prix, ou l'absence de vente.
- **Le bouton Quitter a disparu** : il se masque dès que vous avez une contribution.
