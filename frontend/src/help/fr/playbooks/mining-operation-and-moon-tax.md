# Opération de minage et taxe lunaire : du plan au règlement

Ce guide suit une opération de minage dans l'ordre où on la mène : planifier, inviter, démarrer, terminer, lire les parts, régler. Il suit ensuite votre ledger personnel, puis la taxe lunaire d'une raffinerie, côté gestionnaire et côté mineur. Chaque étape nomme l'écran et le bouton tels qu'ils s'affichent.

Tout se passe dans le module **Minage** du menu latéral, qui a cinq onglets : **Opérations**, **Lunes**, **Sites**, **Valeur du minerai**, **Mon minage**. Les heures de l'application sont en EVE time (UTC).

> Quelques libellés de l'onglet **Mon minage** s'affichent sans accent en français (**Derniere sync :**, **Jamais synchronise**). Ils sont cités tels quels.

## La règle qui traverse tout le guide : inconnu n'est jamais 0

Quand une quantité ou un prix manque, la valeur affichée est **inconnue**, jamais 0 ni une somme partielle. Une seule quantité inconnue rend la valeur totale et toutes les parts inconnues, et l'opération ne peut pas être figée tant que le cas n'est pas réglé. Les causes sont listées à l'écran.

## Partie 1. Mener une opération de minage

Une opération passe par des statuts, suivis dans la barre d'étapes en haut de l'opération (**Chacun pour soi** s'arrête à **Terminée**, le mode prorata à **Réglée**) :

| Statut | Ce qui se passe |
|---|---|
| **Planifiée** | Les inscrits s'ajoutent, les règles se règlent. |
| **En cours** | L'instantané de début de chaque personnage est pris. |
| **Clôture** | L'instantané de fin est lu, les quantités et l'aperçu des parts apparaissent. |
| **Parts** | Les parts sont figées, les paiements se suivent. |
| **Réglée** | Tous les paiements sont marqués payés, l'opération est en archive, en lecture seule. |
| **Terminée** | Fin de l'opération en mode **Chacun pour soi** (rien à régler), en archive. |

Le propriétaire (**Owner**) et les **Admin** mènent l'opération. Un participant simple ne démarre ni ne termine l'opération : il rejoint, quitte tant que l'opération est **Planifiée**, part plus tôt et lit.

### 1. Créer l'opération

1. Menu **Minage**, onglet **Opérations**, bouton **Nouvelle opération**.
2. Dans **Nouvelle opération de minage**, remplissez :
   - **Libellé** (obligatoire) et **Début (EVE time)** ;
   - **Visibilité** : **Privée (lien)** (réglage par défaut) ou **Ma corporation**. **Ma corporation** est grisé si votre corporation est inconnue ;
   - **Répartition** : **Prorata de la valeur**, **Chacun pour soi** (chacun garde son minerai, pas de part, pas de paiement), ou **Temps pondéré**, marqué **bientôt** et non sélectionnable ;
   - **Récurrence** : **Une seule fois**, **Chaque semaine** (même jour et même heure EVE que le début) ou **À chaque chunk d'une raffinerie** (une opération par extraction, à l'arrivée du chunk) ;
   - **Vaisseau de boost et compresseurs**, facultatif (**Non déclaré** par défaut, modifiable ensuite dans le bloc **Valorisation**) ;
   - **Vos personnages** : cochez ceux qui minent (**Tous les prêts** et **Aucun** aident).
3. Cliquez sur **Créer l'opération**. L'écran **Opération créée** propose **Ouvrir l'opération**.

Un personnage ne peut être que dans une seule opération ouverte. Un personnage dont le jeton est invalide est marqué **à reconnecter** : sa quantité serait inconnue, reconnectez-le avant de le cocher.

**Depuis un chunk lunaire** : onglet **Lunes**, vue **Chunks**, bouton **Planifier l'opération** sur la ligne du chunk. Le système du chunk est repris et fixé au démarrage (message **Le système est fixé au démarrage de l'opération**). Un chunk qui a explosé n'est plus proposé.

