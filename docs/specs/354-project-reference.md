# #354 — Projet de référence à la création (périmètre réduit)

Décision de Sylvain (2026-11-07) : par produit, le dernier projet `completed` de moins d'un mois, mono-produit, dont le lecteur est propriétaire ou participant accepté ; coût réel par unité et marge affichés dans `NewProjectModal`. Retard et import de doctrine : hors périmètre (issues distinctes). Circuit m.

## Règles

1. Candidats = projets au statut `completed`, `completedAt` dans [maintenant − 1 mois, maintenant], où le lecteur a une participation `Accepted` (le propriétaire est participant). `IndustryProjectParticipantRepository::findAcceptedOnProjectsCompletedBetween` (déjà trié par `completedAt` décroissant).
2. Un projet avec plus d'un produit (`getItems()->count() !== 1`) n'est jamais une référence : aucune ligne pour lui.
3. Une ligne par produit (`typeId`) : le projet le plus récent l'emporte. Un projet plus ancien du même produit n'est jamais utilisé, même si le plus récent a des chiffres inconnus.
4. Unités produites = `requiredQuantity` de la ligne de BOM racine du produit : job, `activityType` `manufacturing`, `typeId` du produit, `parentTypeId` nul (= runs × sortie par run). Ligne absente ou quantité 0 : `units` et `unitCost` `null`.
5. Coût réel par unité = `ProjectDistributionService::calculateDistribution(project)->totalProjectCost / units`, non arrondi. `totalProjectCost` `null` (valeur d'un apport inconnue) : `unitCost` `null`.
6. Marge = `DistributionResult::marginPercent` du projet (profit / coût de production, déjà la marge unique du module). `null` si frais ou coût inconnus.
7. Aucun chiffre inconnu n'est remplacé par 0 ou par une valeur d'un autre projet : le champ est `null` et l'écran affiche « inconnu ».
8. Service `App\Service\Industry\Project\ProjectReferenceReader::read(User $user, \DateTimeImmutable $now): list<ProjectReference>` ; constante nommée pour la fenêtre (`REFERENCE_MAX_AGE = '1 month'`). `ProjectReference` : `productTypeId`, `projectId`, `completedAt`, `units`, `unitCost`, `marginPercent`.
9. API : `GET /api/industry/project-references` (ressource `ProjectReferencesResource`, `ROLE_USER`, lecture seule, `skip_null_values` false). Corps : `id` (littéral `project-references`), `rows` : `productTypeId`, `projectId`, `completedAt` (ATOM), `units`, `unitCost`, `marginPercent`. Le lecteur est l'utilisateur authentifié : jamais les projets d'un autre. Sans authentification : 401.
10. Front : store `group-industry/projectReferences.ts` (`fetchReferences`, `rows`, `error`), chargé à l'ouverture de `NewProjectModal` ; sous chaque produit de la liste des items qui a une référence : date de fin, coût par unité (ISK), marge (%), « inconnu » pour un `null`. Aucune ligne pour un produit sans référence. Erreur de chargement : message discret, création non bloquée. Chaînes i18n en et fr.

## Exemples

| Projet terminé | Produit | Référence |
|---|---|---|
| il y a 5 j, 1 produit, 100 unités, coût 2 000 000, marge 12 % | Ishtar | 20 000 ISK/unité, 12 % |
| il y a 40 j | Ishtar | aucune (hors fenêtre) |
| il y a 5 j, 2 produits | Ishtar, Hecate | aucune |
| deux Ishtar (il y a 5 j et 10 j) | Ishtar | celui de 5 j |
| il y a 5 j, valeur d'un apport inconnue | Ishtar | ligne, `unitCost` null, `marginPercent` null |
| il y a 5 j, projet d'un autre pilote | Ishtar | aucune |

## Cas limites

- Aucune ligne de BOM racine : `units` null, `unitCost` null, marge conservée.
- Projet sans vente : `marginPercent` null (frais inconnus) ou calculée par le service de répartition, inchangée.
- Quantité de projets importante : `findAcceptedOnProjectsCompletedBetween` charge items et BOM en lot (pas de requête par projet).

## Hors périmètre

- Retard (durée réelle contre durée au plus tôt) : le projet ne porte ni durée prévue ni planning (issue distincte).
- Création depuis une doctrine (liste EFT) : aucun import n'existe (issue distincte).
- Références de la corporation : seulement mes projets (propriétaire ou participant accepté).
- Projets multi-produits : exclus, pas de répartition au prorata.

## Décisions prises en autonomie

- Fenêtre bornes incluses (`>=` du dépôt) ; « moins d'un mois » = un mois calendaire glissant (`-1 month`).
- Une référence au coût inconnu est affichée avec « inconnu » plutôt que masquée : l'utilisateur sait qu'un projet existe mais que son coût n'est pas fiable.

## Actions prod

Aucune (pas de migration, pas de commande console).
