# Tenir ses colonies PI sans arrêt d'extracteur

Ce playbook sert à voir l'état de vos colonies planétaires (PI) et de vos extracteurs, à être prévenu avant qu'un extracteur s'arrête, et à savoir ce que l'application ne peut pas savoir. EVE Tools lit vos colonies, il n'en modifie aucune : redémarrer un extracteur se fait en jeu.

Les libellés entre guillemets « » sont ceux de l'écran. Certains sont écrits sans accent dans l'application (« Planetaire », « Preferences », « Debit initial ») et sont repris tels quels pour que vous les retrouviez.

## 1. Autoriser la lecture des colonies

Chaque personnage doit avoir l'autorisation de lire ses colonies (scope ESI de la planétologie). Un personnage sans ce scope, dont l'autorisation n'est plus valide, ou dont le compte ne s'est pas connecté par EVE SSO depuis plus de 7 jours, est ignoré par la synchro : ses colonies ne sont plus relues et aucune alerte ne les concerne. Pour corriger, voir [Premiers pas](/help?page=playbooks/getting-started), étape 4, bouton « Re-autoriser ».

## 2. Ouvrir « Planétaire » et lancer une synchro

Menu « Planétaire ». L'écran s'intitule « Interaction Planetaire ». En haut à droite, « Derniere sync : » donne l'âge de la lecture la plus récente, ou « jamais ».

- Les colonies sont relues **toutes les 30 minutes** pour les comptes actifs. Le bouton « Synchroniser » lance la même synchro à la demande. Il passe à « Sync... » pendant le travail, puis l'écran se recharge.
- Une synchro relit toutes les colonies de chaque personnage, en laissant une demi-seconde entre deux colonies : plus vous avez de colonies, plus elle prend de temps.
- Une colonie supprimée en jeu disparaît de l'écran à la synchro suivante.
- Sans colonie, l'écran affiche « Aucune colonie PI ». Lancez « Synchroniser » ou vérifiez le scope (étape 1).

Piège : les données lues par l'ESI reflètent votre dernière interaction **en jeu** (c'est ce que dit l'infobulle du bouton). Si vous venez de relancer un extracteur, EVE Tools voit l'ancienne échéance jusqu'à la synchro qui relit la colonie. L'âge de chaque donnée : [fraîcheur et confidentialité des données](/help?page=data/freshness-privacy).

## 3. Lire les quatre cartes du haut

| Carte | Ce qu'elle compte |
|---|---|
| « Colonies » | Vos colonies, « sur N personnages ». |
| « Extracteurs actifs » | Les extracteurs non expirés, **y compris** ceux qui sont « bientôt expirés ». Dessous : « N extracteurs au total ». Un mot rouge « expires » s'ajoute dès qu'au moins un extracteur est expiré, sans le nombre. |
| « Expirent bientot » | Les extracteurs non expirés dont l'échéance tombe dans le seuil, « dans les prochaines 24h » par défaut. |
| « Production theorique/jour » | Un total en ISK, voir l'étape 7. |

Un extracteur sans échéance connue entre dans « N extracteurs au total » seulement. Si les statistiques ne se chargent pas, les cartes d'extracteurs affichent un tiret à la place du nombre (infobulle « Valeur inconnue »), jamais 0.

Sous les cartes, un bandeau apparaît quand une colonie est concernée :

- rouge : « N extracteurs expires », suivi de « système (type de planète) » pour chaque colonie ;
- ambre : « N expirent dans moins de 24h ».

Piège : le bandeau compte des **colonies**, pas des extracteurs, malgré son libellé. Une colonie avec un extracteur expiré et un autre bientôt expiré n'est comptée que dans le rouge. Les cartes, elles, comptent des extracteurs.

Autre piège : le statut des colonies (bandeau, bordure des cartes, cartes du haut) est calculé quand les données sont chargées. Les minuteurs, eux, tournent à la seconde. Un extracteur peut donc afficher « EXPIRE » en rouge alors que le bandeau ne l'a pas encore compté : rechargez la page ou lancez « Synchroniser ».

## 4. Lire une colonie

Les colonies sont groupées par personnage. Une carte montre la planète et son type, le système et sa sécurité, « CC Lv » (niveau du centre de commandement), le nombre d'installations (« inst. »), les minuteurs, et « MAJ » suivi de l'âge de la lecture de cette colonie.

