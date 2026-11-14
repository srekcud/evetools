# Mener un projet de groupe en tant que propriétaire

Ce guide suit un projet du début à la fin, dans l'ordre où le propriétaire agit : créer, publier, inviter, accepter, relire les apports, lire la répartition, enregistrer les ventes, virer et clôturer. Chaque étape nomme l'écran et le bouton tels qu'ils s'affichent.

Solo et groupe sont le même projet : un projet de groupe est un projet auquel d'autres joueurs ont été acceptés. Pour construire un projet seul, de l'analyse jusqu'à la vente, voir [Produire un item T2 de A à Z](/help?page=playbooks/t2-product-a-to-z).

> Plusieurs libellés de l'application sont en anglais dans les deux langues (**Approve**, **Reject**, **Promote**, **Kick**, **Record Sale**, **Save**, **Add Contribution**). Ils sont cités ici tels quels. D'autres s'affichent sans accent en français (**Publie**, **Termine**) et sont cités de même.

## Ce que fait chaque rôle

| Rôle | Peut |
|---|---|
| **Proprietaire** | Tout ce que fait un admin, plus nommer un admin (**Promote**), lier les jobs et les achats du projet, supprimer le projet tant qu'il n'est pas terminé. Il ne peut ni quitter le projet ni être retiré. |
| **Admin** | Modifier le projet, changer son statut, accepter ou rejeter une demande, relire les contributions, enregistrer et corriger les ventes. Un admin ne gère pas un autre admin. |
| **Membre** | Soumettre des contributions et suivre sa part. |

## 1. Créer le projet

1. Menu latéral : **Industrie**, onglet **Projets**.
2. Cliquez sur **Nouveau projet** : la fenêtre **Nouveau projet de groupe** s'ouvre.
3. Ajoutez les items à produire (recherche, ou **Coller en lot**), réglez ME, TE et runs, puis validez avec **Creer le projet**.

Le détail des options (liste noire, structures, invention, modèles) est dans le guide [Produire un item T2 de A à Z](/help?page=playbooks/t2-product-a-to-z). Deux réglages comptent pour un groupe :

- **Projet privé** est coché par défaut. Un projet privé n'apparaît pas dans la découverte de la corporation et **personne ne peut le rejoindre avec le lien** (voir l'étape 3).
- **Containers corp** : si vous nommez des containers, les admins peuvent vérifier les apports de matériaux contre leur contenu (bouton **Tout verifier**, onglet **Approvisionnement & jobs**).

Le projet naît au statut **Brouillon**, et vous y êtes inscrit comme **Proprietaire**.

Les frais de vente du projet (**Frais courtier %** et **Taxe de vente %**) sont repris de vos compétences et de vos standings à la création. S'ils n'ont pas pu être lus, ils restent **inconnus** : l'icône crayon (infobulle **Modifier**) ouvre **Modifier le projet** où vous les saisissez. Les revenus nets et les payouts restent inconnus tant qu'ils manquent.

## 2. Publier

Dans l'en-tête du projet, le bouton **Publier** fait passer le projet de **Brouillon** à **Publie**. Un projet **Publie** et non privé apparaît dans l'onglet **Disponibles** (mention « meme corp ») des membres de votre corporation : ils le rejoignent sans approbation.

Le cycle de vie ne va que dans un sens :

| Statut affiché | Bouton pour y aller |
|---|---|
| **Brouillon** | (création) |
| **Publie** | **Publier** |
| **En cours** | **Démarrer** (depuis **Brouillon**) ou **Passer à En cours** (depuis **Publie**) |
| **En vente** | **Passer à En vente** |
| **Termine** | **Passer à Termine**, puis **Terminer** dans la fenêtre de confirmation |

Aucun retour en arrière n'est possible. Publier n'est pas obligatoire : **Démarrer** saute directement de **Brouillon** à **En cours**.

## 3. Inviter

Le lien d'invitation est de la forme `/industry/join/<code>`. Il se copie de deux façons : le bouton **Lien de partage** de l'en-tête (il s'appelle **Inviter** tant que vous travaillez seul), ou **Copy Join Link** et la carte **Lien d'invitation** dans l'onglet **Équipe**.

Le lien ne fonctionne que si **les deux** conditions sont réunies :

- le projet n'est **pas privé** : décochez **Projet privé** dans **Modifier le projet**, puis **Enregistrer**. Sinon la personne arrive sur **Adhésion impossible** avec le message « Project not found » (message de l'API, en anglais). Repasser le projet en privé plus tard garde les participants déjà acceptés ;
- le statut est **Publie** ou **En cours**. Un **Brouillon**, un projet **En vente** ou **Termine** refuse les nouveaux membres.

À l'ouverture du lien :

