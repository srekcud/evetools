# Produire un item T2 de A à Z : de l'analyse à la marge réelle

Ce guide suit un item T2 depuis le choix du produit jusqu'à la marge lue dans le projet terminé. Les noms d'écrans et de boutons sont ceux de l'application en français, orthographe comprise (certains libellés n'ont pas d'accent, quelques-uns restent en anglais : ils sont signalés).

À la fin, vous aurez : choisi un produit sur ses chiffres, créé le projet, apporté vos matériaux au prix réel, lancé les jobs en jeu, enregistré les ventes et lu la marge réelle.

Les structures et le système favori (étape 4 de [Premiers pas](/help?page=playbooks/first-steps), guide [Configurer ses structures industrielles](/help?page=playbooks/industry-structures)) doivent être renseignés avant : sans structure, le plan est calculé sans bonus.

## Étape 1 : choisir le produit

Menu **Production** › **Industrie**, onglet **Analyse**. Quatre sous-onglets : **Marges**, **Scan**, **Achat vs Prod**, **Pivot**.

- **Marges** : tapez le produit dans « Rechercher un produit (ex: Nomad, Raven...) », réglez **Runs**, **ME**, **TE** et **Systeme solaire**, puis cliquez sur **Analyser**. Pour un item T2, ME et TE sont grisés : l'écran affiche « Item T2 detecte. ME/TE sont definis par le decrypteur d'invention » et démarre à ME 2 / TE 4.
- **Scan** : le **Scanner de Profits** classe tous les items fabricables ; cliquer sur une ligne ouvre l'item dans **Marges**.

Ce que vous lisez dans **Marges** :

- **Cout total** : matériaux, installation des jobs et, pour un T2, l'invention.
- **Meilleur prix de vente**, **Profit** et **Marge**, puis le tableau **Comparaison prix de vente** par lieu (colonnes Lieu, Prix unitaire, Revenu, Frais, Profit, Marge).
- **Où vendre** : prix, volume et jours pour tout vendre, par marché.
- Pour un T2, le panneau **Invention** compare les décrypteurs (tentatives, coût par unité, écart avec « sans décrypteur ») ; choisissez-en un dans la liste.

La **Marge** se calcule toujours ainsi : `profit ÷ coût total`, avec `profit = revenu − frais − coût total`. La note sous le tableau « Comparaison prix de vente » dit « Marge = profit / revenu » : ce libellé est périmé, le calcul divise bien par le coût.

Un chiffre manquant (prix, indice de coût, compétence d'inventeur, frais de vente) s'affiche « inconnu », jamais 0. Voir [Des chiffres qui semblent faux](/help?page=faq/numbers-that-look-wrong). Les frais de vente viennent des compétences et des standings de votre personnage principal : [Industrie, FAQ](/help?page=faq/industry).

## Étape 2 : créer le projet

**Créez le projet avant d'acheter ou de lancer quoi que ce soit.** Les transactions du wallet proposées pour les achats et les ventes, et les jobs rapprochés du projet, ne remontent que s'ils datent d'après la création du projet.

1. Dans **Marges**, après l'analyse, cliquez sur **Créer le projet**. Vous arrivez sur **Projets** avec la fenêtre **Nouveau projet de groupe** déjà remplie (produit, runs, ME, TE). Sans analyse : onglet **Projets**, bouton **Nouveau projet**, puis **Ajouter un item**, **Coller en lot** ou **Depuis un modèle**.
2. Vérifiez les runs et, pour un T2, laissez ou non cochée **Exclure l'invention** : cochée, le plan ne contient ni invention, ni copie T1, ni datacores, et les BPC T2 sont à fournir.
3. Options utiles : **Nom** (facultatif), **Usage personnel (pas de vente)** (les finances montrent alors un coût par unité à la place du profit), **Projet privé**, **Liste noire** (les items cochés sont achetés, pas fabriqués).
4. Si vous avez terminé, il y a moins d'un mois, un projet de ce seul produit, une ligne **Référence** donne son coût unitaire et sa marge. Un chiffre inconnu reste « inconnu ».
5. Cliquez sur **Creer le projet** : vous arrivez sur le projet, au statut **Brouillon**.

Un projet que vous menez seul est un projet solo : les onglets **Contributions** et **Équipe** sont masqués, et vos apports sont approuvés dès le dépôt.

## Étape 3 : lire le projet et le démarrer

Le bandeau des phases (**Préparer**, **Approvisionner**, **Produire**, **Vendre**, **Clôturer**) et la carte **Prochaine action** disent où en est le projet. Les onglets utiles : **Approvisionnement & jobs**, **Production**, **Invention**, **Ventes**, **Finances**.

Dans l'en-tête, cliquez sur **Démarrer** (ou **Publier** pour ouvrir le projet à d'autres). Le projet passe à **En cours**. Il n'y a pas d'autre condition : vous pouvez démarrer avant d'avoir tout acheté.

