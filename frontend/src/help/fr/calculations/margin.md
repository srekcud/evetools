# Profit et marge

Cette page explique comment EVE Tools calcule le profit et la marge d'une production, du chiffre estimé de l'analyse au chiffre réel d'un projet vendu. Il n'y a qu'une définition de la marge : **le profit divisé par le coût**, pas par le revenu. Le coût d'installation des jobs est détaillé dans [coût d'un job](/help?page=calculations/job-cost), les frais de vente dans [frais de vente](/help?page=calculations/market-fees), les prix pondérés dans [prix pondéré](/help?page=calculations/weighted-price-appraisal).

## Où le chiffre apparaît

| Écran | Ce qui est affiché | Nature |
|---|---|---|
| Menu « Industrie », onglet « Analyse », sous-onglet « Marges » | Cartes « Cout total », « Meilleur prix de vente », « Profit », « Marge » ; tableau « Comparaison prix de vente » (colonnes « Revenu », « Frais », « Profit », « Marge ») ; colonne « Meilleure marge » du panneau d'invention d'un T2 | Estimation |
| Sous-onglet « Scan » | Colonnes « Marge % » et « Profit/Unite », cartes « Rentables » et « Meilleure Marge » | Estimation, un run |
| Sous-onglet « Achat vs Prod » | Cartes « Cout de Production Total », « Prix de Vente », « Marge » | Estimation |
| Sous-onglet « Pivot » | Marge de chaque produit proposé | Estimation, même formule que « Scan » |
| Un projet, onglet « Finances » | « Résumé financier » : « Profit » (ou « Profit provisoire ») et « Marge : » ; encadré « Marge provisoire » avec « Marge projetée au dernier prix de vente » | Réel, sur les ventes saisies |
| Menu « Bilan », onglet « Industrie » | « Profit de la période », colonnes « Profit », « Marge » et « Capital engagé » par mois | Réel, projets terminés |
| Menu « Bilan », onglet « Vue d'ensemble », et page « Accueil », carte « Bilan des 30 derniers jours », case « Industrie » | Profit industrie de la période | Réel, projets terminés |

## La formule

Pour une vente :

revenu = prix unitaire × unités vendues
frais = revenu × taux de frais du lieu de vente
profit = revenu − frais − coût
marge = profit ÷ coût × 100

- La marge n'est calculée que si le coût est strictement positif. Sinon elle est inconnue.
- Une marge négative est une perte. Elle n'a pas de plancher.
- Les écrans affichent la marge avec une décimale (« +22,2 % »).

### Le revenu

Le revenu est le prix unitaire multiplié par les **unités produites** (runs × unités par run), pas par le nombre de runs. Le prix dépend du lieu de vente :

| Ligne de « Comparaison prix de vente » | Prix unitaire | Taux de frais |
|---|---|---|
| « Jita », étiquette « vente » | Prix pondéré des ordres de vente Jita sur la quantité produite | courtage + taxe de vente |
| Votre structure de marché, étiquette « sous-enchere » | Ordre de vente le moins cher de la structure | courtage + taxe de vente |
| Votre structure de marché, étiquette « vente instantanee » | Ordre d'achat le plus cher de la structure | taxe de vente seule |
| « The Forge », étiquette « contrat » | Contrat public mono-item le moins cher | aucun |

- La structure de marché est votre structure de marché préférée, la première de vos structures de marché à défaut, sinon la station de Jita.
- Une ligne sans prix (ou à prix nul) n'apparaît pas.
- La ligne marquée « Meilleur » est celle qui a la marge la plus haute. Les cartes « Meilleur prix de vente », « Profit » et « Marge » reprennent cette ligne ; le lieu est nommé sous la carte « Marge ».

### Le coût de production

Dans « Marges », le coût de production (« Cout total de production ») est :

coût = matériaux achetés + installation des jobs + invention (T2 seulement)

- **Matériaux** : prix pondéré Jita des matériaux à acheter, après ME et bonus de structure. Les composants que vous fabriquez sont comptés au prorata des runs nécessaires (coût marginal), sans arrondi à des runs entiers.
- **Installation** : somme des coûts d'installation des jobs de fabrication et de réaction, voir [coût d'un job](/help?page=calculations/job-cost).
- **Invention** : pour un T2, coût d'une tentative × nombre de tentatives attendues. Une tentative = datacores + décrypteur (s'il y en a un) + installation de l'invention + installation de la copie T1 d'un run. Tentatives attendues = runs T2 voulus ÷ (probabilité de succès × runs par BPC inventé). Le résultat n'est pas arrondi à des tentatives entières.
- Le BPO T1 lui-même, le transport et un éventuel prix de BPC ne sont pas comptés dans « Marges ».

### Dans un projet

