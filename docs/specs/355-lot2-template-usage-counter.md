# #355 lot 2 : compteur « Utilisé N× » des modèles de projet

Maquette : `industry-project-v3.html#p=36` (colonne « Utilisé »). Lot 1 : `docs/specs/355-project-templates.md`. Demandé par Sylvain après la fusion de #412.

## Règles

1. **Lien** : `IndustryProject` gagne `template` (ManyToOne `IndustryProjectTemplate`, nullable, `ON DELETE SET NULL`, migration). Le projet ne copie rien du modèle et ne le suit pas : le lien sert seulement à compter.
2. **Création** : `CreateProjectInput` gagne `templateId` (uuid, optionnel). Le modèle doit être visible de l'utilisateur (`IndustryProjectTemplateRepository::findVisibleTo`) : sinon 404 « Template not found », aucun projet créé. Absent ou `null` : projet sans modèle, comportement actuel inchangé. Le lien est posé même si l'utilisateur a modifié le formulaire après avoir choisi le modèle (« point de départ »).
3. **Compteur** : `usageCount` sur `IndustryProjectTemplateResource` = nombre de projets dont `template` est ce modèle, tous propriétaires et tous statuts confondus (un projet supprimé ne compte plus). Un modèle jamais utilisé : 0, calculé par une requête de comptage groupée pour tous les modèles listés (une seule requête, pas une par modèle), jamais un défaut. Visible de ceux qui voient le modèle (le chiffre ne dit rien d'autre sur les projets).
4. **Suppression du modèle** : les projets gardent leurs données, leur lien passe à `null`. Les projets créés avant ce lot ne sont pas comptés (aucune reprise de données).
5. **Front** : `NewProjectModal` retient l'id du modèle utilisé (événement `use` du panneau), l'envoie en `templateId` à la création, l'oublie à chaque ouverture (`resetForm`) ; `ProjectTemplatesPanel` affiche la colonne « Utilisé » (`3×`, FR et EN, `data-testid="project-template-usage"`), type `ProjectTemplate::usageCount`.
6. **Mercure** : aucune action asynchrone.

## Exemples

| # | État | Résultat |
|---|---|---|
| E1 | Bob crée un projet avec `templateId` d'un modèle d'Alice partagé | projet lié, `usageCount` du modèle 1 pour Alice et Bob |
| E2 | Bob puis Alice créent chacun un projet avec ce modèle | `usageCount` 2 |
| E3 | Création avec le `templateId` d'un modèle non partagé d'Alice par Bob | 404, aucun projet créé |
| E4 | Création sans `templateId` | aucun lien, compteurs inchangés |
| E5 | Modèle jamais utilisé | `usageCount` 0 |
| E6 | Alice supprime le modèle utilisé deux fois | les deux projets existent, lien `null` |
| E7 | Un projet lié est supprimé | `usageCount` baisse de 1 |

## Cas limites et accès

- `templateId` mal formé : 422 (validation uuid). Aucun identifiant d'un autre périmètre accepté sans contrôle de visibilité.
- Le compteur inclut les projets privés d'autres utilisateurs sous forme de simple nombre ; accepté (colonne de la maquette).

## Tests existants modifiés

`ProjectTemplateCollectionProviderTest` et `ProjectTemplateApiTest` (nouveau champ `usageCount`), fixtures front `projectTemplateFixtures.ts` : le test-writer les met à jour dans ce lot.

## Hors périmètre

Reprise des projets déjà créés, tri par usage, statistiques par période.
