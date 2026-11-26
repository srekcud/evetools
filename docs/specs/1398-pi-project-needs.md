# Spec #1398 : « Demandé par tes projets industrie » dans l'onglet Stock (partielle)

Mode nuit : validée en autonomie, décisions en bas. Pile sur #1395 (`docs/specs/1395-pi-stock-coverage.md`, onglet Stock, `PersonalStockReader`). Maquette locale non versionnée : bloc « Demandé par tes projets industrie ». **Seule la première puce de l'issue est livrée** ; la seconde (sorties PI dans « Utiliser mon stock ») est un blocage : voir décisions.

## Contexte

- Un produit PI est un type sortie d'un schéma de planète (`PlanetSchematicTypeRepository::getSchematicMap()`, clé `output`), sans id en dur.
- Un projet demande un produit PI par une ligne de BOM matériau (`IndustryProjectBomLine::isJob()` faux) ; reste à fournir = `getRemainingQuantity()`.
- Projets lus : `IndustryProjectRepository::findOpenOfAcceptedParticipant($user)` (participant `accepted`, projet non `completed`), même périmètre que les autres vues projet de l'utilisateur.

## Backend

1. Ressource `PlanetaryProjectNeedsResource` : `GET /planetary/project-needs`, id fixe `project-needs`, `ROLE_USER`, `skip_null_values: false`, provider dédié, lecteur `App\Service\Planetary\Stock\PlanetaryProjectNeedsReader`, calcul pur `PlanetaryProjectNeedsBuilder`. Aucun identifiant reçu.
2. Demandes : pour chaque projet ouvert de l'utilisateur, chaque ligne matériau (pas un job) dont le type est un produit PI et dont le reste est > 0.
3. Résultat `products[]`, un élément par type, trié par nom (null en dernier) puis `typeId` : `typeId`, `typeName`, `required` (Σ des restes de ses lignes sur tous les projets), `stock` (?int), `missing` (?int), `projects[]` (`projectId`, `projectName`, `remaining`), triés par `projectName` puis `projectId`. Plusieurs lignes du même type dans un projet : leurs restes s'additionnent dans une seule entrée de projet.
4. `stock` = quantité du type dans le stock personnel de l'utilisateur, tous lieux (`PersonalStockReader::of($user)->quantitiesByTypeId`, même base que « Utiliser mon stock »), 0 si le type est absent. `null` quand le stock est inconnu (`unknownReason()` non nul ou assets personnels jamais synchronisés, `CachedAssetRepository::hasPersonalAssetsOf`). `missing = max(0, required − stock)`, `null` quand `stock` l'est.
5. Aucune demande : `products = []`. Accès : projets, assets et conteneurs du seul utilisateur connecté. Fraîcheur : BOM à la lecture, assets à la dernière synchro.

## Frontend

6. Store `planetaryProjectNeeds` (`load()`, `products`, `error`), bloc `PiStockProjectNeeds.vue` dans `PiStockTab` sous la liste d'achat : une ligne par produit (icône, nom, demandé, en stock, manque, projets en lien vers le projet), `UnknownValue` pour un `null`, message « Aucun projet ne demande de produit PI » quand la liste est vide, erreur `pi.stock.projectNeeds.loadError`. Aucun bouton d'achat ici (la liste d'achat reste celle de #1395, ces produits sont des sorties, pas des entrées). FR et EN.

## Exemples chiffrés (tests rouges)

| Cas | Attendu |
|---|---|
| Projet A : ligne matériau Broadcast Node 10 demandés, 4 fournis ; projet B : 5 demandés, 0 fournis | `required` 11 ; projets A 6, B 5 |
| Même total, stock 7 | `stock` 7, `missing` 4 |
| Stock 20 | `missing` 0 (jamais négatif) |
| Type absent du stock | `stock` 0, `missing` = `required` |
| Stock inconnu (assets non synchronisés) | `stock` null, `missing` null |
| Ligne job du même type | ignorée |
| Type non PI (Tritanium) | ignoré |
| Ligne entièrement fournie (reste 0) | ignorée, produit absent s'il n'a aucune autre ligne |
| Projet terminé, ou utilisateur participant non accepté | ignoré |
| Deux lignes du même type dans un projet (3 et 2) | une entrée de projet à 5 |
| Tri | nom null en dernier, puis `typeId` |
| Autre utilisateur | ses projets n'apparaissent pas |

## Hors périmètre

Sorties PI dans « Utiliser mon stock » (blocage) ; achat, prix ; réservation du stock pour un projet.

## Décisions prises en autonomie

- Issue partielle : pas de `Closes`. La seconde puce n'est pas livrée : `IntermediateStockProposer` ne propose que des lignes de job de rang zéro du groupe composant, une sortie PI est une ligne matériau. L'ouvrir touche du code Industry (proposition, `OwnStockSupplyGuard`, apport, valorisation, niveau 3) : circuit sous-estimé, voir le blocage du journal et le commentaire de l'issue.
- Le stock comparé est le stock personnel tous lieux (ce que « Utiliser mon stock » lirait), pas seulement la maison.