Le calcul est le même, sur des chiffres réels :

- **revenu** = somme des ventes saisies (marché et contrats) ;
- **frais** = (courtage % + taxe de vente %) du projet appliqués au revenu marché, plus les frais des contrats lus dans le journal wallet ;
- **coût** = « Cout total du projet », somme des valeurs des apports approuvés (voir [contributions de groupe](/help?page=calculations/group-contributions)) ;
- **marge** = (revenu net − coût) ÷ coût × 100.

### Marge provisoire et marge projetée

Tant qu'il reste des produits finaux non vendus, le profit s'appelle « Profit provisoire » et l'encadré « Marge provisoire » liste les « Invendus : » avec le prix unitaire de leur dernière vente.

- La **marge affichée** (« Marge : ») ne compte que les ventes déjà saisies. Elle est donc basse, souvent négative, au début des ventes.
- La **marge projetée au dernier prix de vente** ajoute les invendus : quantité invendue × prix unitaire de la dernière vente du même produit, diminué du courtage et de la taxe de vente du projet.

marge projetée = (revenu net actuel + invendus × dernier prix × (1 − (courtage % + taxe %) ÷ 100) − coût) ÷ coût × 100

### Dans le Bilan

Le « Profit » d'un projet terminé est **votre part** : vos apports approuvés × marge du projet (le profit entier pour un projet solo). La « Marge » d'un mois = somme des profits des projets à profit connu ÷ somme du « Capital engagé » de ces mêmes projets × 100.

## D'où vient chaque terme

| Terme | Source | Fraîcheur |
|---|---|---|
| Prix des matériaux, datacores, décrypteurs | Carnet d'ordres de vente de Jita (ESI), prix pondéré sur la quantité | Synchronisé toutes les heures ; un type absent est lu à la demande |
| Prix de vente « Jita » | Même carnet, pondéré sur la quantité produite | Toutes les heures |
| Prix de structure (« sous-enchere », « vente instantanee ») | Marché de votre structure (ESI) | Toutes les heures |
| Prix de contrat | Contrats publics de The Forge (ESI) | Toutes les 30 min |
| Coût d'installation | Voir [coût d'un job](/help?page=calculations/job-cost) | Cost index 2 h, prix ajustés 24 h |
| Courtage, taxe de vente | Compétences et standings du personnage principal, voir [frais de vente](/help?page=calculations/market-fees) | À chaque « Sync toutes » |
| Probabilité d'invention | SDE et compétences de l'« Inventeur » choisi | Import du SDE, synchro des compétences |
| Recettes, unités par run | SDE | À chaque import du SDE |
| Ventes et apports d'un projet | Votre saisie dans le projet ; frais des contrats lus dans le journal wallet du vendeur | À la saisie ; journal lu avec la synchro wallet |

## Exemple

Valeurs d'exemple, rondes, qui ne viennent pas du jeu.

**Analyse.** 10 runs d'un produit qui sort 1 unité par run : 10 unités. Coût de production 100 000 000 ISK (90 M de matériaux, 10 M d'installation). Courtage 2 %, taxe de vente 4 %.

| Ligne | Prix unitaire | Revenu | Frais | Profit | Marge |
|---|---|---|---|---|---|
| Jita, vente | 13 000 000 | 130 000 000 | 7 800 000 (6 %) | 22 200 000 | +22,2 % |
| Structure, vente instantanee | 12 000 000 | 120 000 000 | 4 800 000 (4 %) | 15 200 000 | +15,2 % |
| The Forge, contrat | 12 500 000 | 125 000 000 | 0 | 25 000 000 | +25,0 % |

La ligne contrat est « Meilleur ». Rapporté au revenu, le profit Jita donnerait 17,1 % : ce n'est pas ce que l'application calcule.

**Projet.** Mêmes 10 unités, coût du projet 100 000 000 ISK. 6 unités vendues au marché à 13 000 000 ISK :

- revenu 78 000 000, frais 6 % = 4 680 000, revenu net 73 320 000 ;
- « Profit provisoire » = −26 680 000, « Marge : » −26,7 % ;
- marge projetée : 4 invendus × 13 000 000 × 0,94 = 48 880 000, revenu net projeté 122 200 000, soit **+22,2 %**.

## Quand le chiffre est inconnu

Une donnée manquante rend le résultat **inconnu**, jamais 0.

