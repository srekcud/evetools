# Questions fréquentes

Des réponses courtes aux questions qui reviennent. Chacune renvoie vers le playbook ou la page de calcul qui détaille. Une règle vaut pour tout EVE Tools : quand une donnée manque, l'écran dit « inconnu ». Il ne met jamais 0 à la place.

## Compte et personnages

### Pourquoi EVE Tools demande-t-il autant de scopes ESI ?

Tous les scopes sont demandés en une seule fois, à chaque connexion d'un personnage. Un personnage autorisé avant l'ajout d'un nouveau scope affiche « Scopes manquants » tant que vous ne l'avez pas re-autorisé.

Certains scopes ne servent à aucune fonction pour l'instant : le code ne lit ni les notifications EVE, ni la flotte, ni la localisation, ni le statut en ligne, ni les killmails. Le scope `esi-planets.manage_planets.v1` a un nom trompeur : EVE Tools ne fait que lire vos colonies.

Le tableau scope par scope, avec ce que chacun alimente, est dans la page sur [la fraîcheur et la confidentialité des données](/help?page=data/freshness-privacy).

### Comment ajouter un personnage secondaire ?

Page Personnages, bouton « Ajouter un personnage ». Vous passez par EVE SSO, vous choisissez le personnage, il est rattaché à votre compte et une synchro de ses assets démarre. Un personnage déjà rattaché à un autre compte EVE Tools est refusé.

Pour en retirer un, utilisez l'icône « Retirer le personnage » de sa carte. Ses données en cache sont supprimées. Le personnage principal ne se retire pas : désignez-en d'abord un autre.

Pas à pas dans [Premiers pas](/help?page=playbooks/getting-started).

### Comment changer de personnage principal ?

Sur la carte d'un personnage secondaire, l'étoile (info-bulle « Definir comme principal »), puis vous confirmez. Le choix pèse plus qu'il n'en a l'air, car le personnage principal fixe :

- votre corporation pour EVE Tools : découverte des projets de groupe et acceptation automatique (voir plus bas) ;
- le seul nom que voient les autres participants d'un projet, jamais celui de vos personnages secondaires ;
- les frais de vente copiés dans vos nouveaux projets, calculés avec ses compétences Accounting et Broker Relations et ses standings.

Si le nouveau principal est dans une autre corporation, la fenêtre de confirmation vous prévient que votre corporation de référence change.

### Mon personnage est « à reconnecter » : que faire ?

Le jeton d'accès se renouvelle tout seul. Ce qui casse, c'est l'autorisation : EVE SSO refuse le renouvellement quand vous avez révoqué l'application ou que le jeton a expiré. Le personnage sort alors des synchros, les autres continuent, et l'Accueil affiche dans « À faire » une ligne « personnage à reconnecter » qui mène à la page Personnages.

Pour réparer, utilisez « Re-autoriser » quand le bouton est affiché. Sinon, « Ajouter un personnage » et choisissez le même personnage dans EVE SSO : son jeton est remplacé, il n'est pas dupliqué.

Piège : le badge « Token valide » de la page Personnages ne regarde que les scopes et le déchiffrement du jeton. Il ne détecte pas une autorisation révoquée. Fiez-vous à l'Accueil.

### Qui voit mes données ?

Vos assets, votre wallet, vos compétences, vos jobs et vos colonies PI sont lus pour votre compte et affichés à votre compte. Ce qui est partagé :

