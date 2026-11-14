# Mener une opération de minage et suivre la taxe lunaire

Ce playbook couvre deux sujets liés : l'opération de minage (plusieurs joueurs, instantanés du ledger, parts) et la taxe lunaire d'une raffinerie (relevés, paiements). EVE Tools lit l'ESI et calcule, il ne déplace jamais d'ISK : les dons et les paiements se font en jeu.

Qui fait quoi :

- l'organisateur (rôle « Owner ») et ses « Admin » pilotent l'opération : étapes 1 à 11 ;
- les participants la rejoignent : étape 2 ;
- le gestionnaire d'une raffinerie règle la taxe : étapes 12 à 16, avec le rôle Accountant (ou Director) ;
- chaque mineur lit sa taxe et la paie : étape 17.

Les libellés entre guillemets « » sont ceux de l'écran. Les heures sont en EVE time (UTC).

## 0. Avant de commencer

- Chaque personnage qui minera doit avoir l'autorisation de lire son ledger de minage (scope ESI du minage du personnage). Sans elle, ou si l'autorisation n'est plus valide, il est « à reconnecter » et ne peut pas être inscrit. Voir [Premiers pas](/help?page=playbooks/getting-started), étape 4.
- Un personnage ne peut être que dans une seule opération ouverte, c'est-à-dire « Planifiée », « En cours » ou « Clôture ». Il est « déjà dans « … » » tant que l'autre n'est pas passée en « Parts », « Réglée » ou « Terminée ».
- La visibilité « Ma corporation » exige une corporation connue. Sinon l'écran dit « Corporation inconnue : visibilité privée seulement. ».

## 1. Créer l'opération

Menu « Minage », onglet « Opérations », bouton « Nouvelle opération ». Pour partir d'un chunk lunaire, voir l'étape 13.

Le formulaire « Nouvelle opération de minage » demande :

- « Libellé » (obligatoire, 100 caractères au plus).
- « Début (EVE time) » : prochaine heure pleine par défaut. C'est la date prévue et la référence des récurrences. Le démarrage réel est l'instant où vous cliquez sur « Démarrer l'opération » (étape 4), pas cette heure.
- « Visibilité » : « Privée (lien) » ou « Ma corporation » (par défaut si votre corporation est connue). Une opération « Ma corporation » apparaît dans l'onglet « Disponibles » des membres de la corporation.
- « Répartition » : « Prorata de la valeur » ou « Chacun pour soi » (chacun garde son minerai : pas de part, pas de paiement). « Temps pondéré » est grisé, marqué « bientôt ».
- « Vaisseau de boost et compresseurs » : facultatif, à déclarer si un booster compresse sur place.
- « Récurrence » : voir l'étape 11.
- « Vos personnages » : votre principal est coché s'il est prêt. « Tous les prêts » et « Aucun » aident à cocher. Il en faut au moins un.

Le bouton « Créer l'opération » affiche « Opération créée », « L'opération est planifiée. », le lien d'invitation et « Ouvrir l'opération ».

## 2. Inviter et accepter les participants

Sur l'opération, la carte « Lien d'invitation » donne l'adresse à partager (bouton « Copier »). Tout participant accepté la voit.

- Le destinataire ouvre le lien, choisit ses personnages (« prêt », « à reconnecter » ou « déjà dans « … » ») puis « Rejoindre avec N personnages ». Autre voie : onglet « Disponibles » puis « Rejoindre ».
- On ne rejoint que tant que l'opération est « Planifiée » ou « En cours ».
- Un membre de la corporation de l'organisateur est accepté tout de suite, même si l'opération est « Privée (lien) ». Les autres ont une « demande en attente » : « Accepter » ou « Refuser » (Owner et Admin, qui reçoivent une notification de la catégorie « Opérations de minage »).
- « Renouveler le lien » invalide l'ancien (Owner et Admin, opération « Planifiée » ou « En cours »).
- Dans « Inscrits », l'Owner peut « Nommer admin » / « Retirer admin », et retirer un participant. Un participant « Quitter l'opération » tant qu'elle est « Planifiée ». L'Owner peut « Supprimer l'opération » tant qu'elle est « Planifiée ».

## 3. Régler la valorisation (Owner et Admin)

Carte « Valorisation », modifiable tant que l'opération est « Planifiée », « En cours » ou « Clôture » :

