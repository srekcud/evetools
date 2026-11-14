# Fraîcheur et confidentialité des données

Cette page dit d'où viennent les chiffres d'EVE Tools, quel âge ils peuvent avoir, ce que l'application conserve sur vous, qui d'autre peut le voir, ce qui est purgé et comment tout supprimer. Une règle vaut partout : quand une donnée manque, l'écran dit « inconnu ». Il ne met jamais 0 à la place.

## D'où viennent les données

Pour ses données, EVE Tools n'interroge que trois services : l'ESI (l'API d'EVE Online), EVE SSO (la connexion) et le portail développeurs de CCP pour le SDE. Il n'existe pas de site tiers de prix.

| Source | Ce qu'elle fournit | Lue avec |
|---|---|---|
| ESI, à votre nom | assets, wallet, compétences, jobs, ordres de marché, colonies PI, blueprints, minage de vos personnages | le jeton de chacun de vos personnages |
| ESI, pour une corporation | assets, blueprints, jobs, extractions lunaires, ledger de raffinerie, journal wallet | le jeton d'un personnage de la corporation qui tient le rôle voulu, pas forcément le vôtre |
| ESI publique | carnets d'ordres de Jita (The Forge), prix ajustés, cost indices, historiques de marché, contrats publics | aucun jeton |
| ESI, carnet d'une structure de joueur | ordres de la structure | le jeton d'un seul joueur qui suit cette structure et a le scope du marché des structures |
| SDE (données statiques de CCP) | types, blueprints et recettes, systèmes, stations, schémas PI | import manuel, par commande |

Le SDE ne se met pas à jour seul. Une vérification quotidienne compare la dernière version publiée par CCP à celle qui est importée et prévient les administrateurs, sans rien importer.

### Pourquoi « inconnu » plutôt que 0

Un chiffre plausible mais faux est pire qu'un trou. Si un prix, un cost index, une compétence ou un solde manque, tout résultat qui en dépend est « inconnu », et une somme qui contient un inconnu est inconnue, jamais partielle. Un 0 affiché veut dire que la donnée est connue et vaut zéro, ou, pour un revenu PVE, qu'aucune entrée n'est enregistrée.

Les exceptions connues, où l'écran affiche un 0 qui n'en est pas un, sont dans [limites connues](/help?page=limits/known-limits). Les causes courantes d'un « inconnu » sont dans la [FAQ](/help?page=faq/faq).

## Scopes

Un scope est une permission que vous accordez à EVE Tools dans EVE SSO, par personnage.

- **Tous les scopes sont demandés ensemble**, à chaque connexion et à chaque « Ajouter un personnage ». Il n'y a pas de choix scope par scope.
- **Un scope demandé n'est pas forcément lu.** Certains ne servent à aucune fonction pour l'instant. Le tableau ci-dessous dit, scope par scope, ce que chacun alimente.
- **« Scopes manquants »** s'affiche sur la carte d'un personnage dont le jeton n'a pas tous les scopes demandés aujourd'hui, par exemple parce qu'il a été autorisé avant l'ajout d'un scope. Le bouton « Re-autoriser » règle le problème.
- **Un scope manquant écarte le personnage de la fonction concernée.** Les synchros du wallet, des ordres de marché, des blueprints, des colonies PI et des jobs vérifient chacune leur scope et ignorent le personnage qui ne l'a pas : ses données ne sont pas lues.
- **Pour une corporation, le scope ne suffit pas.** Le personnage doit aussi tenir le rôle en jeu (Director, Station_Manager, Accountant selon la donnée). Sans personnage connecté qui le tienne, la donnée de corporation n'est pas lue.

**Tableau scope par scope : tableau généré, #935.** Une ligne par scope, avec la fonction qu'il alimente. Il remplacera cette phrase.

## Fréquence des synchros

### Planifiées et à la demande

- Une **synchro planifiée** est lancée seule, à intervalle fixe, sans action de votre part.
- Une **synchro à la demande** part d'une action : connexion, ajout d'un personnage, bouton « Actualiser » ou équivalent, ouverture d'un écran.

**Tableau des synchros planifiées et de leurs fréquences : tableau généré, #935.** Il remplacera cette phrase.

### Quand une synchro planifiée saute votre compte

Les synchros planifiées des jobs, du wallet, des blueprints, des ordres de vente, des colonies PI, du PVE, du minage et d'Ansiblex ne tournent que si les deux conditions sont réunies :

