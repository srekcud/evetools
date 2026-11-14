# Fabriquer un produit T2, de l'analyse à la marge réelle

Ce playbook suit un produit T2 de bout en bout : choisir quoi produire dans l'analyse, créer le projet, acheter les matériaux, lancer les jobs (invention comprise), enregistrer la vente et lire la marge réelle. Les étapes sont dans l'ordre où vous les faites. Un projet fabriqué seul est un projet comme un autre : il ne diffère d'un projet de groupe que par l'accès (à plusieurs, voir [propriétaire d'un projet de groupe](/help?page=playbooks/group-project-owner)).

Les libellés entre guillemets « » sont ceux de l'écran. Quelques-uns sont écrits sans accent ou en anglais dans l'application (« Cout total », « Record Sale ») et sont repris tels quels.

## Avant de commencer

- Compétences et standings synchronisés (« Sync toutes ») : sans eux, les frais de vente du projet sont inconnus et la probabilité d'invention de l'analyse peut l'être. Voir [Premiers pas](/help?page=playbooks/getting-started), étape 5.
- Au moins une structure de production déclarée, avec sa taxe d'installation : [Configurer ses structures industrielles](/help?page=playbooks/industry-structures).
- Faites ces deux points avant l'étape 2. Les frais du projet sont copiés depuis votre personnage principal au moment de la création, et ils ne sont jamais recalculés ensuite (voir l'étape 2).

## 1. Analyser

Menu « Industrie », onglet « Analyse », sous-onglet « Marges ». Sous les sous-onglets, trois étiquettes donnent l'âge des données : « Prix Jita », « Cost index », « Prix ajustés » (voir [fraîcheur des données](/help?page=data/freshness-privacy)).

1. Dans « Analyser la marge de profit », cherchez le produit. Un T2 porte l'étiquette « T2 ».
2. Réglez « Runs » et, si besoin, « Systeme solaire » (« Par défaut » ou l'un de vos systèmes favoris). Pour un T2, « ME » et « TE » sont grisés, avec 2 et 4 au départ : ils suivent le décrypteur que vous choisissez plus bas.
3. Cliquez sur « Analyser ».

Ce que vous lisez :

- Quatre cartes : « Cout total » (avec « par unite »), « Meilleur prix de vente », « Profit » et « Marge ». Les trois dernières portent sur le meilleur lieu de vente, nommé sous la carte « Marge ».
- Le tableau « Comparaison prix de vente » : une ligne par lieu qui a un prix (Jita, votre structure de marché en « sous-enchere » puis en « vente instantanee », The Forge en « contrat »), avec « Prix unitaire », « Revenu », « Frais », « Profit », « Marge » et « Volume quotidien ».
- « Où vendre » compare Jita et votre structure : prix, volume, frais, « Net / u » et « Pour tout vendre ».
- Pour un T2, le panneau d'invention (« Impact du decrypteur sur le profit ») : choix de l'« Inventeur » (votre personnage principal au départ, ou « Sans competences »), puis une ligne par décrypteur (« Aucun » compris) avec probabilité, « Tentatives », « Jobs », « Cout / unite » et « Meilleure marge ». Cliquer sur une ligne choisit ce décrypteur et relance l'analyse. Ce choix ne sert qu'à comparer : il n'est pas enregistré dans le projet.
- En dessous : « Detail des couts materiaux » (prix « Prix moyens ponderes Jita »), « Cout d'installation » par job, et « Resume des couts ».

Si un prix, un cost index, une taxe d'installation ou les compétences de l'inventeur manquent, le coût total s'affiche « Inconnu » avec sa cause, et le tableau de comparaison est remplacé par cette cause. Si les frais de vente sont inconnus, l'en-tête du tableau affiche « Frais inconnus », un bandeau en donne la cause, et « Frais », « Profit » et « Marge » valent « Inconnu ». Aucune de ces valeurs n'est un zéro.

D'où viennent les chiffres : [coût d'un job](/help?page=calculations/job-cost), [prix pondéré](/help?page=calculations/weighted-price-appraisal), [frais de marché](/help?page=calculations/market-fees), [marge](/help?page=calculations/margin).

## 2. Créer le projet

Au-dessus des résultats, cliquez sur « Créer le projet ». Vous arrivez sur « Projets » avec la fenêtre « Nouveau projet de groupe » déjà remplie (le titre dit « de groupe » même pour un projet seul). Vous pouvez aussi ouvrir « Projets » et cliquer sur « Nouveau projet ».

1. Dans le tableau des items, contrôlez le produit, « ME », « TE » et « Runs ». Le ME vient de l'analyse. Le TE est à 4 pour un T2, quel que soit le décrypteur : corrigez-le si votre BPC T2 est différent.
2. « Nom » est facultatif.
3. « Projet privé » est coché par défaut : le projet n'apparaît pas dans la découverte de la corporation. Laissez-le ainsi si vous travaillez seul.
4. « Usage personnel (pas de vente) » : ne le cochez que si vous ne vendez pas. Le projet montre alors un coût par unité à la place du profit, et ce choix ne se change plus après la création.
5. « Exclure l'invention » : les BPC T2 sont à fournir, le plan ne contient ni invention, ni copie T1, ni datacores. Ce choix ne se change qu'en brouillon.
6. « Structure de vente » : par défaut votre structure de marché préférée. « Liste noire » : les catégories cochées sont achetées et non fabriquées (vos réglages Industrie en pré-cochent).
7. Cliquez sur « Creer le projet ».

Le projet s'ouvre au statut « Brouillon ». Trois points à retenir :

- **Produits, runs, ME et TE ne se modifient plus après la création.** Pour les changer, supprimez le projet (possible tant qu'il n'a ni apport approuvé ni vente) et recréez-le.
- **Créez le projet avant d'acheter et de lancer les jobs.** Les achats du wallet et les jobs ESI antérieurs à la création du projet ne sont ni rattachés ni proposés au rattachement (un achat manuel reste possible).
- **Frais de vente.** « Frais courtier % » et « Taxe de vente % » sont lus à la création depuis les compétences et standings de votre personnage principal. S'ils étaient inconnus à ce moment-là, ils le restent, même si vous synchronisez ensuite : saisissez-les avec le bouton « Modifier » de l'en-tête. Détail : [frais de marché](/help?page=calculations/market-fees).

## 3. Lire la page du projet

De haut en bas :

- l'en-tête, avec le statut, les boutons « Publier » et « Démarrer » (en brouillon) et les icônes « Modifier » et « Supprimer » ;
- le rail des phases : « Préparer », « Approvisionner », « Produire », « Vendre », « Clôturer », chacune avec un compteur ;
- « Prochaine action » : l'unique chose à faire maintenant, avec son bouton ;
- les onglets. Seul, vous voyez « Approvisionnement & jobs » (ouvert par défaut), « Finances », « Ventes », « Production », « Planning », « Journal » et « Invention ». « Contributions » et « Équipe » sont masqués tant que vous êtes le seul participant.

Quand le plan vous convient, cliquez sur « Démarrer » (le projet passe « En cours »). Ignorez « Publier », réservé aux projets que d'autres doivent rejoindre.

## 4. Acheter les matériaux

Onglet « Approvisionnement & jobs ». « Materiaux a sourcer » liste les matériaux à acheter (la BOM ne contient que ces feuilles ; composants et blueprints sont dans « Jobs a lancer »), avec « Requis », « Fourni », « Restant », « Progression » et « Cout est. ».

- **« Cout est. » n'est pas le prix de l'analyse.** C'est le prix percentile 5 % de Jita lu à la création du projet, et il n'est jamais rafraîchi. L'analyse utilise le prix pondéré de la quantité. Un matériau sans prix affiche « --- ».
- **« Où acheter les matériaux »** compare, pour la quantité restante, « Jita + import » et « Structure » (votre structure de marché préférée), et indique « Acheter à ». Sans structure préférée, ou si son marché n'est pas synchronisé, tout part à Jita ; sans système favori pour l'activité, l'import est inconnu. Les boutons « Copier Jita (N) » et « Copier <structure> (N) » copient les quantités **restantes**, une ligne « nom, tabulation, quantité » par matériau.
- Le bouton « Copier le multibuy » en haut de « Materiaux a sourcer » copie la quantité **requise** de toutes les lignes, pas le restant. « Évaluer dans l'Évaluateur » envoie la même liste à l'évaluateur.

Après l'achat en jeu, il faut que l'achat entre dans le projet :

- **Rattachement automatique.** Le planificateur passe toutes les 5 minutes, mais un personnage n'est relu que quand sa lecture est due : la pastille « Wallet lu à … · prochaine lecture vers … » de la fenêtre « Achats » vous dit où il en est, et le bouton « Actualiser le wallet » lance la lecture sans attendre, pour les personnages dont le cache ESI est échu (sinon il indique la prochaine lecture). Un nouvel achat est rattaché seul quand une seule ligne restante de vos projets ouverts (matériau ou composant) porte son type et que sa quantité tient dans le restant de la ligne.
- **Rattachement à la main.** Sous « Fourni », le bouton « Achats » (propriétaire seul) ouvre « Achats de … » : « Transactions du wallet » avec « Lier », ou « Achat manuel » (« Quantité », « Prix unitaire », « Ajouter un achat »). Sur une ligne de composant, « Achats » est sous le nom de la ligne.
- Un achat lié (« N achetés ») devient un apport valorisé au **prix réellement payé**. Seul à relire, il fait avancer « Fourni » tout de suite ; dans un projet de groupe, c'est d'abord une proposition d'apport qui n'y entre qu'à la confirmation de livraison (container du projet ou confirmation à la main). Voir [contributions de groupe](/help?page=calculations/group-contribution-and-distribution).
- **« J'ai fourni »** sert à déclarer un stock que vous aviez déjà. Il est approuvé tout de suite, mais valorisé au prix Jita (percentile 5 %) du jour de la déclaration, pas à ce que vous avez payé, et sans saisie de valeur. Ne l'utilisez pas sur une ligne dont vous liez aussi l'achat : les deux comptent (doublon d'apport) et l'onglet « Contributions », où on les voit, est masqué quand vous êtes seul.

