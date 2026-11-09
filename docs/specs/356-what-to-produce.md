# #356 — Quoi produire : produits rentables selon ma capacité

Maquette : `industry-project-v3.html#p=22` et `#p=31` (note 14, vue Atelier). Circuit `circuit:l`, spec écrite par le thread (mode nuit, 0 sous-agent).

## Prérequis vérifiés sur origin/main 190ccb15

| Besoin | Présent |
|---|---|
| Classement de rentabilité Jita / structure, prix de vente, volume journalier, motifs d'inconnu | `BatchProfitScannerService::scan` (ventes `jita` et `structure`) |
| Structure de marché préférée, frais du vendeur | `ProjectSellStructures::defaultFor`, `SellerSalesFeesResolver::forUser` |
| Part de volume vendable | `SellMarketComparison::SELL_SHARE_OF_DAILY_VOLUME` (0,1) |
| Compétences requises et niveaux du personnage | `IndustryActivitySkillRepository::findSkillsForBlueprints`, `CachedCharacterSkillRepository` |
| Slots libres | `SlotTrackerService::getSlotStatus` (`globalKpis`, `unknownSkillsCharacterCount`) |
| Durée d'un job | `JobDuration`, `SkillTimeModifier`, temps de base `IndustryActivityRepository` |

## Règles