### 2. Inviter les participants

Sur la page de l'opération, le bloc **Lien d'invitation** donne le lien à copier (**Copié !**). **Renouveler le lien** invalide l'ancien.

- Un membre de votre corporation est accepté d'office.
- Un membre d'une autre corporation voit **Demande envoyée** : le propriétaire ou un admin doit l'accepter. Dans le bloc **Inscrits**, la ligne porte **demande en attente**, avec **Accepter** et **Refuser**.
- Un participant peut **Quitter l'opération** tant qu'elle est **Planifiée**. Le propriétaire peut la **Supprimer** dans ce statut seulement.

Une opération **Ma corporation** apparaît aussi dans l'onglet **Disponibles** de la liste, avec un bouton **Rejoindre**. Vos opérations sont dans **Mes opérations**.

### 3. Régler la valorisation et les parts

Le bloc **Valorisation** se règle tant que l'opération est **Planifiée**, **En cours** ou en **Clôture**. Bouton **Enregistrer les réglages**.

| Réglage | Choix (valeur par défaut en premier) |
|---|---|
| **Base de valorisation** | **Compressé**, **Brut**, **Retraité**. Une unité compressée vaut une unité brute. |
| **Marché** | **Jita** ou **Structure suivie** |
| **Côté** | **Achat (buy)** ou **Vente (sell)** |
| **Rendement de retraitement** | Affiché avec la base **Retraité**, pré-rempli depuis votre profil de retraitement |
| **Part du booster** et **Part corporation** | 0 % par défaut |
| **Booster** | **Aucun**, ou un participant |