- « Base de valorisation » : « Brut », « Compressé » (par défaut) ou « Retraité » (avec un « Rendement de retraitement »).
- « Marché » : « Jita » (par défaut) ou « Structure suivie ». « Côté » : « Achat (buy) » (par défaut) ou « Vente (sell) ».
- « Part du booster » et « Part corporation » : 0 à 100 %, 0 par défaut. « Booster » désigne le participant qui reçoit la part du booster.
- « Enregistrer les réglages ».

La règle de partage est affichée sous la carte : G est la somme des quantités par le prix unitaire de la base, le booster prend G × sa part, la corporation G × sa part, chaque participant sa valeur × le reste. Prix unitaire et valeur : [valeur du minerai et taxe lunaire](/help?page=calculations/ore-value-moon-tax).

## 4. Démarrer

Bouton « Démarrer l'opération » (Owner ou Admin, opération « Planifiée »). La fenêtre propose « Système (facultatif) » :

- avec un système, seule la quantité minée dans ce système compte (« Compté dans … seulement ») ;
- avec « Tous les systèmes », tout ce que le personnage a miné entre les deux instantanés compte, y compris hors opération (« le minage hors opération pendant la fenêtre est inclus »).

« Démarrer » passe l'opération « En cours » et prend, pour chaque personnage inscrit, l'instantané de début de son ledger. Dans « Inscrits », « ✓ début 2/3 » indique les instantanés lus. Un échec s'affiche en « ✗ » (« token invalide · quantité inconnue », « lecture impossible ») : « Relancer » retente la lecture (Owner, Admin ; le participant lui-même hors « Clôture »). Un personnage « à reconnecter » doit d'abord être reconnecté.

## 5. Pendant l'opération

- Un participant accepté après le démarrage a son instantané de début à l'acceptation : ce qu'il a miné avant n'est pas compté.
- « Présence » : « En ligne », « AFK · N min », « Parti », « Pas encore vu ». Elle vient de l'écran de l'opération ouvert dans EVE Tools, pas de l'état en jeu.
- « Je pars » prend tout de suite l'instantané de fin de vos personnages, sans retour possible.
- Les parts se calculent uniquement avec l'instantané de début et l'instantané de fin. Aucune quantité en cours d'opération n'entre dans les parts.

## 6. Terminer

« Terminer l'opération » (Owner ou Admin), puis « Terminer à <heure> ». L'opération passe en « Clôture ». Ce qui est miné ensuite ne compte pas.

L'instantané de fin est lu quand le cache ESI du ledger expire, soit environ 10 minutes après la fin : la page « En attente du ledger de fin » donne l'heure de lecture. La progression s'affiche (« N / M personnages »). Quand tous les instantanés sont réglés, chaque participant reçoit la notification « Mining ledger ready ».

Seuls les personnages dont l'instantané de début a été lu reçoivent un instantané de fin. Une lecture de fin 30 jours ou plus après le jour du démarrage est refusée : « ✗ hors des 30 jours du ledger ».

## 7. Contrôler les quantités

La carte « Quantités minées » donne une ligne par participant :

- « inconnue » : instantané manquant, token invalide, lecture impossible, hors des 30 jours, ou ledger incohérent (quantité de fin inférieure à celle du début). Elle n'est jamais comptée comme 0 ;
- « en mesure » : un instantané n'est pas encore lu.

