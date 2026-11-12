# #744 : suivi des planètes usines (stock, produits fabricables, temps restant)

Mode nuit : spec validée en autonomie. S'appuie sur la notion de stock de colonie de #743 (`contents` des pins).

## Contexte vérifié (origin/main + #742/#743)

- La carte de colonie (`PiColonyCard.vue`) n'affiche que des timers d'extracteur ; une colonie sans extracteur affiche « aucun extracteur ».
- `ColonyResource` (collection et détail) est produite par `PlanetaryResourceMapper::toResource()`, qui parcourt déjà les pins (contenus chargés avec la colonie).
- Aucune donnée ne permet de compter les produits déjà sortis depuis le dernier approvisionnement (pas d'historique de contenu ni de compteur de cycles) : le « fait » n'est pas calculable.
- Recettes : `PlanetSchematicTypeRepository::getSchematicMap()` ; cycle : `PlanetSchematicRepository::findBySchematicId()`.

## Règles

1. Une **planète usine** est une colonie sans aucun extracteur (actif ou non) et avec au moins une usine dont le schéma, la sortie et le cycle sont connus (une usine au schéma inconnu est ignorée, comme dans le calcul de production).
2. **Produit final** : parmi les sorties des usines de la colonie que aucune autre usine de la colonie ne consomme, celle de plus grande profondeur de chaîne (profondeur = 1 + celle de ses entrées fabriquées par la colonie). Égalité : le plus d'usines, puis le plus petit `typeId`.
3. **Stock** : somme des `amount` des contenus de tous les pins de la colonie (même définition que #743, `PlanetaryColony::getStockByType()`).
4. **Cycles fabricables** : plus grand N tel que N cycles du schéma final soient réalisables avec le stock. Chaque entrée est prise d'abord dans le stock ; le manque d'un intermédiaire fabriqué par la colonie est comblé par `ceil(manque ÷ sortie du schéma)` cycles de son schéma, dont les entrées se prennent à leur tour au stock, récursivement. Un type non fabriqué par la colonie ne se complète pas. Le stock du produit final n'est jamais consommé.
5. **Produits fabricables** = N × sortie du schéma final. **Produit final en stock** = stock du produit final (le seul « fait » connu, voir Décisions).
6. **Temps restant** = `ceil(N ÷ nombre d'usines du produit final) × temps de cycle du schéma final`, en secondes. Stock épuisé (N = 0) : 0 s.
7. `ColonyResource::$factoryProgress` : `null` hors planète usine, sinon `finalProductTypeId`, `finalProductName` (nom SDE ou null), `factoryCount`, `stockedFinalProduct`, `maxProducts`, `remainingSeconds`, `endsAt` (ISO 8601, `cachedAt` de la colonie + temps restant, calculé par le mapper : le stock lu date de la dernière synchro).
8. Carte de colonie : pour une planète usine, `endsAt` remplace le timer d'extracteur (même rendu de timer), accompagné du produit final, du stock de produit final et des produits encore fabricables. Sans `factoryProgress`, rendu inchangé.

## Exemples chiffrés (schémas de test : Coolant = 40 Electrolytes + 40 Water → 5, cycle 3600 s ; Water = 3000 Aqueous Liquids → 20, cycle 1800 s)

| Colonie | N | Produits fabricables | Temps restant |
|---|---|---|---|
| 1 usine Coolant, 400 Electrolytes, 1000 Water | 10 | 50 | 36 000 s |
| idem avec 2 usines Coolant | 10 | 50 | 18 000 s |
| idem, 400 Electrolytes, 240 Water (Water limite) | 6 | 30 | 21 600 s |
| usine Water + usine Coolant, 12 000 Aqueous, 400 Electrolytes, 0 Water | 2 | 10 | 7 200 s |
| même colonie, stock 5 Coolant déjà fabriqués | 2 | 10 | `stockedFinalProduct` = 5 |
| usine Coolant, aucun stock | 0 | 0 | 0 s |

## Cas limites

- Usine Reactive Metals non consommée à côté de Water → Coolant : le produit final reste Coolant (profondeur 2 contre 1).
- Stock d'une entrée dans le contenu d'une usine : compté comme celui d'un stockage.
- Colonie avec extracteur, avec ou sans usine : `factoryProgress` null.

## Tests existants modifiés

- `PlanetaryResourceMapperTest` : le constructeur du mapper gagne le suivi des planètes usines (stub), aucune assertion modifiée.

## Hors périmètre

- Compteur de produits déjà sortis, historique de contenu (question ouverte).
- Branchement aux alertes d'expiration (`PlanetarySyncService::checkExpiringExtractors()`), qui demande une nouvelle catégorie de notification.
- Simulateur PI, planification de nouvelles colonies.

## Glossaire

Propositions ajoutées : **Planète usine**, **Produit final de colonie**, **Cycles fabricables**.
