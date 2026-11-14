# Coût d'un job d'industrie

Cette page explique comment EVE Tools calcule le coût d'installation d'un job (fabrication, réaction, copie, invention), d'où vient chaque terme, et ce que changent la structure, ses rigs, le ME et le TE du blueprint. La marge qui en découle est décrite dans [marge](/help?page=calculations/margin). Pour déclarer vos structures : [configurer ses structures industrielles](/help?page=playbooks/industry-structures).

## Où le chiffre apparaît

- **Menu « Industrie », onglet « Analyse », sous-onglet « Marges ».** Le tableau « Cout d'installation » donne une ligne par job (colonne « Cout install. ») et le « Cout total installation ». La case « Installation » de « Resume des couts » reprend ce total.
- **Sous-onglet « Achat vs Prod ».** Même calcul que « Marges ».
- **Sous-onglet « Scan ».** Le coût d'installation d'un seul run entre dans la marge de chaque ligne. Il est calculé à part, avec les hypothèses décrites plus bas.
- **Projet, onglet « Production », carte « Structures ».** Le total de chaque structure comprend l'installation (indice de coût, bonus de rôle, taxe).

Le coût d'un job déjà lancé, lu dans l'ESI et enregistré comme apport « Job Install » d'un projet, ne passe pas par ce calcul : c'est le montant facturé par le jeu.

## La formule

### 1. La valeur estimée du job (EIV)

EIV = runs × somme sur les matériaux de (quantité de base par run × prix ajusté du matériau)

Les quantités sont celles du blueprint dans le SDE, **avant ME et avant tout bonus** de structure ou de rig. Le prix ajusté est celui que publie l'ESI, ce n'est pas un prix de marché.

### 2. La base du job

- Fabrication et réaction : base = EIV.
- Copie et invention : base = 2 % de l'EIV de fabrication du blueprint concerné (copié ou inventé).

### 3. Le coût d'installation

coût d'installation = base × cost index × multiplicateur de coût de la structure
\+ base × taxe d'installation
\+ base × 4 % (surcharge SCC)

- Le **cost index** est celui du système et de l'activité du job.
- Le **multiplicateur de coût de la structure** combine le bonus de rôle de coût du type de structure et, pour la copie, l'invention et la recherche, le bonus de coût des rigs de science de cette activité. Il ne porte que sur le terme du cost index, jamais sur la taxe ni sur la SCC. Sans structure, il vaut 1.
- La **taxe d'installation** est celle que vous avez saisie pour la structure. Sans structure de joueur apte au job, le job est compté en station NPC à 0,25 %.
- La **surcharge SCC** vaut 4 % de la base, quelle que soit la structure.
- Aucun arrondi n'est appliqué au calcul. L'écran « Marges » affiche chaque ligne sans décimale.
- La taxe des clones alpha (0,25 % de la base) existe dans le calcul mais n'est jamais activée : EVE Tools compte toujours un clone oméga.

### Quel système, quelle structure

- **Structure.** En fabrication et en réaction, EVE Tools retient la structure qui réduit le plus les matériaux du produit, en préférant votre système favori. En copie et en invention, c'est la structure au taux d'installation le plus bas (cost index × multiplicateur de coût + taxe). Une structure déclarée comme « Station NPC » n'entre pas dans ce choix : sans structure de joueur apte, le calcul utilise la station NPC par défaut décrite plus haut.
- **Système du cost index.** Celui de la structure si elle a été importée depuis le jeu (son système est alors connu). Sinon, sur « Marges », « Scan » et « Pivot », le système choisi dans « Systeme solaire » ; avec « Par defaut », votre système favori de fabrication (ou de réaction pour une réaction), et Jita si vous n'en avez pas. Un système choisi à la main sert à la fois à la fabrication et aux réactions.

## Ce que changent le ME, le TE et les bonus de matériaux et de temps

Le ME, le TE, les bonus de matériaux et les bonus de temps **ne changent pas le coût d'installation**, puisque l'EIV part des quantités de base. Ils changent les quantités de matériaux à acheter et la durée.

Quantité d'un matériau pour un job de fabrication :

