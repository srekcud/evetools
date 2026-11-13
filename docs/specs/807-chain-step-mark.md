# Spec #807 : marquer une étape de la chaîne lancée ou faite hors de l'application

Mode nuit (2026-11-13) : spec rédigée sans gate. Décision de Sylvain (commentaire du 2026-11-13) : option 1, circuit l, entité de marque (ligne, runs, état), POST et DELETE sur la ligne, runs marqués comptés dans le reste de la ligne et plafonnés par les runs appariés, évènement Mercure `industry-project`, boutons dans l'onglet Chaîne.

## Contexte

Une ligne de job de la BOM n'avance que par un job ESI apparié (`IndustryProjectJobMatch`, `esiJobId` obligatoire) ; une ligne de copie ou d'invention n'accepte aucun lien (`LinkJobProcessor`). Une invention lancée hors de l'application laisse donc la ligne `todo`. Statuts d'un job dans `ProjectChainBuilder` : `done` (runs livrés >= runs), `active` (job en cours ou runs livrés partiels), `blocked` (un composant enfant n'est pas `done`), `missing_materials`, `todo`, `unknown`. Le reste d'une ligne du cockpit = runs moins `getMatchedRuns()`.

## Règles

1. **Marque** : entité `IndustryProjectBomLineMark`, au plus une par ligne de BOM (unique, FK `ON DELETE CASCADE`, `OneToOne` orphelin supprimé avec la ligne), table `industry_project_bom_line_marks`, champs `state` (enum `BomLineMarkState` : `launched`, `done`), `runs` (entier >= 1), `markedAt`. Migration.
2. **Pose** : `POST /industry/projects/{projectId}/bom/{lineId}/mark`, corps `{state, runs}`, propriétaire seul (`ProjectAccessChecker::assertOwner`), pose ou remplace la marque de la ligne (idempotent). 404 : projet ou ligne inconnus, ligne d'un autre projet. 400 : projet `Completed`, ligne qui n'est pas une ligne de job (`isJob`), `state` inconnu, `runs` < 1 ou > runs de la ligne. Toute activité de job est admise (copie, invention, fabrication, réaction). Réponse 200 : la marque (`ProjectBomLineMarkResource`, `id` = id de la ligne, `lineId`, `state`, `runs`, `markedAt`).
3. **Retrait** : `DELETE /industry/projects/{projectId}/bom/{lineId}/mark` (provider qui renvoie la ressource, 404 sans marque), mêmes accès, projet `Completed` refusé (400).
4. **Runs comptés** : `IndustryProjectBomLine::getCountedRuns()` = max(runs appariés, runs marqués), runs marqués plafonnés aux runs de la ligne. Une marque ne s'ajoute jamais aux runs appariés : un job apparié ensuite, de n'importe quel statut, ne double pas le compte ; marque de 10 runs et job de 6 = 10 ; marque de 10 et job de 14 = 14.
5. **Statut de la chaîne** (`ProjectChainBuilder::computedJobStatusOf`) : runs livrés effectifs = max(runs livrés appariés, runs d'une marque `done`) ; une marque `launched` compte comme un job en cours (`active`) tant que les runs livrés effectifs n'atteignent pas ceux de la ligne ; le reste de l'algorithme est inchangé. Conséquences : marque `done` sur toutes les runs = `done`, ce qui débloque le parent d'un composant (`blocked` devient `todo` ou `missing_materials`) ; marque partielle ou `launched` = `active` ; un lien en statut inconnu rend toujours la ligne `unknown`.
6. **Cockpit** : le reste d'une ligne de job (`ProjectCockpitCalculator`) part de `getCountedRuns()`, donc de la marque comme d'un job apparié ; `CockpitBomLine::$matchedRuns` reçoit les runs comptés (`ProjectCockpitInputFactory`).
7. **Apports** : une marque ne crée aucun apport dérivé ni coût (`DerivedContributionService` inchangé) ; l'appariement automatique et manuel (`IndustryJobMatcher`, `LinkJobProcessor`) est inchangé et ne retire pas la marque.
8. **Recalcul de la BOM** : une ligne qui porte une marque est une ligne travaillée : avec `refuseToDropWorkedLines`, la retirer du plan est refusé (message comme pour un job lié), couper ses runs sous les runs marqués aussi ; sinon la ligne gardée par `updateLineInPlace` conserve sa marque.
9. **Lecture** : `ProjectChainNode::$mark` (`{state, runs}` ou `null`) sur les nœuds de job, exposé par `ProjectChainResource` ; aucune autre ressource n'est changée.
10. **Mercure** : après pose ou retrait, `MercurePublisherService::syncCompleted` sur le type `industry-project` pour le propriétaire et chaque participant accepté, `data = {projectId, lineId}` (topic déjà déclaré dans `getTopicsForUser()`).
11. **Affichage** (onglet Chaîne, `ProductionChainJobTree.vue` ou le composant de nœud) : sur chaque nœud de job d'un projet non terminé, pour le propriétaire seul, un contrôle HUD « Marquer » : choix lancé ou fait, runs (entier de 1 aux runs du nœud, valeur initiale = runs du nœud), valider ; une marque posée affiche un badge « Marqué lancé/fait (n runs) » et un bouton « Retirer la marque ». Erreur 400 : message du serveur affiché, rien n'est modifié. Après pose ou retrait : recharge de la chaîne et du cockpit. Les autres membres voient le badge sans contrôle. Textes FR et EN, noms tronqués avec `title`.

## Exemples chiffrés

| Cas | Entrée | Attendu |
|---|---|---|
| Issue | invention de 10 runs marquée `done` 10 | nœud `done`, runs comptés 10, reste cockpit 0 |
| Composant fait | composant marqué `done` pour tous ses runs | le job parent passe de `blocked` à `todo` |
| Partiel | fabrication de 10 runs, marque `done` 4 | `active`, reste 6 |
| Lancé | marque `launched` 10 sur 10 | `active` (pas `done`), reste 0 |
| Pas de double compte | marque `done` 10, job livré apparié de 6 | livrés effectifs 10, reste 0 |
| Job plus grand | marque 10, job livré 14 | 14 comptés |
| Marque remplacée | POST `done` 4 puis POST `launched` 7 | une seule marque : `launched` 7 |
| Retrait | DELETE | statut et reste comme sans marque |
| Hors bornes | `runs` 11 sur une ligne de 10, ou 0 | 400, marque inchangée |
| Ligne de matériau | POST sur un matériau | 400 |
| Non propriétaire | membre accepté ou étranger | 403 |
| Projet terminé | POST ou DELETE | 400 |
| Plan recalculé | ligne marquée retirée du plan, projet publié | refus 400, marque conservée |
| Statut de lien inconnu | marque `done` et job apparié `unknown` | `unknown` |

## Cas limites

- La marque ne vérifie rien dans les assets ni dans les jobs ESI : c'est une déclaration du propriétaire.
- Plusieurs lignes de job du même produit : chaque ligne a sa marque.
- Ligne dont les runs baissent au recalcul du brouillon : runs marqués plafonnés aux runs de la ligne.

## Accès

Écriture : propriétaire seul. Lecture de la marque : tout accès à la chaîne du projet, inchangé. La ligne doit appartenir au projet de l'URL.

## Tests existants contredits ou modifiés

Aucun attendu : sans marque, `getCountedRuns()` = `getMatchedRuns()`. À vérifier au rouge : tests qui construisent `ProjectChainNode` (nouveau paramètre final par défaut), `CockpitBomLine`, la liste des champs de `ProjectChainResource`.

## Hors périmètre

Marque sur une ligne de matériau, coût ou apport d'une marque, vérification contre les jobs ESI, marque par un membre non propriétaire, retrait automatique à l'appariement d'un job, Mercure côté onglet Chaîne (rechargement local seulement).

## Décisions (mode nuit)

- D1 : « suivant » = le statut des parents de composant et le reste du cockpit ; une invention ou copie n'a pas de dépendant dans le calcul actuel (le statut du produit ne lit pas ses lignes de blueprint), non modifié ici.
- D2 : pas de double compte = max(runs appariés, runs marqués), jamais la somme.
- D3 : propriétaire seul, comme les liens de jobs.
- D4 : une marque laisse la ligne travaillée pour le recalcul de la BOM (refus plutôt que perte silencieuse).

## Glossaire

- **Marque d'étape** (Step mark) : déclaration du propriétaire qu'une ligne de job de la chaîne est lancée ou faite hors de l'application, pour un nombre de runs ; elle compte comme un job apparié pour le statut et le reste de la ligne, sans double compte avec un job ESI (spec #807).
- **Runs comptés** (Counted runs) : max(runs appariés, runs marqués) d'une ligne de job.
