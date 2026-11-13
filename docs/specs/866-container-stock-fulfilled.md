# Spec #866 : le stock du container projet compte comme fourni sur les lignes matériaux

Mode nuit (2026-11-13) : spec rédigée sans gate. Décision produit de l'issue non rouverte : le stock du container du projet est la source de vérité des lignes matériaux, déduit sans validation du propriétaire. Cette spec tranche les quatre « points pour la spec » de l'issue.

## Contexte

`ProjectContainerService::verifyContainer` est un affichage seul : la ligne « R.A.M.- Starship Tech » dit `Partial: 2 / 55` mais reste Fourni 0, Reste 55. `fulfilledQuantity` est persisté (somme des apports approuvés, dérivés des achats compris) ; il ne bouge que par apport. Le stock du container est vivant : il peut baisser sans qu'aucun apport ne change. Il ne peut donc pas être écrit dans `fulfilledQuantity` : il se superpose à la lecture, rien n'est persisté.

## Règles

1. **Quantité fournie effective** d'une ligne matériau (`isJob = false`) = `max(fulfilledQuantity, min(stockDuContainer, requiredQuantity))`. Le stock du container est la quantité du type dans le container nommé du projet (`ProjectContainerService::containerStockOf`, sous-containers compris, containers de même nom additionnés, #801). Reste effectif = `required − effective`, pourcentage et `isFulfilled` suivent la quantité effective.
2. **Pas de double compte** : les apports et les achats approuvés sont des unités qui, dans le cas courant (achat puis dépôt), sont déjà dans le container. Le maximum, jamais la somme, les compte une fois. Limite connue : des unités apportées et gardées hors du container, plus d'autres dans le container, ne se cumulent pas.
3. **Baisse du container** : la part issue du container suit le stock lu à chaque lecture (unités consommées ou déplacées). Le plancher est `fulfilledQuantity` : un apport approuvé ne disparaît jamais. Exemple : apport 10, container 12 → 12 ; le container tombe à 4 → 10.
4. **Container illisible** (`unknown`, `container_not_found`, `container_in_hidden_division`, `corporation_assets_stale`, pas de corporation, pas de directeur, assets corpo jamais synchronisés, aucune division visible) : la part container est **inconnue**, jamais 0 ni la dernière valeur connue. La quantité effective vaut alors le seul plancher `fulfilledQuantity`, présenté comme borne basse : la ressource porte `containerStockState = unknown` et `containerQuantity = null`.
5. **Sans nom de container** sur le projet : `containerStockState = none`, `containerQuantity = null`, quantité effective = `fulfilledQuantity` (comportement actuel, aucun changement).
6. **Container lisible** : `containerStockState = known`, `containerQuantity` = stock du type (0 si le type n'y est pas : container trouvé et vide pour ce type, ce n'est pas un inconnu).
7. **Sans validation** : rien n'est créé (ni apport, ni notification, ni évènement Mercure nouveau), aucune écriture. Les chemins d'écriture gardent la quantité persistée : un apport manuel reste plafonné par `requiredQuantity − fulfilledQuantity` (`ProjectContributionService::submit`), `AutomaticPurchaseLinker`, `LineReservationService` et la garde de reconstruction de `IndustryProjectService` ne changent pas.
8. **Lecture réservée aux membres acceptés** comme `GET /bom` aujourd'hui ; la lecture du stock du container utilise les assets corpo visibles du propriétaire du projet (`containerStockOf`), jamais ceux du lecteur : un membre sans division visible voit les mêmes chiffres que le propriétaire.
9. **Périmètre des lectures** (une seule source, `ProjectContainerFulfillment`, lue une fois par requête) :
   - `GET /industry/projects/{projectId}/bom` : `fulfilledQuantity`, `remainingQuantity`, `fulfillmentPercent`, `isFulfilled`, `freeQuantity` (reste effectif moins les réservations) des lignes matériaux, plus les champs `containerQuantity` et `containerStockState` ;
   - `GET /industry/projects/{projectId}/material-sourcing` : la quantité à sourcer d'une ligne est son reste effectif (`MaterialsToSourceFactory`) ;
   - cockpit du projet (`GET /industry/projects/{projectId}/cockpit`, `ProjectCockpitInputFactory`) : `CockpitBomLine::$fulfilledQuantity` des lignes matériaux est la quantité effective.
   Les lignes de job (`isJob = true`) gardent la quantité persistée dans les trois lectures ; le champ `containerStockState` y vaut `none`.