1. votre dernière **connexion par EVE SSO** date de moins de 7 jours (ces 7 jours sont aussi la durée d'une session) ;
2. l'**autorisation est valide** : celle du personnage pour les synchros par personnage (jobs, wallet, blueprints, ordres de vente, colonies PI), celle d'au moins un de vos personnages pour les synchros par compte (PVE, minage, Ansiblex).

Ajouter un personnage ne compte pas comme une connexion. Un personnage dont l'autorisation est révoquée sort seul des synchros : les autres continuent. Il revient quand vous le reconnectez.

Certaines autres synchros planifiées n'appliquent pas cette condition de connexion. Elles dépendent d'un personnage qui a le scope et le rôle voulus : le carnet d'une structure (lu avec le joueur le plus récemment connecté qui la suit et a le scope), les extractions lunaires et la taxe lunaire d'une corporation.

### Ce qui n'est jamais planifié

- **Assets** : à la connexion, à l'ajout ou à la ré-autorisation d'un personnage, et par « Actualiser ». Les assets de corporation se rafraîchissent à la demande, au plus une fois par heure, avec un Director connecté.
- **Compétences et standings** : uniquement à la demande, par la synchro des compétences.
- **Soldes du wallet** : lus à l'ESI à l'ouverture de l'Accueil et de la page Personnages, jamais stockés.
- **Prix absent du cache** : lu directement à l'ESI et gardé 5 minutes.

Plusieurs écrans ont aussi un bouton pour relancer tout de suite une synchro planifiée (blueprints, colonies PI, Ansiblex, extractions lunaires, taxe lunaire).

### Délais de cache de l'ESI

Chaque réponse de l'ESI porte une date d'expiration (`Expires`) et souvent une empreinte (`ETag`). EVE Tools les respecte dans trois cas, et nulle part ailleurs :

- **Wallet** : un personnage n'est relu qu'une fois l'`Expires` de sa dernière lecture passé. Sans `Expires`, la relecture attend 1 heure. Après un échec, elle attend 15 minutes.
- **Jobs de corporation** : lus une seule fois par corporation et gardés jusqu'à l'`Expires`, pour tous ses membres.
- **Fiches publiques** (personnage, corporation, alliance, lune) : gardées jusqu'à l'`Expires`, 5 minutes si l'ESI n'en donne pas. Passé ce délai, EVE Tools redemande avec l'`ETag` : une réponse « inchangé » réutilise la copie. La copie est conservée 7 jours. Si l'ESI est injoignable, c'est elle qui est servie, même vieille de plusieurs jours.

Quand l'ESI répond 420 ou 429 (budget d'erreurs épuisé ou limite de débit), le client attend le délai indiqué puis rejoue la requête une seule fois. Si elle échoue encore, la lecture est en échec.

Les données de l'ESI sont aussi vieilles que le dernier événement en jeu. Exemple : une colonie PI reflète votre dernière interaction en jeu, voir [colonies planétaires](/help?page=playbooks/planetary-colonies).

## Ce qui est stocké

