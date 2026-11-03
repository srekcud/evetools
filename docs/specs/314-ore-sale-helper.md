# Spec #314 — Minage : aide à la vente du minerai

**Statut : validée en autonomie en mode nuit le 2026-11-03, à relire par Sylvain.**

Lot `group:mining-ops`, partie valeur du minerai. Circuit `circuit:s` avec logique métier : rouge puis vert, sans migration.

## Contexte

Après une session de minage, le joueur veut vendre son minerai compressé : combien il en détient, ce que ça vaut, et un résumé à coller pour monter un contrat. L'issue place « Vendre mon minerai » après l'opération et le décrit sur le minerai compressé détenu par les personnages de l'utilisateur. L'opération groupée (#263/#264 : quantités minées par participant, valorisation) n'est pas encore dans `main` : cette première version porte sur **tout le minerai compressé détenu par les personnages de l'utilisateur** (actifs personnels synchronisés). Le rattachement à une opération (quantités minées de l'opération seulement) viendra avec #263/#264, sans changer le contrat de la réponse.

## Règles métier

- **S1 — Types retenus.** Un type d'actif personnel (`cached_assets`, `is_corporation_asset = false`, quantités agrégées par type sur tous les personnages de l'utilisateur, `CachedAssetRepository::getAggregatedQuantitiesByUser`) est retenu s'il est publié, que son nom commence par `Compressed ` (jamais `Batch Compressed `) et qu'il est de catégorie 25 (Asteroid : minerais et glaces compressés) ou du groupe 4168 (Compressed Gas). Les quantités nulles ou négatives sont écartées.
- **S2 — Prix.** Par type : prix Jita d'achat (`JitaMarketService::getBuyPricesWithFallback`) et de vente (`getPricesWithFallback`), et, si `structureId` est fourni, prix d'achat le plus haut et de vente le plus bas de la structure (`StructureMarketService::getHighestBuyPrices`, `getLowestSellPrices`). Un prix absent est `null`, jamais 0.
- **S3 — Meilleur prix par type.** `bestPrice` est le plus haut des prix connus parmi les quatre sources, `bestSource` la source qui le porte (`jita_buy`, `jita_sell`, `structure_buy`, `structure_sell` ; égalité : l'ordre de cette liste). Aucun prix connu : `bestPrice`, `bestSource` et `totalValue` du type valent `null`.
- **S4 — Ligne.** `typeId`, `typeName` (`null` si absent du SDE, l'identifiant reste), `quantity`, `unitVolume` (volume SDE, `null` si absent), `totalVolume` (`quantity × unitVolume`, `null` si le volume manque), les quatre prix (`jitaBuyPrice`, `jitaSellPrice`, `structureBuyPrice`, `structureSellPrice`), `bestPrice`, `bestSource`, `totalValue` (`quantity × bestPrice`). Lignes triées par `totalValue` décroissante, lignes inconnues à la fin, égalité par nom.
- **S5 — Totaux.** `totalQuantity` (somme), `totalVolume` (somme ; `null` dès qu'une ligne n'a pas de volume), `totalValue` (somme ; `null` dès qu'une ligne n'a pas de prix), `missingPriceTypeIds` (types sans prix, listés), `missingVolumeTypeIds`. Aucune ligne : totaux à 0, listes vides, `rows` vide.
- **S6 — API.** `GET /api/mining/ore-sale` (`ROLE_USER`), ressource DTO sans migration ; paramètre `structureId` optionnel (entier positif, sinon 400). Réponse : `structureId`, `rows`, `totals`. Les actifs d'un autre utilisateur ne sont jamais lus.
- **S7 — Résumé à copier (front).** Texte construit côté front depuis la réponse : une ligne par type, `typeName<TAB>quantity` (identifiant si le nom manque), suivie d'une ligne `Total volume: <m³>` et `Reference value: <ISK>` ; une valeur inconnue s'écrit `unknown` (jamais 0) suivie de la liste des types sans prix. Format du nombre sans séparateur de milliers, point décimal, deux décimales pour l'ISK et le m³. Bouton « Copier » (presse-papiers).
- **S8 — Front.** Panneau « Vendre mon minerai » dans le sous-onglet « Valeur au m³ » de l'onglet Minage (ou sous-onglet voisin) : tableau des lignes (quantité, volume, prix Jita achat/vente, prix structure si choisie, meilleur prix et sa source, valeur), totaux, texte du résumé et bouton copier. Valeur ou prix `null` : « — » avec `common.unknownValue`. La structure choisie dans `MiningTab` (`localStorage` `miningSelectedStructure`) est transmise quand elle existe. État vide : « aucun minerai compressé détenu » (distinct d'un prix inconnu). HUD EVE, jamais la couleur seule, noms longs.

## Exemples chiffrés

Types : Compressed Veldspar (62516, volume 0,001), Compressed Bitumens (62454, volume 0,1), Compressed Clear Icicle (28434, volume 100), Compressed Fullerite-C50 (62399, volume 0,1, groupe 4168). Tritanium (34) et Batch Compressed Veldspar (28432) sont aussi détenus.

| # | Cas | Attendu |
|---|---|---|
| P1 | Détenus : 12 000 Compressed Veldspar sur un personnage et 8 000 sur un autre | une ligne, `quantity` 20 000 |
| P2 | Détenus : Tritanium 5 000, Batch Compressed Veldspar 100, Compressed Veldspar 10 | seule la ligne Compressed Veldspar |
| P3 | Veldspar compressé : Jita achat 17,0, Jita vente 19,0, pas de structure | `bestPrice` 19,0, `bestSource` `jita_sell`, `totalValue` = `quantity` × 19,0 |
| P4 | Idem avec `structureId` : achat structure 21,0 | `bestPrice` 21,0, `bestSource` `structure_buy` |
| P5 | Égalité : Jita achat 20,0 et Jita vente 20,0 | `bestSource` `jita_buy` |
| P6 | Bitumens compressé sans aucun prix, Veldspar compressé 20 000 à 19,0 | ligne Bitumens `bestPrice` `null`, `totalValue` `null` ; totaux : `totalValue` `null`, `missingPriceTypeIds` [62454] |
| P7 | Compressed Clear Icicle 5 (volume 100), Compressed Veldspar 20 000 (volume 0,001) | volumes 500 et 20 ; `totals.totalVolume` 520 |
| P8 | Compressed Fullerite-C50 sans volume dans le SDE | `unitVolume` `null`, `totals.totalVolume` `null`, `missingVolumeTypeIds` [62399] |
| P9 | Tri : valeurs 380 000 (Veldspar), 900 000 (Icicle), inconnue (Bitumens) | Icicle, Veldspar, Bitumens |
| P10 | Aucun actif | `rows` [], totaux 0, `totalValue` 0.0 connu |
| P11 | Actifs corporation de l'utilisateur, actifs d'un autre utilisateur | jamais comptés |
| P12 | `structureId` = `abc` ou 0 | 400 |
| P13 | Front : résumé avec Veldspar 20 000 et Icicle 5, volume 520, valeur 1 280 000 | `Compressed Veldspar\t20000`, `Compressed Clear Icicle\t5`, `Total volume: 520.00`, `Reference value: 1280000.00` |
| P14 | Front : valeur inconnue (P6) | `Reference value: unknown` et la liste des types sans prix, jamais 0 |
| P15 | Front : réponse vide | état « aucun minerai compressé détenu », pas de bouton copier actif |

## Cas limites

- Quantité entière très grande : somme en entiers, pas de flottants pour les quantités.
- Prix Jita sur place absent mais récupérable par repli ESI : `getPricesWithFallback` (comportement existant, déjà utilisé par l'estimation).
- Type d'actif absent du SDE : ligne ignorée (non publié ou inconnu = non retenu), pas d'erreur.

## Hors périmètre

- Rattachement à une opération (quantités minées de l'opération, #263/#264) et « Après l'opération » dans le flux de clôture (#265).
- Création du contrat en jeu, prise en compte de la taxe de transport ou de vente.

## Termes proposés au glossaire (non ajoutés, la PR #326 modifie `docs/glossary.md`)

| Terme | Définition |
|---|---|
| **Aide à la vente** (*sale helper*) | Vue du minerai compressé détenu : quantité, volume, meilleur prix de référence par type et résumé à copier pour un contrat. Prix ou volume manquant = inconnu (`null`). |
