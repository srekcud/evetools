# #300 — Brancher les barèmes de location de ligne

Décision de Sylvain (issue #300, 2026-11-03) : brancher les barèmes. Modèle de la maquette unifiée v3 (`industry-project-v3.html`, page 11) : location d'une ligne = facteur par jour × jours occupés + montant par run × runs, pré-remplie dans le formulaire de contribution, modifiable avant l'envoi.

## Contexte

Les barèmes existent (`User::getLineRentalRates()`, `IndustryProject::getLineRentalRatesOverride()`, modales de réglage) mais la contribution `ContributionType::LineRental` exige une valeur saisie à la main (`ProjectContributionService::requireEnteredValue`). Le barème par défaut (7 catégories + facteur) n'existe que dans le front (`LineRentalModal.vue`, `NewProjectModal.vue`). Aucune classification d'un produit en catégorie de barème n'existe côté back. Aucune migration.

## Règles

1. **Catégories de barème** (clés déjà stockées) : `component_t1`, `module_t1`, `ship_t1`, `module_t2`, `ship_t2`, `capital_t1`, `capital_t2`, plus le facteur `location_factor` (ISK par jour).
2. **Barème par défaut côté back** : facteur 1 000 000 ISK/jour ; par run : component_t1 250, module_t1 10 000, ship_t1 100 000, module_t2 100 000, ship_t2 2 500 000, capital_t1 25 000, capital_t2 125 000.
3. **Résolution d'une clé, clé par clé** : surcharge du projet, sinon barème du contributeur (`User`), sinon défaut. Une clé inconnue d'une couche est ignorée. Une couche vide ou nulle passe à la suivante.
4. **Catégorie d'un produit de la ligne** (type produit de la `IndustryProjectBomLine`, données SDE) : catégorie SDE Ship (6) dans un groupe capital (rig category `capital_ship`) → `capital_t2` si le produit est T2 (`InventionService::isT2`), sinon `capital_t1` ; autre vaisseau → `ship_t2` / `ship_t1` ; catégorie SDE Module (7) → `module_t2` / `module_t1` ; groupes Construction Components (334) et Capital Construction Components (873) → `component_t1`. Tout autre produit (drone, charge, déployable, structure, composant avancé…) : catégorie **inconnue**, jamais de repli vers une catégorie voisine.
5. **Jours occupés et runs** : seuls comptent les jobs liés à la ligne (`IndustryProjectJobMatch`) dont le job ESI en cache (`CachedIndustryJob`, retrouvé par `esiJobId`) appartient à un personnage du contributeur. Jours = somme de (fin − début) de ces jobs en secondes / 86 400. Runs = somme des runs des liaisons concernées.
6. **Valeur suggérée** = facteur × jours + montant de la catégorie × runs, arrondie à 2 décimales.
7. **Inconnu explicite** : catégorie inconnue, aucun job lié du contributeur sur la ligne, ou job lié absent du cache : pas de valeur (jamais 0), avec une raison (`unknown_category`, `no_linked_job`, `job_data_missing`). Le formulaire garde alors la saisie manuelle.
8. **Endpoint de lecture** `GET /industry/projects/{projectId}/line-rental-suggestions/{id}` où `id` est l'id de la ligne BOM : réservé à un participant accepté ou au propriétaire (`ProjectAccessChecker::assertAcceptedMember`), calculé pour l'utilisateur appelant (ses personnages, son barème). Ligne d'un autre projet ou inexistante : 404. Réponse : `bomItemId`, `suggestedValue` (nullable), `unknownReason` (nullable), `category`, `days`, `runs`, `dayFactor`, `ratePerRun` (nullables si inconnus).
9. **Formulaire de contribution** (`ContributeModal.vue`) : type Location de ligne exige désormais une ligne choisie (le back refuse déjà sans `bomItemId`). Au choix de la ligne, le front appelle l'endpoint, pré-remplit la valeur estimée, affiche le détail (catégorie, jours, runs, facteur, montant par run) ; la valeur reste éditable ; le total affiché suit la valeur saisie. Raison inconnue : message court et champ vide. La soumission envoie toujours une valeur explicite : le contrat de `POST /contributions` ne change pas.
10. Docs : `docs/glossary.md` (ligne « Location de ligne » : formule, catégories, résolution) et `docs/modules/industry.md`.

## Exemples chiffrés (futurs tests rouges)

| Cas | Entrées | Résultat |
|---|---|---|
| Mockup v3 (C13) | module_t2, 5 jobs de 4 h 37 min (16 620 s chacun), 50 runs, barème défaut | jours 0,961805…, valeur 5 961 805,56 |
| Surcharge de projet gagne | override `module_t2` = 200 000, user `module_t2` = 150 000, défaut 100 000 ; 10 runs, 1 jour | 1 000 000 + 2 000 000 = 3 000 000 |
| Barème utilisateur sinon | override vide, user `ship_t1` = 120 000 ; 3 runs, 0,5 jour | 500 000 + 360 000 = 860 000 |
| Défaut sinon | aucune couche ; ship_t1, 2 runs, 1 jour | 1 000 000 + 200 000 = 1 200 000 |
| Facteur surchargé | override `location_factor` = 2 000 000 ; module_t1, 4 runs, 0,25 jour | 500 000 + 40 000 = 540 000 |
| Drone T2 | catégorie SDE 18 | valeur nulle, `unknown_category` |
| Aucun job lié du contributeur | ligne module_t2 sans liaison à ses personnages | valeur nulle, `no_linked_job` |
| Job lié hors cache | liaison dont `esiJobId` n'est pas en cache | valeur nulle, `job_data_missing` |
| Vaisseau T2 capital | groupe Force Auxiliary, `isT2` vrai | `capital_t2` |

## Cas limites

- Jobs d'un autre membre sur la même ligne : ignorés (règle 5).
- Un seul job manquant du cache rend toute la suggestion inconnue (pas de somme partielle).
- Barème utilisateur vide (`[]`, état initial) : défauts.

## Accès et fraîcheur

Participant accepté ou propriétaire seulement ; ligne vérifiée comme appartenant au projet de l'URL ; seuls les personnages de l'utilisateur appelant sont lus. Durée lue dans `CachedIndustryJob` (cache de la sync des jobs, 30 min) : pas de nouvel appel ESI.

## Tests existants

Aucun test existant contredit : `ProjectContributionService` et le contrat de soumission ne changent pas.

## Hors périmètre

Suppression des barèmes, changement du contrat de soumission, valeur calculée côté serveur quand la valeur est omise, classification des drones et autres produits (question ouverte), location d'une ligne comme apport partagé avec la marge ou coût fixe avant répartition (question ouverte, la cascade actuelle compte la location comme apport), Mercure (aucune action asynchrone).

## Glossaire

« Location de ligne » complété ; « Catégorie de barème » : l'une des 7 catégories, ou inconnue.