La règle affichée sous le formulaire résume le calcul : **G** est la somme des quantités multipliées par le prix unitaire de la base. Le booster reçoit G × part du booster, la corporation G × part corporation, et chaque participant sa valeur minée × le reste. Le calcul se fait en ISK entiers : le reste d'arrondi va aux plus grandes fractions, et la ligne de contrôle l'affiche (**reste d'arrondi**).

En **Chacun pour soi**, il n'y a ni part ni booster ni paiement : la valeur minée de chacun est seulement calculée.

Le bloc **Fleet storage** (destinataire à choisir, chaque participant déclare s'il verse son minerai) crée une dette du destinataire envers chaque déposant : sa part moins ce qu'il a gardé. Un **Container corpo à vérifier** (nom exact) permet de comparer ce qui a été versé aux assets de la corporation ; le constat n'empêche pas de figer les parts.

Avant le démarrage, le bloc **Combien de mineurs ?** estime le nombre de mineurs à partir de vos propres sites scannés jusqu'à épuisement. Il n'affiche rien tant qu'il manque d'historique (trois sites de ce type avec un équipage connu au minimum), jamais une durée inventée.

### 4. Démarrer

Bouton **Démarrer l'opération** (propriétaire ou admin). Le formulaire **Démarrer « … »** propose **Système (facultatif)** :

- avec un système, seules les lignes du ledger de ce système comptent (**Compté dans … seulement**) ;
- sans système (**Tous les systèmes**), le minage hors opération pendant la fenêtre est inclus (**Compté dans tous les systèmes**).

Bouton **Démarrer**. L'instantané de début du ledger ESI de chaque personnage est pris à ce moment. Un inscrit en retard a son instantané à l'acceptation. Sous **Inscrits**, chaque personnage porte son état : **✓ début n/m**, ou **✗ début n/m**. Un instantané illisible se relance par **Relancer**. Un personnage à reconnecter donne **instantané de début non lu, quantité inconnue**.

### 5. Pendant l'opération

- La présence (**En ligne**, **AFK · n min**, **Parti**, **Pas encore vu**) vient du dernier signe de vie dans EVE Tools, pas de l'état en jeu que l'ESI ne donne pas.
- **Je pars** (départ anticipé) prend votre instantané de fin tout de suite et ne se défait pas. Il borne votre minage.
- Le propriétaire ou un admin peut cliquer sur **Terminer l'opération** à tout moment.

### 6. Terminer et attendre le ledger de fin

Bouton **Terminer l'opération**, puis **Terminer à …** dans la confirmation. Ce qui est miné ensuite ne compte pas.

L'ESI garde le ledger de minage en cache 10 minutes. L'écran **En attente du ledger de fin** affiche l'heure de lecture (**lecture dans**) et la progression (**n / m personnages**). Rien n'est estimé en attendant. Le statut est **Clôture**.

### 7. Lire les quantités

Le bloc **Quantités minées** donne, par personnage et par minerai, la différence entre le ledger de fin et celui du début. Une quantité est **inconnue** (jamais 0) quand :

- le jeton du personnage est invalide (**✗ token invalide · quantité inconnue**) ou la lecture a échoué (**✗ lecture impossible**) ;
- l'instantané de fin manque (**✗ pas d'instantané de fin · quantité inconnue**) ;
- l'opération est trop ancienne pour le ledger, qui couvre 30 jours (**✗ hors des 30 jours du ledger**) ;
- une ligne de fin est inférieure à celle du début, ce qui rend le ledger incohérent.

Une quantité encore en cours de lecture s'affiche **en mesure**. Le propriétaire ou un admin peut **Corriger une quantité** (alt oublié, minage hors site) ; la correction garde la trace de qui, quand et de la valeur mesurée d'origine.

### 8. Lire l'aperçu des parts, puis les figer

En **Clôture**, l'**Aperçu de la répartition au prorata de la valeur** affiche, par participant, **Quantité (u)**, **Valeur minée**, **% de G**, **Part**, **ISK/h**, et les cartes **Valeur totale G**, **Part du booster**, **À répartir** et **ISK/h de l'opération**. Les prix sont lus maintenant et recalculés à chaque lecture (**Relire les prix**).

Bouton **Figer les parts**, puis confirmation. Les prix du moment sont copiés dans l'opération, et quantités, prix et parts ne changent plus. Le bouton est refusé, avec la raison à l'écran, si :

- l'opération n'est pas en **Clôture** ;
- une quantité est inconnue ou en mesure (les noms sont listés) ;
- un minerai n'a pas de prix sur la base choisie ;
- une part du booster est fixée sans **Booster** accepté dans l'opération ;
- un participant déclare verser du minerai sans destinataire du fleet storage accepté, ou verse plus que ce qu'il a miné.

### 9. Payer, puis régler

Statut **Parts**. Le bloc **Paiements** liste qui doit quoi à qui (**… doit … ISK à …**) :

- Chacun garde son minerai et verse au booster la différence entre sa valeur minée et sa part. Le booster reverse la part corporation. Sans booster, la différence va à la corporation.
- Le propriétaire ou un admin coche la ligne à la main quand le don est fait en jeu : l'application ne détecte pas le don.

Quand tous les paiements sont marqués payés, bouton **Marquer réglée**, puis confirmation : l'opération passe en archive, en lecture seule. En **Chacun pour soi**, le bouton est **Marquer terminée** : les prix et les valeurs minées sont figés, rien n'est à régler.

L'onglet **Archive** de la liste montre vos opérations réglées ou terminées et celles de votre corporation ouvertes à la corporation. Le bloc **Récapitulatif à partager** copie un texte ou une image à coller dans Discord (**Copier le texte**, **Copier l'image**). Aucun webhook n'est utilisé et le texte ne contient aucun nom de participant.

## Partie 2. Suivre son ledger personnel

Onglet **Mon minage**, vue **Ledger**. Le ledger ESI de vos personnages est synchronisé toutes les heures ; la ligne **Derniere sync :** donne l'heure, et **Actualiser** relance la lecture. Les boutons de période (et **YTD**) filtrent ce que vous lisez.