| Type de donnée | Ce qui est conservé | Rattaché à |
|---|---|---|
| Compte | date de création, date de dernière connexion, personnage principal, réglages (blacklist d'industrie, structures de marché suivies, barème de location de ligne) | votre compte |
| Personnages | identifiant EVE, nom, corporation, alliance, état de l'autorisation, date de dernière synchro | votre compte |
| Jetons EVE SSO | jeton d'accès, jeton de renouvellement, date d'expiration, liste des scopes accordés | chaque personnage |
| Données lues à l'ESI | assets, compétences, attributs, standings, jobs, transactions et journal du wallet, ordres de vente, colonies PI (pins, routes), blueprints, entrées de minage | chaque personnage ou votre compte |
| Ce que vous saisissez | projets et modèles de projet, structures de production, stockpile, favoris, alertes de prix, revenus et dépenses PVE, escalations, opérations de minage, notifications, abonnement aux notifications push du navigateur | votre compte |
| Données de corporation | assets et blueprints de corporation, extractions lunaires, relevés d'observateurs de raffinerie, dons du wallet de corporation, relevés de taxe lunaire, compositions de lunes | la corporation, pas un compte |
| Données communes | SDE, prix de marché et historiques, carnets de structures, prix ajustés, cost indices, contrats publics | aucun compte |

Le solde de votre wallet n'est pas stocké.

**Jetons.** Le jeton de renouvellement est chiffré avant d'être écrit en base. Le jeton d'accès, de courte durée, est conservé tel quel. Aucune autre donnée n'est chiffrée par l'application. Les copies temporaires de réponses ESI gardées en cache sont étiquetées par personnage et invalidées à la suppression du compte.

Les administrateurs disposent d'une fiche de dépannage par utilisateur : personnages, état des jetons, scopes manquants, dates de création et de dernière connexion, date de la dernière donnée reçue par type de synchro. Elle ne contient ni assets, ni wallet, ni jeton.

## Ce qui est partagé

Par défaut, vos données de personnage (assets, wallet, compétences, jobs, colonies PI, ordres, PVE, minage) ne sont visibles que de votre compte. Ce qui sort de votre compte :

| Quoi | Qui le voit | Comment c'est réglé |
|---|---|---|
| Votre personnage principal dans un **projet de groupe** : nom, identifiant, corporation, rôle, statut, total et nombre de contributions, date d'arrivée | les participants du projet | nom du principal seulement, jamais un personnage secondaire |
| Dans un projet de groupe : vos contributions et leur valeur, leurs notes, les jobs liés que vous avez lancés, les ventes saisies, le journal d'activité | les participants du projet | l'installateur d'un job est montré sous le nom de votre principal, et sous le nom réel du personnage à vous seul |
| Un **projet de groupe non privé** | les membres de la corporation de son propriétaire tant qu'il est Publié (onglet « Disponibles »), et tout utilisateur connecté qui a le lien d'invitation tant qu'il est Publié ou En cours | un projet est privé par défaut ; privé, il répond comme un lien inconnu |
| Vos **modèles de projet** | la corporation de votre principal | partagés seulement si vous le choisissez ; non partagés par défaut |
| La configuration d'une **structure de production** (type, sécurité, rigs, taxe d'installation) dont votre corporation est propriétaire | les autres membres de la corporation, qui en gardent chacun leur copie | automatique à l'import de la structure |
| **Blueprints de corporation** | les membres de la corporation, dès qu'un Director est connecté | tous les blueprints de toutes les divisions sont visibles par défaut ; un Director peut décocher « Montrer les blueprints de la corpo des divisions masquées » |
| **Assets de corporation** | les membres de la corporation, division par division | rien n'est visible tant qu'un Director n'a pas réglé la visibilité (tous les membres, une liste de joueurs ou masquée) |
| Une **opération de minage** | ses participants : nom du principal, corporation, rôle, présence (« En ligne », « AFK », « Parti »), et les personnages que vous y inscrivez (nom et identifiant) ; les membres de votre corporation voient aussi dans la liste celles réglées sur « Ma corporation » | « Privée (lien) » par défaut : on y entre par le lien |
| Une **escalation** | vous seul, votre corporation, votre alliance ou tout le monde sans compte | « Visible que par moi » par défaut ; en « Public », le nom du personnage, le système, le prix et les notes sont lisibles sans connexion tant qu'elle est en vente et non expirée |
| Une **évaluation partagée** | quiconque a le lien `/s/…`, sans compte | voir ci-dessous |
| Le **carnet d'une structure** | tous ceux qui la suivent | lu avec le jeton d'un seul joueur, résultat commun |

Pour les extractions lunaires et la taxe lunaire, qui voit quoi est détaillé dans [minage et taxe lunaire](/help?page=playbooks/mining-moon-tax). Côté projet de groupe : [propriétaire](/help?page=playbooks/group-project-owner) et [participant](/help?page=playbooks/group-project-member).

**Une évaluation partagée est publique par son lien.** Elle contient une copie figée de l'évaluation : lignes, totaux, coût de transport, structure de comparaison. Aucun nom de personnage n'y figure, mais le nom de la structure de comparaison, s'il y en a une, y est. Le lien est court et aléatoire, et la page d'aperçu qu'il ouvre est servie avec l'en-tête `X-Robots-Tag: index, follow` : un moteur de recherche qui trouve le lien peut l'indexer. Ne partagez pas une évaluation dont le contenu est confidentiel.

## Ce qui est purgé et quand

Des tâches quotidiennes effacent :

