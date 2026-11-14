# Mener un projet de groupe en tant que propriétaire

Ce playbook suit un projet de groupe du côté de la personne qui l'a créé : publier, inviter, accepter les demandes, approuver les contributions, enregistrer les ventes, lire la répartition, faire les virements, terminer. Pour créer le projet, acheter les matériaux et lancer les jobs, voir [Fabriquer un produit T2](/help?page=playbooks/t2-project) : un projet seul est un projet comme un autre. Côté invité : [participant d'un projet de groupe](/help?page=playbooks/group-project-member).

Les libellés entre guillemets « » sont ceux de l'écran. Plusieurs sont en anglais dans l'application, quelle que soit la langue (« Approve », « Reject », « Record Sale », « Promote », « Kick », « Profit Distribution »), ou écrits sans accent (« Publie », « Termine »). Ils sont repris tels quels.

Un admin voit les mêmes écrans que vous et peut relire les contributions, traiter les demandes, enregistrer les ventes et changer le statut. Restent au propriétaire : nommer un admin, les achats (« Achats », « Lier un job », « Délier ») et l'association de vos jobs ESI aux lignes du projet (« Associer les jobs » dans « Approvisionnement & jobs »).

## Avant de commencer

- Le plan vous convient. Les catégories de la liste noire et l'exclusion de l'invention ne se changent qu'en « Brouillon ».
- Les frais de vente du projet (« Frais courtier % », « Taxe de vente % ») sont copiés de vos frais de vendeur à la création. Vides, le revenu net des ventes au marché, le profit et les virements sont inconnus. Voir [frais de marché](/help?page=calculations/market-fees) et le [playbook T2](/help?page=playbooks/t2-project), étape 2.

## 1. Publier le projet

Menu « Industrie », onglet « Projets », « Mes projets » : ouvrez le projet (après sa création, vous y arrivez directement). En « Brouillon », l'en-tête propose deux boutons :

- « Publier » : le projet passe à « Publie ».
- « Démarrer » : le projet passe à « En cours », sans passer par « Publie ».

Depuis « Publie », le bouton devient « Passer à En cours ».

**Piège : un projet est privé par défaut, et « Publier » n'y change rien.** Un projet privé n'apparaît pas dans « Disponibles », et son lien répond comme un lien inconnu. Pour ouvrir le projet : icône « Modifier » de l'en-tête, décochez « Projet privé », puis « Enregistrer ». Le badge « Privé » disparaît à côté du statut.

Ce qui est ouvert, et à qui :

| Porte d'entrée | Condition |
|---|---|
| Lien d'invitation | Projet non privé, au statut « Publie » ou « En cours » |
| Onglet « Disponibles » | Projet non privé, au statut « Publie » seulement, et de la même corporation que vous (celle de votre personnage principal) |
| À partir de « En vente » | Plus personne ne peut rejoindre |

Pour fermer à nouveau l'accès, recochez « Projet privé » : les participants déjà acceptés restent.

## 2. Inviter

Cliquez sur « Inviter » dans l'en-tête. Le bouton s'appelle « Lien de partage » dès qu'il y a un autre participant, une demande ou une contribution à relire. Il copie le lien dans le presse-papiers (la confirmation, « Link copied! », est en anglais). Le même lien est dans l'onglet « Équipe » : bouton « Copy Join Link » et carte « Lien d'invitation » avec « Copier ».

- Le lien a la forme `<adresse d'EVE Tools>/industry/join/<code>`. Le code d'invitation est propre au projet et ne change jamais. Il n'existe pas d'écran où saisir un code : l'invité ouvre le lien.
- Un invité qui n'est pas connecté arrive sur l'écran de connexion, puis sur l'Accueil : le lien n'est pas rejoué. Dites-lui de rouvrir le lien une fois connecté.
- **Même corporation que vous** (celle du personnage principal des deux) : l'invité est accepté tout de suite et ouvre le projet. Dans « Disponibles », le bouton est « Rejoindre le projet ».
- **Autre corporation, ou corporation inconnue** : l'invité voit « Demande envoyée » et attend votre réponse. L'écran lui promet une notification à l'acceptation, mais EVE Tools n'en envoie aucune : prévenez-le vous-même.

## 3. Accepter les demandes

Aucune notification ne vous prévient d'une demande. Elle apparaît dans l'onglet « Équipe » (et dans « Journal », « Membre arrivé »). Cet onglet est masqué tant que vous êtes le seul participant, sans demande ni contribution à relire, et il apparaît dès qu'une demande arrive. Voir [Qui peut rejoindre un projet, et qui approuve ?](/help?page=faq/faq).

