# Spec #269 — Minage : rendement de retraitement calculé depuis les compétences et le lieu

**Statut : validée en autonomie en mode nuit le 2026-11-03, à relire par Sylvain.**

Lot `group:mining-ops`, partie valeur du minerai. Circuit `circuit:m`, sans note de câblage. Une migration (nouvelle table).

## Contexte

Le rendement de retraitement est saisi à la main et gardé dans `localStorage` (`MiningTab.vue`, clé `miningReprocessYield`), avec un défaut à 78 % (`OreValueService`) que seule une structure atteint. #268 lit ce `localStorage` pour la colonne « retraité » de la table de valeur au m³. Cette spec calcule le rendement depuis les compétences du personnage et le lieu, l'enregistre côté serveur par utilisateur, et remplace la lecture `localStorage` de la table de valeur.

La synchronisation des compétences d'Industry (`SyncCharacterSkillsProcessor`) ne garde que les compétences de fabrication : les compétences de retraitement n'y sont pas. Le rendement lit donc directement `GET /characters/{character_id}/skills/` (scope `esi-skills.read_skills.v1`, déjà demandé), sans toucher au code Industry.

## Règles métier

- **Y1 — Formule.** Facteur de compétences `F = (1 + 0,03 × Reprocessing) × (1 + 0,02 × Reprocessing Efficiency) × (1 + 0,02 × compétence de retraitement du minerai)`, `implant` en fraction (0, 0,01, 0,02, 0,04).
  - Station NPC : `rendement = 0,50 × F × (1 + implant)`.
  - Structure (Athanor, Tatara) : `rendement = ((50 + rig) / 100) × (1 + sécurité) × (1 + bonus structure) × F × (1 + implant)`, avec rig 0 (aucun), 1 (T1), 3 (T2) ; sécurité 0 (hisec), 0,06 (lowsec), 0,12 (nullsec et trou de ver) ; bonus structure 0,02 (Athanor), 0,055 (Tatara). Résultat en fraction (0,7236 pour 72,36 %), non plafonné.
