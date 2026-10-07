# Configurer ses structures industrielles

Les structures de production portent les bonus de structure et de rig : moins de matériaux et des jobs plus courts. Sans structure configurée, les projets Industrie sont calculés sans aucun bonus.

Ne confondez pas avec la **Structure de marche** des paramètres (clic sur votre carte personnage, en bas du menu). Celle-ci sert uniquement à comparer les prix avec Jita.

> Le moteur de calcul Industrie est en cours de refonte. Les règles de calcul décrites ici peuvent évoluer.

## Où trouver la configuration

1. Dans le menu latéral : **Production** › **Industrie**.
2. À droite de la barre d'onglets, cliquez sur l'icône d'engrenage (infobulle **Configuration**). L'icône est masquée quand un projet est ouvert : revenez d'abord à la liste.
3. Le panneau **Configuration** s'ouvre sur la section **Frais**. Dépliez la section **Structures**.

La section contient deux blocs : **Systèmes favoris** en haut, puis la liste **Structures de production**.

## Ajouter une structure

Cliquez sur **Ajouter** : le formulaire **Nouvelle structure** s'ouvre. Il y a trois façons de le remplir.

- **Structures partagées par la corporation** : cette liste propose les structures que d'autres membres de votre corporation ont déjà importées et qui ne sont pas encore dans votre liste. La mention « Rigs configurés » indique qu'une configuration existe. Choisir une structure remplit le nom, le type, la sécurité et les rigs.
- **Ou rechercher par nom (ESI)** : tapez au moins 3 caractères. La recherche passe par votre personnage principal et renvoie 10 résultats au plus. Les structures déjà présentes dans votre liste ne sont pas proposées, et celles dont votre personnage ne peut pas lire les détails sont ignorées. Une étoile ★ signale une structure de votre corporation. Le nom et le type sont remplis (Raitaru, Azbel, Sotiyo, Athanor, Tatara), **mais pas la sécurité** : choisissez-la vous-même.
- **Saisie manuelle** : renseignez seulement le nom. Une telle structure n'est pas partagée et n'est pas reconnue quand un job ESI y a tourné (voir plus bas). Préférez l'import quand c'est possible.

Les champs du formulaire :