La valeur affichée (**Valeur totale**, **ISK / jour actif**) vient de vos réglages de l'onglet **Valeur du minerai** (structure, taxe d'export). Un minerai sans prix rend la valeur inconnue et il est nommé (**Minerais sans prix : …**), jamais compté pour 0. La vue **Saison** donne, pour votre corporation, les opérations terminées sur la période (**30 jours**, **90 jours**, **Tout**) : heures minées par joueur et ISK/h par vaisseau. Une opération dont les parts ne sont pas figées compte ses heures mais pas son ISK (**Valeur de la saison inconnue**).

## Partie 3. La taxe lunaire d'une raffinerie

Onglet **Lunes**, vue **Taxe lunaire**. Deux affichages : **Gestionnaire** et **Ma taxe**. Si aucune de vos corporations ne vous est gérable, l'application ouvre directement **Ma taxe**.

### Comment la taxe est calculée

Pour chaque personnage, chaque raffinerie et chaque période, d'après le ledger de la raffinerie :

taxe = somme, sur chaque type de minerai, de **quantité × prix unitaire × taux de la rareté ÷ 100**, arrondie à 2 décimales par ligne.

- Le **prix unitaire** est le prix **Jita buy** du minerai en équivalent compressé, sans taxe d'export ni rendement de retraitement.
- La **rareté** (R4, R8, R16, R32, R64) vient du groupe du minerai lunaire. Chaque rareté a son taux, réglé par le gestionnaire.
- Un type sans prix, ou hors des groupes de minerai lunaire, rend la taxe **inconnue** : le relevé s'affiche **Inconnu**, **Dû** et **Reste** montrent **inconnue** et la liste **sans prix : …**, jamais 0.
- La période est **Hebdomadaire** (du lundi 00:00 UTC au lundi suivant) ou **Mensuelle** (du 1er 00:00 UTC au 1er du mois suivant), au choix de chaque raffinerie. Une ligne du ledger appartient à la période qui contient sa date.

### Relevé provisoire et relevé figé

- Tant que la période est en cours, la ligne est **Provisoire**, calculée aux prix du moment, avec la mention **période en cours** à la place de la référence.
- Une période terminée est figée dès que le ledger de la raffinerie a été lu au moins 1 heure après sa fin. Le **relevé** porte alors ses lignes, ses prix et ses taux figés : il ne change plus. Il reçoit une **référence** de la forme `TAX-<année>-W<semaine>-XXXX` (hebdomadaire) ou `TAX-<année>-M<mois>-XXXX` (mensuelle), à copier avec **Copier**.

### Côté gestionnaire : ouvrir une raffinerie

Il faut le rôle corporation **Accountant** (ou **Director**) sur un de vos personnages, avec le scope de lecture des rôles. Sans cela, la corporation affiche **Accès gestionnaire refusé**, **Rôles de la corporation illisibles**, ou **Aucune de tes corporations ne t'est gérable**, avec le détail du rôle manquant ou du personnage à reconnecter. Les rôles mettent jusqu'à environ 1 h à apparaître côté ESI.

1. Onglet **Lunes**, vue **Taxe lunaire**, affichage **Gestionnaire**. Un panneau par corporation, avec une carte par raffinerie. La liste vient des extractions : **Aucune raffinerie connue, synchronisez les extractions.** si elle est vide.
2. Remplissez la carte : **Ouverte à l'alliance** (case), **Période** (**Hebdomadaire** ou **Mensuelle**), **Division du wallet** (de 1 à 7), **Taux R4 (%)**, **R8**, **R16**, **R32**, **R64** (de 0 à 100 chacun). Tous les champs sont obligatoires.
3. **Enregistrer** : le message **Réglage enregistré** confirme.

**La case Ouverte à l'alliance est ce qui ouvre la raffinerie** : seules les raffineries cochées sont lues par la synchronisation, comptées dans les relevés et visibles des mineurs. Décochée, la raffinerie n'apparaît plus.

La synchronisation tourne toutes les heures pour chaque corporation qui a une raffinerie ouverte ; le bouton **Synchroniser** la lance tout de suite. Elle lit deux sources, affichées sous **Ledger de minage** et **Paiements** quand elles posent problème (**pas encore synchronisé**, **accès refusé**, **à reconnecter**, **erreur ESI**, avec **dernière lecture le**) :