- **Y2 — Compétence de retraitement du minerai.** Le SDE donne, par type de minerai, la compétence qui s'y applique (attribut 790 `reprocessingSkillType` : Simple Ore Processing, Coherent Ore Processing, Ice Processing…). L'issue emploie un seul terme « Ore Processing » : le rendement est donc calculé pour **une** compétence choisie par l'utilisateur (`oreProcessingSkillTypeId`), parmi celles que le SDE associe à au moins un minerai de la table (#268, règle V1). Aucune compétence par défaut : sans choix, pas de rendement.
- **Y3 — Niveaux.** Niveau lu sur `active_skill_level` de la réponse ESI. Une compétence absente de la liste ESI (non entraînée) vaut niveau 0, connu. Un échec de lecture rend le rendement **inconnu** (`null`), jamais un défaut.
- **Y4 — Profil de retraitement.** Un par utilisateur (table `user_reprocessing_profiles`, une ligne par utilisateur, suppression en cascade avec l'utilisateur ou le personnage) : personnage (appartenant à l'utilisateur), `oreProcessingSkillTypeId`, `location` (`npc_station`, `athanor`, `tatara`), `rigTier` (`none`, `t1`, `t2` ; structure seulement), `security` (`highsec`, `lowsec`, `nullsec` ; structure seulement), `implantPercent` (0, 1, 2 ou 4), niveaux lus (`reprocessingLevel`, `reprocessingEfficiencyLevel`, `oreProcessingLevel`, `null` si illisibles), `reprocessYield` (`null` si inconnu), `failure` (`null`, `missing_token`, `missing_scope`, `esi_error`), `computedAt`. Pour `npc_station`, `rigTier` et `security` sont enregistrés à `null`.
- **Y5 — Enregistrer.** `PUT /api/mining/reprocessing-profile` (corps JSON : `characterId` (identifiant EVE), `oreProcessingSkillTypeId`, `location`, `rigTier`, `security`, `implantPercent`) valide, lit les compétences, calcule, enregistre et rend le profil. Lecture impossible (aucun token, scope absent, erreur ESI) : le profil est enregistré avec `reprocessYield` `null`, niveaux `null` et `failure` renseigné, la réponse reste 200.
- **Y6 — Recalculer.** `POST /api/mining/reprocessing-profile/refresh` (sans corps, `EmptyInput`) relit les compétences et recalcule avec les réglages enregistrés. Aucun profil : 404.
- **Y7 — Lire.** `GET /api/mining/reprocessing-profile` rend le profil, ou `configured: false` (autres champs `null`) sans profil. La réponse porte aussi `oreProcessingSkills: [{typeId, typeName}]` (liste des choix de Y2, triée par nom).
- **Y8 — Validation (422/400).** `characterId` qui n'appartient pas à l'utilisateur : 404. `oreProcessingSkillTypeId` hors de la liste de Y2, `location`, `rigTier`, `security` ou `implantPercent` hors des valeurs de Y4 : 400. Structure sans `rigTier` ou sans `security` : 400.
- **Y9 — Appel ESI.** Un seul appel `GET /characters/{id}/skills/` par enregistrement ou recalcul, jeton valide obtenu par `TokenManager`, lecture par `EsiClient::get`. Réponse sans liste `skills` ou entrée sans `skill_id` ou `active_skill_level` : `esi_error` (pas de défaut). Aucun Mercure : l'appel est synchrone et unique, le résultat revient dans la réponse HTTP.
- **Y10 — Table de valeur (#268).** `OreValueTab` n'envoie plus le rendement de `localStorage` : il envoie `reprocessYield` du profil quand il est connu, rien sinon (colonne retraité inconnue, avec un message qui renvoie au panneau de réglage). `useOreValueTable` ne lit plus `localStorage`. L'onglet affiche le panneau « Rendement de retraitement » : personnage, compétence du minerai, lieu, rig, sécurité, implant, rendement calculé en % (ou « inconnu » avec la raison), niveaux lus, bouton recalculer.
- **Y11 — Hors de cette spec.** Les entrées de minage, le tableau de bord du ledger et `MiningEntryCollectionProvider` gardent leur rendement par défaut 78 % et la saisie manuelle de `MiningTab` : les remplacer change des montants affichés (question posée à Sylvain, journal de la boucle). Le choix du rendement **par opération** attend l'entité Opération (#262/#264).

## Exemples chiffrés

| # | Cas | Attendu |
|---|---|---|
| R1 | Station NPC, compétences 5/5/5, implant 4 | 0,72358 (72,36 %) |
| R2 | Tatara, rig T2, nullsec, compétences 5/5/5, implant 4 | 0,906281 (90,63 %) |
| R3 | Station NPC, compétences 0/0/0, implant 0 | 0,5 |
| R4 | Athanor, rig T1, hisec, compétences 5/5/5, implant 0 | 0,723858 |
| R5 | Tatara, sans rig, lowsec, compétences 3/2/1, implant 2 | 0,65946 (à 1e-4 près) |
| R6 | ESI : Reprocessing 4, Reprocessing Efficiency 3, 60377 niveau 5 | niveaux lus 4 / 3 / 5 |
| R7 | ESI sans la compétence 3385 (liste non vide) | `reprocessingLevel` 0, rendement calculé |
| R8 | Personnage sans token | profil enregistré, `reprocessYield` `null`, niveaux `null`, `failure` `missing_token` |
| R9 | Personnage dont le token n'a pas `esi-skills.read_skills.v1` | `failure` `missing_scope`, `reprocessYield` `null` |
| R10 | ESI en erreur, ou réponse sans liste `skills` | `failure` `esi_error`, `reprocessYield` `null` |
| R11 | PUT avec `characterId` d'un autre utilisateur | 404, rien d'enregistré |
| R12 | PUT avec `oreProcessingSkillTypeId` absent de la liste de Y2 ; `location` `foo` ; `implantPercent` 3 ; `tatara` sans `rigTier` ; `athanor` sans `security` | 400 chacun |
| R13 | PUT `npc_station` avec `rigTier` `t2` et `security` `nullsec` | enregistrés à `null`, rendement R1-like sans rig ni sécurité |
| R14 | Deuxième PUT du même utilisateur | met à jour la ligne unique (pas de doublon) |
| R15 | POST refresh avec profil | relit l'ESI, met à jour `reprocessYield` et `computedAt` |
| R16 | POST refresh sans profil | 404 |
| R17 | GET sans profil | `configured: false`, `oreProcessingSkills` rempli |
| R18 | GET d'un autre utilisateur | ne voit jamais le profil du premier |
| R19 | Front : profil `reprocessYield` 0,72358 | la table envoie `reprocessYield=0.72358` |
| R20 | Front : profil absent ou rendement `null` | aucun `reprocessYield` envoyé, colonne retraité « — » avec le message de réglage |

## Cas limites

- Personnage supprimé après l'enregistrement : le profil part avec lui (cascade) ; l'utilisateur doit en choisir un autre.
- ESI 420 ou 429 : `esi_error` (le comportement du client ESI existant s'applique).
- Rendement calculé > 1 : impossible avec les valeurs autorisées (maximum 0,9063).

## Hors périmètre

- Taxe de retraitement liée au standing, rigs spécifiques par famille de minerai, `localStorage` de `MiningTab` pour les entrées (Y11).
- Rendement par opération (attend #262/#264).

## Termes proposés au glossaire (non ajoutés, la PR #326 modifie `docs/glossary.md`)

| Terme | Définition |
|---|---|
| **Rendement de retraitement** (*reprocessing yield*) | Fraction des minéraux d'une portion de minerai rendue au retraitement, calculée depuis les compétences du personnage et le lieu. Inconnue (`null`) si les compétences ne sont pas lisibles. |
| **Profil de retraitement** (*reprocessing profile*) | Réglages enregistrés d'un utilisateur (personnage, compétence du minerai, lieu, rig, sécurité, implant) et rendement qui en découle. |
