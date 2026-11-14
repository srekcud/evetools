# Premiers pas

Ce playbook va de la première connexion EVE SSO à un compte utilisable : vos personnages rattachés, vos compétences lues, vos structures de production déclarées, et une idée claire de ce qui se met à jour seul. Suivez les étapes dans l'ordre, chacune suppose la précédente.

Les libellés entre guillemets « » sont ceux de l'écran. Certains sont écrits sans accent dans l'application (« Definir comme principal », « Parametres », « Competences ») et sont repris tels quels pour que vous les retrouviez.

## 1. Se connecter avec EVE SSO

Sur l'écran de connexion, cliquez sur l'image officielle « Log in with EVE Online ». Vous passez par EVE SSO : choisissez le personnage et autorisez l'application. Tous les scopes sont demandés en une seule fois (voir la [FAQ](/help?page=faq/faq)).

Au retour, EVE Tools ouvre votre session et vous amène sur l'Accueil. À la première connexion, ce personnage devient votre personnage principal. Une synchro des assets de vos personnages démarre à chaque connexion.

- La session dure 7 jours. Après, reconnectez-vous : sans connexion récente, les synchros planifiées s'arrêtent pour votre compte (voir le tableau en fin de page).
- Si un bandeau « EVE Online servers are under maintenance » s'affiche, l'ESI est dégradé ou injoignable. Réessayez plus tard. Ce bandeau et les messages d'erreur de connexion restent en anglais.

## 2. Lire l'Accueil

Vous arrivez sur « Accueil », de haut en bas :

- « À faire » : une ligne par action (personnage à reconnecter, extracteur PI arrêté ou bientôt expiré, alerte de prix déclenchée). Sans rien à traiter : « Tout est à jour ». Une source qui n'a pas pu être chargée donne une ligne « … : inconnu », jamais un compteur à 0.
- « En cours » : opérations de minage, jobs qui finissent, prochains chunks, extracteurs PI. Un compte neuf affiche « Aucun job en cours » et équivalents tant que les premières synchros n'ont pas tourné.
- « Bilan des 30 derniers jours » : PVE, Minage, Industrie, Total, avec le lien « Bilan ».
- Les cartes « Personnages » et « Portefeuille » (« ISK total »), puis « Vos personnages » et la colonne « Activite ».

Deux chiffres à bien lire dès maintenant.

**« ISK total »** est la somme des soldes de vos personnages, lus auprès de l'ESI quand vous ouvrez l'écran. Un solde illisible n'est pas compté et l'écran ajoute « Total partiel : N solde(s) inconnu(s) » : lisez alors le total comme un minimum. Piège : si aucun solde n'est lisible, le total s'affiche « 0 » avec cette mention, ce qui n'est pas un vrai zéro.

**Le « Bilan des 30 derniers jours »** :

- « PVE » : les revenus PVE enregistrés sur la période. Sans entrée, 0 veut dire « rien d'enregistré », pas « mesuré à zéro ». D'où viennent-ils : [FAQ](/help?page=faq/faq).
- « Minage » : la valeur de votre minage sur la période. Si un type miné n'a pas de prix, elle est « inconnue » et le « Total » l'est aussi. Calcul : [valeur du minerai et taxe lunaire](/help?page=calculations/ore-value-moon-tax).
- « Industrie » : votre part de profit des projets terminés sur la période. Un projet terminé dont le profit est inconnu n'est pas compté, et l'Accueil ne le signale pas : la page Bilan en donne le nombre. Calcul : [contributions de groupe](/help?page=calculations/group-contributions) et [marge](/help?page=calculations/margin).
- « Total » : PVE, Minage et Industrie additionnés, sans retrancher les dépenses.

## 3. Ajouter vos autres personnages