- Carte fermée : un seul minuteur, celui de l'échéance la plus proche **de tous les extracteurs de la colonie, expirés compris**. Une colonie dont un extracteur est expiré affiche donc un minuteur rouge même si les autres tournent. Cliquez sur la carte : l'en-tête passe à un minuteur par extracteur.
- Couleurs : vert au-delà du seuil, ambre en dessous, rouge à l'expiration.
- Le badge « OBSOLETE » signale une colonie lue il y a plus d'une heure. Avec une synchro toutes les 30 minutes, c'est qu'une lecture a manqué (voir l'étape 1).
- Une colonie sans extracteur mais avec des usines (« planète usine ») tourne sur un stock importé. Son minuteur est le temps que durera ce stock, avec « N en stock, M encore fabricables » du produit final. Il part de la dernière lecture.
- Sans extracteur ni usine reconnue : « Aucun extracteur ».

Carte ouverte :

- « Extracteurs » : « Cycle », « Qte/cycle », « Tetes d'extr. » et « Debit initial », donné en « max ~N / heure ».
- « Usines » : schéma, entrées, sortie, cycle.
- « Production theorique / jour » de la colonie, voir l'étape 7.
- « Flux de stockage » : par stockage, « Entrant » et « Sortant » par cycle et par jour, « Flux net » et « remplissage ~N jours ». Ce délai divise la capacité par le flux net entrant, avec un volume par unité fixé par tier dans l'application. Il ne tient pas compte de ce que le stockage contient déjà : lisez-le comme un ordre de grandeur pour un stockage vide.

En bas de page, la légende affiche « > 24h », « < 24h » et « Expire ». Les deux premiers libellés sont fixes : les couleurs suivent **votre** seuil (étape 5).

## 5. Régler le seuil et les notifications

Deux chemins mènent au même formulaire : « Compte », « Parametres », section « Notifications », ou la cloche de l'en-tête puis le bouton « Preferences » du panneau. Ligne « Planetaire », colonnes « Active », « Push navigateur » et « Seuil (min) », puis « Enregistrer ».

- **Seuil (min)** : le délai avant l'échéance à partir duquel un extracteur est « bientôt expiré ». 1440 minutes (24 h) par défaut, entre 15 et 10 080 (7 jours). Hors de ces bornes l'enregistrement est refusé (« Erreur lors de la sauvegarde »). Un champ vide revient à 1440.
- Il pilote les cartes, le bandeau, les couleurs des minuteurs, la ligne « Extracteurs PI » de l'Accueil et les notifications. Les écrans en tiennent compte au rechargement, les notifications à la synchro suivante.
- **Active** : décochée, la catégorie ne produit plus de notification, et le champ du seuil est grisé.
- **Push navigateur** : désactivé par défaut. L'activer demande l'autorisation de votre navigateur. Sans elle, ou si le navigateur ne gère pas le push, l'écran affiche « Les notifications push ont ete refusees par le navigateur » ou « Push non supporte par ce navigateur ».

## 6. Comment l'alerte arrive

L'alerte est évaluée **à la fin de chaque synchro**, pas en continu. Pour chaque extracteur d'une colonie, au plus une notification par palier et par échéance :

| Palier | Condition | Titre de la notification |
|---|---|---|
| Avertissement | l'échéance tombe dans le seuil et il reste 30 minutes ou plus | « Extractor expiring soon » |
| Critique | il reste moins de 30 minutes (valeur fixe, indépendante du seuil) | « Extractor expiring soon » |
| Expiré | l'échéance est passée | « Extractor expired » |

Le message donne le produit, la planète, le personnage et, avant l'échéance, le nombre de minutes restantes. La notification est dans la cloche, catégorie « Planetaire » : un clic l'ouvre sur « Planétaire ». Si le push est activé, le même texte part au navigateur. **Ces textes sont toujours en anglais**, quelle que soit la langue choisie.

Un palier ne se répète pas : le suivant (Critique, puis Expiré) peut s'ajouter. Quand vous relancez l'extracteur, la nouvelle échéance repart de zéro dès qu'une synchro la lit.

Pièges :