Quand tous les matériaux sont couverts, l'approvisionnement (dont « Où acheter les matériaux ») se replie ; « Afficher l'approvisionnement » le rouvre.

## 5. Lancer les jobs

Même onglet, « Jobs a lancer », en trois groupes : « Blueprints » (copie T1 et invention), « Composants » et « Produits finaux ». L'onglet « Production » montre l'ordre à suivre : un job est « Bloqué » tant qu'un composant n'est pas « Terminé », et « Matériaux manquants » quand ses sous-composants sont faits mais pas ses matériaux. Lancez les jobs en jeu, puis :

- **Fabrications et réactions.** Les jobs ESI de vos personnages sont rattachés à chaque synchro des jobs (toutes les 30 minutes), ou quand vous cliquez sur « Associer les jobs » (propriétaire seul). Un job est retenu s'il utilise le blueprint de la ligne, a démarré après la création du projet, a une production (ni annulé ni revenu) et que ses runs tiennent dans le reste de la ligne (plus, au besoin, un seul job plus gros). Le bouton « Lier un job » corrige à la main. Le coût d'installation lu dans l'ESI devient un apport « Job Install » ; si l'ESI ne donne pas de coût, il est inconnu, jamais 0.
- **Invention et copie T1.** Aucun job ESI ne s'y rattache. Le nombre de « Tentatives » du plan est le nombre de runs à inventer divisé par (runs du BPC inventé × probabilité de base), arrondi au supérieur, sans compétences ni décrypteur : l'analyse, qui en tient compte, peut donner un autre chiffre. Pour que ces lignes comptent et que leur coût entre dans le projet, cliquez sur « J'ai fourni » sur la ligne : le type « BPC » est présélectionné, la quantité est préremplie, et vous saisissez une « Valeur estimee » supérieure à 0, c'est-à-dire ce que les datacores et l'invention vous ont coûté. Si vous avez déjà des BPC T2, ouvrez plutôt « BPC T2 possédés » (« Déclarer mes BPC T2 ») : les runs déclarés sortent du plan. Ce panneau n'apparaît que si le plan trouve des BPC T2 dans vos blueprints synchronisés.
- **« Marquer ».** Dans l'onglet « Production », vue « Arbre des jobs », le propriétaire peut marquer une ligne « Lancé » ou « Fait » pour un nombre de runs. La marque change le statut de la ligne et le nombre de runs restants, mais ne crée aucun coût. Piège : elle ne fait pas avancer le compteur « Produire » des phases. Une ligne n'y compte comme livrée que si ses jobs liés sont livrés ou si ses apports couvrent sa quantité. Après une marque « Fait » sur une invention, « Prochaine action » peut donc rester sur « Lancer un job ». Le bouton « Passer à En vente » de l'en-tête reste disponible.