« Corriger une quantité » (Owner ou Admin, seulement en « Clôture ») : choisissez le personnage, le type et la quantité. La valeur saisie remplace la valeur mesurée pour ce type et la correction est tracée (qui, quand, valeur d'origine). Pour rendre connue une quantité inconnue, il faut saisir chaque type miné : seuls les types corrigés comptent alors.

## 8. Figer les parts, ou terminer

Pendant la « Clôture », l'« Aperçu de la répartition au prorata de la valeur » relit les prix à chaque lecture (« Relire les prix »). Un prix ou une quantité manquante donne « Valeur totale et parts inconnues. Rien n'est compté pour 0. ».

- « Prorata de la valeur » : « Figer les parts » (Owner ou Admin), puis confirmez. Les prix du moment sont copiés dans l'opération, l'opération passe « Parts » et les réglages ne changent plus. Le bouton refuse si : une quantité est inconnue ou en mesure ; un minerai n'a pas de prix sur la base ; la part du booster est supérieure à 0 sans booster choisi ; des participants versent du minerai au fleet storage sans destinataire choisi, ou plus qu'ils n'ont miné.
- « Chacun pour soi » : « Marquer terminée ». Mêmes refus pour les quantités et les prix. Rien n'est à régler, l'opération passe « Terminée » en archive.

## 9. Payer et régler

À l'état « Parts », la carte « Paiements » liste qui doit combien à qui (« X doit N ISK à Y »). Chacun garde son minerai et verse la différence entre sa valeur minée et sa part : au booster, qui reverse la part corporation, ou directement à la corporation quand la part du booster est à 0. Une règle supplémentaire s'affiche quand du minerai passe par le fleet storage.

Une fois le don fait en jeu, cochez la ligne (« payé »). L'écran laisse cocher l'Owner, les Admin et le payeur de la ligne. EVE Tools ne lit pas le wallet pour ces paiements : seule la case compte. Quand toutes les lignes sont payées, « Marquer réglée » puis confirmation : l'opération passe « Réglée », en archive, en lecture seule. L'onglet « Archive » liste les opérations « Réglée » et « Terminée ».

## 10. Notifications d'une opération

Catégorie « Opérations de minage » (titres en anglais) : « Mining operation join request » pour une demande d'inscription (Owner et Admin), « Mining ledger ready », « Mining shares computed » à « Figer les parts », « Payment received » quand un paiement est coché pour son bénéficiaire.

## 11. Opérations récurrentes

Dans le formulaire de l'étape 1, « Récurrence » propose « Une seule fois », « Chaque semaine » et « À chaque chunk d'une raffinerie » (si votre corporation a une raffinerie connue). Le choix est absent quand l'opération part d'un chunk.

- « Chaque semaine » : même jour et même heure EVE que le début. L'occurrence suivante est créée au plus tôt 7 jours avant son début (vérification toutes les 30 minutes). Elle est liée au chunk de la corporation qui arrive ce jour-là, s'il n'y en a qu'un.
- « À chaque chunk d'une raffinerie » : une opération par extraction de la raffinerie choisie, à l'arrivée du chunk, pour les extractions commencées après la création de la récurrence.
- Une occurrence reprend le libellé, la visibilité, la répartition, la valorisation (base, marché, côté, structure, rendement, parts, booster si c'était vous) de la plus récente. Le vaisseau de boost et les compresseurs ne sont pas repris : à redéclarer. Elle inscrit vos personnages qui ne sont dans aucune autre opération ouverte : ceux de l'occurrence précédente manquent si elle est encore « Planifiée », « En cours » ou « Clôture ».
- Les occurrences portent l'icône ↻. Le panneau « Récurrences » de « Mes opérations » donne la règle et « Prochaine : », avec « Arrêter » puis « Arrêter la récurrence » : les opérations existantes restent. Une récurrence s'arrête d'elle-même si la corporation de son organisateur a changé.

## 12. Rôles et lectures de la taxe lunaire

Trois lectures différentes, toutes faites avec le jeton d'un personnage de la corporation inscrit dans EVE Tools, pas forcément le vôtre :

| Donnée | Rôle du lecteur | Fréquence |
|---|---|---|
| Chunks (extractions) | Station_Manager ou Director | 30 minutes, ou « Rafraîchir » |
| Ledger de la raffinerie | Accountant ou Director | 1 heure, ou « Synchroniser » |
| Journal du wallet (dons) | Accountant, Junior_Accountant ou Director | 1 heure, ou « Synchroniser » |

Tout utilisateur qui a un personnage dans une corporation de joueurs voit les chunks de cette corporation, avec ou sans rôle. Pour **gérer** la taxe (vue « Gestionnaire »), un de vos propres personnages de la corporation doit avoir Accountant ou Director. Sinon l'écran dit « Accès gestionnaire refusé » ou « Aucune de tes corporations ne t'est gérable », et seule la vue « Ma taxe » reste (étape 17). Scopes et fraîcheur : [fraîcheur et confidentialité des données](/help?page=data/freshness-privacy).

## 13. Lire les chunks

Menu « Minage », onglet « Lunes », sous-onglet « Chunks ». Affichages « Liste », « Semaine », « Mois » ; « EVE time » ou « Heure locale ». Les chunks restent visibles jusqu'à 14 jours après leur décroissance naturelle.

- « Statut » : « Extraction » avant l'arrivée du chunk, « Prêt » de l'arrivée à la « Décroissance naturelle », « Explosé » ensuite.
- « Raffineries » : « Suivre » pour être notifié (catégorie « Chunks lunaires », réglable dans « Compte », « Parametres », section « Notifications ») une heure avant l'arrivée et à l'arrivée. Chaque alerte part une fois, jamais pour un événement antérieur au suivi, avec une vérification toutes les 5 minutes.
- « Valeur » : estimation du chunk avant booster et taxe, à partir de la composition de la lune. Sans scan : « valeur inconnue · coller le scan de la lune ». Ouvrez la ligne, collez le résultat de la Moon Survey Probe dans « Scan de la lune » puis « Enregistrer la composition ». Un chunk sans prix d'un minerai reste non valorisé plutôt que partiellement valorisé. Le premier collage est ouvert à tout membre ; le remplacer (« Coller un nouveau scan ») demande d'être l'auteur, Director ou Station_Manager. Chiffres : [valeur du minerai et taxe lunaire](/help?page=calculations/ore-value-moon-tax).
- « Planifier l'opération » (chunk non « Explosé ») ouvre le formulaire de l'étape 1 avec le nom de la lune en libellé et l'arrivée du chunk comme début. Le système du chunk est fixé au démarrage. Quand une opération est liée, « Opération liée → » remplace le bouton, et l'opération porte la carte « Chunk lié ».
- « Mineurs » (chunk non « Extraction ») : « Mineurs de ce chunk, taxe estimée au prix actuel ». Réservé aux comptables (« Réservé aux comptables de la corporation »). Le ledger de la raffinerie est daté au jour : un minage va au chunk dont l'intervalle, du jour d'arrivée au jour de décroissance, contient ce jour (le plus récent si deux se recouvrent). Le relevé affiché vaut pour toute sa période, pas seulement ce chunk.

## 14. Ouvrir une raffinerie à la taxe (gestionnaire)

Sous-onglet « Taxe lunaire », vue « Gestionnaire ». Une carte par raffinerie connue par les chunks (sinon « Aucune raffinerie connue, synchronisez les extractions. »).

- « Ouverte à l'alliance » : c'est le seul interrupteur qui fait lire la raffinerie. Décochée, aucun ledger n'est lu, aucun relevé créé, aucune ligne « Provisoire ». Malgré son nom, il ne filtre pas par alliance : tous les mineurs du ledger de la raffinerie sont pris.
- « Période » : « Hebdomadaire » (lundi 00:00 UTC) ou « Mensuelle » (le 1er 00:00 UTC).
- « Division du wallet » : 1 à 7, la division où les dons arrivent. Elle sert à lire le journal.
- « Taux R4 (%) » à « Taux R64 (%) » : 0 à 100, deux décimales. Les cinq taux et la division sont obligatoires pour « Enregistrer » (« Réglage enregistré »).

La taxe d'un type est sa quantité × le prix d'achat Jita du minerai compressé × le taux de sa rareté. Un type sans prix, ou hors des groupes de minerais lunaires, rend la taxe inconnue. Détail : [valeur du minerai et taxe lunaire](/help?page=calculations/ore-value-moon-tax).

## 15. Synchroniser et lire les relevés

Le bouton « Synchroniser » lance la lecture sans attendre l'heure (« Sync N % »). Les bandeaux « Ledger de minage : … » et « Paiements : … » disent l'état de chaque lecture (« pas encore synchronisé », « accès refusé », « à reconnecter », « erreur ESI », « dernière lecture le … »). Les deux lectures sont indépendantes.

**Quand un relevé existe.** La période est terminée et le ledger a été lu avec succès au moins une heure après sa fin, à la fin d'une synchro de ledger réussie. Le relevé fige alors les lignes, les prix et les taux, et reçoit une référence `TAX-<année>-W<semaine>-xxxx` (hebdomadaire) ou `TAX-<année>-M<mois>-xxxx` (mensuel). Un changement de taux ou de prix, ou une ligne de ledger tardive, ne le modifie plus. La période en cours n'a pas de relevé : elle est « Provisoire » (« période en cours »), calculée à chaque lecture avec les prix du moment.

| « Statut » | Sens |
|---|---|
| « Provisoire » | Période en cours, sans référence. |
| « Inconnu » | Au moins un type sans prix ou hors des groupes lunaires : « inconnue » et « sans prix : » suivi des numéros de type. Jamais 0, et aucun don n'est appliqué. |
| « À payer » | Rien de payé, moins de 7 jours après la fin de la période (ou 7 jours pile). |
| « Partiel » | Une partie est payée, dans le même délai. |
| « En retard » | Reste à payer, plus de 7 jours après la fin de la période. |
| « Payé » | Reste nul, par dons ou par « Marquer payé ». |
| « Annulé » | Annulé par un gestionnaire, sorti des totaux. |

Colonnes : « Personnage », « Corporation », « Alliance » (celles de la dernière ligne du ledger de la période), « Raffinerie », « Période », « Référence », « Dû », « Payé », « Reste », « Statut », « Alliance » (« Hors alliance » si la corporation du mineur n'est pas dans l'alliance de la raffinerie ; simple drapeau, sans effet sur le montant) et « Actions ». Au-dessus : « Dû », « Payé », « Reste » (« inconnu » avec « dont … connus » si une ligne est inconnue) et le nombre « Hors alliance ». Les relevés « Provisoire » comptent dans ces totaux. Filtre « Tous » / « En retard », « Exporter CSV » avec le même filtre.

Actions : « Marquer payé » (statuts « À payer », « En retard », « Partiel »), « Annuler » puis « Confirmer l'annulation » ou « Garder » (sauf « Annulé » et « Provisoire »). Un relevé marqué payé à la main ne peut plus être annulé, et inversement, et « Marquer payé » ne se défait pas.

EVE Tools n'envoie aucune notification sur les relevés.

## 16. Rapprocher les dons (gestionnaire)

La section « Dons reçus » liste les dons en ISK reçus par le wallet de la corporation sur 90 jours (« À attribuer » par défaut, ou « Tous »), avec « Date », « Donateur », « Montant », « Motif », « Attribution » et « Attribuer à ».

Un don paie un relevé si les trois conditions sont réunies : même corporation, même payeur (le personnage auquel un gestionnaire l'a attribué, sinon le donateur), et date du don postérieure ou égale au début de la période du relevé. Un don paie d'abord le relevé le plus ancien ; le surplus passe au suivant. Les relevés « Payé » à la main, « Annulé » ou « Inconnu » n'absorbent rien. **Le « Motif » n'est jamais lu** : mettre la référence en motif aide à lire la liste, ne change pas le rapprochement.

« À attribuer » montre les dons dont le payeur n'a aucun relevé de la corporation (« Provisoire » compris). Pour un don fait depuis un autre personnage : « Choisir un mineur » puis « Attribuer ». La liste de mineurs vient des relevés affichés : avec le filtre « En retard », elle est réduite aux mineurs en retard. Un nouveau don apparaît à la synchro du journal suivante.

## 17. Côté mineur : « Ma taxe »

Vue « Ma taxe » (aucun rôle requis) : vos relevés groupés par corporation, avec « Période », « Raffinerie », « Personnage », « Dû », « Payé », « Reste », « Statut », « Référence » et « Copier ». Sans relevé : « Aucune taxe lunaire pour vos personnages ».

Pour payer, faites en jeu un don du « Reste » à la corporation, depuis le personnage qui a miné. L'encadré « Comment payer » demande aussi la référence en motif : c'est utile au gestionnaire, mais le rapprochement ne la lit pas. Payé depuis un autre personnage, le paiement doit être attribué par le gestionnaire (étape 16). La reconnaissance attend la synchro du journal : une heure au plus.

## Contrôle

- Opération : chaque personnage affiche « ✓ début » avant que vous commenciez ; en « Clôture », aucune quantité « inconnue » ; « Figer les parts » ne liste aucun refus.
- Taxe : « Ledger de minage » et « Paiements » n'affichent aucun bandeau d'accès ; chaque raffinerie suivie porte « Réglage enregistré » ; la ligne « Provisoire » de la période en cours existe pour chaque mineur.

## Pièges

- **Changer la « Période » d'une raffinerie qui a déjà des relevés.** Le code ne compare pas aux relevés d'un autre découpage : à la synchro suivante, les mêmes jours de minage peuvent produire un second relevé (mensuel après des relevés hebdomadaires, par exemple). Choisissez la période avant la première synchro.
- **Tout ce que l'ESI renvoie est enregistré,** y compris des jours antérieurs à l'ouverture de la raffinerie : des périodes déjà terminées peuvent produire des relevés tout de suite, aux taux saisis.
- **Le montant d'un « Provisoire » bouge,** puisqu'il suit les prix du moment. Seul le relevé figé est stable.
- **Un échec de lecture du ledger retarde les relevés.** Si le ledger n'a pas pu être lu, aucun relevé n'est créé à cette synchro. Sans personnage avec le rôle requis et le scope valide, la lecture reste « accès refusé » ou « à reconnecter » jusqu'à ce que quelqu'un se reconnecte.
- **Une occurrence récurrente peut être créée sans certains de vos personnages** si l'occurrence précédente est encore ouverte (étape 11).
- **Mettre fin trop tard.** L'instantané de fin lu 30 jours ou plus après le jour du démarrage est perdu : la quantité devient inconnue, à corriger à la main.
