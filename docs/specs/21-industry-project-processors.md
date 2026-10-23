# Spec #21 — Processors de projet industriel : taxes, statut, erreurs

## Contexte

`CreateProjectProcessor` calcule la taxe estimée d'un projet avec des taux codés en dur (6,1 % pour un prix Jita, 4,6 % pour un prix de structure) au lieu des réglages de l'utilisateur, et avale en silence (`catch (\Throwable)` vides) toute erreur des estimations de coût d'installation, de coût matériaux et de prix de vente. `UpdateProjectProcessor` ignore en silence un statut inconnu.

## Règles métier

- **R1 — Taxe estimée.** `estimatedTaxAmount = valeur de vente estimée × (courtage + taxe de vente)` des réglages Industry de l'utilisateur (`IndustryUserSettings::getBrokerFeeRate()`, `getSalesTaxRate()`), avec les défauts `EveConstants::DEFAULT_BROKER_FEE_RATE` et `DEFAULT_SALES_TAX_RATE` sans réglages. Même taux quelle que soit la source du prix (Jita ou structure).
- **R2 — Statut invalide.** Un `status` inconnu dans `PATCH` d'un projet est refusé par une erreur de validation (422), le projet n'est pas modifié.
- **R3 — Erreurs d'estimation.** Une estimation qui échoue (coût d'installation, coût matériaux, prix de vente) laisse la valeur estimée vide (`null`, inconnue) et est journalisée en avertissement avec le motif ; la création du projet aboutit.