Dans l'onglet « Production », « Acheter ou fabriquer » compare chaque intermédiaire fabriqué à son achat Jita plus import, et « Passer en achat » le retire de la fabrication.

## 6. Vendre

Quand les jobs sont faits, cliquez sur « Passer à En vente » (« Prochaine action » propose « Passer à la vente », qui fait de même). Les ventes s'enregistrent dès qu'elles ont lieu, tant que le projet n'est pas terminé.

Onglet « Ventes ». « Mes ordres de vente » montre vos ordres ouverts sur les produits du projet (lus toutes les 5 minutes) et votre position (« 1er » ou « sous-coté depuis … »). Il est en lecture seule : EVE Tools ne modifie aucun ordre. Pour enregistrer une vente :

- **« Transactions à rattacher »** : ventes de votre wallet sur les produits du projet, depuis sa création, pas encore rattachées. « Enregistrer comme vente » reprend la transaction ; « Quantité à enregistrer » permet d'en enregistrer une partie ; « Doublon possible » signale une vente déjà saisie de même quantité et de même prix.
- **« Rattacher un contrat »** : contrats item exchange terminés de vos personnages. Quantité, prix et date sont figés d'après le contrat.
- **Saisie à la main** : bouton « Record Sale », puis « Item », « Quantity », « Unit Price (ISK) », « Canal » (« Ordre de marché » ou « Contrat »), « Venue », « Vendeur » et « Save » (ces libellés sont en anglais dans l'application).

Le canal compte. Une vente par « Contrat » ne paie pas le courtage ni la taxe de vente du projet ; ses frais de contrat sont lus dans le journal du wallet, et inconnus s'ils n'y sont pas. Sous le tableau, une ligne par produit donne « N / M vendus » (unités vendues sur unités produites).

## 7. Lire la marge réelle et terminer

Onglet « Finances ». « Résumé financier » donne le « Revenu total », les « Frais courtier » et la « Taxe de vente » (avec leur pourcentage), le « Revenu net », le « Cout total du projet » détaillé en « Matériaux », « Installation jobs + location ligne » et « BPC », puis le « Profit » et « Marge : ». Avant la première vente, le revenu total affiche « Aucune vente » et le revenu net, le profit et la marge « En attente des ventes ». Avec des ventes partielles, le profit devient « Profit provisoire » et, plus bas, « Marge provisoire » liste les « Invendus : » et la « Marge projetée au dernier prix de vente ».

- **Marge = profit ÷ coût total du projet.** Exemple (valeurs rondes) : un revenu net de 120 millions pour un coût de 100 millions donne un profit de 20 millions et une marge de 20 %, pas 16,7 %. Le texte d'aide du tableau de l'analyse dit « Revenu = prix unitaire x runs » et « Marge = profit / revenu » : les deux sont inexacts. Le revenu est le prix unitaire multiplié par les unités produites (pas par les runs), et le calcul est bien profit ÷ coût, dans l'analyse comme dans le projet. Détail : [marge](/help?page=calculations/margin).
- **Le coût est la somme des apports approuvés, rien d'autre.** Un matériau de votre hangar jamais déclaré, une invention jamais saisie : ils n'y sont pas, et la marge est alors trop belle. Si un seul apport a une valeur inconnue, le coût, le profit et la marge sont inconnus (jamais partiels). Détail : [contributions de groupe](/help?page=calculations/group-contributions).
- **Frais inconnus : pas de marge.** Si « Frais courtier % » ou « Taxe de vente % » du projet est vide et qu'une vente passe par le marché, le revenu net, le profit et la marge sont inconnus. Une vente par contrat n'en a pas besoin.
- « Seuil de rentabilité par unité » (dans « Du revenu au profit ») est inconnu tant que les jobs finaux ne sont pas tous livrés, ou qu'un apport ou les frais sont inconnus.

Pour clore, cliquez sur « Passer à Termine ». La fenêtre « Terminer le projet » liste « Avant de terminer » : contributions en attente, produits invendus et « Marge finale : … % » (ou « Marge finale inconnue : un coût ou un frais manque »). Terminer fige les comptes : plus de vente, d'apport ni de changement de frais, et le projet ne peut plus être supprimé. Cette action est irréversible.

## Ce que vous avez lu, et d'où ça vient

| Chiffre | Où | À savoir | Page |
|---|---|---|---|
| « Cout total », « Cout d'installation » | Analyse | Estimation à partir des cost index et prix ajustés ESI | [coût d'un job](/help?page=calculations/job-cost) |
| Prix des matériaux | Analyse | Prix pondéré de la quantité | [prix pondéré](/help?page=calculations/weighted-price-appraisal) |
| « Cout est. » | Projet, « Materiaux a sourcer » | Percentile 5 %, figé à la création | [prix pondéré](/help?page=calculations/weighted-price-appraisal) |
| « Frais courtier », « Taxe de vente » | Analyse et « Finances » | Copiés à la création du projet, jamais recalculés | [frais de marché](/help?page=calculations/market-fees) |
| « Marge » | Analyse et « Finances » | Profit ÷ coût ; estimée d'un côté, réelle de l'autre | [marge](/help?page=calculations/margin) |
| « Cout total du projet » | « Finances » | Somme des apports approuvés | [contributions de groupe](/help?page=calculations/group-contributions) |

## Et ensuite

- Un projet à plusieurs : [propriétaire](/help?page=playbooks/group-project-owner) ou [participant](/help?page=playbooks/group-project-member).
- Suivre vos ordres de vente : [ordres de marché](/help?page=playbooks/market-orders).
- Un chiffre reste « inconnu » : [limites connues](/help?page=limits/known-limits).
