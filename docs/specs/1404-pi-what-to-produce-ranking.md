# Spec #1404 : classement « Que produire ? » des P2, P3 et P4

Mode nuit : validée en autonomie, décisions en bas. Pile sur #1402 (arbres, choix, coût) et #1403 (`planetaryPlanets.ts` : disposition, planètes, emplacements libres). Maquette locale non versionnée : `rankOne`, `bestPlan`, `renderRank`. Rien de la maquette n'est recopié : recettes, volumes et prix viennent de l'API.

## Contexte

La décision d'installer est le point dur. Le classement donne, pour une base choisie (emplacements libres du plan, 1 planète, 1 personnage), les produits P2, P3 et P4 par profit par jour, semaine et mois, avec la chaîne la moins chère, la sortie plafonnée par une part du volume Jita, les planètes réellement utilisées, le volume, la tendance de prix sur 30 jours et les achats par semaine. « + Plan » ajoute le produit aux productions planifiées.

## Backend

1. Historique de The Forge pour les types de sortie P2 à P4 : `SyncRegionalMarketVolumesHandler` (déjà quotidienne, déjà suivie par `SyncTracker`) appelle `MarketHistoryService::syncHistory()` pour chacun de ces types sur The Forge uniquement, ce qui alimente `market_price_history` ; la liste de volumes des régions (`syncRegionalDailyVolumes`) ne change pas. Un échec ESI compte comme un type en échec du résumé existant, les autres types continuent. Liste des types : nouveau service `PlanetaryProductTypeResolver` (sorties des schémas dont `PlanetaryProductionCalculator::findProductTier()` vaut P2, P3 ou P4). Aucune nouvelle tâche planifiée ; le libellé de la synchro dans `HelpDescriptions` mentionne l'historique des produits PI et les tables d'aide sont régénérées (`app:help:generate-tables`).
2. Ressource API Platform `PlanetaryPlannerMarket`, `GET /planetary/planner/market`, `ROLE_USER`, identifiant fixe `market`, sans paramètre ni donnée d'utilisateur. Contenu : `regionId` (The Forge) et `products: [{typeId, averageDailyVolume, averagePrice, priceChange, lastDate}]` pour chaque type P2 à P4 ayant au moins une entrée d'historique.
3. Calcul par type, lu seulement dans `market_price_history` (aucun appel ESI à la requête) : les 30 dernières entrées (convention du dépôt : 30 entrées, pas 30 jours calendaires, voir `JitaMarketService::computeAverageDailyVolume`) donnent `averageDailyVolume` (moyenne de `volume`) et `averagePrice` (moyenne de `average`) ; `priceChange` = `(averagePrice − moyenne des 30 entrées précédentes) / moyenne précédente × 100`, arrondi à 0,1, `null` si moins d'une entrée précède ou si la moyenne précédente est nulle ; `lastDate` = date de la dernière entrée. Un type sans historique est absent (jamais un volume de 0).
4. Le volume vient de l'historique stocké et non du cache de volumes (`getCachedDailyVolumes` rend `0.0` pour un type absent, valeur plausible interdite ici).

## Frontend : calcul (`frontend/src/utils/planetaryRanking.ts`, fonctions pures)

