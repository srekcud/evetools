# Surveiller ses ordres de vente et reprendre la tête

Ce playbook suit vos ordres de vente ouverts face au meilleur prix du marché : où lire votre position, depuis quand un ordre est sous-coté, quel prix copier pour repasser devant. EVE Tools lit et compare, il ne modifie jamais un ordre : vous changez le prix en jeu.

Portée, à connaître avant de commencer : seuls sont suivis les ordres de vente de vos personnages sur les **produits finaux** d'un projet industriel dont vous êtes membre accepté, qui n'est ni « Usage personnel (pas de vente) » ni « Termine ». Un ordre sur un autre objet n'apparaît nulle part. Les ordres d'achat sont ignorés.

Les libellés entre guillemets « » sont ceux de l'écran. Certains sont écrits sans accent dans l'application (« Parametres », « Structure de marche », « Termine ») et sont repris tels quels.

## 1. Autoriser la lecture des ordres

Chaque personnage doit avoir l'autorisation de lire ses ordres (scope ESI des ordres du personnage). Les personnages qui ne l'ont pas, ou dont l'autorisation n'est plus valide, sont ignorés. La carte « Mes ordres de vente » vous le dit : « Reconnectez-vous avec <personnages> pour lire leurs ordres de vente (nouvelle permission ESI). »

Pour corriger : menu « Compte », onglet « Personnages », « Re-autoriser » sur la carte du personnage. Voir [Premiers pas](/help?page=playbooks/getting-started), étape 4.

Conditions de la lecture automatique : une connexion EVE SSO de moins de 7 jours. Au-delà, plus aucun de vos ordres n'est relu et les positions affichées restent celles du dernier relevé.

## 2. Choisir les marchés à suivre

Un ordre n'a de position que sur un **marché suivi** :

- **Jita 4-4** : toujours suivi. La colonne « Marché » affiche « Jita IV - Moon 4 ».
- **Une structure** : « Compte », « Parametres », section « Marché », bloc « Structure de marche ». Cherchez la structure (3 caractères au moins) et choisissez-la dans la liste : elle est enregistrée tout de suite (« Structure enregistree »). « Supprimer » sur la ligne de la structure la retire.

Piège : l'enregistrement ne lance aucune lecture du carnet de la structure. Il arrive à la prochaine synchro horaire, donc jusqu'à une heure plus tard, et il faut qu'un joueur qui suit la structure ait le scope de marché de structure. Avant cela, vos ordres dans cette structure restent « Marché non suivi », sauf si un autre joueur la suit déjà.

