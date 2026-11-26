# Spec #1405 : Mes templates, plans enregistrés recalculés aux prix du jour

Mode nuit : validée en autonomie, décisions en bas. Pile sur #1402, #1403 et #1404 (`planetaryPlanner.ts`, `planetaryPlanets.ts`, store `planetaryPlanner`). Maquette locale non versionnée : `TPL`, `renderTpl`. Aucune constante de la maquette n'est recopiée.

## Contexte

Un joueur décide rarement quoi installer : il enregistre ses plans pour les comparer et les recharger. Un template garde ses productions, ses choix produire ou acheter et ses réglages ; ses planètes et son profit sont recalculés à chaque affichage avec les prix du jour. Les dispositions de planète se déduisent du calcul (#1403) et ne sont pas stockées.

## Backend

1. Entité `PlanetaryPlanTemplate` (table `planetary_plan_templates`) : `id` (UUID), `user` (`ManyToOne` vers `User`, `onDelete: CASCADE`, comme `MarketFavorite`), `name` (100 caractères au plus), `productions` (JSON : liste de `{typeId, targetPerDay}`), `choices` (JSON : `{typeId: 'produce'|'buy'}`, choix explicites seulement), `settings` (JSON : `{priceSource, taxPercent, daysBetweenRounds}`), `createdAt`. Une migration, validée sur une base isolée (`schema:validate`).
2. Ressource API Platform `PlanetaryPlanTemplate`, `ROLE_USER`, identifiant `id` (UUID) :
   - `GET /planetary/templates` : les templates de l'utilisateur connecté, du plus récent au plus ancien ;
   - `POST /planetary/templates` : crée un template, 201 avec la ressource ;
   - `DELETE /planetary/templates/{id}` : supprime un template de l'utilisateur (provider qui renvoie la ressource, 404 pour un id inexistant ou d'un autre utilisateur, jamais 403) ;
   - pas de `PATCH` (renommer = enregistrer de nouveau puis supprimer).
