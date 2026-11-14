# Coût d'un job, bonus de structure et de rig, ME et TE

Cette page explique comment EVE Tools calcule ce qu'un job coûte à installer, combien de matériaux il consomme et combien de temps il dure. Pour chaque terme : la formule, d'où vient la donnée, sa fraîcheur, et ce qui se passe quand elle manque.

Elle vaut pour les jobs de **fabrication** et de **réaction**. La copie, l'invention et la recherche suivent la même logique avec une base différente (voir plus bas).

## Où ces calculs servent

- Les analyses **Marges**, **Scan**, **Achat vs Prod** et **Pivot**, ainsi que la comparaison acheter ou fabriquer d'un projet, **estiment** le coût d'installation avec la formule ci-dessous.
- Dans un projet, une fois un job ESI lié à une ligne, le coût d'installation compté dans la répartition est le **coût réel lu dans le job ESI**, pas cette estimation. Si le job n'a pas de coût, le montant est inconnu.
- Les quantités de matériaux et les durées d'un projet suivent les règles ME, TE et bonus des sections « Matériaux : ME, structure et rigs » et « Durée ».

## Coût d'installation

Le coût d'installation est la somme de trois termes calculés sur la même base :

```
coût d'installation = base × cost index × bonus de rôle de coût
                    + base × taxe d'installation
                    + base × surcharge SCC (4 %)
```

| Terme | Ce que c'est | D'où vient la donnée |
|---|---|---|
| **Base** | L'**EIV** (valeur estimée) du job : `runs × Σ (quantité de base × prix ajusté)`. Les quantités sont celles du SDE, **avant ME et avant tout bonus** de structure ou de rig. | Quantités : SDE. Prix ajusté : ESI `GET /markets/prices/`. |
| **Cost index** | Indice du système solaire et de l'activité. Il monte avec l'activité industrielle du système. | ESI `GET /industry/systems/`. |
| **Bonus de rôle de coût** | Multiplicateur de la structure (1 sans structure). Il ne porte que sur le terme du cost index. | SDE, selon le type de structure. |
| **Taxe d'installation** | Pourcentage prélevé par le propriétaire de la structure, de 0 à 50 %. | Saisie par vous sur la structure ; 0,25 % en station NPC, fixé par le jeu. |
| **Surcharge SCC** | Taxe fixe de 4 % de la base en fabrication et en réaction. | Constante du code. |

Points à retenir :

- Le bonus de rôle de coût multiplie **seulement** le terme du cost index. Il ne réduit jamais la taxe d'installation ni la SCC.
- La taxe est un terme en plus : elle ne multiplie pas le cost index.
- Sans structure capable de lancer le job, le calcul suppose une **station NPC** : taxe de 0,25 %, aucun bonus de rôle.
- Le **système** dont on lit le cost index est celui de la structure quand elle a été importée (seules les structures importées connaissent leur système). Sinon c'est le système favori de l'activité, et dans les analyses le système choisi (Jita par défaut). Sans aucun système, le cost index est inconnu.
- Un bonus de rôle absent du SDE compte pour 1, c'est-à-dire aucun bonus.
- EVE Tools ne compte pas la taxe de clone alpha.
- Le choix de la structure d'un job est décrit dans le guide [Configurer ses structures industrielles](/help?page=playbooks/industry-structures).

### Autres activités

- **Copie et invention** : la base n'est pas l'EIV mais **2 % de l'EIV de fabrication** du blueprint concerné (copié ou inventé), multiplié par les runs. La SCC est de 4 %. Les rigs de laboratoire ajoutent un bonus de rig de coût sur le terme du cost index.
- **Recherche ME et TE** : base = 2 % de l'EIV de fabrication × un multiplicateur selon le niveau visé, SCC de 2 %. Ce modèle est à confirmer en jeu.

## Matériaux : ME, structure et rigs

```
quantité = runs × quantité de base × (1 − ME / 100) × mod_structure × Π mod_rig
```

- **ME** : niveau de recherche du blueprint, de 0 à 10. Chaque niveau retire 1 % des matériaux.
- **mod_structure** : bonus de rôle de la structure sur les matériaux, lu dans le SDE. Il ne s'applique qu'à la fabrication.
- **mod_rig** : pour chaque rig dont la catégorie cible celle du **produit** du job (jamais celle du matériau), `1 − bonus % × modificateur de sécurité / 100`. Le bonus du rig et le modificateur de sécurité viennent du SDE (la sécurité choisie sur votre structure : High-Sec, Low-Sec ou Null-Sec).
- **Arrondi** : une seule fois par job, jamais par run. Le résultat est arrondi à 2 décimales puis au supérieur, et ne descend jamais sous **1 unité par run**.
- **Réactions** : ME 0 toujours, la formule de réaction n'a pas de ME, et il n'y a pas de bonus de rôle sur les matériaux.

### D'où vient le ME d'un projet

- Pour un blueprint que vous possédez (personnages et corporation), le plan prend le **meilleur** : le ME le plus haut, puis le TE le plus haut, puis le BPO avant le BPC. Il remplace le ME saisi.
- Sinon, le ME saisi sur le produit du projet. À la création, un produit ajouté à la main part à ME 2 / TE 4 ; un produit préchargé depuis une analyse part à ME 0 / TE 0 (TE 4 pour un T2). Ces valeurs se modifient.
- Les composants fabriqués dans le projet comptent à ME 10 et TE 20.