- le ledger des observers, avec le rôle **Accountant** et le scope de lecture du minage corporation ;
- le journal du wallet de la **Division du wallet** choisie, avec le rôle **Accountant** ou **Junior_Accountant** et le scope de lecture des wallets corporation.

### Côté gestionnaire : suivre les relevés

Le tableau liste **Personnage**, **Corporation**, **Alliance**, **Raffinerie**, **Période**, **Référence**, **Dû**, **Payé**, **Reste**, **Statut**. Le filtre **Tous** / **En retard** et **Exporter CSV** sont au-dessus, les totaux **Dû**, **Payé**, **Reste**, **Hors alliance** en dessous (un total partiel s'affiche **inconnu**, **dont … connus**).

| Statut | Quand |
|---|---|
| **Provisoire** | Période en cours |
| **À payer** | Relevé figé, rien reçu |
| **Partiel** | Une partie du montant est couverte par des dons |
| **En retard** | Reste à payer, plus de 7 jours après la fin de la période |
| **Payé** | Dons reçus suffisants, ou **Marquer payé** cliqué |
| **Annulé** | **Annuler** puis **Confirmer l'annulation** (**Garder** pour renoncer) |
| **Inconnu** | Taxe inconnue (prix ou rareté manquant) |

**Marquer payé** est offert pour **À payer**, **Partiel** et **En retard**. Un relevé annulé ne peut plus être marqué payé, et un relevé marqué payé ne peut plus être annulé. **Annuler** n'est pas offert sur un relevé provisoire ou déjà annulé. La mention **Hors alliance** signale un relevé dont la corporation enregistrée n'est pas dans l'alliance du propriétaire de la raffinerie (alliances différentes, ou l'une des deux corporations sans alliance) ; elle reste vide quand l'ESI n'a pas répondu.

### Côté gestionnaire : attribuer les dons

Le panneau **Dons reçus** liste 90 jours de dons faits à la corporation (filtre **À attribuer** ou **Tous**). L'application range les dons d'un personnage sur ses relevés dans l'ordre des périodes, les plus anciennes d'abord. **Un don paie les relevés du personnage qui l'a fait, reçus à partir du début de la période.** Si le mineur a payé depuis un autre personnage, choisissez **Choisir un mineur**, puis **Attribuer** : le don compte alors pour ce mineur.

### Côté mineur : lire et payer

Affichage **Ma taxe** : vos relevés, tous personnages confondus, groupés par corporation, avec **Période**, **Raffinerie**, **Personnage**, **Dû**, **Payé**, **Reste**, **Statut**, **Référence**. Le bloc **Comment payer** dit quoi faire : un don en jeu du montant restant à la corporation, avec la référence du relevé en motif. Le paiement est reconnu après la prochaine synchronisation du journal du wallet, ce qui peut prendre jusqu'à une heure. Si vous payez depuis un autre personnage, le gestionnaire rapproche le paiement à la main. **Aucune taxe lunaire pour vos personnages** s'affiche si rien n'est dû.

## Pièges connus

- **Raffinerie décochée** : sans **Ouverte à l'alliance**, aucun relevé, aucune synchronisation, aucune ligne chez les mineurs.
- **Le motif du don n'est pas lu** : l'application associe un don à un relevé par le personnage donateur et la date, pas par la référence `TAX-…`. La référence aide le gestionnaire à vérifier à la main.
- **Rôles** : les deux synchronisations exigent le rôle **Accountant** (ou **Junior_Accountant** pour le wallet). Un personnage sans le rôle, ou au jeton invalide, laisse la corporation sans données.
- **Quantité inconnue d'un alt** : un alt à reconnecter ou sorti de la fenêtre du ledger bloque **Figer les parts** pour toute l'opération, jusqu'à reconnexion ou **Corriger une quantité**.
- **Opération sans système** : le minage hors opération pendant la fenêtre est compté.
- **Temps pondéré** : annoncé, non disponible.
