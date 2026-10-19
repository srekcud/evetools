# Spec #115 (lot 3a, notifications de jobs industrie) — Un type absent du SDE n'a plus de nom inventé

## Contexte

Lots 1, 2, 4a, 4b et 5 (specs `115-type-name-missing-from-sde*.md`) : le **nom de type inconnu** vaut `null` dans l'API ; le front affiche « Nom inconnu (#id) » ; les commandes console écrivent `type <typeId> (name missing from the SDE)` (`ConsoleProjectLabel`).

`IndustryJobSyncService::getTypeName()` (`:270-273`) invente `"Type #<typeId>"` pour le produit d'un job terminé. Ce nom part dans :

- la notification de job terminé (titre `Job completed: %s`, message `%s %s (%dx) ready to deliver`, données `productName`, `:245-250`), enregistrée en base et poussée en Web Push ;
- le message Mercure `industry-job-completed` (`%s - %s completed!`, données `productName`, `:229-233`) ;
- le message Mercure `industry-project` qui liste les étapes terminées d'un projet (`:335-346`).

## Règles métier

- **R1 — Données.** `productName` dans les données de la notification et du message Mercure vaut `null` pour un type absent de `invTypes` ; `productTypeId` reste renseigné.
- **R2 — Texte.** Dans un texte (titre, message, liste d'étapes), un type inconnu s'écrit `type <typeId> (name missing from the SDE)`, le même libellé que la console. Ce format n'existe qu'à un endroit du back : il est partagé avec `ConsoleProjectLabel`, pas recopié.
- **R3 — Résolution.** Le service garde sa lecture actuelle d'`InvTypeRepository` (son harnais de test le construit avec le dépôt) et retire seulement le nom inventé : `getTypeName()` renvoie `?string`.
- **R4 — Inchangé.** Un type connu garde exactement les textes et données actuels ; nombre de notifications, catégorie, niveau, `jobId`, runs et activité inchangés.

## Exemples chiffrés

Types : Nitrogen Fuel Block 4051 (connu), 1000 (absent de `invTypes`). Job de fabrication, 5 runs.

| # | Situation | Entrée | Attendu |
|---|---|---|---|
| E1 | Garde-fou, produit connu | job 7001 terminé, produit 4051 | titre `Job completed: Nitrogen Fuel Block`, message `Nitrogen Fuel Block Manufacturing (5x) ready to deliver`, `productName = "Nitrogen Fuel Block"` |
| E2 | Notification, produit inconnu | job 7002 terminé, produit 1000 | titre `Job completed: type 1000 (name missing from the SDE)`, message `type 1000 (name missing from the SDE) Manufacturing (5x) ready to deliver`, données `productName = null`, `productTypeId = 1000` |
| E3 | Mercure `industry-job-completed`, produit inconnu | même job | message `type 1000 (name missing from the SDE) - Manufacturing completed!`, données `productName = null` |
| E4 | Mercure `industry-project`, étape inconnue | étape de produit 1000 terminée dans le projet « Batch » | message `Project "Batch": type 1000 (name missing from the SDE) completed` |

## Décision prise en autonomie

- **Texte d'une notification** : une notification est un texte enregistré, pas une réponse d'API recomposée par le front ; elle porte donc le libellé explicite de la console plutôt que `null`. Les données structurées portent `null` (R1).
- **Périmètre** : les autres syncs du lot 3 (mining, PVE, PI, scans PVE, assets) enregistrent un nom dans une colonne et demandent une migration ; ils restent pour une session de jour.

## Hors périmètre

- Nom de projet `null` dans le message `industry-project` (`$project->getName()`), comportement existant non lié à #115.
- Reste du lot 3 et suppression de `resolve()` / `resolveMany()`.

## Glossaire

Aucun terme ajouté.
