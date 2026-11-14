# Surveiller ses ordres de vente et reprendre la tête

Ce guide suit vos ordres de vente d'un projet Industrie contre le meilleur prix du marché : où lire votre position, depuis quand un ordre est sous-coté, comment copier le prix à coller en jeu, et comment être prévenu sans ouvrir le projet. Chaque étape nomme l'écran et le bouton tels qu'ils s'affichent.

EVE Tools ne modifie jamais un ordre : il lit vos ordres, les compare au carnet du marché et vous donne un prix à coller. La modification se fait en jeu.

Pour savoir si un prix reste rentable avant de le baisser, voir [Profit et marge : comment EVE Tools les calcule](/help?page=calculations/profit-and-margin).

## Ce qui est suivi

Le tableau ne montre pas tous vos ordres, seulement ceux qui répondent à toutes ces conditions :

- ce sont des **ordres de vente** (les ordres d'achat sont ignorés) ;
- ils appartiennent à l'un de **vos personnages** (pas seulement le principal) ;
- ils portent sur un **produit final** du projet, c'est-à-dire un item dont la BOM a un job du groupe « final » ;
- le projet n'est ni **Termine** ni en usage personnel : dans ces deux cas le tableau reste vide.

Les ordres sont lus avec la permission ESI de lecture de vos ordres de marché (`esi-markets.read_character_orders.v1`). Un personnage qui ne l'a pas, ou dont la connexion n'est plus valide, n'est pas lu (voir l'étape 6).

## 1. Ouvrir l'onglet Ventes du projet

1. Menu latéral : **Industrie**, onglet **Projets**.
2. Dans **Mes projets**, ouvrez le projet.
3. Cliquez sur l'onglet **Ventes**.

Le tableau **Mes ordres de vente** est le premier bloc de l'onglet, au-dessus des ventes enregistrées.

## 2. Lire le tableau

| Colonne | Contenu |
|---|---|
| **Ordre** | L'item, la quantité restante (`×quantité`) et le nom du personnage qui détient l'ordre. |
| **Marché** | Le lieu de l'ordre : **Jita 4-4**, ou le nom de la structure. Si le nom de la structure est inconnu, l'application affiche `#` suivi de l'identifiant du lieu. |
| **Mon prix** | Le prix de votre ordre. |
| **Meilleur prix** | Le prix de vente le plus bas du carnet du même lieu et du même item, votre ordre compris. Vaut **Valeur inconnue** quand la position est inconnue. |
| **Position** | **1er**, **#N · sous-coté depuis …** ou la raison pour laquelle elle est inconnue. |

Les lignes sont triées par urgence : d'abord les ordres sous-cotés (le plus ancien en premier), puis les positions inconnues, puis les ordres en première position.

### Ce que veut dire la position

- **1er** (pastille verte) : aucun ordre du carnet n'est strictement moins cher que le vôtre. **À prix égal, vous restez 1er.**
- **#N · sous-coté depuis …** (pastille orange) : au moins un ordre est moins cher. N est le nombre d'ordres strictement moins chers, plus un. Dans ce cas, **Meilleur prix** passe en orange.
- Une position inconnue (pastille violette en pointillés) a deux causes :

| Message | Cause |
|---|---|
| **Marché non suivi** | Le lieu de l'ordre n'est ni Jita 4-4 ni une structure dont EVE Tools garde le carnet. |
| **Pas de données de marché** | Le carnet du lieu est vide pour cet item. |

Une position inconnue n'est jamais comptée comme « 1er » ni comme « sous-coté » : **Meilleur prix** affiche **Valeur inconnue** et aucun prix à copier n'est proposé.

### Les marchés suivis