Menu « Compte », onglet « Personnages ». Cliquez sur « Ajouter un personnage » (le même bouton existe sur l'Accueil, au-dessus de « Vos personnages »). Choisissez dans EVE SSO le personnage à ajouter. Vous revenez sur la page Personnages, et une synchro de ses assets démarre.

La carte apparaît sous « Meme corporation (N) » ou « Autres corporations (N) », selon la corporation de votre personnage principal.

- Un personnage déjà rattaché à un autre compte EVE Tools est refusé (le message est en anglais).
- Un personnage déjà rattaché à votre compte voit son jeton remplacé, il n'est pas dupliqué.
- Pour changer de personnage principal : l'étoile « Definir comme principal » d'une carte, puis confirmation. Ce choix fixe votre corporation de référence et les frais de vente de vos projets, détails dans la [FAQ](/help?page=faq/faq).
- Pour en retirer un : « Retirer le personnage ». Le principal ne se retire pas.

## 4. Vérifier l'état de chaque carte

Chaque carte montre :

- un badge d'autorisation. Sur la carte du principal : « Token valide », « Token expire » ou « Scopes manquants ». Sur les autres cartes : « Valide », « Expire » ou « Scopes manquants » ;
- le wallet du personnage, ou « Inconnu » s'il n'a pas pu être lu ;
- sous « En formation », la compétence en cours et « N dans la file », ou « Pas de formation en cours ». Piège : ce dernier message s'affiche aussi quand la file n'a pas pu être lue.

Si le badge n'est pas vert, utilisez « Re-autoriser » (bouton sur la carte du principal ; icône sur les autres cartes, dont l'infobulle dit « Scopes manquants » quand il manque des scopes). Vous repassez par EVE SSO avec le même personnage, et son jeton est remplacé.

Piège : un badge vert ne prouve pas que l'autorisation n'a pas été révoquée chez CCP. L'Accueil, ligne « personnage à reconnecter » dans « À faire », est plus fiable. Voir la [FAQ](/help?page=faq/faq).

## 5. Synchroniser les compétences

Les compétences ne sont jamais synchronisées automatiquement. Sans elles, les frais de vente (courtage et taxe de vente, calculés avec les compétences Accounting et Broker Relations et les standings de votre principal) restent inconnus, et les limites de slots peuvent être fausses. Voir [frais de marché](/help?page=calculations/market-fees).

1. Menu « Industrie », puis l'icône d'engrenage (infobulle « Configuration »). Autre chemin : « Compte », « Parametres », section « Industrie ».
2. Dépliez la section « Competences » et cliquez sur « Sync toutes ».
3. Contrôlez la table : une ligne par personnage, avec Industry, Adv. Industry, Reactions, la colonne « Source » (« ESI » après une synchro) et « Derniere sync ». Les personnages qui n'ont pas pu être lus sont listés sous « Compétences non synchronisées pour N personnage(s) », avec la raison.

Le même bouton lit aussi les standings du personnage.

- Avant la première synchro, la table affiche des niveaux à 0 avec la source « Manuel » : c'est une absence de donnée, pas des niveaux mesurés.
- Un niveau que vous réglez à la main reste « Manuel » et n'est pas écrasé par la synchro.
- Dans Industrie, onglet « Production », « Slots », un avertissement « Les limites de slots peuvent etre inexactes… » avec le bouton « Synchroniser les Skills » ouvre cette même section.

## 6. Déclarer vos structures de production