Sous « Demandes en attente », chaque ligne donne le personnage, sa corporation, la date et « Via » (« Lien court »), avec deux boutons :

- « Accepter » : la personne devient participante, avec le rôle de membre.
- « Rejeter » : la demande disparaît. La personne peut redemander avec le même lien.

Dans le tableau des participants, « Promote », « Demote » et « Kick » changent le rôle ou retirent quelqu'un. Seul le propriétaire promeut un admin.

**Nommer un admin change qui relit vos apports** (étape 4).

## 4. Approuver les contributions

Elles arrivent dans l'onglet « Contributions », dont le compteur donne le nombre de contributions. Tant qu'une contribution attend, la carte « Prochaine action » affiche « Contributions à relire » (bouton « Relire les contributions »), avant toute autre action. Elle apparaît aussi dans l'« Atelier ».

Le tableau donne, par ligne : le type, la quantité, la « Valuation », la « Method » et le « Status ». Pour une contribution « Pending », deux boutons : « Approve » et « Reject ».

- **Vous approuvez une quantité, pas un prix.** La valeur d'un matériau déclaré est le prix Jita du jour d'apport, lu au dépôt et conservé tel quel à la revue. Les autres types portent une valeur saisie, ou le coût réel ESI. Détail : [contributions de groupe](/help?page=calculations/group-contributions).
- **Certaines contributions n'ont rien à relire.** Un job lié arrive déjà « Approved » avec les badges « Auto-detected » et « ESI ✓ ». Un achat que vous avez lié aussi, tant que vous êtes le seul relecteur ; dès qu'un autre propriétaire ou admin peut relire, il arrive « Pending » comme proposition d'apport, et le bouton se lit « Confirm delivery » : il compte quand le container du projet couvre la quantité, ou quand un autre relecteur confirme à la main (badge « Delivery confirmed by hand », apport non vérifié). Détail : [contributions de groupe](/help?page=calculations/group-contribution-and-distribution).
- **Doublon.** Si une contribution déclarée et approuvée double une contribution détectée par EVE Tools, la ligne porte « Doublon avec un apport ESI » et « Reject » reste disponible, même approuvée. C'est la seule exception.
- **Approuvé = verrouillé.** Une contribution approuvée ne se rejette plus : elle entre dans le coût réel et dans les payouts, et le projet ne peut plus être supprimé. Voir [Pourquoi je ne peux plus toucher à une contribution approuvée ?](/help?page=faq/faq).
- **Vos propres contributions.** Tant que vous êtes le seul relecteur (aucun autre admin accepté), elles sont approuvées au dépôt. Dès que vous nommez un admin, les suivantes restent « Pending » et seul cet admin peut les relire : on ne relit pas sa propre contribution.
- **Valeur inconnue.** Une contribution sans prix connu rend inconnus les totaux qui l'incluent, jamais partiels. Le pied de tableau (« Total Engaged Costs », « Approved Value », « Pending Value ») le dit.

Quand toutes les matières sont couvertes et les jobs livrés, la carte propose « Passer à la vente ». Le bouton « Passer à En vente » de l'en-tête est disponible dans tous les cas : EVE Tools ne vérifie pas que la production est finie.

## 5. Enregistrer les ventes

Onglet « Ventes ». L'enregistrement est réservé au propriétaire et aux admins, et se fait à partir de vos propres personnages. Trois façons :

1. **« Record Sale »** : le formulaire demande « Item » (« Choisir un item », avec le reste à vendre), « Quantity », « Unit Price (ISK) », « Canal » (« Ordre de marché » ou « Contrat »), « Venue » et « Vendeur », puis « Save ».
2. **« Transactions à rattacher »** : les ventes du wallet de vos personnages, pour les produits du projet, depuis la création du projet. « Enregistrer comme vente » reprend quantité et prix. « Doublon possible » signale une vente déjà saisie avec la même quantité et le même prix unitaire.
3. **« Rattacher un contrat »** : les contrats item exchange terminés de vos personnages qui ont vendu un produit du projet. Quantité, prix et date sont figés d'après le contrat.

Une vente se corrige (« Modifier ») et se supprime (« Supprimer ») tant que le projet n'est pas terminé. Sous le tableau, chaque produit affiche « N / M vendus » : unités vendues sur unités produites. M est remplacé par un tiret long quand la production est inconnue.