| Donnée manquante | Effet | À l'écran |
|---|---|---|
| Prix Jita d'un matériau | Coût, profit et marges inconnus | « Marges » : cartes « Inconnu », tableau remplacé par « Un materiau n'a aucun prix a Jita : cout de fabrication et marges inconnus. » et « Materiaux sans prix : … » |
| Cost index, prix ajusté ou taxe d'installation | Installation, donc coût et marges inconnus | La cause, voir [coût d'un job](/help?page=calculations/job-cost) |
| Probabilité d'invention, compétences de l'inventeur, prix d'un datacore ou du décrypteur | Invention, donc coût et marges inconnus | Message de la cause (par exemple « Competences du personnage choisi inconnues : … ») |
| Frais de vente | Frais, profit et marge inconnus, sauf la ligne contrat | « Frais inconnus » ; colonnes « Frais » et « Profit » à « — », « Marge » à « Inconnu » |
| Prix de vente à un lieu | La ligne de ce lieu disparaît | Aucune ligne : « Pas de donnees marche » |
| Prix de vente au lieu choisi (« Scan ») | Marge inconnue | « Inconnu », la cause en infobulle (« Le produit n'a aucun prix de vente a Jita : … ») |
| Projet sans vente | Revenu net, profit, marge inconnus | « En attente des ventes » |
| Valeur d'un apport approuvé | Coût, profit, marge inconnus | « Un apport approuvé n'a pas de prix connu : … » |
| Frais du projet (vente au marché) ou frais d'un contrat | Revenu net, profit, marge inconnus | « Frais inconnus : le propriétaire du projet n'a pas synchronisé ses compétences et ses standings, … » |
| Dernier prix d'un invendu | Marge projetée inconnue | « jamais vendu » à côté du produit, marge projetée à « — » |
| Profit d'un projet terminé (Bilan) | Projet exclu des sommes, compté à part | « dont 1 à profit inconnu, non compté » |

Un prix Jita **partiel** (carnet trop court pour toute la quantité) n'est pas inconnu : il est affiché avec la mention « … % couvert ». Le prix moyen de la partie couverte est appliqué à toutes les unités : pour un matériau, le vrai coût est probablement plus élevé.

## Pièges

- **La note sous « Comparaison prix de vente » est fausse sur deux points.** Elle dit « Revenu = prix unitaire x runs » et « Marge = profit / revenu ». Le calcul multiplie par les unités produites et divise par le coût. Elle dit aussi que les frais incluent le courtage : la ligne « vente instantanee » ne paie que la taxe.
- **« Meilleur prix de vente » affiche un revenu.** Le grand chiffre est le revenu total de la ligne à la meilleure marge ; le prix unitaire est sous « par unite ». Ce n'est pas forcément le prix le plus haut.
- **« sous-enchere » ne sous-enchérit pas.** Le prix est celui de l'ordre de vente le moins cher de la structure, sans retrait.
- **« Marges », « Scan » et « Achat vs Prod » ne comptent pas le même coût.** « Scan » compte un seul run, achète tous les matériaux directs au lieu de fabriquer les composants, ajoute le transport (« Import », et « Export » pour une vente à Jita) et votre prix de BPC s'il est saisi, et ne suppose aucun décrypteur pour un T2. « Achat vs Prod » choisit pour chaque composant le moins cher entre acheter et fabriquer, en runs entiers, et vend au prix de votre structure s'il existe, sinon au prix pondéré Jita, toujours avec courtage et taxe. « Marges » ne compte ni transport ni prix de BPC.
- **« Prix de Vente » d'« Achat vs Prod »** porte un nom de système entre parenthèses qui ne change pas avec la structure choisie, ni quand le prix vient de Jita.
- **Analyse et projet ne comparent pas la même chose.** L'analyse estime un coût de marché ; le projet additionne ce qui a été apporté et approuvé. Un matériau jamais déclaré en apport n'est pas dans le coût du projet, et sa marge paraît meilleure.
- **La marge projetée applique toujours courtage et taxe.** Même si la dernière vente d'un produit était un contrat, sans frais de marché.
- **Accueil et Bilan n'affichent pas les projets à profit inconnu de la même façon.** Les deux excluent de la somme les projets terminés dont le profit est inconnu. Le Bilan le dit (« dont N à profit inconnu, non compté »), et affiche « — » si aucun projet n'a de profit connu. La case « Industrie » de l'Accueil ne le signale pas, et affiche 0 quand tous les projets de la période sont inconnus.

## Note technique

Analyse : `ProfitMarginService::marginForPrice()` (`src/Service/Industry/ProfitMarginService.php`), coût par `ProductionCostCalculator::costOf()` en `CostMode::MarginalCost`. « Scan » : `BatchProfitScannerService::scan()`. « Achat vs Prod » : `BuyVsBuildService::marginPercentOf()`. « Pivot » : `PivotAdvisorService`. Projet : `ProjectDistributionService::calculateDistribution()` et `projectedMarginPercent()`, invendus `UnsoldProductsOfProject`. Bilan : `CompletedProjectFiguresLoader`, `IndustryRevenueAggregator`, `LedgerDashboardProvider`.
