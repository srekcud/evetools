# Spec — Noyau de calcul Industrie (v1)

- **Statut** : validée, décisions D1 à D12 du 2026-10-07 (D11a en attente de vérification en jeu)
- **Date** : 2026-10-07
- **Décision d'architecture** : [ADR-0009](../adr/0009-noyau-calcul-industrie.md)
- **État du code de référence** : commit `fb9cc98`

Les termes en gras sont ceux du [glossaire](../glossary.md).

## 1. Contexte

Le calcul Industrie (BOM, quantités, coûts, durées) est réparti dans une dizaine de services qui recalculent chacun leur version des mêmes règles. Pour une même entrée, l'arbre affiché, les étapes d'un projet, la liste d'achats, Profit Margins et le scanner donnent des chiffres différents. Plusieurs écarts avec le jeu ont été mesurés contre des sources de référence (implémentations publiques recoupées, attributs dogma du SDE, un plan publié par un planificateur tiers) :

- les runs d'un projet sont lus comme une quantité : 1 run au lieu de 25 pour un fuel block ([#2](https://github.com/srekcud/evetools/issues/2)) ;
- la demande d'un intermédiaire n'est pas agrégée entre branches : sur un vaisseau capital, 29 matériaux à acheter sur 63 sont surestimés, jusqu'à +50 % ([#69](https://github.com/srekcud/evetools/issues/69)) ;
- le coût d'installation est sous-estimé d'environ 70 % sur un cas courant, et vaut 0 quand une donnée manque ([#7](https://github.com/srekcud/evetools/issues/7)) ;
- un prix absent rend un matériau gratuit ([#8](https://github.com/srekcud/evetools/issues/8)) ;
- trois règles de choix de structure et deux tables de bonus coexistent ([#6](https://github.com/srekcud/evetools/issues/6), [#71](https://github.com/srekcud/evetools/issues/71), [#72](https://github.com/srekcud/evetools/issues/72)).

Le détail mesuré, avec les lignes de code, est dans l'ADR-0009. Cette spec décrit les règles que le nouveau noyau doit respecter. Ses exemples chiffrés deviennent les tests rouges (ADR-0004).

## 2. Périmètre

### v1 (D9)

| Activité | Inclus |
|---|---|
| Fabrication | BOM multi-niveaux, quantités, coût d'installation, durée |
| Réaction | idem, sans ME |
| Copie | coût d'installation |
| Invention T2 | probabilité, decryptors, espérance de coût, coût d'installation |

Entrée du planificateur : une liste de cibles (produit, runs, ME, TE), un stock de départ, une politique de construction (blacklist, achats forcés) et l'affectation des structures.

### Hors périmètre v1

- T3 et reliques, recherche ME/TE (D9).
- Implants de temps (D7) : issue séparée si le besoin existe.
- Stock cible en fin de plan : il reste le **Stockpile** existant, en Application (D8).
- Découpage par durée maximale ou par remplissage des slots : il reste en Application. Le noyau fournit seulement la règle matériaux par job (D11b).
- Suivi des jobs ESI, achats wallet, liens d'étapes, Group Industry : consommateurs du noyau, hors noyau.
- Taxes de vente et courtage ([#18](https://github.com/srekcud/evetools/issues/18)), définition de la marge ([#19](https://github.com/srekcud/evetools/issues/19)) : hors noyau BOM.
- Les idées fonctionnelles du benchmark (stock tiré des assets, modes de découpage par slots, cash-flows) ne font pas partie de la v1.

### Vocabulaire local

Termes utilisés ici et absents du glossaire, proposés à l'ajout :

- **Job** : un lancement d'activité sur un blueprint, pour un nombre de runs, dans une structure.
- **Intermédiaire** : item fabriqué ou réagi dans le plan pour être consommé par un autre job.
- **Feuille** : matériau acheté (non fabriqué, blacklisté ou acheté de force).
- **Plan** : ensemble des jobs, feuilles et surplus calculés pour une liste de cibles.

## 3. Règles métier

### R1 — Matériaux d'un job

```
qty(job, matériau) = max(runs, ceil(round(runs × base × (1 − ME/100) × mod_matériaux, 2)))
```

- `base` : quantité par run dans le SDE ; `runs` : runs **du job**.
- L'arrondi se fait **par job**, jamais par run, avec un minimum d'une unité par run.
- `round(…, 2)` avant `ceil` absorbe le bruit flottant : `x.0000001` ne donne pas `x + 1`.
- `mod_matériaux` = produit du bonus de structure et du bonus de rig (voir R4). Les bonus se multiplient, ils ne s'additionnent pas.
- Une seule implémentation dans tout le code ([#17](https://github.com/srekcud/evetools/issues/17)). Le scanner et le pivot l'appellent avec 1 run au lieu de leur variante sans structure ni `round`.

### R2 — Réactions

- Pas de ME : `ME = 0` pour un job de réaction, quel que soit le ME du consommateur.
- Pas de bonus de rôle matériaux de structure : `mod_structure = 1`.
- Rig de réaction modulé par la sécurité de réaction : ×1,0 en basse sécurité, ×1,1 en nulle / WH. Le multiplicateur de fabrication (×1,9 / ×2,1) ne s'applique jamais à un rig de réaction ([#72](https://github.com/srekcud/evetools/issues/72)).
- Le ME du job **consommateur** d'un produit de réaction est celui de ce job, pas un ME 10 en dur ([#15](https://github.com/srekcud/evetools/issues/15)).

### R3 — Demande agrégée et runs entiers (mode plan, D2)

```
pour chaque intermédiaire, dans l'ordre topologique (tous ses consommateurs avant lui) :
  demande = Σ qty(job consommateur, intermédiaire)          # R1 sur chaque job consommateur
  runs    = ceil(max(0, demande − stock disponible) / unités par run)
  surplus = runs × unités par run − (demande − stock consommé)
```

- La **Demande** est agrégée sur tout le plan avant l'arrondi en runs entiers ([#69](https://github.com/srekcud/evetools/issues/69)). Un intermédiaire consommé à plusieurs profondeurs additionne ses besoins ([#16](https://github.com/srekcud/evetools/issues/16)).
- Les runs sont des `Runs`, les unités des `Quantity` : on ne passe jamais l'un pour l'autre ([#2](https://github.com/srekcud/evetools/issues/2)).
- Le **Surplus** est explicite dans le résultat, pas perdu.
- Défauts des intermédiaires : ME 10 / TE 20 en fabrication, ME 0 en réaction (défaut actuel conservé : `IndustryTreeService.php:163`, `IndustryProjectFactory.php:218-219`). Ils restent surchargeables par job.

**Plafond de runs d'un BPC (D2).** Un job agrégé ne peut pas dépasser les runs du BPC utilisé. Au-delà, il est découpé en plusieurs jobs, un par BPC, chacun plafonné. Le plafond est une donnée d'entrée par produit, fournie par l'Application (runs restants du BPC possédé, `maxProductionLimit` du SDE pour une copie, runs du BPC inventé). Il est absent pour un BPO.

### R4 — Bonus de structure et de rig (D3, D6, D10)

```
mod_matériaux = mod_structure × mod_rig
mod_rig       = 1 − (bonus_rig % × mod_sécurité) / 100
```

- **Le bonus dépend du produit du job**, jamais de la catégorie du matériau consommé ([#6](https://github.com/srekcud/evetools/issues/6)). Exemple : les composants consommés par un job de vaisseau capital reçoivent le rig « vaisseaux capitaux », pas le rig « composants capitaux ».
- Valeurs numériques lues dans les attributs dogma du SDE (`strEngMatBonus`, `strEngCostBonus`, `strEngTimeBonus`, `strReactionTimeMultiplier`, `attributeEngRig*Bonus`, `RefRig*Bonus`, `attributeThukkerEngRigMatBonus`, `hiSecModifier` / `lowSecModifier` / `nullSecModifier`). Le ciblage rig → groupes reste la table `IndustryRigCategory` (D10). Plus aucune table de bonus codée en dur.
- Une seule règle sert au calcul et à l'affichage des structures.
- Sécurité : `HighSec`, `LowSec`, `NullSec`. L'espace wormhole utilise `NullSec`, affichée « Nullsec / WH » (D6).
- Choix de la structure d'un job (D3, en Application) : la structure assignée à l'étape ; sinon, la meilleure structure de l'utilisateur pour (activité, produit du job). Le système favori sert de filtre. « Meilleure » garde le critère actuel : la plus forte réduction de matériaux (`IndustryBonusService.php:252`).

Valeurs de référence (SDE au 2026-10-07, utilisées par les goldens) :

| Élément | Matériaux | Temps | Coût |
|---|---|---|---|
| Raitaru / Azbel / Sotiyo (fabrication) | ×0,99 | ×0,85 / ×0,80 / ×0,70 | ×0,97 / ×0,96 / ×0,95 |
| Tatara (réaction) | aucun | ×0,75 | aucun |
| Athanor (réaction) | aucun | **aucun** ([#71](https://github.com/srekcud/evetools/issues/71)) | aucun |
| Rig fabrication T1 / T2 | 2,0 % / 2,4 % | 20 % / 24 % | 0 (rigs de coût retirés) |
| Rig réaction T1 / T2 | 2,0 % / 2,4 % | 20 % / 24 % | — |

| Sécurité | Rig fabrication | Rig réaction | Rig Thukker |
|---|---|---|---|
| Haute | ×1,0 | interdit | ×0,1 |
| Basse | ×1,9 | ×1,0 | ×1,9 |
| Nulle / WH | ×2,1 | ×1,1 | ×0,1 |

Les rigs Thukker valent 2,0 %, et 3,7 % pour les composants capitaux de base ([#71](https://github.com/srekcud/evetools/issues/71)).

### R5 — EIV

```
EIV(activité, runs) = runs × Σ (quantité_base × adjusted price)
```

- Quantités **avant ME et avant tout bonus** (ME 0 du SDE).
- Fabrication et réaction : matériaux de l'activité du job.
- Copie et invention : matériaux de **fabrication** du blueprint concerné (le blueprint copié, ou le blueprint T2 inventé). Jamais l'adjusted price du produit ([#7](https://github.com/srekcud/evetools/issues/7)).
- Un adjusted price absent rend l'EIV **inconnue** (R10), avec la liste des typeId concernés.

### R6 — Coût d'installation (D11a)

```
base = EIV                       (fabrication, réaction)
base = 2 % × EIV                 (copie, invention : Base de coût de job)

coût_système = base × cost index × mod_coût_structure
taxe         = base × taux de taxe d'installation
SCC          = base × taux SCC
alpha        = base × 0,25 %      (clone alpha seulement)
total        = coût_système + taxe + SCC + alpha
```

- Le bonus de rôle de coût ne s'applique **qu'au** terme `coût_système`. La taxe, le SCC et la taxe alpha portent sur la base.
- La taxe s'**ajoute** : elle ne multiplie pas le cost index.
- Taux SCC : 4 % en fabrication, réaction et invention. Copie : 4 %, **en attente de vérification en jeu** (D11a) ; la baisse à 2 % de juillet 2025 ne vise que la recherche ME/TE, hors périmètre.
- Taxe d'installation en station NPC : 0,25 %.
- Un cost index absent rend le coût **inconnu** (R10), jamais 0.
- Le résultat expose le détail : `coût_système`, `taxe`, `SCC`, `alpha`, `total`.
- Copie pour l'invention : une copie à 1 run par tentative, comme aujourd'hui (`InventionService.php:291`).

### R7 — Durée d'un job (D7)

```
durée = round(runs × temps_base × (1 − TE/100) × mod_compétences × mod_temps_structure × mod_temps_rig)
fabrication : mod_compétences = (1 − 4 % × Industry) × (1 − 3 % × Advanced Industry) × Π (1 − 1 % × compétence science requise)
réaction    : mod_compétences = (1 − 4 % × Reactions)
```

- **Un seul `round`, sur le job entier.** Plus de `ceil` par run multiplié par les runs.
- Sans implants (D7).

### R8 — Invention T2

```
P               = min(1, base × (1 + (science1 + science2) / 30 + encryption / 40) × mod_decryptor)
BPC T2          : runs = runs_base + Δruns ; ME = 2 + ΔME ; TE = 4 + ΔTE
coût par succès = coût d'une tentative / P             (espérance, sans arrondi)
tentatives      = runs T2 voulus / (P × runs par BPC)
```

- Les modificateurs des decryptors viennent du SDE (table corrigée par [#70](https://github.com/srekcud/evetools/issues/70), `InventionService.php:33-42`).
- Le ME du decryptor s'applique aux matériaux de fabrication du T2 ([#18](https://github.com/srekcud/evetools/issues/18)).
- Une probabilité absente du SDE rend le résultat **inconnu**, jamais 0 ([#74](https://github.com/srekcud/evetools/issues/74)).

### R9 — Stock de départ (D8)

- Le stock d'un item est consommé **avant** le calcul de ses runs (R3).
- Un intermédiaire couvert en partie par le stock réduit d'autant son propre sous-arbre ([#20](https://github.com/srekcud/evetools/issues/20)). Couvert en totalité : aucun job, aucun matériau en dessous.
- Le stock est consommé dans l'ordre topologique, une seule fois au total.
- Pas de stock cible dans le noyau.

### R10 — Prix absent : résultat partiel « inconnu » (D5)

- Un prix de marché, un adjusted price, un cost index ou une probabilité absent ne vaut jamais 0.
- Tout montant est un **Coût inconnu** ou un montant connu. Une somme qui contient un inconnu est inconnue.
- Le résultat garde le détail calculable (quantités, montants connus) et liste les typeId sans prix, avec la raison.
- Les quantités ne dépendent d'aucun prix : elles restent toujours calculées.
- Côté scanner, exclure l'item et le signaler reste une présentation possible ([#22](https://github.com/srekcud/evetools/issues/22)).

### R11 — ISK (D1)

- Value object `Isk` sur un `float`, montant ≥ 0.
- Arrondi à 2 décimales à l'affichage seulement.
- Comparaisons et tests avec une tolérance de 1 ISK.

### R12 — Modes de calcul (D4)

| Mode | Runs | Surplus | Consommateurs |
|---|---|---|---|
| **Plan** | entiers par lot (R3), plafond BPC | explicite | projets, liste d'achats, stockpile, Group Industry |
| **Coût marginal** | fractionnaires : `demande / unités par run` | aucun | Profit Margins, scanner, pivot |

En mode coût marginal, un intermédiaire n'est payé qu'au prorata de la demande. Exemple Hail L (§4.2) : le R.A.M. demandé à 10 unités sur 100 par run compte pour 0,1 run, sans surplus de 90.

### R13 — Découpage d'un job (D11b)

Quand un job est découpé (plafond BPC en R3, durée maximale ou découpage manuel en Application), les matériaux sont **recalculés job par job** avec R1 ([#73](https://github.com/srekcud/evetools/issues/73)). Le total d'un plan découpé peut dépasser celui d'un job unique.

## 4. Exemples chiffrés (tests rouges)

Les goldens fixent la formule, pas la sélection de structure : les tests passent les modificateurs **explicitement**. Fichiers d'origine : `.claude/tasks/industry-core/golden/` (non versionnés, à déplacer dans `tests/` lors de la phase rouge).

| Cas | Statut (D12) |
|---|---|
| Nitrogen Fuel Block | **fait foi** |
| Nomad (plan publié par un planificateur tiers, recalculé à l'unité) | **fait foi** |
| Coût d'installation : vecteurs | fait foi, sauf la copie (D11a) |
| Hail L | **provisoire** : à recouper avec un plan tiers avec structures ou l'API EVE Ref |
| Fernite Carbide | **provisoire** : idem |

### 4.1 Nitrogen Fuel Block — 25 runs, ME 10, station NPC (fait foi)

Couvre R1 (minimum d'une unité par run) et B1 ([#2](https://github.com/srekcud/evetools/issues/2)). Aucun intermédiaire.

| Attendu | Valeur | Aujourd'hui |
|---|---|---|
| Runs | 25 | 1 |
| Unités par run / produites | 40 / 1 000 | — |
| Nitrogen Isotopes | 10 125 | 405 |
| Liquid Ozone | 7 875 | |
| Heavy Water | 3 825 | |
| Oxygen | 495 | |
| Strontium Clathrates | 450 | |
| Coolant | 203 | |
| Enriched Uranium | 90 | |
| Mechanical Parts | 90 | |
| Robotics | **25** (`25 × 1 × 0,9 = 22,5` → 23, mais `max(runs, …)` = 25) | 1 |

### 4.2 Hail L — 10 runs, ME 2, Raitaru nullsec (provisoire)

Racine : `mod_matériaux = 0,99 × (1 − 2,4 × 2,1 / 100) = 0,99 × 0,9496`. Intermédiaire R.A.M. : ME 10, Raitaru sans rig (×0,99). Fernite Carbide et Fullerides achetés de force.

| Job racine | Valeur |
|---|---|
| Runs / unités par run / produites | 10 / 5 000 / 50 000 |
| Fernite Carbide | 27 640 |
| Fullerides | 11 056 |
| Morphite | 139 |
| R.A.M.- Ammunition Tech | 10 |

| Intermédiaire | Runs | Produites | Demande | Surplus |
|---|---|---|---|---|
| R.A.M.- Ammunition Tech | 1 | 100 | 10 | 90 |

Feuilles : Fernite Carbide 27 640, Fullerides 11 056, Tritanium 496, Pyerite 396, Mexallon 198, Morphite 139, Isogen 74, Nocxium 33. Aujourd'hui : 1 run racine au lieu de 10.

### 4.3 Fernite Carbide — 10 runs, Tatara nullsec (provisoire)

Couvre R2 et R3. Réactions : ME 0, pas de bonus de rôle, rig T2 ×1,1 → `1 − 2,4 × 1,1 / 100 = 0,9736`. Fuel blocks : ME 10, Raitaru sans rig (×0,99).

| Job | Activité | Runs | Unités / run | Produites | Demande | Surplus |
|---|---|---|---|---|---|---|
| Fernite Carbide (racine) | réaction | 10 | 10 000 | 100 000 | — | — |
| Ceramic Powder | réaction | 5 | 200 | 1 000 | 974 | 26 |
| Fernite Alloy | réaction | 5 | 200 | 1 000 | 974 | 26 |
| Hydrogen Fuel Block | fabrication | 3 | 40 | 120 | 99 | 21 |

Hydrogen Fuel Block est consommé à deux profondeurs : 49 (racine) + 25 (Ceramic Powder) + 25 (Fernite Alloy) = 99, d'où `ceil(99 / 40) = 3` runs. Un calcul par branche donnerait `2 + 1 + 1 = 4` runs.

Feuilles : Hydrogen Isotopes 1 203, Liquid Ozone 936, Evaporite Deposits 487, Scandium 487, Silicates 487, Vanadium 487, Heavy Water 455, Oxygen 59, Strontium Clathrates 54, Coolant 25, Enriched Uranium 11, Mechanical Parts 11, Robotics 3.

### 4.4 Nomad — 1 run, ME 10, sans structure (fait foi)

Couvre R3 sur une BOM complète : 65 jobs intermédiaires (réactions à deux niveaux, composants, fuel blocks) et 63 feuilles, toutes identiques au plan de référence. Extraits :

| Ligne | Attendu | Aujourd'hui (simulation) |
|---|---|---|
| Carbon Fiber (réaction) | 120 runs | 125 runs |
| Thermosetting Polymer (réaction) | 120 runs | 125 runs |
| Capital Jump Drive | 27 runs | |
| Capital Cargo Bay | 32 runs | |
| Oxygen Fuel Block | 44 runs, 1 760 produites, demande 1 735 | |
| Nitrogen Fuel Block | 106 runs, 4 240 produites, demande 4 225 | |
| Oxygen Isotopes (feuille) | 17 820 | +50 % |
| Hydrocarbons (feuille) | 75 200 | +19 % |
| Atmospheric Gases (feuille) | 60 500 | +24 % |
| Coolant (feuille) | 4 692 | +12 % |

- Ne pas asserter les surplus à l'unité : le plan de référence affiche 14 en surplus de Nitrogen Fuel Block, le recalcul 15 (runs et feuilles identiques).
- Pas de découpage dans ce test. Le plan de référence découpe deux jobs (14 + 13, 16 + 16) sans changer les totaux.
- Coûts et durées du plan de référence non assertables : indices et adjusted prices du jour inconnus.

### 4.5 Coût d'installation (R6)

Taux en fraction. Tolérance 1 ISK.

| Vecteur | Base | Cost index | Mod. coût | Taxe | Alpha | Système | Taxe | SCC | Alpha | **Total** | Aujourd'hui |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Fabrication, sans structure | EIV 1 000 000 | 0,05 | 1,00 | 0,10 | non | 50 000 | 100 000 | 40 000 | 0 | **190 000** | 55 000 |
| Fabrication, Raitaru | EIV 1 000 000 | 0,05 | 0,97 | 0,10 | non | 48 500 | 100 000 | 40 000 | 0 | **188 500** | 55 000 |
| Fabrication, Sotiyo, alpha | EIV 1 000 000 | 0,05 | 0,95 | 0 | oui | 47 500 | 0 | 40 000 | 2 500 | **90 000** | 50 000 |
| Réaction, Tatara | EIV 2 000 000 | 0,03 | 1,00 | 0,05 | non | 60 000 | 100 000 | 80 000 | 0 | **240 000** | 63 000 |
| Invention, 1 tentative | 2 % × 10 000 000 = 200 000 | 0,08 | 1,00 | 0,05 | non | 16 000 | 10 000 | 8 000 | 0 | **34 000** | 16 800 |
| Copie, 10 runs (D11a en attente) | 2 % × 5 000 000 × 10 = 1 000 000 | 0,02 | 1,00 | 0 | non | 20 000 | 0 | 40 000 | 0 | **60 000** | 20 000 |
| Cost index absent | EIV 1 000 000 | absent | — | 0,10 | non | | | | | **inconnu** | 0 |
| Adjusted price absent | 1 000 × 4,0 + 500 × absent | — | — | — | — | | | | | EIV **inconnue** (typeId 35 listé) | EIV 4 000 |

Le vecteur de copie reste en `markTestIncomplete` (ou équivalent) tant que D11a n'est pas confirmée.

### 4.6 Vecteurs synthétiques à ajouter

Non issus des goldens, calculés à la main pour R7, R3 (plafond BPC) et R13.

| Règle | Entrée | Attendu | Aujourd'hui |
|---|---|---|---|
| R7 durée | temps de base 102 s, 10 runs, TE 0, structure ×0,85, sans compétence | `round(10 × 102 × 0,85) = 867` s | `ceil(86,7) × 10 = 870` s |
| R1 + R13 découpage | base 7, ME 10, sans bonus, 32 runs en un job | `ceil(201,6) = 202` | |
| R1 + R13 découpage | même entrée, 3 jobs de 11 + 11 + 10 runs | `70 + 70 + 63 = 203` | 202 (non recalculé) |
| R3 plafond BPC | demande de 32 runs, BPC à 11 runs maximum | 3 jobs : 11, 11, 10 | 1 job de 32 |
| R1 bruit flottant | produit `runs × base × mods` = `n + 1e-7` | `n` | |

## 5. Cas limites

| Cas | Comportement attendu |
|---|---|
| Runs ≤ 0 | refusé à la construction du value object `Runs` |
| ME hors 0..10, TE hors 0..20 ou impair | refusé à la construction |
| Produit sans blueprint ni réaction | erreur explicite, signalée dans le résultat ; jamais un produit retiré en silence ([#20](https://github.com/srekcud/evetools/issues/20)) |
| Stock ≥ demande d'un intermédiaire | 0 run, sous-arbre absent, stock restant disponible pour les autres consommateurs |
| Intermédiaire blacklisté ou acheté de force | feuille, sous-arbre absent |
| Cycle dans le graphe de production | erreur explicite (l'ordre topologique est impossible) |
| Demande nulle d'un intermédiaire | aucun job |
| Probabilité d'invention absente | résultat inconnu ([#74](https://github.com/srekcud/evetools/issues/74)) |
| Cost index, adjusted price ou prix de marché absent | montant inconnu, typeId listés, quantités toujours calculées |
| Plafond BPC absent (BPO) | pas de découpage par R3 |

## 6. Critères d'acceptation

1. Les cas 4.1, 4.4 et 4.5 (hors copie) passent dans `tests/` contre `src/Industry/Domain/`, à 1 ISK près pour les montants.
2. Les cas 4.2 et 4.3 passent ; ils ne deviennent bloquants qu'après recoupement externe (D12).
3. Le vecteur de copie passe après confirmation de D11a.
4. Une seule implémentation de R1, de R4 et de R6 dans `src/` : les anciennes sont supprimées au fil de la migration (§7).
5. `src/Industry/Domain/` ne dépend d'aucune autre partie de `src/`, ni de Doctrine, Symfony ou HTTP (règle deptrac).
6. Aucun `?? 0.0` ni catch silencieux sur un prix, un indice ou une probabilité dans le noyau.
7. Le MSI de `src/Industry/Domain/` est mesuré, puis fixé en cliquet (ADR-0005).
8. Pour une même entrée, arbre affiché, étapes, liste d'achats et Profit Margins donnent les mêmes quantités ([#6](https://github.com/srekcud/evetools/issues/6)).

## 7. Migration des appelants (strangler)

Le noyau est construit à côté de l'existant. Chaque appelant passe à l'Application, sous un test de caractérisation écrit avant. `buildProductionTree()` a aujourd'hui 10 appels dans 7 classes.

| Rang | Appelant | Effet | Caractérisation préalable |
|---|---|---|---|
| 1 | `ProfitMarginService.php:61` | lecture | sortie `/profit-margin` sur les goldens ; embarque R5, R6, R8 |
| 2 | `BuyVsBuildService.php:65` | lecture | sortie buy-vs-build |
| 3 | `StockpileService.php:39`, `:85` | lecture | sortie stockpile |
| 4 | `ProjectProvider.php:57` | lecture (arbre affiché) | snapshot JSON du provider |
| 5 | `IndustryShoppingListBuilder.php:104`, `:124` | lecture | liste d'achats d'un projet de test |
| 6 | `GroupIndustryProjectService.php:88` | **écrit** la BOM de groupe | BOM persistée avant / après ; les contributions approuvées ne bougent pas |
| 7 | `IndustryProjectFactory.php:51`, `:92`, avec `IndustryStepCalculator` et `IndustryCalculationService::calculateMaterialQuantity()` | **écrit** étapes, découpages, liens de jobs | étapes générées sur les goldens ; liens de jobs conservés à la régénération ([#21](https://github.com/srekcud/evetools/issues/21)) |

Ensuite, les calculs qui n'appellent pas l'arbre : `BatchProfitScannerService` (`:299`, `:311`), `PivotAdvisorService` (`:170`, `:212`, `:436`), `InventionService` (coûts de copie et d'invention, probabilité), `ProductionCostService`, `EsiCostIndexService::calculateJobInstallCost()`. `CreateStepProcessor` (`:87`, `:130`, `:186`) et `SplitStepProcessor` (`:83`, `:95`) passent par `Runs` / `Quantity` (B1).

## 8. Issues couvertes

| Issue | Règle |
|---|---|
| [#2](https://github.com/srekcud/evetools/issues/2) runs lus comme une quantité | R3, value objects, §4.1 |
| [#6](https://github.com/srekcud/evetools/issues/6) trois règles de bonus | R4 |
| [#7](https://github.com/srekcud/evetools/issues/7) coût d'installation | R5, R6, §4.5 |
| [#8](https://github.com/srekcud/evetools/issues/8) prix manquant = gratuit | R10 |
| [#15](https://github.com/srekcud/evetools/issues/15) ME 10 en dur sur les réactions | R2 |
| [#16](https://github.com/srekcud/evetools/issues/16) matériau partagé entre profondeurs | R3, §4.3 |
| [#17](https://github.com/srekcud/evetools/issues/17) formule dupliquée | R1 |
| [#18](https://github.com/srekcud/evetools/issues/18) decryptor et invention dans Profit Margins | R8 (la partie vente reste hors noyau) |
| [#19](https://github.com/srekcud/evetools/issues/19) deux définitions de marge | hors noyau |
| [#20](https://github.com/srekcud/evetools/issues/20) stock partiel d'un intermédiaire | R9 |
| [#21](https://github.com/srekcud/evetools/issues/21) processors | §7, rang 7 |
| [#22](https://github.com/srekcud/evetools/issues/22) scanner sans prix | R10 |
| [#69](https://github.com/srekcud/evetools/issues/69) agrégation | R3, §4.4 |
| [#71](https://github.com/srekcud/evetools/issues/71) Athanor, Thukker | R4 |
| [#72](https://github.com/srekcud/evetools/issues/72) rigs de réaction | R2, R4 |
| [#73](https://github.com/srekcud/evetools/issues/73) découpage | R13, §4.6 |
| [#74](https://github.com/srekcud/evetools/issues/74) probabilité absente | R8, R10 |

## 9. Points ouverts

- **D11a** : SCC de 4 % sur la copie, en attente d'un job de copie lancé en jeu.
- **D12** : goldens Hail L et Fernite Carbide **confirmés par EVE Ref** (2026-10-07) ; nouveau golden Ravworks multi-produits (plan partagé, configuration reconstituée), voir `.claude/tasks/industry-core/golden/crosscheck-everef.md`.
- Mode coût marginal (arrondi ou non) : **tranché (D4b, 2026-10-07)**, fractionnaire pur, quantité = base × multiplicateurs, sans arrondi.
- Choix de structure à bonus égal, ou favori sans structure adaptée : **tranché (D3b, 2026-10-07)**, à bonus égal la structure du système favori, puis la plus récemment configurée ; si le favori n'a aucune structure adaptée, la meilleure de toutes avec un avertissement.