## Durée

```
durée = runs × temps de base × (1 − TE / 100) × mod_compétences × mod_structure × Π mod_rig
```

- **Temps de base** : SDE, par run, avant TE, structure et compétences.
- **TE** : niveau de recherche du blueprint, de 0 à 20 par pas de 2. Chaque niveau retire 1 % du temps. Une réaction n'a pas de TE.
- **mod_compétences** (fabrication) : `(1 − 0,04 × Industry) × (1 − 0,03 × Advanced Industry)`, puis `1 − 0,01 × niveau` pour chaque science du blueprint. Réaction : `1 − 0,04 × Reactions`. Une compétence jamais entraînée compte pour le niveau 0.
- **mod_structure** : bonus de temps de la structure, lu dans le SDE (un autre attribut pour les réactions).
- **mod_rig** : comme pour les matériaux, seulement les rigs de temps qui ciblent la catégorie du produit, modulés par la sécurité.
- **Arrondi** : une seule fois sur l'ensemble du job.

La durée se calcule personnage par personnage avec **ses** compétences. Un personnage dont les compétences n'ont pas été synchronisées n'a pas de durée : elle reste inconnue plutôt que supposée.

## Exemple chiffré

> **Exemple fictif, pas une valeur du jeu.** Les chiffres ci-dessous sont choisis pour le calcul. Les vrais bonus viennent du SDE et des rigs de votre structure.

**Coût d'installation** d'un job de fabrication, base (EIV) de 100 000 000 ISK, cost index de 5 % (0,05), bonus de rôle de coût de 0,97, taxe d'installation de 2 % saisie sur la structure :

| Terme | Calcul | Montant |
|---|---|---|
| Cost index | 100 000 000 × 0,05 × 0,97 | 4 850 000 ISK |
| Taxe d'installation | 100 000 000 × 0,02 | 2 000 000 ISK |
| Surcharge SCC | 100 000 000 × 0,04 | 4 000 000 ISK |
| **Total** | | **10 850 000 ISK** |

Le même job en station NPC : 100 000 000 × 0,05 = 5 000 000, plus 0,25 % = 250 000, plus la SCC 4 000 000, soit **9 250 000 ISK**.

**Matériaux** : 10 runs, 1 000 unités de base par run, ME 10, bonus de rôle de 0,99 et un rig à 2 % en High-Sec (modificateur de sécurité 1,0, donc mod_rig 0,98) :

`10 × 1 000 × 0,90 × 0,99 × 0,98 = 8 731,8`, arrondi au supérieur : **8 732 unités** (10 000 sans ME ni bonus).

**Durée** : 10 runs, 6 000 s de base par run, TE 20, Industry 5 et Advanced Industry 5, bonus de temps de structure de 0,85 :

`60 000 × 0,80 × (0,80 × 0,85) × 0,85 = 27 744 s`, soit **7 h 42 min 24 s** (60 000 s sans TE ni bonus).

## Quand le résultat est inconnu

Un coût d'installation est **inconnu**, jamais 0, dès qu'une donnée manque. Une somme qui contient un montant inconnu reste inconnue.

| Donnée manquante | Message affiché | Cause habituelle |
|---|---|---|
| Cost index | « Indice de coût du système inconnu » | Pas de cost index pour ce système et cette activité dans le cache, ou aucun système connu (structure saisie à la main, pas de système favori). |
| Prix ajusté | « Prix ajusté ESI inconnu » | L'ESI ne donne aucun prix ajusté pour un matériau du job, ou un prix nul. Un seul matériau sans prix suffit. |
| Taxe d'installation | « Taxe d'installation de la structure non saisie : coût d'installation inconnu. » | La structure n'a pas de taxe saisie. Saisissez le taux affiché en jeu, ou 0 si la structure ne prélève rien : un 0 saisi est un vrai 0 %. |

Une durée est inconnue quand le temps de base, le TE ou les compétences du personnage manquent.

## Fraîcheur des données

| Donnée | Synchronisation | Conservée en cache | Après expiration |
|---|---|---|---|
| Cost index des systèmes | Toutes les 2 heures | 6 heures (3 synchronisations) | Inconnu jusqu'à la prochaine synchronisation réussie |
| Prix ajustés | Toutes les 24 heures | 72 heures (3 synchronisations) | Inconnu jusqu'à la prochaine synchronisation réussie |
| Taxe d'installation, type, sécurité, rigs | Ce que vous avez saisi | Jusqu'à votre modification | Non applicable |
| Bonus de rôle et de rig, temps de base | SDE importé | Jusqu'au prochain import du SDE | Non applicable |
| Compétences des personnages | Synchronisation des personnages (ESI) | Jusqu'à la prochaine synchronisation | La durée reste inconnue tant que les compétences ne sont pas synchronisées |
| ME et TE des blueprints possédés | Synchronisation des blueprints, toutes les heures | Jusqu'à la prochaine synchronisation | Le ME et le TE saisis sur le produit s'appliquent |

Les deux premières lignes sont des données publiques de l'ESI, communes à tous les utilisateurs.