quantité = max(runs, arrondi supérieur de [arrondi à 2 décimales de (runs × quantité de base × (1 − ME / 100) × multiplicateur de matériaux)])

- Le jeu arrondit une fois par job, jamais par run, et jamais sous une unité par run.
- Le **multiplicateur de matériaux** = bonus de rôle matériaux du type de structure (fabrication seulement) × le multiplicateur de chaque rig qui vise la catégorie du **produit** du job.
- Multiplicateur d'un rig = 1 − (bonus du rig en % × modificateur de sécurité) / 100. Le bonus et les modificateurs de sécurité (haute, basse, nulle) viennent du SDE.
- Une réaction ignore le ME : elle compte toujours ME 0.
- Le TE et les bonus de temps (bonus de rôle de temps, rigs de temps) réduisent la durée du job, pas son coût.

## D'où vient chaque terme

| Terme | Source | Fraîcheur |
|---|---|---|
| Cost index | ESI `GET /industry/systems/`, par système et par activité | Synchronisé toutes les 2 h, gardé 6 h en cache |
| Prix ajusté | ESI `GET /markets/prices/` | Synchronisé toutes les 24 h, gardé 72 h en cache |
| Quantité de base des matériaux | SDE, matériaux de l'activité du blueprint | À chaque import du SDE |
| Bonus de rôle (matériaux, coût, temps) | SDE, attributs du type de structure (Raitaru, Azbel, Sotiyo, Athanor, Tatara) | À chaque import du SDE |
| Bonus de rig et modificateurs de sécurité | SDE, attributs du rig | À chaque import du SDE |
| Type, sécurité, rigs, taxe d'installation | Réglage : panneau « Configuration » de la page Industrie, section « Structures » | Votre saisie |
| Systèmes favoris | Réglage : « Systèmes favoris » (« Fabrication », « Réactions ») | Votre saisie |
| ME, TE, runs | Saisie de l'écran (défauts plus bas) | Votre saisie |

Les étiquettes « Cost index » et « Prix ajustés » sous les sous-onglets de l'analyse donnent l'âge de la dernière synchronisation (voir [fraîcheur des données](/help?page=data/freshness-privacy)).

## Exemple

Valeurs d'exemple, rondes, qui ne viennent pas du jeu.

Un job de fabrication de 10 runs. Le blueprint demande, par run, 1 000 unités d'un matériau A (prix ajusté 500 ISK) et 100 unités d'un matériau B (prix ajusté 5 000 ISK).

- EIV = 10 × (1 000 × 500 + 100 × 5 000) = 10 × 1 000 000 = **10 000 000 ISK**.
- Cost index du système : 0,05 (5 %).

En station NPC (multiplicateur de coût 1, taxe 0,25 %) :

| Terme | Calcul | ISK |
|---|---|---|
| Cost index | 10 000 000 × 0,05 × 1 | 500 000 |
| Taxe d'installation | 10 000 000 × 0,0025 | 25 000 |
| Surcharge SCC | 10 000 000 × 0,04 | 400 000 |
| **Coût d'installation** | | **925 000** |

Dans une structure de joueur avec un multiplicateur de coût de 0,9 et une taxe saisie de 1 % :

| Terme | Calcul | ISK |
|---|---|---|
| Cost index | 10 000 000 × 0,05 × 0,9 | 450 000 |
| Taxe d'installation | 10 000 000 × 0,01 | 100 000 |
| Surcharge SCC | 10 000 000 × 0,04 | 400 000 |
| **Coût d'installation** | | **950 000** |

La structure coûte ici plus cher à l'installation : sa taxe dépasse le gain sur le cost index. Elle peut rester le meilleur choix si ses rigs font économiser plus de matériaux.

Une invention du même produit aurait pour base 2 % × 10 000 000 = 200 000 ISK (EIV d'un run de fabrication du produit T2, ici supposée égale à celle de l'exemple).

Matériaux, même exemple : 100 unités de base par run, 10 runs, ME 10, bonus de rôle matériaux 0,99 et un rig de 2 % en basse sécurité avec un modificateur de 1,9 (multiplicateur 1 − 2 × 1,9 / 100 = 0,962) :
10 × 100 × 0,9 × 0,99 × 0,962 = 857,14, arrondi supérieur **858 unités**. Le coût d'installation, lui, ne bouge pas.