## Étape 4 : apporter les matériaux au prix réel

Onglet **Approvisionnement & jobs**. Le tableau **Materiaux a sourcer** donne, par matériau, **Requis**, **Fourni**, **Restant** et **Cout est.**. Le bloc **Où acheter les matériaux** compare **Jita + import** et **Structure** et indique **Acheter à**. Achetez en jeu.

Pour chaque matériau, deux façons de l'enregistrer :

- **Achats** (propriétaire seulement) : la fenêtre « Achats de *matériau* » liste les **Transactions du wallet** de vos personnages ; **Lier** rattache une transaction. Sans transaction visible, la section **Achat manuel** (quantité, prix unitaire, **Ajouter un achat**) l'enregistre tout de suite. Une ligne de chip rappelle l'état de la lecture (« Wallet lu à … · prochaine lecture vers … », « Wallet jamais lu », « Scope wallet manquant », « Pas de token »). Le bouton **Actualiser le wallet** relit sans attendre les personnages dont le cache ESI est échu. Valeur de l'apport : le **prix payé** ; dans un projet de groupe, l'achat est d'abord une proposition d'apport, qui compte à la confirmation de livraison (voir [valeur des apports](/help?page=calculations/group-contribution-and-distribution)).
- **J'ai fourni** : pour un matériau que vous avez déjà en stock. La fenêtre **Ajouter une contribution** valorise la quantité au **prix Jita du jour d'apport (percentile 5 %)**, figé au dépôt. Libellés en anglais dans cette fenêtre : **Material**, **Job Install**, **BPC**, **Line Rental**.

Une transaction déjà liée à un autre projet n'est pas proposée. Un prix inconnu donne un apport de valeur inconnue, jamais 0 : les totaux qui l'incluent sont inconnus aussi. Détail : [Projets de groupe, FAQ](/help?page=faq/group-projects).

Pour un T2, l'onglet **Invention** compare les décrypteurs en lecture seule : rien n'est enregistré et le plan du projet ne change pas. Dans l'onglet **Approvisionnement & jobs**, la carte **BPC T2 possédés** (bouton **Déclarer mes BPC T2**) sert à déclarer vos BPC T2 déjà possédés, et **Utiliser mon stock** à déclarer vos intermédiaires en stock (propriétaire, projet non terminé).

## Étape 5 : lancer les jobs en jeu

Les jobs se lancent dans le jeu, pas dans EVE Tools. L'application les rapproche des lignes du projet :

- **automatiquement** après chaque synchronisation des jobs, pour les projets non terminés dont vous êtes participant accepté. Seuls les jobs lancés après la création du projet comptent ;
- à la demande : dans le tableau **Jobs a lancer**, bouton **Associer les jobs** (propriétaire) : il relit d'abord les jobs ESI de vos personnages, puis les rapproche ;
- à la main : **Lier un job** sur une ligne (**Délier** pour défaire).

