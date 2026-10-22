# Spec #145 (lot a) — Bornes des taux du coût d'installation

## Contexte

Bilan de #145 sur main 663da26 :

- Bornes de saisie 0 à 50 % : **en place** (spec #113, `StructureFacilityTaxValidationTest`, 422 hors bornes).
- Conversion entité (pourcents) → noyau (fraction) : **en place** (`ConfiguredProductionStructures.php:78`, ÷ 100 ; taxe non saisie = 0 %).
- Taxe non saisie = 0 % : **décidée** par Sylvain (commentaire de l'issue), documentée dans le glossaire ; **absente** de la doc utilisateur (`docs/user/*/`) → ajoutée dans ce lot.
- `InstallCost::assertRate()` (`src/Industry/Domain/InstallCost.php:67-72`) refuse un taux négatif ou non fini, **sans borne haute** : une taxe passée en pourcents (3,5) ou un indice aberrant (5,0) est accepté. **Corrigé dans ce lot.**
- Même nom `facilityTaxRate` pour des pourcents (entité, services) et une fraction (noyau) : lot b.

## Règles métier

- **R1 — Taxe.** `InstallCost::forJob()` refuse (`\InvalidArgumentException`) une taxe d'installation hors de [0 ; 0,5] (fraction ; 0,5 = 50 %, borne de la spec #113).
- **R2 — Cost index.** Il refuse un cost index hors de [0 ; 1] ; un indice absent (`null`) reste un coût inconnu (inchangé).
- **R3 — Doc utilisateur.** `docs/user/fr/structures-industrielles.md` et `docs/user/en/industry-structures.md` décrivent le champ **Taxe d'installation (%)** : de 0 à 50, vide = 0 % dans les calculs, station NPC fixée à 0,25 % par le jeu.

## Exemples

| # | Cas | Attendu (aujourd'hui) |
|---|---|---|
| E1 | taxe 0,5, index 0,05, EIV 1 000 000, fabrication | 590 000 (inchangé) |
| E2 | taxe 0,5001 | exception (accepté) |
| E3 | taxe 3,5 (pourcents passés par erreur) | exception (accepté : 3 590 000) |
| E4 | index 1,0 | accepté : 1 000 000 + 40 000 = 1 040 000 avec taxe 0 |
| E5 | index 1,0001 ; index 5,0 | exception (accepté) |
| E6 | index `null` | coût inconnu (inchangé) |

## Risque de déploiement

Une structure enregistrée avant la validation de #113 avec une taxe > 50 ferait lever le calcul (Profit Margins, coût de production, Pivot, scanner). Vérification en prod avant déploiement : voir « Actions Sylvain ».

## Hors périmètre

Renommage des pourcents (lot b), Buy vs Build (code inchangé : il passe par `InstallCost` et hérite des bornes).

## Glossaire

Aucun ajout.
