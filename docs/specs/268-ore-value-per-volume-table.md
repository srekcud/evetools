# Spec #268 — Minage : table de valeur au m³ des minerais

**Statut : validée en autonomie en mode nuit le 2026-11-03, à relire par Sylvain.**

Lot `group:mining-ops`, partie valeur du minerai. Circuit `circuit:m`, sans note de câblage.

## Contexte

Choisir quoi miner : le laser extrait des m³, pas des unités. La table compare chaque minerai, glace et gaz de la même façon : ISK par m³ **extrait** (volume de l'unité brute), selon que le minerai est vendu brut, compressé ou retraité. Les prix et les valeurs unitaires viennent de `OreValueService::getBatchOrePrices()` (règles de #259 et #260 : prix équivalent compressé lu dans le SDE, aucun prix = inconnu). Cette spec n'ajoute que la mise au m³, la sélection des lignes, le choix du marché et l'affichage.

## Règles métier

- **V1 — Lignes.** Un type du SDE est une ligne quand il est publié, qu'il a un groupe de marché, que son nom ne commence ni par `Compressed ` ni par `Batch Compressed `, et qu'il est de catégorie 25 (Asteroid) ou du groupe 711 (Harvestable Cloud). Sa famille (`kind`) : `ice` pour le groupe 465, `gas` pour le groupe 711, `ore` sinon. Les variantes +5 % et +10 % sont des lignes à part entière (leur nom les distingue).
- **V2 — Volume extrait.** Volume SDE de l'unité brute (`InvType::getVolume()`). Volume absent (`null`) ou ≤ 0 : les trois valeurs au m³ de la ligne sont inconnues (`null`), aucun volume par défaut.
- **V3 — Trois valeurs au m³.** Chacune est la valeur par unité brute divisée par le volume de l'unité brute :
  - brut : `rawUnitPrice` (Jita) ou `structureUnitPrice` (structure) ;
  - compressé : `compressedEquivalentPrice` ou `structureCompressedEquivalentPrice` (prix équivalent par unité brute, #259) ;
  - retraité : `reprocessValue` ou `structureReprocessValue` au rendement demandé.
  Une source `null` donne une valeur `null` (jamais 0). Une source à 0 donne 0, connue.
- **V4 — Marché.** Paramètre `market` : `jita` ou `structure`, et `side` : `buy` ou `sell` (défaut `sell`). `jita` + `sell` = comportement actuel de `OreValueService` (plus bas ordre de vente). `jita` + `buy` : meilleur ordre d'achat Jita. `structure` exige `structureId` (une structure dont le marché est synchronisé ; sinon toutes les valeurs sont `null`, sans erreur) et lit le plus bas ordre de vente ou le plus haut ordre d'achat de la structure selon `side`. `structure` sans `structureId` : 400. Valeur de `market` ou `side` inconnue : 400. Le minéral d'un retraitement est valorisé sur le même marché et le même côté que le minerai. Aucune taxe d'export dans la table.
- **V5 — Rendement.** Paramètre `reprocessYield` (0 à 1, optionnel). Absent : la colonne retraité est inconnue pour toutes les lignes (`reprocessYieldKnown: false`), aucun rendement par défaut. Valeur valide : de 0 exclu à 1 inclus ; 0, négative ou supérieure à 1 : 400 (`OreValueService` ne rend pas de valeur de retraitement nulle, un rendement 0 n'a pas de sens). Le front traite un rendement saisi à 0 comme absent. Le rendement saisi vient du front (`localStorage` aujourd'hui, enregistré côté serveur avec #269).
- **V6 — Retraitement non applicable.** Une ligne sans matériau de retraitement dans le SDE (le gaz) a `reprocessable: false` et un retraité `null` ; le front affiche « — » sans l'infobulle « valeur inconnue » (le retraitement n'existe pas, ce n'est pas une donnée manquante). Une ligne avec matériaux dont un minéral n'a pas de prix a un retraité `null` et `reprocessable: true` (donnée manquante).
- **V7 — Meilleure option.** `bestOption` : l'option (`raw`, `compressed`, `reprocessed`) de plus grande valeur au m³ parmi celles qui sont connues ; égalité : l'ordre `compressed`, `reprocessed`, `raw` ; aucune valeur connue : `null`. `bestIskPerM3` est la valeur de cette option (`null` si aucune).
- **V8 — Écart.** `gapToBestPercent` : `(bestIskPerM3 du tableau − bestIskPerM3 de la ligne) / bestIskPerM3 du tableau`, en pourcentage (0 pour la ligne qui porte le maximum). `null` si `bestIskPerM3` de la ligne est `null`, ou si le maximum du tableau est ≤ 0.
- **V9 — Minéraux contenus.** `minerals` : `{typeId, typeName, quantityPerUnit}` par matériau de retraitement du type brut, `quantityPerUnit = quantité du SDE / portionSize du brut` (rendement 100 %, rendement non appliqué). Nom manquant dans le SDE : `typeName: null`. Vide pour le gaz.
- **V10 — Tri de la réponse.** Par `bestIskPerM3` décroissant, lignes à `bestIskPerM3` `null` à la fin, égalité par nom croissant. Le front retrie localement par colonne ; une valeur `null` est toujours placée après les valeurs connues, quel que soit le sens du tri.
- **V11 — API.** Ressource DTO `OreValueTableResource` (aucune migration), `GET /api/mining/ore-values`, `ROLE_USER`. Réponse : `{id, market, side, structureId, reprocessYieldKnown, rows: [...]}` ; une ligne : `typeId, typeName, kind, groupName, volume, rawIskPerM3, compressedIskPerM3, reprocessedIskPerM3, bestOption, bestIskPerM3, gapToBestPercent, reprocessable, minerals`.
- **V12 — Pas de sync ESI ni de Mercure** : lecture des marchés déjà en cache.
- **V13 — Front.** Un sous-onglet « Valeur au m³ » dans l'onglet Minage du Ledger. Sélecteur de marché (Jita achat, Jita vente, structure suivie réutilisant le choix de `MiningTab`), rendement lu dans `localStorage` (`miningReprocessYield`, en %, converti en 0-1 ; clé absente = rendement inconnu), filtre par famille (minerai, glace, gaz), tri par colonne. Valeur `null` : « — » avec `common.unknownValue` (sauf V6). La meilleure option de chaque ligne est mise en évidence (texte et pas la couleur seule). Identité HUD EVE, noms longs testés.

## Exemples chiffrés

Harnais : `BuildsOreValueServiceOnSdeOres`. Veldspar (volume 0,1, portion 100, Tritanium 400 par portion), prix Jita vente Veldspar 18,37, Compressed Veldspar 19,61, Tritanium 4,27 ; Clear Icicle (volume 1000, portion 1), prix 152 300, Compressed Clear Icicle 158 700 ; Fullerite-C50 (volume 1, gaz) prix 2 741, Compressed Fullerite-C50 2 813. Rendement 0,78.

| # | Cas | Attendu |
|---|---|---|
| T1 | Veldspar, Jita vente, rendement 0,78 | `rawIskPerM3` 183,7 ; `compressedIskPerM3` 196,1 (19,61 × 100 / 100 / 0,1) ; `reprocessedIskPerM3` 13,3224 / 0,1 = 133,224 ; `bestOption` `compressed` ; `bestIskPerM3` 196,1 |
| T2 | Clear Icicle, Jita vente | `rawIskPerM3` 152,3 ; `compressedIskPerM3` 158,7 (équivalent 158 700 / 1000) |
| T3 | Fullerite-C50, Jita vente, Compressed Fullerite-C50 à 2 813, rendement 0,78 | `rawIskPerM3` 2 741 ; `compressedIskPerM3` 2 813 (portions 1 et 1, volume 1) ; `reprocessedIskPerM3` `null` ; `reprocessable` false ; `bestOption` `compressed` |
| T3b | Fullerite-C50, Jita vente, sans prix pour Compressed Fullerite-C50 | `rawIskPerM3` 2 741 ; `compressedIskPerM3` `null` ; `bestOption` `raw` |
| T4 | Veldspar, rendement absent | `reprocessedIskPerM3` `null`, `reprocessYieldKnown` false ; `bestOption` `compressed` |
| T5 | Veldspar, Jita achat, Veldspar 17,50, Compressed Veldspar 18,00, Tritanium 4,00 | `rawIskPerM3` 175 ; `compressedIskPerM3` 180 ; retraité (400 × 0,78 × 4 / 100) / 0,1 = 124,8 |
| T6 | Structure 1000000000001, vente, Compressed Veldspar 1 100, pas de prix brut | `rawIskPerM3` `null` ; `compressedIskPerM3` 11 000 ; `bestOption` `compressed` |
| T7 | Veldspar sans aucun prix | trois valeurs `null`, `bestOption` `null`, `bestIskPerM3` `null`, `gapToBestPercent` `null` |
| T8 | Veldspar sans `volume` dans le SDE | trois valeurs `null` (V2) |
| T9 | Écart : T1 (196,1) et T2 (158,7) | `gapToBestPercent` T1 0, T2 19,07 ((196,1 − 158,7) / 196,1, à 0,01 près) |
| T10 | Tri de la réponse : T3 (2 813), T1, T2, T7 | T3, T1, T2, T7 (`null` en dernier) |
| T11 | Lignes : « Compressed Veldspar » et « Batch Compressed Veldspar » dans le SDE | absentes de `rows` (V1) ; type non publié, sans groupe de marché ou hors catégorie 25 / groupe 711 : absent |
| T12 | `market=structure` sans `structureId` ; `market=foo` ; `side=foo` ; `reprocessYield=1,5` ; `reprocessYield=0` | 400 chacun |
| T13 | `minerals` du Veldspar | `[{typeId: Tritanium, typeName: 'Tritanium', quantityPerUnit: 4}]` (400 / 100) |

## Cas limites

- Aucun prix Jita en cache (marché jamais synchronisé) : toutes les lignes en valeurs `null`, pas d'erreur.
- Structure sans marché synchronisé : idem.
- Prix à 0 (taxe d'export absente ici, donc rare) : 0 connu.
- Rendement 0 : refusé (400, V5).

## Hors périmètre

- Filtre par sécurité ou région (l'emplacement des minerais n'est pas dans les tables SDE importées).
- Rendement calculé depuis les compétences et le lieu : #269.
- Taxe d'export, taxe de retraitement, volume de cale (la valeur au m³ du compressé est ramenée au m³ extrait, pas au m³ transporté).

## Termes proposés au glossaire (non ajoutés, la PR #326 modifie `docs/glossary.md`)

| Terme | Définition |
|---|---|
| **Valeur au m³ extrait** (*value per mined m³*) | Valeur par unité brute d'un minerai (brut, compressé ou retraité) divisée par le volume de l'unité brute. Inconnue (`null`) si une source de prix manque. |
| **Meilleure option** (*best option*) | Parmi brut, compressé, retraité, l'option de plus grande valeur au m³ extrait connue pour un minerai. |
