# Participer à un projet de groupe en tant que membre

Ce playbook suit un projet de groupe du côté d'une personne qui n'en est pas propriétaire : rejoindre par le lien d'invitation, apporter des matériaux ou du travail, attendre la relecture, lire sa part, recevoir ses virements. Pour le côté propriétaire (publier, inviter, relire, vendre, terminer), voir [Mener un projet de groupe en tant que propriétaire](/help?page=playbooks/group-project-owner).

Les libellés entre guillemets « » sont ceux de l'écran. Plusieurs sont en anglais dans l'application, quelle que soit la langue (« Add Contribution », « Material », « Job Install », « Pending », « Approved », « Profit Distribution »), ou écrits sans accent (« Publie », « Termine », « Quantite », « Etape BOM »). Ils sont repris tels quels.

## 1. Rejoindre

Le propriétaire vous envoie un lien de la forme `<adresse d'EVE Tools>/industry/join/<code>`. Le raccourci `<adresse d'EVE Tools>/join/<code>` mène au même écran. Il n'existe pas d'écran où saisir un code : ouvrez le lien.

- **Pas encore connecté.** Vous arrivez sur l'écran de connexion, puis sur l'Accueil : le lien n'est pas rejoué. Rouvrez-le une fois connecté.
- **Corporation du propriétaire = la vôtre.** C'est la corporation de votre personnage principal, comparée à celle du personnage principal du propriétaire. Après « Adhésion au projet... », vous ouvrez directement le projet.
- **Autre corporation, ou corporation inconnue.** Vous voyez « Demande envoyée » et le bouton « Retour aux projets ». L'écran promet une notification à l'acceptation, mais EVE Tools n'en envoie aucune : prévenez le propriétaire, ou revenez voir. Sans réponse, rien ne change : le projet n'apparaît ni dans « Mes projets » ni dans « Disponibles », et rouvrir le lien vous ramène à la liste des projets, sans message. Une fois la demande acceptée, le projet apparaît dans « Mes projets ».
- **« Adhésion impossible ».** Le message est celui du serveur, en anglais. « Project not found » : le code est inconnu, ou le projet est privé (les deux cas sont indiscernables). « This project is no longer accepting new members » : le projet n'est plus « Publie » ni « En cours ».

Autre porte d'entrée, si vous êtes de la corporation du propriétaire : Menu « Industrie », onglet « Projets », onglet « Disponibles ». Il liste les projets « Publie » et non privés de votre corporation, avec « Rejoindre le projet » et l'acceptation immédiate.

**Piège : un participant retiré ou parti repasse toujours par une demande, même dans la corporation du propriétaire.** Le projet n'est plus listé dans « Disponibles » : rouvrez le lien, vous recevez « Demande envoyée » et le rôle de membre. Voir [Qui peut rejoindre un projet, et qui approuve ?](/help?page=faq/faq).

## 2. Lire l'écran du projet

Ouvrez le projet depuis « Mes projets ». Sous l'en-tête, la barre de phases (« Préparer », « Approvisionner », « Produire », « Vendre », « Clôturer ») puis la carte « Prochaine action ». Pour vous, elle peut montrer :

- « Approvisionner un matériau », bouton « Contribuer » : il ouvre le formulaire d'apport sur la ligne où il manque la plus grande quantité.
- « Lancer un job », bouton « Voir les jobs ».
- « Rien à faire pour vous » (« Le projet avance sans vous pour le moment ») : les actions qui restent sont celles du propriétaire et des admins (relire, changer le statut, enregistrer les ventes).

Les onglets utiles pour vous : « Approvisionnement & jobs », « Contributions », « Finances », « Équipe » et « Journal ». Le bouton « Compagnon » de l'en-tête (absent quand le projet est « Termine ») ouvre une fenêtre détachée avec les lignes « À attribuer » et « Je m'en charge ».

## 3. Choisir ce que vous apportez

Onglet « Approvisionnement & jobs ». « Materiaux a sourcer » donne, par ligne, « Requis », « Fourni », « Restant » et « Progression » ; « Jobs a lancer » fait de même pour le travail.