- un joueur de **votre corporation** est accepté d'office (la carte le rappelle : « Les membres de la meme corp sont auto-acceptes. ») ;
- tout autre joueur voit **Demande envoyée** et attend votre approbation (« Les autres joueurs devront etre approuves. »). Tant que sa demande n'est pas acceptée, il ne peut pas ouvrir le projet.

L'onglet **Équipe** et l'onglet **Contributions** sont masqués tant que vous êtes seul, sans demande en attente et sans contribution à relire. Ils apparaissent dès qu'une demande arrive.

## 4. Accepter les membres

1. Onglet **Équipe**, bloc **Demandes en attente** : le tableau donne **Personnage**, **Corporation**, **Demandé le** et **Via** (**Lien court**).
2. **Accepter** ajoute la personne comme **Membre**. **Rejeter** retire la demande. La personne peut redemander avec le lien, et sa nouvelle demande repasse en attente, même si elle est de votre corporation.
3. Dans la liste des membres, **Promote** (propriétaire seulement) nomme un admin, **Demote** le ramène à membre, **Kick** retire un membre du projet.

Un membre retiré garde ses contributions dans le projet, mais ses réservations de ligne sont libérées. Un membre peut quitter le projet de lui-même avec **Quitter** tant qu'il n'a soumis aucune contribution.

## 5. Démarrer et récolter les contributions

Passez le projet **En cours** quand l'approvisionnement commence (**Démarrer** ou **Passer à En cours**). Les contributions restent possibles dans tous les statuts jusqu'à **Termine**.

Sous l'en-tête, le bandeau **Phases du projet** (**Préparer**, **Approvisionner**, **Produire**, **Vendre**, **Clôturer**) et la carte **Prochaine action** vous disent quoi faire ensuite : relire des contributions, approvisionner un matériau, lancer un job, passer à la vente, enregistrer les ventes, clôturer.

Un membre peut d'abord réserver une part d'une ligne avec **Je m'en charge** (**Libérer** la rend). Il contribue depuis **Add Contribution** (onglet **Contributions**) ou depuis le bouton **Contribuer** d'une ligne de l'onglet **Approvisionnement & jobs**. Les types sont **Material**, **Job Install**, **BPC** et **Line Rental**. La valeur d'un apport se décide ainsi :

| Apport | Valeur |
|---|---|
| **Material** soumis à la main | Prix Jita du jour d'apport (percentile 5 %), lu au dépôt et conservé tel quel à la revue. Sans prix Jita, la soumission est refusée. |
| **Job Install**, **BPC** | Valeur saisie, supérieure à 0 ISK. |
| **Line Rental** | Valeur saisie. Une suggestion issue de votre barème de location s'affiche quand elle est calculable. |
| Achat lié ou job lié par le propriétaire | **prix payé (wallet)** pour un matériau, **coût réel (ESI)** pour un job. Approuvé d'office, badge **Auto-detected**. |

Ce n'est pas le membre qui lie ses jobs et ses achats : seul le propriétaire le fait. Un job lié et lancé par un autre participant lui est compté comme apport d'installation.

Un membre ne peut soumettre qu'une contribution en attente ou approuvée par ligne et par type, et jamais plus que la quantité restante de la ligne.

## 6. Relire les contributions

1. Onglet **Contributions**, colonne **Actions** : **Approve** ou **Reject** sur chaque ligne au statut **Pending**.
2. **Approve** compte l'apport dans la quantité réalisée de la ligne et dans le coût du projet. **Reject** ne change pas la ligne.
3. Un apport déjà approuvé qui porte le badge **Doublon avec un apport ESI** (le même apport a ensuite été détecté par l'ESI) propose encore **Reject**.

Règles à connaître :

- vous ne relisez pas votre propre contribution, sauf si vous êtes la seule personne à pouvoir relire (propriétaire sans autre admin) ;
- seuls les apports **approuvés** entrent dans la répartition ;
- une contribution **Pending** apparaît dans le compteur de l'onglet, et dans les vérifications avant clôture ;
- aucune relecture n'est possible une fois le projet **Termine**.

Un apport sans prix connu rend inconnus les totaux qui l'incluent : jamais un total partiel (message « Un apport n'a pas de prix connu »).

## 7. Lire la répartition

Onglet **Finances**. Les blocs, de haut en bas : **Du revenu au profit**, le résumé financier, la prévision de vente, le tableau des membres, **Virements entre membres** et **Récapitulatif Discord**.

Le tableau des membres donne, par personne :

| Colonne | Contenu |
|---|---|
| **Costs Engaged** | Somme de ses apports approuvés. |
| **Share %** | Ses apports divisés par le **Cout total du projet**. |
| **Profit Part** | Ses apports multipliés par la marge du projet. |
| **Payout Total** | Ses apports plus sa part de profit. |