1. **Capacité hebdomadaire** (`ProductionCapacityCalculator`, pur). Trois plafonds entiers (arrondi à l'inférieur) :
   - marché = `daily_volume × 0,1 × 7` ;
   - capital = `plafond ÷ coût de production unitaire`, absent sans plafond saisi ;
   - slots = `slots libres × 168 × 3600 ÷ secondes par unité`.
   La quantité par semaine est le plus petit ; `limitedBy` est `market`, `capital` ou `slots` (égalité : marché, puis capital).
2. **Profit par semaine** = quantité × profit net unitaire (frais du vendeur déduits, valeur du scanner). **ISK par heure de slot** = profit net unitaire × 3600 ÷ secondes par unité.
3. **Secondes par unité** = `JobDuration::forManufacturingJob` (ou réaction) d'un run du meilleur personnage apte, divisé par `outputPerRun`. Temps de base : SDE ; TE supposé comme l'ME du scanner (T1 : 20, T2 : `InventionService::BASE_INVENTION_TE`, réaction : sans TE) ; compétences du personnage, pas de bonus de structure.
4. **Compétences** : un produit dont aucun personnage n'a toutes les compétences du blueprint (SDE) est grisé : `missingSkills` liste, pour le personnage qui en manque le moins, `{skillId, name, level}`. Sa quantité, son profit par semaine et son ISK/h sont `null` (jamais 0).
5. **Groupe** : `groupHint = true` quand une compétence manque, ou quand le plafond de capital est inférieur au plafond de marché (le capital limite sous la demande). Mention seule, aucun calcul de groupe.
6. **Marchés** : `jita` (sell venue `jita`) et `capital` (sell venue `structure`, structure préférée de l'utilisateur). Sans structure préférée, le marché `capital` est absent et `capitalStructureName` est `null`. `market` = `all` (défaut), `jita` ou `capital`.
7. **Slots libres** par catégorie (`manufacturing` pour les produits fabriqués, `reaction` pour les réactions) = somme sur les personnages aux compétences connues de `max(0, max − utilisés)`. Aucun personnage aux compétences connues : slots inconnus, quantité, profit et ISK/h `null` pour tous (`freeSlots` `null`), jamais un défaut.
8. **Rentabilité** : un produit à profit unitaire connu et ≤ 0 est listé « à perte » (`loss = true`, quantité, profit et ISK/h `null`), après les produits rentables. Un produit au profit ou au volume inconnu (`unknownReason` du scanner non nul) est exclu du classement et compté dans `unknownProductCount`.
9. **Tri** : produits sans compétence manquante et rentables d'abord, puis par `sort` : `week` (profit par semaine décroissant, défaut) ou `hour` (ISK/h décroissant) ; ensuite produits grisés, puis à perte. Au plus 100 lignes renvoyées.
10. **Capital** : `capital` (query, nombre entier positif d'ISK) absent : aucun plafond de capital, `capitalCap` `null`, jamais de valeur par défaut. Valeur non numérique, nulle ou négative : 400.

## API

`GET /api/industry/opportunities?market=all|jita|capital&sort=week|hour&capital=<ISK>`, `ROLE_USER`, ressource `IndustryOpportunities` : `capitalCap`, `capitalStructureName`, `freeSlots` (`{manufacturing, reaction}` ou `null`), `sellShareOfDailyVolume`, `salesFeesKnown` (faux : frais du vendeur inconnus, tous les profits le sont), `unknownProductCount`, `rows[]` : `typeId`, `typeName`, `categoryLabel`, `market`, `sellPrice`, `dailyVolume`, `unitCost`, `profitPerUnit`, `marginPercent`, `weeklyQuantity`, `limitedBy`, `weeklyProfit`, `iskPerSlotHour`, `missingSkills`, `groupHint`, `loss`. Lecture seule : chaque utilisateur ne voit que ses personnages, sa structure et ses frais. Le scanner expose en plus `blueprintTypeId`, `outputPerRun`, `unitCost`, `teUsed` (champs ajoutés au tableau de `scan`, ressource du scanner inchangée).

## Front

Carte « Quoi produire » dans l'Atelier (`IndustryWorkshop.vue`), HUD EVE : segment Les deux / Jita / Capitale, segment Profit / sem. / ISK / h de slot, champ « Capital engagé au plus » (ISK, mémorisé dans `localStorage`, lecture protégée), rappel « slots libres × 168 h », tableau Produit · Marché · Prix et volume/j · Marge/u · Qté/semaine avec puce « limité par marché / capital / slots » · Profit/sem. avec ISK/h · mention « à faire en groupe » (violet). Ligne grisée « requis : <compétence> <niveau> », « à perte », valeurs inconnues avec leur cause (jamais 0), compteur des produits inconnus. Texte de pied : définition du profit par semaine et de la quantité. Clés i18n `en` et `fr`.

## Cas chiffrés (calculateur)

| Volume/j | Prix net − coût | Coût unité | Plafond | Slots libres | s/unité | Marché | Capital | Slots | Qté | Limite |
|---|---|---|---|---|---|---|---|---|---|---|
| 180 | 64 088,53 | 659 773,47 | 59 500 000 | 14 | 1 662 | 126 | 90 | 5 094 | 90 | capital |
| 14 | 148 257,78 | 659 773,47 | 59 500 000 | 14 | 1 662 | 9 | 90 | 5 094 | 9 | marché |
| 420 | 21 364,75 | 412 000 | absent | 1 | 831 | 294 | — | 727 | 294 | marché |
| 300 | 1 000 | 100 | 1 000 000 | 0 | 600 | 210 | 10 000 | 0 | 0 | slots |

ISK/h de la ligne 1 : 138 819,92. Le 2e produit a `groupHint = false` (marché 9 sous capital 90), le 1er `true` (capital 90 < marché 126).

## Cas limites

- Volume journalier 0 : plafond marché 0, quantité 0, limite marché.
- Coût unitaire inconnu ou ≤ 0, secondes par unité inconnues ou ≤ 0 : ligne exclue comme inconnue (motif du scanner ou `missing_duration`).
- Temps de base absent du SDE : `missing_duration`, exclu et compté.

## Hors périmètre

Calcul de groupe, source contrats (#334, reliquat), bonus de structure sur le temps, wallets affichés, bouton « Produire » (création de projet pré-remplie), alerte ou planification.

## Décisions prises en autonomie

1. Prévision au prix actuel, sans recalcul du carnet à la quantité.
2. Capital saisi côté navigateur et passé en requête, pas de réglage persisté côté serveur.
3. Les produits aux données inconnues sont comptés, pas listés.