La valeur d'un job est son **Coût du job** donné par l'ESI (« coût réel (ESI) »), un apport par participant qui a lancé le job. Un coût que l'ESI ne donne pas reste inconnu. Fréquence de la synchronisation : [Fréquences de synchronisation](/help?page=data/sync-frequencies) ; un job tout juste terminé peut mettre un moment à apparaître (voir [Des chiffres qui semblent faux](/help?page=faq/numbers-that-look-wrong)).

Onglet **Production** : la chaîne par niveau avec le statut de chaque job (**À lancer**, **En cours**, **Terminé**) et la colonne **Coût réel**. Le propriétaire peut aussi y poser une **Marque** (**Lancé** ou **Fait**, avec un nombre de runs) sur une étape, et la retirer (**Retirer la marque**).

Quand tout est produit, la carte **Prochaine action** propose **Passer à la vente**.

## Étape 6 : vendre et enregistrer les ventes

Passez le projet à la vente : bouton **Passer à En vente** dans l'en-tête (ou **Passer à la vente** sur la carte). Vendez en jeu, puis onglet **Ventes** :

- **Mes ordres de vente** : vos ordres sur les produits du projet, avec votre prix, le meilleur prix et votre position (EVE Tools ne modifie jamais un ordre : le bouton copie seulement un prix) ;
- **Transactions à rattacher** : les ventes de votre wallet, faites depuis la création du projet, sur les produits du projet et jamais rattachées ; **Enregistrer comme vente** crée la vente. Un badge **Doublon possible** signale une vente déjà saisie à la main avec la même quantité et le même prix ;
- **Rattacher un contrat** : un contrat item exchange terminé ; quantité, prix et date sont figés d'après le contrat ;
- à la main (propriétaire ou admin) : bouton **Record Sale** (anglais), puis **Item**, **Quantity**, **Unit Price (ISK)**, **Canal** (**Ordre de marché** ou **Contrat**), **Venue**, **Vendeur**, **Save**.

Une vente peut s'enregistrer tant que le projet n'est pas terminé. Les frais viennent du projet : l'icône crayon de l'en-tête (infobulle **Modifier**) ouvre **Modifier le projet**, qui montre **Frais courtier %** et **Taxe de vente %**. Laissés inconnus (compétences et standings du propriétaire non synchronisés), le revenu net, le profit et la marge restent inconnus.

## Étape 7 : lire la marge réelle

Onglet **Finances** :

- **Du revenu au profit** : revenu, courtage, taxe de vente, matériaux, installation des jobs, BPC, location de lignes, courrier, puis **Profit** (ou **Perte**) ;
- **Résumé financier** : **Profit** (ou **Profit provisoire** tant que tout n'est pas vendu) et **Marge** ;
- **Seuil de rentabilité** : le prix par unité sous lequel vous perdez (calculé seulement pour un projet à un seul produit final, quand tous les coûts et frais sont connus) ;
- **Prévision de vente** pour ce qui reste à vendre.

Sans aucune vente, le bandeau « Aucune vente enregistrée » rappelle que revenu, profit et marge seront connus à la première vente. Avec des ventes partielles, la marge est provisoire : les invendus sont valorisés au prix de la vente la plus récente.

La **Marge** réelle est `(revenu net − coût total du projet) ÷ coût total du projet`, le coût total étant la somme des apports approuvés (achats au prix payé, jobs au coût ESI, stock au prix Jita du jour d'apport). C'est la même définition que dans **Marges** : comparez les deux pour voir l'écart entre l'analyse et la réalité.

## Étape 8 : terminer le projet

Quand tout est vendu, cliquez sur **Passer à Termine** (ou **Clôturer le projet** sur la carte). La fenêtre **Terminer le projet** liste **Avant de terminer** : produits invendus (« Tout est vendu » quand il n'en reste pas) et marge finale (« Marge finale : … % », ou « Marge finale inconnue : un coût ou un frais manque »). Confirmez avec **Terminer**.

Terminer fige les comptes : plus de contribution, de vente ni de changement de frais, et le projet ne peut plus être supprimé. Cette action est irréversible. S'il ne contient que ce produit, il servira de **Référence** à la création d'un projet du même produit pendant un mois.