**Piège : le « Vendeur » ne peut être qu'un de vos personnages.** Laissé sur « Inconnu », la vente est attribuée à celui qui l'a enregistrée. Or les virements partent du principe que chacun garde le revenu net de ses ventes : si un autre participant a vendu pour le projet, EVE Tools ne sait pas le lui attribuer, et vous êtes compté comme détenteur de cet argent. Il n'existe pas d'autre réglage.

Le revenu net retranche les « Frais courtier % » et « Taxe de vente % » du projet sur les ventes au marché. Voir [frais de marché](/help?page=calculations/market-fees).

## 6. Lire la répartition

Onglet « Finances ». De haut en bas : « Du revenu au profit », « Résumé financier », « Prévision de vente », le tableau « Profit Distribution », « Virements entre membres », et « Récapitulatif Discord » une fois le projet terminé.

Le tableau « Profit Distribution » donne, par participant : « Costs Engaged », « Share % », « Profit Part » et « Payout Total ». Cliquer sur une ligne (« Show breakdown ») détaille le coût par type de contribution. Les formules sont dans [contributions de groupe](/help?page=calculations/group-contributions) et [marge](/help?page=calculations/margin).

Ce que l'écran peut dire à la place d'un chiffre :

- « En attente des ventes » : aucune vente enregistrée. Revenu, profit, marge et payouts sont inconnus, pas à zéro.
- « Marge provisoire » : il reste des invendus (« Invendus : »). Les payouts se calculent sur le revenu à date, à lire comme provisoires. La « Marge projetée au dernier prix de vente » estime le résultat.
- « Répartition impossible » : aucune contribution approuvée n'a de coût.
- « Un apport approuvé n'a pas de prix connu » : le coût, le profit et les payouts restent inconnus tant qu'il n'en a pas.

## 7. Faire les virements

La section « Virements entre membres » propose le plan de virements en jeu qui donne à chacun son payout. Elle n'est calculée que lorsque tous les produits sont vendus, que les frais sont connus et que chaque coût l'est. Sinon, elle dit pourquoi (par exemple « En attente des ventes » ou « Des produits sont encore invendus »).

1. Copiez la « Référence de paiement » (« Copier »). Elle a la forme `IP-` suivie de six caractères hexadécimaux.
2. Faites chaque virement en jeu avec cette référence dans le motif. EVE Tools ne déplace aucun ISK.
3. Le destinataire coche « Reçu ». Vous ne pouvez pas cocher à sa place (« Seul le destinataire peut cocher ce virement »). La case se coche seule, avec « Vérifié dans le journal wallet », quand le journal wallet du destinataire montre le virement avec la référence et le montant complet.

Le plan se recalcule à la lecture. Une coche ne compte que tant que le montant du virement est inchangé : si vous corrigez une vente, vérifiez-les de nouveau. Un « Écart d'arrondi absorbé par le plus gros payout » peut être indiqué.

## 8. Terminer

Quand tout est vendu, la carte propose « Clôturer le projet » ; l'en-tête propose « Passer à Termine ». La fenêtre « Terminer le projet » affiche « Avant de terminer » : contributions en attente, produits invendus, « Marge finale : … % » (ou « Marge finale inconnue : un coût ou un frais manque ») et « Virements cochés comme reçus : x / y ».

- **Ces vérifications n'empêchent rien.** « Terminer » reste cliquable, même avec des contributions en attente ou des invendus.
- **Irréversible.** Plus de contribution, de revue, de vente ni de changement de frais, et le projet ne peut plus être supprimé.
- Les destinataires peuvent encore cocher « Reçu » après la fin du projet.
- Une fois terminé, « Copier pour Discord » (dans « Récapitulatif Discord ») copie un texte prêt à coller. Rien n'est posté à votre place.

## Ce que vous avez lu, et d'où ça vient

| Chiffre | Où | À savoir | Page |
|---|---|---|---|
| Valeur d'une contribution | « Contributions » | Prix du jour d'apport, prix payé, coût ESI ou valeur saisie | [contributions de groupe](/help?page=calculations/group-contributions) |
| « Revenu net », frais | « Ventes », « Finances » | Frais du projet, copiés à la création | [frais de marché](/help?page=calculations/market-fees) |
| « Marge », « Payout Total » | « Finances » | Calculés sur les contributions approuvées | [contributions de groupe](/help?page=calculations/group-contributions), [marge](/help?page=calculations/margin) |
| Montant d'un virement | « Finances » | Revenu détenu par chaque vendeur moins son payout | [contributions de groupe](/help?page=calculations/group-contributions) |

## Et ensuite

- Ce que voit un invité : [participant d'un projet de groupe](/help?page=playbooks/group-project-member).
- Les limites connues : [limites connues](/help?page=limits/known-limits).