## Exemples chiffrés

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Prix Jita 100 000, 10 runs, 1 unité par run, courtage 1 %, taxe 2 % | taxe estimée 61 000 (6,1 %) | 30 000 (3 %) |
| E2 | Même cas, prix de structure retenu | 46 000 (4,6 %) | 30 000 |
| E3 | Utilisateur sans réglages Industry, prix Jita | 6,1 % | 3,6 % + 3,6 % = 7,2 % → 72 000 |
| E4 | `PATCH` avec `status = "bogus"` | déjà refusé : `UpdateProjectInput::$status` porte `Assert\Choice` (le `tryFrom` du processor n'est jamais atteint) | inchangé, figé par un test de validation |
| E5 | Estimation du coût matériaux qui lève une exception | projet créé, coût vide, rien journalisé | projet créé, coût `null`, un avertissement journalisé |

## Hors périmètre

- Frais de courtage propres à une structure.
- Runs saisis sur un intermédiaire réécrits par la cascade (#185) et arbre construit au PATCH (#204).

## Suite décidée par Sylvain le 2026-10-22

### Lot 2 — ME/TE d'un projet T2

- **R4 — Saisie respectée.** La création d'un projet transmet à la fabrique les ME et TE saisis, T2 compris (aujourd'hui forcés à ME 2 / TE 4, `InventionService::BASE_INVENTION_ME/TE`).
- **R5 — Formulaire.** Choisir un produit T2 pré-remplit ME 2 / TE 4 ; les deux champs restent modifiables et la valeur saisie est envoyée telle quelle.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E6 | Projet T2, ME 4, TE 8 saisis | projet ME 2 / TE 4 | projet et étape racine ME 4 / TE 8 |
| E7 | Projet T2, ME 2, TE 4 (pré-remplissage gardé) | ME 2 / TE 4 | inchangé |
| E8 | Projet T1, ME 10, TE 20 | ME 10 / TE 20 | inchangé |
| E9 | Formulaire : T2 choisi, ME passé à 3 | champ en lecture seule, 2 envoyé | 3 envoyé |

### Lot 3 — Régénération des étapes

Changer les runs, le ME, le TE ou la durée maximale de job d'un projet (ou `app:industry:regenerate-steps`) recalcule le plan comme aujourd'hui, puis l'applique aux étapes existantes au lieu de les remplacer.

- **R6 — Correspondance.** Une étape planifiée correspond à l'étape existante de même blueprint et de même activité, de même rang dans l'ordre des `splitIndex`. L'étape existante est mise à jour sur place (même identifiant) avec les valeurs du plan : produit, runs, quantité, profondeur, ordre, groupe découpé, ME, TE ; sa structure est remise à vide comme pour une étape neuve.
- **R7 — Ce qui est gardé.** Une étape mise à jour garde ses jobs matchés, ses achats, son stock en main, son statut acheté et son mode de match.
- **R8 — Plus de jobs qu'avant.** Une étape planifiée sans étape existante de même rang est créée.
- **R9 — Moins de jobs qu'avant.** Une étape existante en excédent (même blueprint et activité, rang au-delà du plan) est supprimée après avoir donné à la dernière étape gardée de même blueprint et activité ses jobs matchés et ses achats ; son stock s'ajoute au sien, et l'étape gardée est achetée si l'une des deux l'était.
- **R10 — Étape hors plan.** Une étape existante dont le blueprint et l'activité n'apparaissent pas dans le plan (produit ajouté à la main au projet) est gardée telle quelle, placée après les étapes du plan.

Exemples sur la recette des tests d'intégration (frégate : 4 composants par run ; composant : 10 minéraux par run ; 600 s par run) :

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E10 | Frégate 5 runs, un job matché (5 runs) et un achat sur l'étape racine ; runs passés à 10 | étapes remplacées, job matché et achat supprimés | même étape racine, 10 runs, job matché et achat gardés ; composant 36 runs |
| E11 | Composant : stock 7, acheté ; régénération sans changement | nouvelle étape, stock 0, non achetée | même étape, stock 7, achetée, 18 runs, ME 10 / TE 20 |
| E12 | Étape racine en mode `manual` ; ME changé | mode `auto` | mode `manual`, job matché gardé |
| E13 | Racine découpée en 2 jobs, job matché sur le job 1 ; durée maximale relevée, plus de découpage | tout supprimé | une seule étape (celle du job 0), qui porte le job matché du job 1 ; le job 1 est supprimé |
| E14 | Racine non découpée avec un job matché ; durée maximale baissée, 2 jobs | tout supprimé | l'étape existante devient le job 0 et garde son job matché ; le job 1 est créé |
| E15 | Produit ajouté à la main avec un achat ; runs du projet changés | étape et achat supprimés | étape et achat gardés, après les étapes du plan |

## Décisions prises en autonomie (lots 2 et 3, mode nuit)

- R4 sans repli : une saisie absente vaut 0 comme pour un T1 (ME 0 est atteignable en T2 avec un decryptor) ; le pré-remplissage 2/4 est fait par le formulaire.
- R6 : mise à jour sur place plutôt que transfert vers de nouvelles étapes, pour garder les identifiants des étapes.
- R10 : une étape hors plan est gardée plutôt que supprimée, pour ne perdre aucune donnée saisie.
- Lot 3 : ME, TE et structure d'une étape remis aux valeurs du plan. Remplacé par le lot 4.

### Lot 4 — Le plan reprend le ME, le TE et la structure des étapes (option B, décidée par Sylvain le 2026-10-23)

- **R11 — Réglages repris.** À la régénération, une étape planifiée qui correspond à une étape existante (R6) reprend son ME, son TE et sa structure au lieu des défauts (ME 10 / TE 20, meilleure structure). Une étape créée par R8 reprend ceux de l'étape existante de même blueprint et activité de rang 0. Une étape sans étape existante garde les défauts.
- **R12 — Le plan les utilise.** Les matériaux des jobs d'une étape sont calculés avec son ME et, si elle a une structure, le bonus matériaux de cette structure pour la catégorie du produit de l'étape (`IndustryCalculationService::getStructureBonusForStep()`, même règle que le recalcul d'une étape). Sa durée par run (donc son découpage) utilise son TE et, si elle a une structure, le bonus de temps de cette structure.
- **R13 — Racine.** L'étape racine (produit du projet, profondeur 0) prend toujours le ME et le TE du projet ; sa structure est reprise.
- **R14 — Inchangé.** Réaction : ME 0 et TE 0 ; copie : ME 0 et TE 0 ; création d'un projet : défauts comme aujourd'hui.

Exemples sur la recette des tests d'intégration, étendue d'un niveau (frégate ← composant ← sous-composant ← minéral) :

| # | Cas | Aujourd'hui (lot 3) | Attendu |
|---|---|---|---|
| E16 | Composant passé à ME 8, puis runs du projet changés | composant remis à ME 10, sous-composants calculés à ME 10 | composant ME 8, sous-composants calculés à ME 8 |
| E17 | Composant avec une structure au bonus matériaux connu, régénération | structure retirée, meilleure structure appliquée | structure gardée, ses sous-composants calculés avec son bonus |
| E18 | Composant passé à TE 10 avec une durée maximale qui le découpe à TE 10 mais pas à TE 20 | TE 20, pas de découpage | TE 10, groupe découpé |
| E19 | Racine passée à ME 8 par l'étape, puis runs du projet changés (projet ME 10) | ME 10 | ME 10 (R13) |

Décision prise en autonomie : la règle de bonus d'une structure assignée est celle du recalcul d'une étape (catégorie du produit de l'étape), sans trancher #133.

## Décision prise en autonomie (lot 1)

Spec validée en mode nuit. Choix conservateurs : les réglages existants (un seul courtage, une seule taxe) remplacent les taux codés en dur ; une estimation impossible reste inconnue et journalisée plutôt que de faire échouer la création.

## Glossaire

Aucun terme ajouté.