- **Jita 4-4** : le carnet est relu à chaque synchronisation de vos ordres, pour les items que vous y vendez.
- **Les structures** : EVE Tools garde le carnet de la structure de marché par défaut de l'application et de chaque structure choisie comme **Structure de marche** dans les paramètres (clic sur votre carte personnage, en bas du menu), à condition qu'un personnage ait la permission de lire son marché ; sinon la structure est ignorée. Ces carnets sont rafraîchis toutes les heures.
- **Tout autre lieu** (une autre station, une structure que personne n'a choisie) : **Marché non suivi**.

## 3. Savoir depuis quand un ordre est sous-coté

« Sous-coté depuis » s'affiche en minutes (`min`), en heures (`h`) ou en jours (`j`), arrondi à l'unité inférieure. Le compteur avance seul sur la page, sans rechargement.

C'est la durée écoulée depuis la **première synchronisation qui a vu l'ordre sous-coté**, pas depuis l'instant réel où un concurrent vous a passé :

- la durée repart de zéro dès que l'ordre n'est plus sous-coté (il repasse **1er**, ou sa position devient inconnue). Un ordre qui se fait sous-coter à nouveau repart de zéro ;
- un ordre déjà sous-coté la première fois que l'application le lit compte à partir de cette lecture ;
- l'application ne connaît pas l'heure exacte du dépassement : elle n'a que ses lectures.

## 4. Reprendre la tête : copier le prix

Sur un ordre sous-coté, le bouton **Copier {prix}** apparaît à droite de la ligne. Le prix proposé est le **meilleur prix moins 100 ISK**. Pas de bouton quand la position est **1er** ou inconnue, ni quand le meilleur prix moins 100 ISK ne serait pas positif.

1. Cliquez sur **Copier {prix}**. L'application copie le nombre brut dans le presse-papiers, sans séparateur de milliers. Elle n'affiche aucune confirmation.
2. En jeu, ouvrez vos ordres de marché, faites **Modifier l'ordre** sur l'ordre concerné et collez le prix.
3. Revenez dans EVE Tools : la nouvelle position apparaît à la prochaine synchronisation (jusqu'à 5 minutes).

Le prix proposé n'est qu'un calcul. Le tableau ne tient pas compte de vos frais, de la quantité restante ni de la rentabilité : voyez la section « Avant de baisser » plus bas.

### Exemple (fictif)

Votre ordre est à 5 200 000 ISK. Le carnet de Jita 4-4 contient 5 100 000, 5 150 000 et 5 200 000 ISK.

- Deux ordres sont strictement moins chers (5 100 000 et 5 150 000) : position **#3 · sous-coté depuis …**.
- **Meilleur prix** : 5 100 000 ISK.
- Prix proposé : 5 100 000 − 100 = 5 099 900 ISK.

Avec un seul concurrent à 5 200 000 ISK (même prix que vous), vous restez **1er** : l'égalité ne compte pas comme un dépassement.

## 5. Être prévenu sans ouvrir l'onglet Ventes

Chaque ordre sous-coté d'un projet où vous êtes accepté (hors projet **Termine** ou en usage personnel) remonte à trois endroits :

- **Atelier** (**Industrie**, onglet **Atelier**), dans la **File d'actions** : une ligne « Ordre sous-coté : {item} ×{quantité}, meilleur prix {meilleur prix} contre {votre prix}. », niveau **Avertissement**, avec le bouton **Voir mes ordres**, qui ouvre l'onglet **Ventes** du projet. Les alertes de prix sous le seuil de rentabilité et celles d'ordres sous-cotés passent avant les autres actions de la file ;
- la **pastille** à droite de **Industrie** dans le menu : elle compte les actions de la file, ordres sous-cotés compris ;
- le **Compagnon** du projet (bouton **Compagnon** de l'en-tête du projet) : une alerte « Sous-coté : {item} ×{quantité} à {marché}, meilleur prix {meilleur prix} contre {votre prix} ». Elle ne décrit que le premier ordre sous-coté de la liste, et le Compagnon la relit quand une synchronisation des ordres se termine.

La **File d'actions** et la pastille se relisent à l'ouverture de l'application et de l'**Atelier**, pas à la fin d'une lecture des ordres : rechargez la page pour les mettre à jour. L'application n'envoie aucune notification push ni aucun message pour un ordre sous-coté.

## 6. Quand rien ne s'affiche ou qu'une valeur est inconnue

| Ce que vous voyez | Cause | Que faire |
|---|---|---|
| « Aucun ordre de vente pour les produits de ce projet. » | Aucun ordre de vente de vos personnages ne porte sur un produit final du projet, ou le projet est **Termine** ou en usage personnel, ou les ordres n'ont pas encore été lus. | Vérifiez le produit et le statut du projet ; attendez la prochaine synchronisation. |
| « Reconnectez-vous avec {personnages} pour lire leurs ordres de vente (nouvelle permission ESI). » | Un personnage n'a pas la permission de lire les ordres, ou sa connexion n'est plus valide. Ses ordres ne sont pas lus. | Reconnectez ce personnage avec EVE SSO, en acceptant la permission demandée. |
| **Marché non suivi** | Lieu hors Jita 4-4 et hors structures suivies. | Aucune position n'est calculée. Choisissez cette structure comme **Structure de marche** dans les paramètres. |
| **Pas de données de marché** | Carnet vide pour cet item à ce lieu. | Aucune position n'est calculée ; attendez la prochaine lecture du marché. |
| Un message d'erreur à la place du tableau | Le chargement du tableau a échoué. | Rechargez la page. |

## Avant de baisser le prix

Reprendre la tête n'est pas toujours rentable. Avant de copier un prix, comparez-le au **seuil de rentabilité** du projet, expliqué dans [Profit et marge](/help?page=calculations/profit-and-margin). Quand le prix de Jita passe sous ce seuil, la **File d'actions** de l'**Atelier** affiche aussi « Jita ({prix}) est sous le seuil de rentabilité de {seuil} : {écart} perdus par unité vendue au marché. », avec le bouton **Où vendre**.

## Fraîcheur des données

- Vos ordres sont lus **toutes les 5 minutes** pour chaque utilisateur ayant un personnage actif avec une connexion valide. Aucun bouton ne force la lecture depuis l'onglet **Ventes** ; quand elle se termine, le tableau se relit seul.
- Le carnet de **Jita 4-4** est relu à chaque lecture de vos ordres. Si cette relecture échoue, la synchronisation de l'utilisateur échoue et le tableau garde l'état de la dernière lecture réussie.
- Les carnets de **structure** sont rafraîchis toutes les heures : le meilleur prix d'une structure peut avoir jusqu'à environ une heure de retard.
- Le tableau n'affiche pas l'heure de la dernière lecture.

## Pièges connus

- **Vos propres ordres comptent** : le carnet ne distingue pas les ordres des autres joueurs des vôtres. Un autre de vos ordres moins cher sur le même item et le même lieu vous place en **sous-coté**.
- **Seul le meilleur prix compte** : la position ne tient pas compte des quantités. Un concurrent à 1 ISK d'écart avec 1 unité vous sous-cote autant qu'un gros ordre.
- **Seul Jita 4-4 est suivi** : un ordre posé dans une autre station de Jita ou d'ailleurs est **Marché non suivi**.
- **Le lieu peut s'afficher `#identifiant`** quand l'application ne connaît pas le nom de la structure.
- **Le bouton ne confirme rien** : après un clic sur **Copier {prix}**, collez en jeu pour vérifier que le presse-papiers contient bien le prix.
- **Le texte de reconnexion parle d'une nouvelle permission** même quand la cause est une connexion devenue invalide.
- **Pas de visite guidée** pour ce module : ce guide est le seul parcours pas à pas.