5. Pour un produit P2 à P4, indépendamment des choix de l'utilisateur : P1 toujours acheté ; pour chaque étape P2 à P4 intermédiaire, produire si `coût produire ≤ prix`, acheter sinon (règles 9 et 13 de #1402) ; le produit visé est produit. Coût unitaire = coût produire de la chaîne ainsi choisie ; un prix manquant sur un maillon rend le coût `null`.
6. Pour une unité par jour : usines par tier (règle 7 de #1402, fractionnaire, sans plafond), volume par jour de l'usine par tier (règle 4 de #1403), disposition par tier (règle 5 de #1403) ; planètes par unité par jour `ppu = Σ usines du tier / usines par planète`. Un tier sans disposition (volume SDE inconnu, ne tient pas) rend le produit « ne tient pas » ou « inconnu » : pas de sortie, pas de profit.
7. Base : `free` = planètes libres du plan (`freePlanets` de #1403, désactivée si le maximum du compte est inconnu), `planet` = 1, `character` = 6. Sortie maximale `qMax = base / ppu`. Plafond de volume : `part × averageDailyVolume`, part en % (défaut 5, entre 0 et 100). Sortie `q = min(qMax, plafond)` ; plafonnée si `q < qMax`. Volume inconnu (type absent de `products`) : produit à profit inconnu, classé après les autres.
8. Planètes utilisées = `ceil(q × ppu − 1e-9)`, affichées sur la base. Profit par jour `q × (prix − coût unitaire)` au prix de la source de #1402 ; semaine `×7`, mois `×30`. Achats par semaine : `q × 7 × Σ(quantité par jour d'unité × prix)` des produits achetés de la chaîne (P1 compris). Marge `(prix − coût) / prix`. Coût, prix ou volume inconnu : profit `null`.
9. Tri : profit par jour décroissant, inconnus à la fin ; filtre de tier (Tous, P2, P3, P4). Premier affichage : 15 lignes, bouton pour tout voir.
10. « + Plan » ajoute une production du produit avec `round(q)` par jour ; désactivé si `round(q) < 1` ou si la base est nulle.

## Frontend : écran et état

11. Dans l'onglet Planificateur, sous les planètes : bloc « Que produire ? » (base, part du volume, chips de tier, tableau : rang, produit avec tier, « fait · achète » par tiers, sortie par jour avec marque de plafonnement, planètes utilisées / base, volume Jita, tendance 30 jours, marge, profit jour / semaine / mois, achats par semaine, bouton « + Plan »). Direction HUD EVE, noms longs, `formatIsk`.
12. Le store charge `/planetary/planner/market` avec le catalogue ; état de page : base, part du volume, tier (non persistés) ; `addProduction(typeId, targetPerDay?)` accepte un objectif optionnel (défaut inchangé : sortie d'une usine pendant un jour).
13. Part du volume invalide (vide, hors 0 à 100) : refusée, valeur précédente conservée. API du marché en erreur : le classement affiche « volume inconnu » partout et le bandeau d'erreur existant ; l'onglet reste utilisable.

## Exemples chiffrés (tests rouges)

Un P2 `B` (sortie 5, cycle 3 600 s, entrées `A` 40 et `C` 40, P1 achetés), prix `B` 1 000, `A` 20, `C` 30, taxe 4 %, base `planet` avec disposition 26 usines par planète pour le tier P2.

| Cas | Attendu |
|---|---|
| Coût unitaire | (40 × 20 + 40 × 30) / 5 + 1 000 × 0,04 = 400 + 40 = 440 |
| Usines pour 1/j | 1 / (5 × 24) = 0,008333 ; `ppu` = 0,008333 / 26 = 0,00032051 |
| Sortie maximale | `qMax` = 1 / ppu = 3 120 / jour |
| Plafond | volume moyen 20 000, part 5 % : plafond 1 000, `q` = 1 000, plafonnée ; sans plafonnement `q` = 3 120 |
| Profit | 1 000 × (1 000 − 440) = 560 000 / jour, 3 920 000 / semaine, 16 800 000 / mois |
| Planètes utilisées | ceil(1 000 × 0,00032051) = 1 sur 1 |
| Achats / semaine | 1 000 × 7 × (8 × 20 + 8 × 30) = 2 800 000 |
| Intermédiaire bon marché | un P2 intermédiaire dont le coût produire dépasse le prix est acheté dans la chaîne (« achète ») |
| Prix manquant | un prix de P1 manquant : coût et profit `null`, ligne en fin de tableau |
| Volume absent | type absent de `products` : profit `null`, jamais plafonné à 0 |
| Base libre | `free` = 4 planètes libres : `qMax` = 4 / ppu ; maximum du compte inconnu : option désactivée |
| Tendance | 30 entrées à 100 puis 30 à 110 : `priceChange` 10,0 ; moins d'une entrée précédente : `null` |
| Tri | profits 3, 1, `null`, 2 : ordre 3, 2, 1, `null` |
| + Plan | `q` 1 000,4 : objectif 1 000 ; `q` 0,4 : bouton désactivé |

## Cas limites

- Produit visé qui ne tient pas dans une planète : « ne tient pas N j », pas de profit.
- Part de volume 0 : sortie nulle, bouton désactivé.
- Plusieurs P1 ou entrées partagées dans la chaîne : achats additionnés par produit dans l'estimation, une ligne par produit.

## Accès et fraîcheur

`/planetary/planner/market` : `ROLE_USER`, aucune donnée d'utilisateur ni de corporation, aucun identifiant reçu. Fraîcheur : synchro quotidienne ; `lastDate` est affichée par produit, sans refus d'une donnée ancienne.

## Tests existants contredits ou modifiés

`SyncRegionalMarketVolumesHandler` : constructeur et liste de types (tests du handler, `grep -rn "SyncRegionalMarketVolumesHandler" tests`) ; `PlanetaryPlannerCatalogTest` et la fixture front de #1402/#1403 inchangés.

## Hors périmètre

Valorisation de l'extraction (P1 toujours acheté ici) ; marchés de structure ; persistance des réglages ; templates (#1405) ; suivi du plan (#1406) ; alerte sur donnée ancienne.

## Décisions (mode nuit)

- Volume et tendance lus dans l'historique stocké, alimenté par la synchro quotidienne existante étendue à The Forge seule (53 appels ESI par jour) ; la liste de volumes des autres régions n'est pas agrandie ; pas de nouvelle tâche planifiée ni de repli ESI à la requête.
- Calcul côté front sur le catalogue et le marché, indépendant des choix de l'utilisateur (le classement compare les produits à chaîne égale, la plus économique).
- Pas de plafond de volume quand le volume est inconnu : le profit est alors inconnu plutôt que surestimé.
- 15 lignes par défaut (comme la maquette), bouton pour tout voir.