La marge du projet est le profit divisé par le coût de production : (**Revenu net** moins le **Cout total du projet**) divisé par le **Cout total du projet**. Le **Revenu net** est le revenu total moins le **Frais courtier**, la **Taxe de vente** et les frais réels des contrats rattachés. Les formules détaillées sont dans [Profit et marge](/help?page=calculations/profit-and-margin) et le coût d'un job dans [Coût d'un job, bonus de structure et de rig, ME et TE](/help?page=calculations/job-cost-and-bonuses).

Les cas « inconnu » de cet écran :

- **En attente des ventes** : aucune vente enregistrée, donc ni revenu net, ni marge, ni payouts. Une **Marge provisoire** et une marge projetée au dernier prix de vente s'affichent quand des produits restent invendus et que leur dernier prix est connu ; un produit « jamais vendu » n'a pas de projection ;
- **Répartition impossible** : aucune contribution approuvée n'a de coût ;
- un frais de courtage ou une taxe de vente inconnus rendent le revenu net, la marge et les payouts inconnus. Seul le revenu des ventes au marché les exige ;
- un apport approuvé sans prix : coût de production, profit et payouts inconnus.

## 8. Passer à la vente et enregistrer les ventes

Quand la production est terminée, **Passer à En vente**. Les ventes s'enregistrent dans tous les statuts jusqu'à **Termine**, et seuls le propriétaire et les admins le font.

Onglet **Ventes** :

1. **Record Sale** ouvre le formulaire **Record a Sale** : l'item (la liste indique « restant à vendre »), **Quantity**, **Unit Price (ISK)**, le canal (**Ordre de marché** ou **Contrat**), **Venue** et le **Vendeur**. Validez avec **Save**.
2. Le bloc **Transactions à rattacher** liste les transactions wallet de vos personnages qui correspondent à un produit du projet. **Enregistrer comme vente** en fait une vente ; la mention **Doublon possible** signale une vente déjà enregistrée de même quantité et de même prix unitaire.
3. Le bloc **Rattacher un contrat** liste les contrats item exchange terminés de vos personnages. **Rattacher** fige la quantité, le prix et la date d'après le contrat ; les frais réels du contrat sont lus dans le journal wallet.
4. **Modifier** sur une ligne ouvre **Corriger une vente**, **Supprimer** retire la vente : la distribution est recalculée.

Le **Vendeur** compte : chaque vendeur garde le revenu net de ses ventes, et les virements en découlent. Une vente enregistrée par quelqu'un qui n'est plus participant accepté bloque le calcul des virements.

Les suggestions de transactions et de contrats ne remontent que ce qui date d'après la création du projet.

## 9. Virements et clôture

Les virements se calculent quand **tous** les produits sont vendus et que les frais et les coûts sont connus. Tant qu'un produit est invendu, ou qu'un frais ou un coût est inconnu, le bloc **Virements entre membres** dit pourquoi (par exemple « Des produits sont encore invendus »).

1. Dans **Virements entre membres**, copiez la **Référence de paiement** avec **Copier** et mettez-la en motif de chaque virement fait en jeu.
2. Le destinataire coche **Reçu**. La case se coche aussi seule quand son journal wallet montre le virement avec la référence et le montant complet : mention **Vérifié dans le journal wallet**. Vous ne cochez jamais à la place du destinataire.
3. Si rien n'est à virer, le bloc l'indique : chacun détient déjà son payout. Un écart d'arrondi est absorbé par le plus gros payout.
4. **Copier pour Discord** (bloc **Récapitulatif Discord**) produit un message prêt à coller. Rien n'est posté pour vous.

Pour terminer :

1. Cliquez sur **Passer à Termine** : la fenêtre **Terminer le projet** s'ouvre.
2. Lisez **Avant de terminer** : contributions en attente de revue, **Tout est vendu** ou **Produits invendus**, **Marge finale** (ou « Marge finale inconnue : un coût ou un frais manque »), **Virements cochés comme reçus**. Ces vérifications informent, elles **ne bloquent pas** la clôture.
3. **Terminer** fige les comptes. C'est irréversible : plus de contribution, de revue, de vente ni de changement de frais, et le projet ne peut plus être supprimé. Les réservations de ligne sont libérées.

Une fois le projet **Termine**, l'onglet **Approvisionnement & jobs** montre le surplus de matières.

## Pièges connus

- **Le lien ne marche pas** : le projet est resté privé (réglage par défaut), ou il est encore **Brouillon**. Vérifiez les deux.
- **Un membre d'une autre corporation ne voit rien** : sa demande est en attente, il n'ouvre le projet qu'après votre **Accepter**.
- **Les onglets Équipe et Contributions manquent** : ils sont masqués tant que vous êtes seul, sans demande ni contribution à relire.
- **Payouts « inconnus »** : cherchez un frais manquant dans **Modifier le projet**, ou une contribution approuvée sans prix.
- **Tout le monde ne peut pas quitter** : le bouton **Quitter** disparaît dès qu'un membre a soumis une contribution ; **Kick** reste à votre disposition.
