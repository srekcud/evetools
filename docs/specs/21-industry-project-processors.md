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

- ME/TE forcés pour un T2 à la création, et régénération des étapes (perte des liens de jobs) quand les runs changent : à décider avec Sylvain.
- Frais de courtage propres à une structure.

## Décision prise en autonomie

Spec validée en mode nuit. Choix conservateurs : les réglages existants (un seul courtage, une seule taxe) remplacent les taux codés en dur ; une estimation impossible reste inconnue et journalisée plutôt que de faire échouer la création.

## Glossaire

Aucun terme ajouté.
