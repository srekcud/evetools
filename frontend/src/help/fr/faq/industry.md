# FAQ : industrie

## Comment la structure et ses bonus sont-ils choisis pour un projet ?

Pour chaque étape du projet, le calcul retient une structure parmi celles de votre liste, dans cet ordre :

1. la structure associée à l'étape, quand un job ESI lié à l'étape a tourné dans une structure **importée** (vous ne la choisissez pas à la main) ;
2. sinon, la meilleure de vos structures situées dans le système favori de l'activité (fabrication ou réaction) ;
3. sans système favori, ou sans structure dans ce système, la meilleure de toutes vos structures pour l'activité.

« Meilleure » veut dire : le meilleur bonus de matériaux pour la catégorie du produit. Sans aucune structure configurée, le projet est calculé sans bonus.

Le détail, les cas particuliers et la taxe d'installation sont dans le guide [Configurer ses structures industrielles](/help?page=playbooks/industry-structures).

## ME/TE : d'où viennent les valeurs par défaut ?

- **Produit du projet** : vous saisissez le ME et le TE de chaque produit à la création. Un produit ajouté à la main démarre à ME 2 et TE 4. Un produit ouvert depuis une analyse arrive avec les valeurs de l'analyse ; à défaut, il démarre à ME 0 et TE 0 (TE 4 pour un produit T2).
- **Blueprint que vous possédez** : s'il existe un blueprint visible qui produit le produit, le plan du projet utilise le sien à la place des valeurs saisies. EVE Tools retient celui qui a le plus haut ME, puis le plus haut TE, puis un BPO avant un BPC. Les blueprints de tous vos personnages et ceux de la corporation (selon la visibilité réglée par le directeur) entrent dans ce choix.
- **Composants fabriqués** (intermédiaires) : ME 10 et TE 20.
- **Réactions** : ME 0 et TE 0, car une formule de réaction ne se recherche pas.

## Acheter ou produire : comment la décision est-elle calculée ?

Pour chaque intermédiaire du plan d'un projet qui a un job, EVE Tools compare les options pour la quantité demandée, chacune livrée à la station qui l'utilise :

- **produire** : le coût de fabrication, avec tous les runs entiers (le surplus produit est compris dans le coût) ;
- **acheter à Jita** : le prix Jita pondéré, plus le coût d'**import** ;
- **acheter au marché de la capitale** : le prix du marché de la capitale, quand il est lu.

L'import passe par votre **hub de courier**, le système où les couriers de la corporation livrent toujours les achats de Jita (la capitale, C-J6MT par défaut). Il vaut le volume en m³ × la quantité × le tarif Jita → hub de courier (800 ISK par m³ par défaut), puis, quand l'atelier n'est pas dans le système du hub, × votre tarif de **pont** (0 par défaut). Un atelier à Jita ne paie aucun import. Le hub de courier se règle dans « Systèmes favoris », les deux tarifs (« Coût d'export » et « Tarif du pont ») dans la configuration de l'Industrie. L'import est inconnu sans volume, sans atelier ou sans hub de courier, jamais 0.

Le choix recommandé est l'option la moins chère, avec à égalité produire d'abord, puis Jita, puis le marché de la capitale. Il est **inconnu** dès qu'un coût dont il dépend l'est. Rien n'est enregistré : le calcul est refait à chaque lecture.