- « Fourni » compte les contributions **approuvées**. Si le projet nomme un container dont le stock est lisible, c'est le plus grand de ce nombre et du contenu du container, borné au requis. Container illisible : le chiffre porte « ≥ » et « Non vérifiable ».
- Une contribution en attente ne change pas « Fourni » : une ligne reste à couvrir tant que personne ne l'a approuvée.
- La colonne « Réservé par » montre qui s'en charge. « Je m'en charge » (avec une quantité à saisir) annonce ce que vous prenez ; « Libérer » le retire. Cela informe sans bloquer : un autre participant peut contribuer sur la même ligne. Votre réservation baisse quand une de vos contributions est approuvée.
- Sur une ligne, le bouton est « Contribuer ». « J'ai fourni » est le libellé du propriétaire tant qu'il est seul : vous ne le verrez pas.
- **Ce qui reste au propriétaire** : « Achats », « Lier un job », « Délier » et « Associer les jobs ». Vos achats wallet ne se rattachent pas à votre place, et ils ne comptent pas tant que vous ne les déclarez pas.

## 4. Déclarer une contribution

Trois accès au même formulaire : « Contribuer » sur une ligne (type, item et quantité sont préremplis, la quantité étant votre réservation, sinon le restant), « Add Contribution » dans l'onglet « Contributions » (absent quand le projet est « Termine »), ou « Contribuer » dans la carte « Prochaine action ».

La fenêtre « Ajouter une contribution » demande un « Type de contribution », l'« Item » (« Etape BOM » pour un job), la « Quantite », des « Notes » facultatives, puis « Soumettre la contribution ». Elle rappelle : « Votre contribution sera examinee par un admin du projet. »

| Type | Pour quoi | Valeur |
|---|---|---|
| « Material » | Un matériau de « Materiaux a sourcer » | Aucune saisie : prix Jita du jour d'apport (percentile 5 %), lu au dépôt |
| « Job Install » | Le coût d'installation d'un job lancé pour le projet | « Valeur estimee » à saisir, strictement positive |
| « BPC » | Une ligne de copie ou d'invention | « Valeur estimee » à saisir, strictement positive |
| « Line Rental » | L'usage de votre slot, selon votre barème | « Valeur estimee » obligatoire ; une suggestion peut s'afficher (« Suggestion issue de votre barème »), sinon « Aucune suggestion » et saisie à la main |

Détail de la valorisation : [contributions de groupe](/help?page=calculations/group-contributions).

Refus à connaître (le message est celui du serveur, en anglais, affiché dans la fenêtre) :

- **Une seule contribution en attente ou approuvée par ligne et par type.** « A contribution of this type is already pending or approved for this item ». Apportez donc la quantité entière en une fois. *Exemple : une ligne demande 200, vous en avez 120. Soumettez 120 ; une fois en attente ou approuvée, vous ne pouvez plus ajouter de « Material » sur cette ligne.* Une contribution rejetée ne bloque pas : vous pouvez en soumettre une autre.
- **Quantité au-dessus du restant** : « The contribution exceeds the remaining quantity of the line ».
- **Pas de prix Jita pour un matériau ce jour-là** : « No market price for type N: no Jita price for the deposit day. ». La contribution est refusée, pas valorisée à 0.
- **Vous ne pouvez pas retirer une contribution en attente.** Aucun bouton, aucune annulation : demandez à un relecteur de la rejeter, puis soumettez-la de nouveau.

**Un job de fabrication ou de réaction est souvent détecté sans vous.** Les jobs de vos personnages sont lus toutes les 30 minutes. Si un job installé depuis la création du projet correspond à une ligne de fabrication ou de réaction, EVE Tools le lie, crée une contribution « Job Install » à votre nom avec les badges « Auto-detected » et « ESI ✓ », déjà « Approved », au coût donné par l'ESI (inconnu si l'ESI n'en donne pas). Votre contribution déclarée du même type sur la ligne, si elle était encore en attente, est alors rejetée d'office. Ne déclarez pas un job déjà détecté : un apport déclaré et approuvé qui double un apport détecté est signalé « Doublon avec un apport ESI » aux relecteurs. Les copies et inventions ne sont pas liées ainsi : déclarez-les en « BPC ».

## 5. Attendre la relecture

Votre contribution apparaît dans l'onglet « Contributions » au statut « Pending », avec les autres contributions du projet (celles de tous les participants, sous le nom de leur personnage principal). Seuls le propriétaire et les admins ont les boutons « Approve » et « Reject ».

