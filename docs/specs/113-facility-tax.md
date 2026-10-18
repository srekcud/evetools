# Spec #113 — Saisie de la taxe d'installation des structures de production

## Contexte

La taxe d'installation (`IndustryStructureConfig::$facilityTaxRate`) existe en base et les calculs la lisent déjà (`EsiCostIndexService::calculateJobInstallCost()`, `InventionService`, `ProductionCostService`, `ProfitMarginService`), mais elle n'est exposée ni par l'API des structures ni par le formulaire. Elle est donc vide partout, et le coût d'installation est calculé sans taxe dans les structures de joueur, qui en facturent presque toujours une. Une taxe vide est lue comme 0 % (décision du 2026-10-09, inchangée).

## Règles métier

- **R1 — Unité et bornes.** La taxe d'installation est un pourcentage : `3.5` signifie 3,5 %. Valeurs admises : de 0 à 50 inclus. Hors bornes : 422, à la création comme à la modification.
- **R2 — Structure de joueur, taxe optionnelle.** À la création, la taxe peut être omise : elle reste `null` (non saisie). L'API renvoie `facilityTaxRate` dans la réponse de création, de modification et dans la liste des structures ; `null` veut dire « non saisie ».
- **R3 — Modification.** Un PATCH avec `facilityTaxRate` remplace la taxe. Un PATCH sans ce champ la laisse inchangée.
- **R4 — Station NPC, taxe fixée par le jeu.** Une structure de type `station` a toujours une taxe de 0,25 %, quelle que soit la valeur envoyée ou stockée. L'API renvoie `0.25`, et les calculs de coût d'installation lisent 0,25 %, y compris pour les stations déjà enregistrées avec une taxe vide.
- **R5 — Changement de type.** Une station NPC passée en structure de joueur par un PATCH prend la taxe envoyée dans ce PATCH, sinon `null`. Les 0,25 % ne la suivent pas.
- **R6 — Structure corpo partagée.** La configuration partagée (`sharedConfig` de la liste des structures corpo) contient `facilityTaxRate`. Le formulaire d'import la préremplit, comme les rigs ; le membre peut la modifier avant de valider.
- **R7 — Formulaire.** Structure de joueur : champ « Taxe d'installation (%) », vide autorisé, de 0 à 50. Station NPC : pas de champ, affichage « 0,25 % (fixée par le jeu) ». La carte d'une structure affiche sa taxe, ou « non saisie ».

## Exemples chiffrés

| # | Situation | Entrée | Attendu |
|---|---|---|---|
| E1 | Création d'un Raitaru | `facilityTaxRate: 5` | stockée 5, réponse `5.0` |
| E2 | Création d'un Raitaru sans taxe | champ absent | stockée `null`, réponse `null` |
| E3 | Création d'un Azbel | `facilityTaxRate: 3.5` | réponse `3.5` |
| E4 | Borne basse | `0` | acceptée, réponse `0.0` |
| E5 | Borne haute | `50` | acceptée, réponse `50.0` |
| E6 | Au-delà | `50.01` | 422 |
| E7 | Négative | `-1` | 422 |
| E8 | PATCH d'un Raitaru à 2 % | `facilityTaxRate: 7.5` | réponse `7.5` |
| E9 | PATCH d'un Raitaru à 2 % sans le champ | `name: "X"` | taxe toujours `2.0` |
| E10 | PATCH hors bornes | `facilityTaxRate: 60` | 422, taxe inchangée |
| E11 | Création d'une station NPC | `facilityTaxRate: 10` | réponse `0.25` |
| E12 | Station NPC existante, taxe stockée `null` | liste des structures | réponse `0.25` |
| E13 | Station NPC existante, taxe stockée `null` | coût d'installation d'une étape dans cette station | calculé avec une taxe de 0,25 % |
| E14 | PATCH station → Raitaru sans taxe | `structureType: "raitaru"` | réponse `null` |
| E15 | PATCH station → Raitaru avec taxe | `structureType: "raitaru", facilityTaxRate: 4` | réponse `4.0` |
| E16 | Structure corpo configurée par un autre membre à 4 % | liste des structures corpo | `sharedConfig.facilityTaxRate = 4.0` |
| E17 | Structure corpo configurée sans taxe | liste des structures corpo | `sharedConfig.facilityTaxRate = null` |

## Cas limites

- Taxe vide sur une structure de joueur : lue comme 0 % par les calculs, comme aujourd'hui.
- Une taxe décimale (`2.25`) est conservée telle quelle, sans arrondi.

## Hors périmètre

- Rendre la taxe obligatoire (second temps de l'issue).
- Remettre une taxe saisie à `null` par un PATCH.
- La formule du coût d'installation (taxe multipliant le cost index, surcharge SCC absente) : issue #7, noyau Industry.
- Une taxe différente selon le personnage ou la liste d'accès de la structure.

## Glossaire

Ajout de **Taxe d'installation** (Facility tax).
