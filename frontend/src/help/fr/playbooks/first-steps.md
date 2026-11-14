# Premiers pas : connexion, alts, structures, première synchronisation

Ce guide va de la connexion EVE SSO à un tableau de bord utilisable. Les noms de menus et de boutons sont ceux de l'application en français, orthographe comprise.

À la fin, vous aurez : votre compte et vos alts connectés, vos structures de production et vos compétences renseignées, et vous saurez ce qui se met à jour tout seul et ce qui demande un clic.

## Étape 1 : se connecter avec EVE SSO

1. Sur la page de connexion, cliquez sur le bouton **Log in with EVE Online** (image officielle de CCP, en anglais quelle que soit la langue de l'application).
2. Vous passez sur le site de CCP : choisissez le personnage et validez les autorisations demandées (les scopes ESI). EVE Tools demande les 31 scopes de sa liste en une seule autorisation. Le détail est dans la page [Scopes ESI](/help?page=data/esi-scopes) et la [FAQ compte et personnages](/help?page=faq/account-and-characters).
3. Vous revenez dans l'application, sur l'écran **Accueil**.

Le premier personnage connecté devient votre **Personnage principal**. Il compte à plusieurs endroits (recherche de structures, frais de vente, visibilité dans les projets de groupe).

Dès la connexion, une synchronisation des assets démarre en arrière-plan pour tous vos personnages.

Si le bouton ne réagit pas, la page de connexion affiche un bandeau quand ESI est en maintenance ou dégradé. Dans ce cas, réessayez plus tard.

## Étape 2 : lire l'Accueil

L'écran **Accueil** résume votre compte :

- **Personnages** (avec « comptes lies ») : le nombre de personnages connectés.
- **Portefeuille** (« ISK total ») : la somme des soldes. Si le solde d'un personnage n'a pas pu être lu, le total affiche « Total partiel : 1 solde inconnu » (ou « N soldes inconnus »). Un solde inconnu n'est jamais compté pour 0.
- **Vos personnages** : un badge **Token valide** ou **Token invalide** par personnage.
- **À faire** : les actions en attente, par exemple « 1 personnage à reconnecter ». Si le chargement échoue, la ligne affiche « Personnages à reconnecter : inconnu ».
- **Activite** : les événements en temps réel. Le point à côté du titre indique **Mercure connecte** ou **Deconnecte**.

## Étape 3 : ajouter vos alts

1. Menu **Compte**, onglet **Personnages** (ou, depuis l'icône de votre portrait en haut à droite, **Personnages**).
2. Cliquez sur **Ajouter un personnage**. Le bouton du même nom de l'**Accueil** fait la même chose.
3. Vous passez par EVE SSO pour le nouveau personnage et vous revenez sur **Personnages**. Une synchronisation de ses assets démarre en arrière-plan.

La page classe vos alts sous **Meme corporation** et **Autres corporations** (le nombre de personnages est affiché à côté), sous la carte **Personnage principal**. Sans alt, elle affiche « Aucun personnage alternatif ».

Chaque carte porte un badge de jeton :

| Badge | Sens | Action |
|---|---|---|
| **Token valide** (carte principale), **Valide** (alts) | Le jeton couvre les scopes requis. | Aucune. |
| **Token expire** (carte principale), **Expire** (alts) | EVE SSO a refusé le renouvellement du jeton. Le personnage sort des synchronisations. | **Re-autoriser** sur la carte. |
| **Scopes manquants** | Le jeton est valide mais ne couvre pas tous les scopes. | **Re-autoriser**. |

Sur la carte d'un alt, des icônes (infobulles) servent à **Re-autoriser** (visible seulement si le jeton est expiré ou incomplet), **Definir comme principal** et **Retirer le personnage**. Le **Portefeuille** d'un personnage dont le solde est illisible affiche « Inconnu ».

## Étape 4 : renseigner vos structures de production

Sans structure configurée, les projets Industrie sont calculés sans aucun bonus de structure ni de rig.

1. Menu **Industrie**, icône d'engrenage (infobulle **Configuration**) sous les onglets du module ; ou menu **Compte** › **Parametres** › section **Industrie**. Les deux ouvrent le même panneau.
2. Dépliez la section **Structures** et cliquez sur **Ajouter**.

Le formulaire, l'import depuis la corporation ou par recherche ESI, la sécurité, les rigs et la taxe d'installation sont détaillés dans [Configurer ses structures industrielles](/help?page=playbooks/industry-structures). Ce guide explique aussi comment le coût d'installation d'un job et le bonus d'une structure sont calculés.

Le même panneau contient les sections **Frais** (coût d'export en ISK/m³), **Competences** (étape 5) et **Blacklist**, avec **Durée max des jobs** en haut.

À ne pas confondre : **Structure de marche**, dans **Compte** › **Parametres** › section **Marché**, sert seulement à comparer les prix avec Jita. Elle n'intervient pas dans les bonus de production.

## Étape 5 : synchroniser compétences et standings

Les compétences servent aux temps de production. Le courtage et la taxe de vente d'une vente sont calculés depuis les compétences et les standings de votre personnage principal. Tant que ces données manquent, les frais du vendeur sont affichés « inconnus » : l'application ne les remplace pas par 0.

Cette synchronisation ne se fait jamais seule :

1. **Compte** › **Parametres** › section **Industrie**, ou l'engrenage du module **Industrie** (étape 4).
2. Dépliez la section **Competences** et cliquez sur **Sync toutes**.

Elle lit les compétences, les attributs et les standings de chacun de vos personnages. La colonne **Source** indique **ESI** pour une valeur lue chez CCP et **Manuel** pour une valeur réglée à la main. Si un personnage échoue (jeton absent, scope manquant, autorisation révoquée, ESI indisponible), la raison s'affiche avec le lien **Reconnecter des personnages**.

## Étape 6 : comprendre ce qui se met à jour seul

| Donnée | Comment elle arrive |
|---|---|
| Assets | Une synchronisation démarre à la connexion et à chaque ajout d'alt. Ensuite, elle n'est plus planifiée : menu **Patrimoine** › **Inventaire**, bouton **Actualiser**. |
| Jobs d'industrie | Toutes les 30 minutes, pour les personnages dont le jeton est valide. |
| Transactions du wallet | Un passage toutes les 5 minutes, qui ne lit que les personnages dont l'expiration du cache ESI est passée. |
| Colonies planétaires | Toutes les 30 minutes. Le bouton **Synchroniser** de la page **Planétaire** force la lecture. |
| Blueprints, données PVE, registre de minage | Toutes les heures. |
| Compétences et standings | À la demande seulement (étape 5). |

Le tableau complet, généré depuis le code, est dans la page [Fréquences de synchronisation](/help?page=data/sync-frequencies).

Ce que vous voyez pendant et après une synchronisation :

- **Inventaire** : une barre de progression apparaît pendant la lecture, le bouton passe à **Sync...**, puis une ligne indique l'ancienneté (« Synchronisé » suivi du temps écoulé). Tant que les assets d'un personnage ne sont pas arrivés, la page affiche « Synchronisation en cours ».
- **Inventaire, vue corporation** : après une actualisation, le bouton est verrouillé jusqu'à l'heure affichée (« Actualisation possible à »). Cette limite ne concerne pas la vue d'un personnage.
- **Industrie** › **Atelier** : le bloc « Prochaines fins de jobs » affiche « sync ESI » suivi de l'ancienneté, ou « sync ESI inconnue » quand aucune lecture n'a eu lieu.

Un job terminé en jeu n'apparaît donc pas forcément tout de suite : il arrive à la prochaine échéance de 30 minutes, et le cache ESI peut ajouter un délai.

## Si quelque chose ne s'affiche pas

- **Inventaire vide** : la première synchronisation n'est pas arrivée. Attendez la fin de « Synchronisation en cours », ou cliquez sur **Rafraichir depuis EVE**.
- **Personnage à reconnecter dans À faire** : **Re-autoriser** sur sa carte, dans **Personnages**.
- **Frais du vendeur inconnus** : faites l'étape 5 avec un personnage principal dont le jeton est valide.
- **Aucun bonus dans un projet** : ajoutez vos structures (étape 4).
- **Autres questions** : la [FAQ des chiffres qui semblent faux](/help?page=faq/numbers-that-look-wrong).

Les pages de calcul de chaque chiffre lu ici (frais de vente, coût d'un job) seront ajoutées à la section « Comment c'est calculé » du centre d'aide. En attendant, le calcul du coût d'installation est décrit dans le guide des structures de l'étape 4.