- **Aucune notification** ne vous prévient d'une approbation ni d'un rejet. La page ouverte se met à jour toute seule. Sinon, le « Journal » garde « Apport approuvé » ou « Apport rejeté ».
- Un rejet ne porte **aucun motif** : demandez-le au relecteur.
- **Approuvé = verrouillé.** Une contribution approuvée ne se rejette plus (sauf le doublon ci-dessus), entre dans le coût réel et dans les payouts. Voir [Pourquoi je ne peux plus toucher à une contribution approuvée ?](/help?page=faq/faq).
- Un admin ne relit pas sa propre contribution : seule une autre personne habilitée la relit.
- Le pied de tableau (« Total Engaged Costs », « Approved Value », « Pending Value ») dit si une valeur est inconnue : le total qui l'inclut est alors inconnu, jamais partiel.

## 6. Suivre votre part

Onglet « Finances ». Le tableau « Profit Distribution » liste tous les participants, vous compris, par nom de personnage principal (« Unknown » sans personnage principal, « Pilote supprimé » pour un compte supprimé). Rien ne met votre ligne en évidence : repérez votre nom. Ses colonnes : « Costs Engaged », « Share % », « Profit Part », « Payout Total ». « Show breakdown » détaille vos coûts par type.

- Seules vos contributions **approuvées** comptent. Une contribution « Pending » n'est dans aucun de ces chiffres.
- Tant qu'aucune vente n'est enregistrée, l'écran dit « En attente des ventes » : le payout est inconnu, pas à zéro. Avec des invendus, « Marge provisoire » : lisez les payouts comme provisoires.
- Vous voyez les mêmes écrans et les mêmes chiffres que le propriétaire. Les messages possibles (« Répartition impossible », « Un apport approuvé n'a pas de prix connu ») sont détaillés dans le [playbook propriétaire](/help?page=playbooks/group-project-owner), étape 6.
- Formules : [contributions de groupe](/help?page=calculations/group-contributions) et [marge](/help?page=calculations/margin).

## 7. Recevoir vos virements

L'enregistrement des ventes est réservé au propriétaire et aux admins : vous ne pouvez pas en saisir. Ce sont eux qui détiennent le revenu net, et le plan de « Virements entre membres » (onglet « Finances ») donne à chacun son payout. Il n'est calculé que lorsque tous les produits sont vendus, que les frais sont connus et que chaque coût l'est ; sinon il dit pourquoi.

1. Le payeur fait le virement en jeu avec la « Référence de paiement » dans le motif. EVE Tools ne déplace aucun ISK.
2. Vous cochez « Reçu » sur le virement dont vous êtes destinataire. Vous ne pouvez pas cocher celui d'un autre (« Seul le destinataire peut cocher ce virement »). La case se coche seule, avec « Vérifié dans le journal wallet », quand votre journal wallet montre le virement avec la référence et le montant complet.
3. Une coche ne compte que tant que le montant du virement ne change pas : si une vente est corrigée, revérifiez.

Vous pouvez cocher « Reçu » même après la fin du projet.

## 8. Inviter ou quitter

- **Inviter.** Le bouton « Lien de partage » de l'en-tête copie le lien d'invitation (confirmation « Link copied! ») ; l'onglet « Équipe » donne le même lien. Une personne que vous invitez est acceptée d'office seulement si elle est de la corporation du **propriétaire**, pas de la vôtre.
- **Quitter.** Onglet « Équipe », « Quitter » sur votre ligne, avec confirmation. Le bouton n'apparaît que tant que vous n'avez aucune contribution approuvée, et le propriétaire ne peut pas quitter. Vos réservations sont libérées. Pour revenir : lien d'invitation, en demande (étape 1).

## Ce que vous avez lu, et d'où ça vient

| Chiffre | Où | À savoir | Page |
|---|---|---|---|
| « Cout est. » | « Approvisionnement & jobs » | Estimation de la BOM, Jita percentile 5 %, pas votre coût réel | [FAQ](/help?page=faq/faq) |
| « Valuation » d'une contribution | « Contributions » | Prix du jour d'apport, coût ESI ou valeur saisie | [contributions de groupe](/help?page=calculations/group-contributions) |
| « Costs Engaged », « Share % », « Payout Total » | « Finances » | Calculés sur les contributions approuvées | [contributions de groupe](/help?page=calculations/group-contributions), [marge](/help?page=calculations/margin) |
| Montant d'un virement | « Finances » | Revenu détenu par chaque vendeur moins son payout | [contributions de groupe](/help?page=calculations/group-contributions) |

## Et ensuite

- Ce que fait le propriétaire de son côté : [propriétaire d'un projet de groupe](/help?page=playbooks/group-project-owner).
- Les limites connues : [limites connues](/help?page=limits/known-limits).