- **avec les participants d'un projet de groupe** : votre personnage principal (nom, corporation, rôle), vos contributions et leur valeur, les jobs liés au projet que vous avez lancés (statut, runs, coût, structure) sous le nom de votre principal, les ventes que vous saisissez ;
- **avec votre corporation** : vos projets publiés non privés, les modèles de projet que vous choisissez de partager, la configuration (type, sécurité, rigs, taxe d'installation) d'une structure appartenant à votre corporation que vous avez importée ;
- **les assets et blueprints de corporation** : ils sont lus avec le jeton d'un personnage Director connecté, et seul un Director règle quelles divisions sont visibles (tous les membres, une liste de joueurs ou aucune).

La liste exhaustive, module par module, est dans [la page sur la confidentialité des données](/help?page=data/freshness-privacy). Côté projet de groupe : [participant](/help?page=playbooks/group-project-member).

### Comment supprimer mon compte ?

Page Personnages, « Zone de danger », « Supprimer mon compte ». Pour confirmer, vous saisissez le nom exact de votre personnage principal. La suppression est immédiate et irréversible.

- **Supprimés** : tous vos personnages, vos jetons EVE SSO, vos données personnelles (assets, compétences, jobs, transactions, PI, alertes, notifications, réglages), vos modèles de projet, et les projets dont vous êtes propriétaire quand aucun autre participant accepté ne peut les reprendre.
- **Transférés** : un projet dont vous êtes propriétaire, avec d'autres participants acceptés, passe à l'admin le plus ancien, sinon au participant accepté le plus ancien. Ses jobs et achats liés sont vidés et son contrat de courrier délié.
- **Conservés, anonymisés en « Pilote supprimé »** : vos contributions approuvées et les ventes que vous avez enregistrées dans les projets des autres.

Les jetons sont révoqués auprès de CCP au mieux : si la révocation échoue, le compte est supprimé quand même et l'échec n'est journalisé que côté serveur.

## Chiffres qui semblent faux

### Pourquoi « inconnu » au lieu d'un prix ou d'une marge ?

Parce qu'un chiffre plausible mais faux est pire qu'un trou. Dès qu'une donnée manque, le résultat qui en dépend est « inconnu », et une somme qui contient un inconnu est inconnue, jamais partielle.

Causes fréquentes :

- pas de prix Jita pour un matériau ;
- prix ajusté ESI ou cost index du système absent ;
- taxe d'installation d'une structure de joueur non saisie (un 0 saisi reste un vrai 0 %) ;
- probabilité d'invention inconnue, compétence non synchronisée ;
- frais de vente inconnus : compétences ou standings du personnage principal pas encore synchronisés ;
- volume d'un item absent des données statiques.

La plupart des écrans nomment la donnée manquante. Détails dans [limites connues](/help?page=limits/known-limits), [marge](/help?page=calculations/margin) et [coût d'un job](/help?page=calculations/job-cost).

### Pourquoi mon inventaire n'est pas à jour ?

Les assets ne sont pas planifiés. Ils se synchronisent à votre connexion, à l'ajout d'un personnage et au bouton « Actualiser » de l'Inventaire, qui exige un personnage dont l'autorisation est valide.

Les assets de corporation se rafraîchissent aussi à la demande, au plus une fois par heure : l'écran affiche « Actualisation possible à … » pendant l'attente. Ils exigent un Director connecté.

Voir [fraîcheur des données](/help?page=data/freshness-privacy).

### Pourquoi plus rien ne se met à jour depuis plusieurs jours ?

Les synchros planifiées (jobs, wallet, blueprints, ordres de vente, colonies PI, PVE, minage, Ansiblex) ne tournent que pour un compte connecté par EVE SSO depuis moins de 7 jours et qui a un personnage à l'autorisation valide. Ces 7 jours sont aussi la durée d'une session. Reconnectez-vous : la connexion remet le compteur à zéro.

### Pourquoi un job terminé n'apparaît pas tout de suite ?

Les jobs sont relus toutes les 30 minutes, pas à la demande. Les jobs de corporation sont lus une fois par corporation et gardés jusqu'à l'`Expires` donné par l'ESI, donc ils peuvent arriver plus tard encore. Un personnage à reconnecter est ignoré.

Un job fini en jeu est d'abord à livrer (statut ESI `ready`), puis livré (`delivered`) quand vous le récupérez. Dans l'onglet Slots, la mention « Données périmées » apparaît quand l'autorisation du personnage est invalide ou que le job en cours le plus récent date de plus d'une heure.

### Pourquoi le coût d'un projet diffère de ce que j'ai payé en jeu ?

Deux chiffres coexistent.

- **L'estimation.** Quand la BOM est calculée, chaque matériau est valorisé au prix percentile 5 % de Jita, c'est-à-dire la moyenne des 5 % les moins chers du volume en vente. Le « coût du reste à produire » reprend ces prix, sans l'installation des jobs ni le déjà engagé.
- **Le coût réel**, la somme des contributions approuvées : prix payé pour un achat lié, coût ESI pour un job lié, prix Jita du jour d'apport pour un matériau déclaré, valeur saisie pour le reste.

Ce qui n'est pas lié ou pas approuvé n'est pas dans le coût réel : un achat non lié, une contribution en attente de revue, un job non lié. Un achat ne compte que pour la ligne du même type. Un matériau déjà dans votre hangar n'a un coût que si vous le déclarez en contribution.

Voir [contributions de groupe](/help?page=calculations/group-contributions) et [coût d'un job](/help?page=calculations/job-cost).

## Industrie

### Comment la structure et ses bonus sont-ils choisis pour un projet ?

Le plan d'un projet utilise les structures, le système favori et les blueprints du propriétaire. Les structures se déclarent dans l'Industrie, bouton Configuration, section « Structures ».

Pour une fabrication ou une réaction :

1. la meilleure structure **adaptée** (un de ses rigs cible la catégorie du produit) du système favori, c'est-à-dire celle qui donne le plus petit multiplicateur de matériaux ;
2. sans structure adaptée dans le système favori, la meilleure de toutes celles qui peuvent faire l'activité, avec un avertissement.

Pour la copie et l'invention, c'est la structure au taux d'installation le plus bas.

Dans l'onglet « Production » du projet, la carte « Structures » compare le coût total : matériaux, installation, transport. Le bouton « Autoriser pour ce projet » laisse le plan sortir du système favori quand l'économie atteint le seuil du projet (500 000 ISK par défaut).

Les bonus viennent du type de structure et des rigs que vous avez déclarés (3 au plus). La taxe d'installation d'une structure de joueur doit être saisie, sinon le coût d'installation est inconnu. En station NPC, elle est fixée à 0,25 %.

Voir [coût d'un job](/help?page=calculations/job-cost).

### Quels ME et TE sont utilisés ?

À la création d'un projet, vous saisissez ME et TE pour chaque produit. Une ligne ajoutée à la main est proposée à ME 2 et TE 4. Une création préremplie depuis un autre écran reprend les valeurs transmises, sinon 0 et 0, avec TE 4 pour un produit T2.

Mais si le propriétaire possède un blueprint du produit, ce blueprint remplace les valeurs saisies. Sont pris en compte : les blueprints de tous ses personnages et ceux de la corporation de son principal qui lui sont visibles. Le meilleur gagne : ME le plus haut, puis TE le plus haut, puis BPO avant BPC. Les composants fabriqués sont à ME 10 et TE 20 sauf blueprint possédé. Une réaction n'a ni ME ni TE.

Voir [projet T2](/help?page=playbooks/t2-project).

### Acheter ou fabriquer ?

La carte « Acheter ou fabriquer » de l'onglet « Production » compare chaque intermédiaire du plan :

- **fabriquer** : le coût total de ses jobs, matériaux directs achetés compris ;
- **acheter** : le prix Jita pondéré de la quantité demandée, plus l'import jusqu'à l'atelier : volume en m³ × sauts depuis Jita jusqu'au système favori × 150 ISK par m³ et par saut.

Le choix est « fabriquer » à égalité, « inconnu » dès qu'un des deux coûts l'est. Le surplus des runs entiers n'est pas crédité ; la carte le montre à part (« avec réemploi du surplus »).

« Passer en achat » et « Passer en fabrication » modifient la blacklist du projet et recalculent la BOM. Une fois le projet sorti du brouillon, la bascule est refusée si elle ferait disparaître une ligne déjà travaillée (job lié, achat ou contribution).

Voir [prix pondéré](/help?page=calculations/weighted-price-appraisal).

## Projets de groupe

### Qui peut rejoindre un projet, et qui approuve ?

Un projet est privé par défaut. Privé, il n'apparaît pas dans l'onglet « Disponibles » et son lien d'invitation répond comme un lien inconnu. Pour qu'on le rejoigne, il doit être non privé et au statut Publié ou En cours.

- **Même corporation que le propriétaire** (celle du personnage principal) : accepté d'office. L'onglet « Disponibles » ne liste que les projets Publiés de votre corporation.
- **Autre corporation** : la demande passe dans « Demandes en attente ». Le propriétaire ou un admin la traite (« Accepter » ou « Rejeter »).
- **Admins** : seul le propriétaire en nomme.
- **Membre retiré ou parti** : il revient par le lien, mais en demande, avec le rôle de membre.

Voir [propriétaire d'un projet de groupe](/help?page=playbooks/group-project-owner) et [participant](/help?page=playbooks/group-project-member).

### Pourquoi je ne peux plus toucher à une contribution approuvée ?

Une contribution passe de « en attente » à « approuvée » ou « rejetée », relue par le propriétaire ou un admin. On ne relit pas sa propre contribution, sauf le propriétaire quand personne d'autre ne peut relire : dans un projet solo, vos apports sont approuvés au dépôt.

Une fois approuvée, elle ne peut plus être rejetée. Une seule exception : un apport déclaré qui double un apport détecté par EVE Tools (« Doublon avec un apport ESI »), que les relecteurs peuvent rejeter.

Une contribution approuvée entre dans le coût réel et dans les payouts, et verrouille aussi le projet : il ne peut plus être supprimé, et une ligne de BOM qui en porte une n'est jamais supprimée par un recalcul. Un projet Terminé fige tout : plus de contribution, de revue, de vente ni de changement de frais.

### Comment un apport est-il valorisé ?

Selon son type.

- **Matériau déclaré** : prix Jita du jour d'apport, percentile 5 % des ordres de vente, lu au dépôt et figé. Vous ne saisissez aucune valeur. Sans prix Jita ce jour-là, la contribution est refusée.
- **Achat lié** (propriétaire) : le prix payé, lu dans les transactions wallet. Approuvé d'office.
- **Job lié** : le coût ESI du job, au nom de son installateur. Sans coût donné par l'ESI, la valeur est inconnue.
- **Installation de job, BPC, location de ligne** : valeur saisie, strictement positive pour l'installation et le BPC. La location propose une suggestion tirée de votre barème.
- **Courrier** : la récompense du contrat lié, une fois livré.

Un apport sans prix connu rend inconnus les totaux qui l'incluent, jamais partiels. Voir [contributions de groupe](/help?page=calculations/group-contributions).

### Quand mon payout est-il exact ?

Payout = coûts que vous avez apportés (contributions approuvées) × (1 + marge du projet). La marge vaut (revenu net − coût total) ÷ coût total. Le revenu net est la somme des ventes saisies, moins le courtage et la taxe de vente du projet, moins les frais d'un contrat.

*Exemple, valeurs rondes : un projet coûte 100 M ISK en apports approuvés et rapporte 130 M ISK nets. La marge est de 30 %. Vous avez apporté 40 M ISK : votre payout est de 40 × 1,3 = 52 M ISK.*

Il est exact quand :

- tous les produits sont vendus : sans aucune vente, l'écran affiche « En attente des ventes » et les payouts sont inconnus ; avec des ventes partielles, ils sont calculés sur le revenu à date, à lire comme provisoires (« Marge provisoire » estime le résultat si les invendus partaient au dernier prix) ;
- le courtage et la taxe de vente sont connus (copiés à la création depuis vos frais de vendeur, modifiables) ;
- chaque apport approuvé a une valeur, et le coût total n'est pas nul (sinon « Répartition impossible ») ;
- les contributions en attente ont été relues : elles ne comptent pas avant approbation.

À ce stade, « Virements entre membres » calcule un plan de virements, le plus petit possible jusqu'à 12 participants à solder et plus simple (glouton) au-delà, avec la référence de paiement à mettre en motif. Voir [contributions de groupe](/help?page=calculations/group-contributions).

## Marché

### Pourquoi le prix d'EVE Tools diffère de celui de Jita en jeu ?

Les prix Jita viennent d'un carnet synchronisé toutes les heures et gardé 2 heures. Il retient les 20 meilleurs ordres de chaque côté, à la station Jita 4-4, ou dans toute la région The Forge quand la station n'a aucun ordre de ce côté.

Selon l'écran, le prix n'est pas le même :

- **prix pondéré** (Marges, Scan, Achat vs Prod, Pivot, Stockpile, approvisionnement des projets) : la moyenne des ordres consommés du meilleur au pire jusqu'à couvrir votre quantité ;
- **prix percentile 5 %** (BOM d'un projet, apports de matériaux) : la moyenne des 5 % les moins chers du volume en vente.

Si votre quantité dépasse les 20 ordres gardés, le prix est **partiel** : calculé sur ce que le carnet couvre, affiché avec sa couverture, jamais masqué. Voir [prix pondéré](/help?page=calculations/weighted-price-appraisal).

### Pourquoi mon ordre est-il « sous-coté » ?

La carte « Mes ordres de vente » d'un projet liste les ordres de vente de vos personnages pour ses produits, relus toutes les 5 minutes. « Sous-coté » veut dire qu'au moins un ordre du même lieu est à un prix strictement inférieur au vôtre. À prix égal, vous êtes 1er. Le rang est le nombre d'ordres moins chers, plus un.

« Copier » donne le meilleur prix moins 100 ISK, à coller en modifiant l'ordre en jeu. Rien n'est modifié depuis EVE Tools. « Marché non suivi » ou « Pas de données de marché » veut dire position inconnue, jamais « 1er » par défaut. Le message « Reconnectez-vous … » indique que la permission de lire les ordres du personnage manque.

Voir [ordres de marché](/help?page=playbooks/market-orders).

## Planétaire (PI)

### Quand un extracteur est-il « bientôt expiré » ?

Par défaut à moins de 1440 minutes, soit 24 h. Le seuil se règle dans « Preferences de notifications », catégorie Planetaire, colonne « Seuil (min) ». Il pilote les KPI, le bandeau, les minuteurs et les notifications. Les colonies sont relues toutes les 30 minutes pour les comptes actifs.

Voir [colonies planétaires](/help?page=playbooks/planetary-colonies).

### Pourquoi la valeur de production PI est plus basse que ce que produisent mes colonies ?

La production est théorique : cycles et schémas enchaînés, valorisés au prix de vente Jita. Seule la part vendable compte. Ce que consomment vos propres usines est retiré (« consommés sur place » dans le tableau par tier), et une usine sans entrée est « Arrêtée ».

Attention : aujourd'hui, un produit sans prix Jita compte pour 0 ISK dans le total. Il s'affiche « - », comme un produit entièrement consommé, et rien ne signale le prix manquant. C'est contraire à la règle « inconnu, jamais 0 ».

## Minage

### Pourquoi la valeur de mon minage est « inconnue » ?

La valeur est la quantité minée × le prix unitaire du profil de valorisation minière. Dès qu'un type miné en quantité positive n'a pas de prix, le total est inconnu, jamais une somme partielle, et les types concernés sont listés. Une quantité nulle vaut 0. Le ledger est relu toutes les heures.

Voir [valeur du minerai et taxe lunaire](/help?page=calculations/ore-value-moon-tax).

### Comment la taxe lunaire est-elle calculée, et comment la payer ?

Par mineur, raffinerie et période (semaine ou mois, en EVE time) : quantité minée × prix unitaire × taux de la rareté du minerai. Les taux sont fixés par le propriétaire de la raffinerie. Le prix est le prix d'achat Jita du minerai compressé équivalent. Un minerai sans prix ou sans rareté connue rend la taxe inconnue.

Le relevé est figé à la clôture de la période et porte une référence `TAX-…`. Le paiement est un don en ISK au wallet de la corporation. Il est rapproché du relevé par la corporation, le personnage donateur (ou celui à qui un gestionnaire a attribué le don) et la date. Le motif du don n'est pas lu. Un relevé impayé passe « En retard » 7 jours après la fin de sa période. Votre part est dans la vue « Ma taxe ».

Voir [minage et taxe lunaire](/help?page=playbooks/mining-moon-tax).

## PVE

### D'où viennent mes revenus PVE ?

La synchro PVE lit les 30 derniers jours du journal wallet de chaque personnage : primes, ESS et récompenses de missions d'agent.

Les ventes de loot ne sont lues dans vos transactions wallet que pour les types que vous avez configurés (« Types de loot »). Les contrats de loot importés sont les contrats d'échange d'objets terminés dont vous êtes l'émetteur, à prix supérieur à 0 ISK : un contrat à 0 ISK n'est pas importé. Sans type de loot configuré, seuls les contrats de loot et les projets de corporation sont lus.