3. Validation du `POST` (422 avec la liste des champs fautifs, rien n'est stocké) : `name` non vide après trim, 100 au plus ; `productions` de 1 à 20 éléments, `typeId` entier sortie d'un schéma PI (P1 à P4, via `PlanSchematicTypeRepository::getSchematicMap()`), `targetPerDay` nombre fini strictement positif ; `choices` : clés entières sorties d'un schéma PI, valeurs `produce` ou `buy` ; `settings.priceSource` dans `jita-sell`, `jita-buy`, `taxPercent` entre 0 et 100, `daysBetweenRounds` fini et au moins 0,5. Plafond de 50 templates par utilisateur (422 au-delà : « limite atteinte »).
4. Accès : toute lecture ou suppression est filtrée sur l'utilisateur connecté ; l'utilisateur est celui du jeton, jamais un identifiant reçu. À la suppression d'un compte, les templates partent par la cascade de la clé étrangère (même mécanisme que `MarketFavorite` : vérifier `AccountDeletionService` et son test).

## Frontend : calcul et état

5. `frontend/src/utils/planetaryTemplates.ts` (fonctions pures) :
   - `evaluateTemplate(template, catalog, account, overrides)` : avec `buildPlan` (#1402) et `buildPlanetPlan` (#1403) aux réglages du template et aux prix actuels du catalogue : `{planets: number|null, planetsIncomplete, profitPerDay|null, profitPerWeek|null, profitPerMonth|null, profitIncomplete, weeklyPurchasesIsk|null, purchasesIncomplete}` (profit semaine `×7`, mois `×30`) ;
   - `loadTemplate(template)` : productions, choix et réglages à poser dans le plan ;
   - `mergeTemplate(currentProductions, currentChoices, template)` : productions du plan courant suivies de celles du template (un produit déjà planifié reste une production distincte, comme #1402) ; un choix du template ne s'applique que pour un produit sans choix explicite dans le plan courant ; les réglages du plan courant sont conservés ;
   - `templateToPayload(productions, choices, settings, name)`.
6. Store : `templates` (liste), `fetchTemplates()`, `saveTemplate(name)` (envoie le plan courant, ajoute la réponse en tête), `deleteTemplate(id)`, `loadTemplate(id)` (remplace productions, choix explicites et réglages ; sélection sur la première production ; `extractionYieldOverrides` conservés), `addTemplate(id)` (fusion de la règle 5). Un échec de lecture, d'enregistrement ou de suppression pose l'erreur du store (clé i18n) sans toucher au plan. Sans catalogue chargé, `loadTemplate` et `addTemplate` ne font rien.
7. Un template dont un produit n'existe plus au catalogue (SDE changée) : sa ligne est marquée « recette introuvable » avec Charger et Ajouter désactivés, Supprimer reste actif ; jamais d'exception à l'affichage.

## Frontend : écran

8. Bloc « Mes templates » dans l'onglet Planificateur, sous le classement : champ nom et bouton « Enregistrer le plan » (désactivé sans production ou sans nom), tableau (nom, productions « produit × objectif/j », planètes, profit par jour, semaine, mois, achats par semaine, date d'enregistrement, boutons Charger, Ajouter, Supprimer), états inconnus (« inconnu », jamais 0), état vide qui invite à enregistrer, lien externe vers les templates de la communauté (https://github.com/DalShooth/EVE_PI_Templates/tree/main/PlanetaryInteractionTemplates, `rel="noopener noreferrer"`, lien seulement, décision de #933). Direction HUD EVE, noms longs.
9. Charger remplace tout le plan (avec confirmation seulement si le plan courant n'est pas vide : une ligne de confirmation en place, pas de modal bloquante) ; Ajouter n'en demande pas ; Supprimer demande une confirmation en place.

## Exemples chiffrés (tests rouges)

Catalogue de #1404 (`RANKING_CATALOG` : P2 `B` sortie 5, cycle 3 600 s, `A` 40 et `C` 40, P1 achetés, prix `B` 1 000, `A` 20, `C` 30, taxe 4 %).

| Cas | Attendu |
|---|---|
| Profit d'un template | `B` à 120/j : 120 × (1 000 − 440) = 67 200 / jour, 470 400 / semaine, 2 016 000 / mois |
| Prix du jour | prix de `B` à 1 100 : 120 × (1 100 − (400 + 44)) = 78 720 / jour (recalcul, rien de stocké) |
| Prix manquant | prix de `C` manquant : profit `null`, `profitIncomplete` vrai, jamais 0 |
| Réglages du template | taxe 10 % enregistrée dans le template : coût de `B` = 400 + 100 = 500 |
| Fusion | plan {`B` 120} + template {`B` 60, `M` 18} (`M` un autre produit du catalogue) : trois productions dans l'ordre `B` 120, `B` 60, `M` 18 |
| Choix à la fusion | plan avec `B` acheté explicite, template avec `B` produit et `M` acheté : `B` reste acheté, `M` devient acheté |
| Charger | remplace productions, choix explicites et réglages ; les choix qui n'y figurent pas reviennent au défaut |
| Produit disparu | un `typeId` absent du catalogue : ligne « recette introuvable », Charger et Ajouter désactivés |
| API : création | `POST` valide : 201, `createdAt` renseigné, présent dans le `GET` suivant |
| API : validation | nom vide, 0 production, 21 productions, `targetPerDay` 0, `typeId` hors PI, choix `maybe`, taxe 101, jours 0,4 : 422 chacun, rien de stocké |
| API : plafond | 51e template : 422 |
| API : isolation | un `DELETE` ou un `GET` d'un template d'un autre utilisateur : 404 ; la liste de l'utilisateur A ne contient jamais ceux de B |

## Cas limites

- Deux templates de même nom : permis (pas de contrainte d'unicité).
- Template enregistré avec un choix sur un produit retiré ensuite du plan : le choix reste dans le template.
- Plan courant vide : « Ajouter » = chargement ; l'enregistrement est refusé.
- Réponse `GET` en erreur : le bloc reste affiché avec le bandeau d'erreur existant, l'onglet reste utilisable.

## Accès et fraîcheur

`ROLE_USER`, tout est filtré sur l'utilisateur du jeton ; aucun identifiant d'un autre périmètre n'est accepté (seulement l'`id` du template, contrôlé par appartenance, 404 sinon). Les prix viennent du catalogue de #1402 ; le profit et les planètes ne sont jamais stockés.

## Tests existants contredits ou modifiés

À vérifier (5a) : tests listant les entités liées à un utilisateur ou la suppression de compte (`AccountDeletionServiceTest`), tests du store et de l'onglet qui comptent les appels d'API (`piPlannerTab.spec.ts` : ajout de `/planetary/templates`).

## Hors périmètre

Liens vers les layouts de colonie (#933) ; import de templates de la communauté ; partage entre joueurs ; renommage ; « Suivre ce plan » (#1406) ; synchronisation entre onglets.

## Décisions (mode nuit)

- Persistance côté serveur (table dédiée et migration) plutôt que `localStorage` : un plan enregistré doit survivre à un changement d'appareil et à un nettoyage du navigateur, et #1406 en aura besoin ; coût : une migration à déployer.
- Le template ne stocke que les entrées du plan (productions, choix, réglages) ; planètes, dispositions et profits sont recalculés au prix du jour.
- Pas de `PATCH` : le renommage n'est pas demandé.
- « Ajouter » ajoute les productions du template au plan et complète les choix manquants ; les planètes libres sont affichées par les planètes ajoutées de #1403.