- Avec une synchro toutes les 30 minutes, un seuil court (sous 30 minutes) peut laisser passer l'expiration sans alerte préalable. Une expiration n'est de toute façon notifiée qu'à la synchro suivante, soit jusqu'à 30 minutes plus tard.
- Catégorie désactivée : l'alerte est quand même marquée comme envoyée. La réactiver ne la rejoue pas pour cette échéance.
- Seuls les extracteurs avec une échéance sont suivis. Une planète usine qui va manquer de stock, un stockage plein ou une usine à l'arrêt ne déclenchent aucune notification.
- Un personnage ignoré par la synchro (étape 1) ne déclenche rien. La ligne « personnage à reconnecter » de l'Accueil est alors votre seul signal.

## 7. Lire la production

La carte « Production theorique/jour » et le tableau « Production globale par tier » donnent le même total, en ISK par jour. Colonnes : « Tier » (« Brut », « Transforme », « Raffine », « Specialise », « Avance » pour P0 à P4), « Produits », « Volume / jour », « ISK / jour », « % total ». Cliquez sur un tier pour voir chaque produit : entrées, sortie, ISK/j et « Appro. » (le pire écart entre ce que produisent vos colonies et ce que vos usines consomment de cette entrée).

Ce que le calcul fait :

- **Théorique** : pour un extracteur, la quantité par cycle et la durée de cycle lues par l'ESI (le « Debit initial »), sans décroissance. Un extracteur expiré ne compte plus. Une usine tourne selon ce que sa colonie lui fournit (extracteur, usine amont, ou stock).
- **Part vendable seulement** : ce que vos propres usines consomment est retiré (« N/j consommes sur place, M/j vendables »). Une usine sans entrée est « Arrêtée : aucune entrée disponible ».
- **Prix** : l'ordre de vente le moins cher à Jita 4-4 (et non le prix pondéré), tiré des prix Jita partagés par tous les joueurs, resynchronisés toutes les heures. L'écran écrit « MAJ toutes les 2h » : c'est la durée de vie du cache, pas le rythme. Voir [prix pondéré et estimation](/help?page=calculations/weighted-price-appraisal) pour les autres valorisations.

Ce que le total ne dit pas :

- **Un produit sans prix Jita compte pour 0 ISK dans le total.** Il s'affiche « - », comme un produit entièrement consommé, et rien n'indique que le prix manque. Lisez le total comme un minimum.
- Un extracteur dont l'ESI ne donne ni cycle ni quantité, ou une usine dont le schéma est inconnu du SDE, est ignoré sans message.
- Le panneau « Production theorique / jour » **d'une colonie** ne compte que ses usines, à plein régime, sans retirer ce qu'elles consomment, et affiche « - » quand le prix manque. La somme des colonies ne retombe donc pas sur le total global.

## 8. Retrouver l'alerte sur l'Accueil

Dans « À faire » :

- « N extracteurs PI arrêtés » (gravité « Critique ») : les extracteurs expirés ;
- « N extracteurs PI expirent dans moins de 24 h » (gravité « Attention ») : les bientôt expirés.

Les deux lignes ouvrent « Planétaire ». « Acquitter » ou « Tout acquitter » les masque : une ligne acquittée ne revient que si son nombre dépasse celui acquitté, et l'acquittement s'efface quand le nombre retombe à 0. Si les statistiques ne se chargent pas : « Extracteurs PI : inconnu (échec du chargement) ».

Piège : « 24 h » est écrit en dur dans cette ligne, mais le nombre suit **votre** seuil. Avec un seuil de 6 h, la ligne dit « moins de 24 h » et compte les extracteurs de moins de 6 h. La carte « En cours », « Extracteurs PI », affiche le seuil réel (« N expirent sous … ») avec « A / T actifs » et « N arretes ».

## Contrôle final

- Chaque personnage qui a des colonies est lu : aucune ligne « personnage à reconnecter » sur l'Accueil, aucun badge « OBSOLETE » sur l'écran.
- « Derniere sync : » date de moins de 30 minutes.
- La ligne « Planetaire » est « Active » avec le seuil voulu, et le push est activé si vous voulez être prévenu hors de l'application.
- Le total de production est un minimum : un produit sans prix y vaut 0.

Pour les autres chiffres inconnus : [limites connues](/help?page=limits/known-limits) et la [FAQ](/help?page=faq/faq).