| Donnée | Purgée quand |
|---|---|
| Notifications | plus de 7 jours après leur création, lues ou non |
| Runs de synchronisation (historique technique des synchros) | plus de 30 jours |
| Évaluations partagées | une fois expirées, une semaine après leur création |
| Historique de prix de marché | plus de 365 jours |
| Instantanés de carnet de structure et volumes échangés | plus de 90 jours |

Les autres données se remplacent à chaque lecture réussie ou restent tant que le compte existe. Le code ne purge pas un compte inactif : après 7 jours sans connexion, seules ses synchros planifiées s'arrêtent. Les données rattachées à un personnage retiré avec « Retirer le personnage » (assets, jobs, compétences, wallet, colonies PI, ordres, blueprints) sont supprimées avec lui.

Piège : « Retirer le personnage » supprime le jeton d'EVE Tools mais ne le révoque pas auprès de CCP. L'autorisation reste valable côté EVE Online tant que vous ne la révoquez pas vous-même, dans la page des applications autorisées de votre compte EVE Online.

## Supprimer son compte et ses données

Menu « Compte », onglet « Personnages », section « Zone de danger », bouton « Supprimer mon compte ». La fenêtre liste ce qui sera supprimé et conservé, puis demande de saisir le **nom exact de votre personnage principal**. Le bouton « Supprimer définitivement » reste inactif tant que le nom ne correspond pas, et le serveur refuse aussi une confirmation qui ne correspond pas. La suppression est immédiate et irréversible.

**Supprimé** :

- votre compte, tous vos personnages et leurs jetons ;
- vos données personnelles : assets, compétences, jobs, transactions, colonies PI, ordres, alertes, favoris, notifications, réglages, PVE, minage, escalations, modèles de projet, structures de production, évaluations partagées que vous avez créées ;
- vos projets industriels : si d'autres participants acceptés existent, le projet est transféré (voir ci-dessous), sinon il est supprimé avec tout ce qu'il contient ;
- vos demandes d'adhésion en attente et vos contributions non approuvées (en attente ou rejetées) dans les projets des autres ;
- votre participation aux opérations de minage des autres, avec les quantités et corrections de vos personnages.

**Transféré** :

- un projet de groupe dont vous êtes propriétaire passe à l'admin accepté le plus ancien, sinon au participant accepté le plus ancien. Ses jobs et achats liés sont vidés et son contrat de courrier délié ;
- une opération de minage déjà démarrée passe de la même façon à son successeur, et elle est supprimée s'il n'y en a pas. Une opération encore « Planifiée » est supprimée avec vous.

**Conservé, sans lien avec vous** :

- vos contributions approuvées et les ventes que vous avez saisies dans les projets des autres, affichées « Pilote supprimé », avec la note de la contribution effacée ;
- dans ces projets, la ligne de participant reste, sans compte.

**Conservé et pouvant encore porter un nom** (le code ne l'efface pas) :

- le **journal d'activité** d'un projet : les événements déjà enregistrés gardent le nom du personnage principal tel qu'il était ;
- le nom du personnage installateur d'un job lié à un projet dont vous n'êtes pas propriétaire ;
- les noms déjà inscrits dans les paiements d'une opération de minage transférée à un autre participant, et le nom de l'auteur d'une correction de quantité ;
- les données de **corporation** rattachées à un identifiant EVE et non à un compte : relevés d'observateurs de raffinerie, relevés de taxe lunaire, dons du wallet de corporation ;
- les assets et blueprints de corporation lus avec votre jeton : ils ne dépendent d'aucun compte et restent jusqu'à leur prochaine relecture par un Director, donc sans limite si aucun n'est connecté.

**Après la suppression** : vos jetons de renouvellement sont révoqués auprès de CCP au mieux. Si la révocation échoue, le compte est supprimé quand même et l'échec n'est journalisé que côté serveur : révoquez alors vous-même l'application côté EVE Online. Votre session est mise sur liste de blocage, et les copies ESI en cache de vos personnages sont invalidées.

Pour la politique complète, voir « Privacy Policy » (en anglais) en pied de l'écran de connexion.

## Voir aussi

- [FAQ](/help?page=faq/faq) : scopes, « à reconnecter », « plus rien ne se met à jour », qui voit mes données.
- [Limites connues](/help?page=limits/known-limits) : les chiffres qui peuvent surprendre.
- [Premiers pas](/help?page=playbooks/getting-started) : connexion et ajout de personnages.