| Champ | Contenu |
|---|---|
| **Nom** | Obligatoire. |
| **Type de structure** | **Station NPC (aucun bonus)**, Raitaru, Azbel, Sotiyo (Engineering Complex, pour la fabrication), Athanor, Tatara (raffineries, pour les réactions). |
| **Sécurité** | **High-Sec (x1.0)**, **Low-Sec (x1.9)**, **Null-Sec (x2.1)**. Le multiplicateur s'applique au bonus des rigs. Les valeurs affichées sont celles de la fabrication ; pour les rigs de réaction, le calcul des projets applique ×1,0, ×1,0 et ×1,1. Valeur par défaut : Null-Sec. |
| **Rigs (bonus matériaux)** | 3 rigs au plus. Tapez au moins 2 caractères. La liste ne propose que les rigs de la taille de la structure : rigs de fabrication pour un Engineering Complex, rigs de réaction pour une raffinerie. Chaque rig affiche son bonus ME (et TE s'il en a) et les catégories de produits qu'il cible. |

Le cadre **Bonus calculé (base structure + rigs x sécurité)** donne un aperçu avant d'enregistrer. Validez avec **Ajouter**.

Une structure importée ne peut être ajoutée qu'une fois. Si elle est déjà dans votre liste, l'enregistrement est refusé avec le message « Cette structure est déjà importée (*nom*). Modifiez-la depuis la liste. » : modifiez l'entrée existante. Dans la liste corporation et dans la recherche ESI, une structure déjà importée porte la mention « déjà importée ».

Les icônes à droite de chaque structure servent à la modifier ou à la supprimer.

## Structures de corporation partagées

Une structure est partagée avec votre corporation quand elle a été importée (liste corporation ou recherche ESI) et qu'elle appartient à votre corporation. Elle porte alors le badge **CORPO**.

- Sa configuration (type, sécurité, rigs) est proposée aux autres membres quand ils ajoutent cette structure. C'est la configuration la plus récemment créée qui est proposée.
- Chaque membre garde **sa propre copie**. Si vous modifiez la vôtre, les copies déjà importées par les autres ne changent pas. Une confirmation (**Modifier une structure corpo**) vous est demandée si vous changez les rigs, le type ou la sécurité.
- Supprimer une structure corpo la retire de votre liste, mais sa configuration reste disponible pour les autres. Si vous l'importez à nouveau, vous retrouvez votre ancienne entrée, mise à jour avec les valeurs saisies.
- Le message « Aucune structure partagée. Cette liste reprend les structures de la corporation configurées par les autres membres. » signifie qu'aucun autre membre n'a encore partagé de structure que vous n'avez pas déjà.

## Systèmes favoris

Le bloc **Systèmes favoris** propose un système pour la **Fabrication** et un pour les **Réactions**. Tapez au moins 2 caractères, choisissez le système ; la croix le retire.

Ces systèmes servent à :

- **le coût d'installation des étapes** : le cost index utilisé est celui du système favori de l'activité. Sans système favori, c'est celui de Jita ;
- **les onglets d'analyse** **Marges**, **Scan**, **Achat vs Prod** et **Pivot** : le système de fabrication favori est le système par défaut. Sans système favori, c'est Jita.

Ils servent enfin à choisir la structure d'une étape (voir ci-dessous). Seules les structures importées connaissent leur système : une structure saisie à la main n'est jamais retenue comme structure du système favori.

## À quoi servent ces réglages

Pour chaque étape d'un projet, le calcul retient une structure :

1. **La structure associée à l'étape**, s'il y en a une. L'association est automatique : quand un job ESI lié à l'étape a tourné dans une structure de votre liste **importée**, l'étape prend cette structure. L'interface ne permet pas de la choisir à la main.
2. **Sinon, la meilleure de vos structures situées dans le système favori** de l'activité (fabrication ou réaction) : celle qui donne le meilleur bonus matériaux pour la catégorie du produit. Elle passe avant une structure plus avantageuse située ailleurs.
3. **Sans système favori, ou sans aucune structure dans ce système, la meilleure de toutes vos structures** : parmi les Engineering Complex pour la fabrication, ou les raffineries pour les réactions, celle qui donne le meilleur bonus matériaux pour la catégorie du produit.

Le bonus matériaux combine le bonus de base de la structure (1 % pour un Engineering Complex en fabrication) et celui des rigs multiplié par la sécurité. Le bonus de temps combine le bonus de base (Raitaru 15 %, Azbel 20 %, Sotiyo 30 % en fabrication ; 25 % en réaction pour les raffineries) et celui des rigs de temps. Un rig ne s'applique qu'aux catégories de produits qu'il cible.

Dans l'arbre d'un projet :

- **Non configure** : un job lié à l'étape a tourné dans une structure qui n'est pas dans votre liste, ou qui y a été saisie à la main. Importez-la pour que ses bonus soient pris en compte.
- **Sous-optimal — Meilleur : …** : une autre de vos structures donne un meilleur bonus matériaux pour ce produit.

## Pièges connus

- **Type de structure dans le système favori** : dans le système favori, le type de structure n'est pas vérifié. Une raffinerie seule dans votre système favori de fabrication peut être retenue pour une étape de fabrication, sans bonus, au lieu de votre meilleur Engineering Complex situé ailleurs (et inversement pour les réactions). Connu.
- **Doublons créés avant la correction** : le refus d'un second import ne supprime pas les doublons déjà présents. Supprimez l'entrée en trop.
- **Athanor et rigs Thukker** : l'Athanor reçoit à tort un bonus de temps de 25 % en réaction, et le bonus des rigs Thukker est mal évalué (#71). Connu, en cours de correction.
- **Bonus matériaux de réaction affiché** : la valeur « ME Reactions » de la liste et de l'aperçu utilise le multiplicateur de sécurité de la fabrication. Le calcul des projets n'est pas touché, seul l'affichage est faux (#72). Connu, en cours de correction.
- **Bonus « ME Manuf » de la liste** : c'est la somme de tous les rigs de fabrication, toutes catégories confondues, sans le bonus de base de 1 %. Il ne correspond donc à aucun produit en particulier. Le bonus réellement appliqué apparaît dans le projet.
- **Sécurité après une recherche ESI** : elle n'est pas remplie automatiquement. Vérifiez-la avant d'enregistrer.