## Quand le coût est inconnu

Le coût d'installation est **inconnu**, jamais 0, quand il manque :

| Donnée manquante | Message à l'écran |
|---|---|
| Le cost index du système pour l'activité | « Indice de coût du système inconnu » |
| Le prix ajusté d'au moins un matériau | « Prix ajusté ESI inconnu » |
| La taxe d'installation de la structure retenue (champ laissé vide) | « Taxe d'installation de la structure non saisie : coût d'installation inconnu. » |

- Sur « Marges », la ligne du job et le « Cout total installation » affichent « Inconnu ». Le coût total et les marges sont alors inconnus, et le tableau de comparaison des ventes est remplacé par la ou les causes.
- Sur « Scan », la ligne affiche une seule cause, la première trouvée.
- Une taxe saisie à 0 reste un vrai 0 % : saisissez 0 si la structure ne prélève rien.
- Un cost index ou un prix ajusté sort du cache s'il n'a pas été resynchronisé pendant trois intervalles (6 h pour le cost index, 72 h pour les prix ajustés) : le coût redevient inconnu jusqu'à la synchronisation suivante.

## ME et TE par défaut selon l'écran

Les écrans ne partent pas des mêmes valeurs. Vérifiez le ME et le TE avant de comparer deux écrans.

| Écran | Produit T1 | Produit T2 | Réaction |
|---|---|---|---|
| « Marges » | ME 10, TE 20 | ME 2, TE 4, puis ceux du décrypteur choisi | ME 0 dans le calcul |
| « Achat vs Prod » | ME 2 (pas de champ TE) | ME 2 | ME 0 dans le calcul |
| « Scan » (fixes) | ME 10, TE 20 | ME 2, TE 4 | ME 0, TE 0 |
| Création de projet | ME 2, TE 4 | ME 2, TE 4 | ME 0 dans le calcul |

- Pour un T2 sur « Marges », le ME et le TE du produit sont ceux de l'invention (2 et 4 sans décrypteur, modifiés par le décrypteur), quelle que soit la saisie.
- Les composants fabriqués en chemin comptent toujours ME 10 et TE 20, et les réactions ME 0.

## Pièges

- **Le ME ne réduit pas le coût d'installation.** L'EIV part des quantités de base, avant ME et avant bonus.
- **Une taxe laissée vide rend le coût inconnu.** Elle ne compte pas pour 0 %.
- **« Scan » simplifie.** Un seul run, ME fixe, et les quantités de matériaux sans bonus de structure ni de rig. Le cost index est celui du système de l'analyse, même pour une structure importée dans un autre système. Pour un T2, le calcul ne suppose aucun décrypteur, alors que l'aide de l'écran annonce un décrypteur Attainment.
- **Carte « Structures » d'un projet.** Une structure créée à la main (non importée) n'a pas de système connu : son installation y est inconnue, alors que « Marges » utilise votre système favori pour la même structure.
- **L'aperçu « Bonus calculé » de la configuration des structures** est une indication globale. Le calcul applique chaque rig seulement aux produits de la catégorie qu'il vise, avec les valeurs du SDE.
- **La note sous le tableau « Cout d'installation »** dit que les coûts réels peuvent varier selon les bonus de la structure et ses rigs. Le calcul les inclut déjà (bonus de rôle de coût, rigs de science) ; l'écart restant tient au cost index et aux prix ajustés, qui changent entre la synchronisation et le lancement du job.

## Note technique

Calcul du coût : `InstallCost::forJob()` (`src/Industry/Domain/InstallCost.php`), EIV : `EstimatedItemValue::of()`, appelés par `ProductionCostCalculator::installCost()` pour « Marges », « Achat vs Prod » et la carte acheter ou fabriquer d'un projet, par `StructureTotalCostComparator::breakdownOf()` pour la carte « Structures » d'un projet, et par `JobInstallCostEstimator::estimate()` pour « Scan » et « Pivot ». Quantités : `MaterialQuantity::forManufacturingJob()`. Multiplicateurs : `ProductionStructure::installCostMultiplierFor()` et `materialMultiplierFor()`.