10. **Hors périmètre, gardés tels quels** (à noter en limite connue, une issue de suite si besoin) : `GET /industry/actions` (Atelier, lit tous les projets de la personne : un container par projet à chaque rafraîchissement), liste des projets (`ProjectResourceMapper`, pourcentage), chaîne (`ChainMaterialSupply`, urgence des jobs), `RemainingBomCost` et prévision de vente, répartition et seuil de rentabilité (ils lisent les apports et les lignes de job).
11. **Proposition de stock des intermédiaires (#708)** : inchangée, elle garde sa validation (lignes de job `component`, apport d'intermédiaire valorisé, plan recalculé). Question ouverte pour Sylvain, dans « Décisions » du journal : l'étendre à la même règle serait un changement du plan (stock de départ), pas une quantité fournie.
12. **Coût** : les unités déduites du container n'ont ni apport ni valeur ; elles n'entrent pas dans le coût de production (répartition, marge). Question ouverte dans « Décisions » : valoriser le stock du container à la clôture au prix Jita serait un nouveau mécanisme.

## Cas limites

| Cas | Attendu |
|---|---|
| Requis 55, apport 0, container 2 (cas de l'issue) | Fourni 2, Reste 53, 3,64 %, `containerStockState = known`, `containerQuantity = 2` |
| Requis 55, apport 10, container 2 | Fourni 10, Reste 45, `containerQuantity = 2` (le bandeau d'écart du front reste actif) |
| Requis 55, apport 10, container 70 | Fourni 55, Reste 0, `isFulfilled = true` (plafonné à `requiredQuantity`) |
| Container introuvable, apport 0 | Fourni 0 (borne basse), `containerStockState = unknown`, `containerQuantity = null` ; le front n'affiche pas « 0 » comme un fait |
| Container introuvable, apport 10 | Fourni 10 (borne basse), `unknown` |
| Pas de nom de container | Comportement actuel, `none` |
| Container lisible sans ce type | Fourni = apport, `known`, `containerQuantity = 0` |
| Ligne de job composant | quantité persistée, `none` |
| Deux lignes matériaux du même type | chacune plafonnée à son `requiredQuantity` avec le même stock du type (limite connue, le plan agrège les feuilles : cas rare) |

## Front

- `BomTable.vue` : Fourni, Reste, pourcentage lus sur la quantité effective ; avec `containerStockState = unknown`, la quantité fournie est préfixée `≥` et un repère « container illisible » (texte traduit, cause dans l'infobulle) remplace toute impression de zéro. Les puces `ContainerLineStatus` existantes restent.
- `ContainerBanner.vue` (`approvedGapLines`) : inchangé dans son principe (apport > container) ; il lit `fulfilledQuantity` de la ligne, qui vaut `max(apport, container)` : l'écart reste détecté quand l'apport dépasse le container.
- Clés i18n fr et en pour le repère. Identité HUD EVE (voir `ContainerLineStatus.vue`).

## Tests visés

- Unitaires `ProjectContainerFulfillment` : formule de la règle 1 sur les cas du tableau (apport 0, 10, plafond, baisse), états `known`, `unknown`, `none`, jamais 0 pour inconnu, ligne de job.
- Unitaires des trois lecteurs (`ProjectBomProvider`, `MaterialsToSourceFactory`, `ProjectCockpitInputFactory`) : reçoivent la quantité effective ; garde : write paths inchangés (`ProjectContributionService` plafonné sur le persisté).
- Fonctionnel `GET /bom` sur le cas de l'issue (2 sur 55 → Fourni 2, Reste 53) et container introuvable.
- Vitest `BomTable` : `≥` et repère à l'état `unknown`, valeurs effectives à `known`.

## Décisions ouvertes (journal, non bloquantes)

Étendre le stock container à la proposition #708 ; valorisation du stock container dans le coût ; Atelier, liste, chaîne et prévision de vente sur la quantité effective (perf : un container par projet).