Un ordre posé ailleurs (une station NPC autre que Jita 4-4, une structure non suivie) a une position inconnue, et son lieu peut s'afficher sous la forme d'un numéro (« #… ») au lieu d'un nom.

## 3. Ouvrir « Mes ordres de vente »

Menu « Industrie », onglet « Projets », « Mes projets », ouvrez le projet, onglet « Ventes ». La carte « Mes ordres de vente » est en tête de l'onglet, au-dessus des ventes enregistrées. Elle liste les ordres de **tous vos personnages** sur les produits du projet.

Les ordres sont relus toutes les 5 minutes. La carte se recharge d'elle-même à la fin d'une lecture. Elle ne montre pas l'heure du dernier relevé.

Sans ordre, la carte dit : « Aucun ordre de vente pour les produits de ce projet. » C'est aussi ce que vous voyez juste après avoir posé un ordre, tant que la prochaine lecture n'a pas eu lieu.

## 4. Lire une ligne

Colonnes : « Ordre » (produit, quantité restante, personnage), « Marché », « Mon prix », « Meilleur prix », « Position ».

Les lignes sont triées par urgence : d'abord les ordres sous-cotés, du plus ancien au plus récent, puis les positions inconnues, puis les ordres en tête.

| « Position » | Sens |
|---|---|
| « 1er » | Aucun ordre du même lieu n'est à un prix strictement inférieur au vôtre. À prix égal, vous êtes 1er. |
| « #N · sous-coté depuis … » | Au moins un ordre du même lieu est moins cher. N est le nombre d'ordres moins chers, plus un. La durée s'écrit en « min », « h » ou « j ». |
| « Marché non suivi » | Le lieu n'est pas un marché suivi (étape 2), ou le carnet de la structure a expiré. Position et meilleur prix sont inconnus (« Valeur inconnue »). |
| « Pas de données de marché » | Le marché est suivi mais le carnet n'a aucun ordre de vente pour ce produit. Position inconnue. |

Une position inconnue n'est jamais « 1er » par défaut. Pour la lecture générale des « inconnu », voir la [FAQ](/help?page=faq/faq).

Ce que la comparaison fait, et ne fait pas :

- Elle ne regarde que le **prix**. Un ordre concurrent d'une seule unité vous sous-cote autant qu'un gros.
- Elle ne distingue pas vos ordres des autres : **vos propres ordres moins chers comptent**. Deux ordres du même produit dans le même marché, à deux prix : le plus cher apparaît sous-coté par l'autre.
- Elle ignore les frais. Le « Meilleur prix » est un prix de carnet, pas un net. Voir [frais de marché](/help?page=calculations/market-fees).
- Pour un ordre en tête, « Meilleur prix » égale « Mon prix ».
- Le carnet ne garde que les 20 ordres de vente les moins chers par produit et par lieu. Un rang « #21 » se lit donc « 21 ou davantage ».

« Sous-coté depuis » part du premier relevé qui voit l'ordre sous-coté, pas de l'instant où le concurrent a posé son ordre. Il repart de zéro dès qu'un relevé ne voit plus l'ordre sous-coté, y compris quand la position devient inconnue.

## 5. Reprendre la tête

Sur une ligne sous-cotée, le bouton « Copier <prix> » copie dans le presse-papiers le meilleur prix moins 100 ISK. Collez-le en modifiant l'ordre dans le jeu. Rien n'est modifié depuis EVE Tools.

- Le prix affiché sur le bouton est abrégé (« K », « M », « B », deux décimales). Le presse-papiers reçoit le nombre complet. *Exemple : meilleur prix à 4 900 000, le bouton affiche « Copier 4,90 M » et copie 4899900.*
- Le pas est fixe : 100 ISK, quel que soit le prix. Si le meilleur prix est de 100 ISK ou moins, il n'y a pas de bouton.
- Le prix copié part du meilleur prix du dernier relevé. Si un concurrent a bougé depuis, la ligne n'est pas à jour.

Après votre modification, la position se met à jour à la lecture suivante (toutes les 5 minutes). Pour une structure, le carnet de comparaison est relu toutes les heures : la position peut rester « sous-coté » jusqu'à une heure de plus, même si vous avez déjà repris la tête.

## 6. Être prévenu sans ouvrir le projet

EVE Tools n'envoie aucune notification pour un ordre sous-coté. Deux endroits le signalent :

- **« Atelier »** (menu « Industrie », onglet « Atelier »), « File d'actions » : une ligne par ordre sous-coté, « Ordre sous-coté : <produit> ×<quantité>, meilleur prix <prix> contre <votre prix>. », sévérité « Avertissement ». Le bouton « Voir mes ordres » ouvre l'onglet « Ventes » du projet. Ces lignes passent après les alertes de prix et avant les actions habituelles des projets. La file est chargée à l'ouverture de la page : rechargez-la pour voir une lecture récente.
- **« Compagnon »** (bouton de l'en-tête du projet) : une alerte « Sous-coté : <produit> ×<quantité> à <marché>, meilleur prix <prix> contre <votre prix> », qui montre l'ordre le plus urgent. Elle se met à jour à la fin de chaque lecture.

Les alertes de prix de l'onglet « Alertes » du « Navigateur Marche » sont autre chose : un seuil sur le prix d'un objet, sans lien avec vos ordres.

## Contrôle

- Aucun message « Reconnectez-vous… » sur la carte.
- Chaque ligne a une position « 1er » ou « #N », sauf celles dont le marché n'est pas suivi.
- Après avoir collé le nouveau prix en jeu, la ligne repasse « 1er » à la lecture suivante (une heure au plus pour une structure).

## Pièges

- **Les positions peuvent être anciennes sans que l'écran le dise.** Si la lecture d'un personnage échoue, ses lignes gardent leur dernier état. Si le carnet Jita ne peut pas être rafraîchi, aucune ligne n'est mise à jour.
- **Carnet Jita.** Il est lu sur la première page de résultats de l'ESI. Pour un produit très vendu, le meilleur prix peut être faux : voir [limites connues](/help?page=limits/known-limits).
- Un ordre que l'ESI ne renvoie plus (vendu, expiré, annulé) disparaît de la carte à la lecture suivante.
