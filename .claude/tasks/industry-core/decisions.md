# Décisions de Sylvain — moteur Industrie

| # | Décision | Date | Précision |
|---|---|---|---|
| D2 | Agréger la demande d'un même intermédiaire sur tout le plan | 2026-10-07 | **Attention au maximum de runs d'un BPC** : un job agrégé ne peut pas dépasser les runs du BPC utilisé ; au-delà, découper en plusieurs jobs (un par BPC / max runs). |
| D4 | Deux modes : plan (runs entiers, surplus) et coût marginal (lots fractionnaires) | 2026-10-07 | |
| D5 | Prix absent → résultat partiel « inconnu » + liste des typeId sans prix | 2026-10-07 | |
| D9 | Périmètre v1 : fabrication, réaction, copie, invention T2 (pas de T3 ni de recherche ME/TE) | 2026-10-07 | |
| D1 | ISK : value object sur un float, comparaisons avec tolérance de 1 ISK | 2026-10-07 | |
| D3 | Structure d'un job : celle assignée à l'étape, sinon la meilleure pour (activité, produit du job), système favori comme filtre | 2026-10-07 | |
| D6 | Wormholes : libellé « Nullsec / WH » sur la valeur NullSec, pas de classe dédiée | 2026-10-07 | |
| D7 | Temps de job : un seul round() sur le job entier, sans implants | 2026-10-07 | Implants BX : issue séparée si besoin |
| D8 | Stock de départ consommé avant les runs, sous-arbre réduit (#20) ; stock cible = Stockpile existant, hors noyau | 2026-10-07 | |
| D10 | Bonus : valeurs des attributs dogma du SDE, ciblage par `IndustryRigCategory` | 2026-10-07 | |
| D11a | SCC 4 % sur la copie | — | **En attente** : Sylvain vérifie en jeu (détail du coût d'un job de copie) |
| D11b | Matériaux recalculés job par job lors d'un découpage (#73) | 2026-10-07 | |
| D12 | Goldens Hail L / Fernite Carbide provisoires ; recoupement via api.everef.net ET plan Ravworks partagé par Sylvain avec sa config de structures | 2026-10-07 | Nitrogen fuel block fait foi tout de suite |

| D4b | Mode coût marginal : fractionnaire pur (base × multiplicateurs, sans arrondi) | 2026-10-07 | |
| D3b | À bonus égal : structure du système favori, puis la plus récemment configurée ; aucune structure adaptée dans le favori : la meilleure de toutes + avertissement | 2026-10-07 | |
| — | Spec `docs/specs/industry-engine.md` et ADR-0009 validées par Sylvain | 2026-10-07 | |
Issues créées le 2026-10-07 depuis le benchmark : #69 agrégation, #70 decryptors (corrigée tout de suite en TDD), #71 Athanor/Thukker, #72 rigs de réaction, #73 découpage, #74 probabilité absente.

## Décisions du 2026-10-22 (audit du moteur industrie)

| # | Décision | Date | Précision |
|---|---|---|---|
| D3c | Système favori = **filtre souple** : la meilleure structure du favori si elle a un bonus pour (activité, produit) ; sinon la meilleure de toutes + avertissement | 2026-10-22 | Remplace le filtre strict de `StructureSelector.php:43-49` ; tranche la contradiction D3/D3b (#151) |
| D3d | Copie et invention : structure au **coût d'installation minimal** (bonus de coût, taxe, index du système) ; système favori de l'activité en départage | 2026-10-22 | #151 |
| D13 | Blacklist appliquée **partout sauf Buy vs Build** (Stockpile et réduction par stock inclus) ; Buy vs Build l'ignore avec une mention à l'écran | 2026-10-22 | #139 |
| D13b | Catégorie de blacklist cochée seulement si **tous** ses groupes sont blacklistés ; état intermédiaire sinon | 2026-10-22 | #139 ; `IndustryBlacklistService.php:107-108` |
| D14 | Taxe d'installation non saisie = **0 %**, convention documentée (glossaire + doc utilisateur) | 2026-10-22 | #145 : garder la borne haute et l'échelle unique |
| D11a | SCC sur la copie | — | **Toujours en attente** de vérification en jeu (2026-10-22) |

## Décisions du 2026-10-22 (rang 1, Profit Margins sur le noyau)

| # | Décision | Date | Précision |
|---|---|---|---|
| PM1 | Profit Margins sans système de fabrication : coût **inconnu** (`null` + `missingData` `cost_index`), pas d'installation à 0 | 2026-10-22 | Remplace le comportement de f00e391 |
| PM2 | Bloc invention : `unknownReason = 'missing_market_price'` gardé à côté de `missingData` jusqu'à la migration du bloc | 2026-10-22 | |
| PM3 | `missingData` exposé au total et par étape d'installation ; pas sur les lignes matériaux ni sur les options de decryptor | 2026-10-22 | |
| (a)-(e) | Plan `profit-margin-wiring.md` §3 appliqué : mode coût marginal, compétences d'invention à 0, ME/TE du BPC inventé (#143), système sélectionné favori pour toutes les activités, taxe null = 0 % (D14) | 2026-10-22 | |

## Décisions du 2026-10-22 (rang 2, Buy vs Build sur le noyau)

| # | Décision | Date | Précision |
|---|---|---|---|
| BB-a | Mode plan (runs entiers, surplus) pour la racine et les composants | 2026-10-22 | |
| BB-b | Coût build sur un niveau : matériaux directs + installation | 2026-10-22 | Build récursif optimal = autre issue |
| BB-c | Invention du T2 incluse : sans decryptor, compétences à 0 | 2026-10-22 | Ferme le volet Buy vs Build de #124 |
| BB-d | Composants de profondeur 1, quantité du seul job racine | 2026-10-22 | |
| BB-e | Écart ≤ 1 ISK (`Isk::equals()`) → build ; `savings` reste la différence calculée | 2026-10-22 | |
| BB-f | Un `costOf()` pour la racine puis un par composant ; pas de `ProductionCost::$plan` | 2026-10-22 | |
| BB-g | Réactions au système favori de réaction (#99 gardé) | 2026-10-22 | La décision (d) du rang 1 reste propre à Profit Margins |
| BB-h | Mention D13 : « La blacklist industrie ne s'applique pas ici : chaque composant est comparé à son achat » / « Your industry blacklist does not apply here: every component is compared with buying it » | 2026-10-22 | |
| BB-i | #142 hors lot (correction dans le noyau) | 2026-10-22 | |
| BB-j | Prix Jita d'achat d'un composant = prix pondéré du noyau | 2026-10-22 | |
| BB-k | `missingData` au résultat et par composant remplace `missingPriceTypeIds` et `unknownInstallCostReasons` ; verdicts #8 (seul build connu → build, seul achat connu → buy) conservés | 2026-10-22 | |

## Décisions du 2026-10-26

| # | Décision | Précision |
|---|---|---|
| #142/#149 | Spec `docs/specs/142-149-cost-request-cap-and-assigned-structure.md` validée (commit 079ebb4, branche `fix/group-core-wiring`) | Structure assignée incapable de faire l'activité : ignorée, meilleure structure selon D3 + avertissement |
| #151, #139 | Décisions déjà en commentaire des issues : label `circuit:decision` retiré, passées en `circuit:m` | |
| #42 | Les 9 scopes ESI inutilisés sont gardés pour les features planifiées ; issue fermée | |
| #219 / D11a | Toujours en attente de la vérification en jeu par Sylvain | |