Une structure de production porte les bonus de structure et de rig utilisés par le [coût d'un job](/help?page=calculations/job-cost). Sans structure déclarée, le plan d'un projet n'applique aucun bonus de structure ni de rig.

1. Ouvrez « Configuration » comme à l'étape 5, section « Structures » (ou « Parametres », « Industrie »).
2. Bloc « Systèmes favoris » : choisissez un système pour « Fabrication » et un pour « Réactions ».
3. Bloc « Structures de production » : cliquez sur « Ajouter ». Le formulaire « Nouvelle structure » se remplit de trois façons : « Structures partagées par la corporation », « Ou rechercher par nom (ESI) » (3 caractères au moins) ou saisie manuelle du nom.
4. Renseignez « Nom », « Type de structure », « Sécurité », jusqu'à 3 rigs et « Taxe d'installation (%) » (de 0 à 50). Le cadre « Bonus calculé (base structure + rigs x sécurité) » donne un aperçu. Validez avec « Ajouter ».

Pièges :

- La « Sécurité » est pré-cochée sur « Null-Sec ». Une recherche ESI remplit le nom et le type mais pas la sécurité : vérifiez-la avant d'enregistrer.
- Une taxe d'installation laissée vide s'affiche « non saisie », et le coût d'installation des jobs dans cette structure est inconnu, pas calculé avec 0 %. Saisissez 0 si la structure ne prélève vraiment rien. Pour une « Station NPC (aucun bonus) », la taxe est fixée par le jeu à 0,25 %.
- Préférez l'import (liste de la corporation ou recherche ESI) à la saisie manuelle : le détail, y compris le partage avec la corporation, est dans [Configurer ses structures industrielles](/help?page=playbooks/industry-structures).

## 7. Choisir une structure de marché (facultatif)

Ne la confondez pas avec une structure de production. Elle sert à comparer les prix avec Jita. « Compte », « Parametres », section « Marché », bloc « Structure de marche » : cherchez une structure (3 caractères au moins), le choix est enregistré tout de suite (« Structure enregistree »). Comment les prix sont lus : [prix pondéré](/help?page=calculations/weighted-price-appraisal).

## 8. Remplir l'inventaire

Menu « Patrimoine », onglet « Inventaire ». Les assets arrivent avec la synchro lancée à la connexion ou à l'ajout du personnage. Tant qu'ils ne sont pas là, l'écran affiche « Aucun asset ».

Pour relancer : le bouton « Actualiser » en haut à droite, ou le lien « Rafraichir depuis EVE » quand la liste est vide (l'écran affiche alors « Synchronisation en cours » jusqu'à la fin). Il faut un personnage dont l'autorisation est valide. Les assets de corporation (« Assets corporation ») ont un délai entre deux actualisations (« Actualisation possible à … ») et exigent un Director connecté.

## Ce qui se met à jour seul, et ce qui demande un clic

Conditions communes aux synchros par joueur : une connexion EVE SSO de moins de 7 jours et, pour les synchros par personnage, une autorisation valide. Un personnage à reconnecter est ignoré, les autres continuent.

| Donnée | Rythme | Notes |
|---|---|---|
| Jobs d'industrie | toutes les 30 minutes | |
| Blueprints | toutes les heures | Ceux de la corporation exigent un Director connecté. |
| Ordres de vente des personnages | toutes les 5 minutes | |
| Transactions wallet | tick de 5 minutes | Ne relit que les personnages dont l'`Expires` ESI est passé. |
| Colonies planétaires | toutes les 30 minutes | |
| PVE, minage | toutes les heures | |
| Ansiblex | toutes les 12 heures | |
| Prix Jita | toutes les heures | Partagé par tous les joueurs. |
| Cost index | toutes les 2 heures | Partagé. |
| Prix ajustés | toutes les 24 heures | Partagé. |
| Contrats publics | toutes les 30 minutes | Partagé. |

Ne se met à jour que sur action ou à l'ouverture :

- **assets** : connexion, ajout ou re-autorisation d'un personnage, puis « Actualiser » (étape 8) ;
- **compétences et standings** : « Sync toutes » (étape 5), jamais planifiés ;
- **soldes wallet et file de compétences** : lus auprès de l'ESI à l'ouverture de l'Accueil et de la page Personnages ;
- **structures, systèmes favoris, structure de marché** : ce que vous avez saisi, rien ne les change seul.

L'âge de chaque donnée partagée, et ce que chaque scope alimente : [fraîcheur et confidentialité des données](/help?page=data/freshness-privacy).

## Contrôle final

- L'Accueil n'a aucune ligne « personnage à reconnecter ».
- Chaque carte de la page Personnages affiche un badge vert, et la table « Competences » indique « ESI ».
- Au moins une structure de production est déclarée, avec sa taxe d'installation.
- L'Inventaire montre vos assets.

Si un chiffre reste « inconnu » malgré cela : [limites connues](/help?page=limits/known-limits) et la [FAQ](/help?page=faq/faq).

## Et ensuite

- Un premier projet T2 : [projet T2](/help?page=playbooks/t2-project).
- Un projet à plusieurs : [propriétaire](/help?page=playbooks/group-project-owner) ou [participant](/help?page=playbooks/group-project-member).
- Suivre vos ordres : [ordres de marché](/help?page=playbooks/market-orders).
- Colonies PI : [colonies planétaires](/help?page=playbooks/planetary-colonies).
- Minage lunaire : [minage et taxe lunaire](/help?page=playbooks/mining-moon-tax).
