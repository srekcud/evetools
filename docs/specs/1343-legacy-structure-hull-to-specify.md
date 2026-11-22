# #1343 : coque à préciser des structures héritées

Circuit m, mode nuit. Branche d'intégration `feat/industry-flow`, pas de PR.

## Constat (vérifié dans le code)

- Un `IndustryStructureConfig` peut avoir le type hérité `engineering_complex` ou `refinery` (enregistré avant le choix de la coque).
- `StructureBonusSummary::of` (écrans de configuration) : pas d'identifiant de coque, donc rôle de structure absent, bonus de temps affichés à `0` au lieu d'inconnus.
- `ConfiguredProductionStructures::productionStructureOf` lève `UnexpectedValueException` sur ces types : toute planification du propriétaire échoue, la recommandation ne « laisse » pas la structure de côté. Test actuel : `ConfiguredProductionStructuresTest::testLegacyStructureTypeIsRefusedRatherThanGivenAGuessedBonus`.
- `PATCH /industry/structures/{id}` accepte déjà un nouveau `structureType` (validé par `ProductionStructureRigsValidator`).

## Règles

1. Terme du glossaire : **Coque à préciser** / *Hull to specify* = structure dont le type est `engineering_complex` (coques Raitaru, Azbel, Sotiyo) ou `refinery` (Athanor, Tatara). Aucune coque n'est devinée.
2. `IndustryStructureConfig::isHullToSpecify(): bool` est vrai pour ces deux types seulement. Une constante associe chaque type hérité à ses coques possibles.
3. `StructureBonuses` porte `hullToSpecify: bool`. Quand il est vrai, `manufacturingTimeBonus` et `reactionTimeBonus` sont `null` (rôle de structure inconnu), jamais `0`. Les bonus de matériaux restent ceux des rigs (déjà connus). Type de coque connu : `hullToSpecify` faux, bonus de temps numériques comme aujourd'hui.
4. `StructureConfigResource`, `StructureBonusPreviewResource` et l'entrée `sharedConfig` de `CorporationStructureResource` exposent `hullToSpecify` et des bonus de temps nullables (`?float`).
5. `ConfiguredProductionStructures` n'inclut plus les structures à coque à préciser dans `configuredBy` (plus d'exception pour elles : aucune structure ne les compte dans une recommandation, un coût ou un plan). Un type inconnu autre que `station` et ces deux types lève toujours.
6. `GET /industry/projects/{projectId}/work-stations` (`ProjectWorkStationsResource`) expose `structuresToSpecify` : liste `{id, name, structureType}` des structures du propriétaire du projet dont la coque est à préciser (non supprimées). Source : `OwnerStructureLabels::hullToSpecifyOf(User $owner)`.
7. Front, écrans de configuration des structures (`StructureConfig.vue`) :
   - carte d'une structure à coque à préciser : pas de bonus de temps affiché ; un avertissement ambre « Coque à préciser » avec la phrase « Le bonus de rôle dépend de la coque : choisis-la pour le connaître » ;
   - dans cet avertissement, un sélecteur des coques possibles du type (Raitaru, Azbel, Sotiyo pour un Engineering Complex ; Athanor, Tatara pour une Refinery) et un bouton qui envoie `PATCH` avec le seul `structureType` choisi ; en cas d'erreur du serveur (rigs d'une autre taille, 400), le message du serveur s'affiche dans l'avertissement ;
   - l'aperçu du formulaire (`bonusPreview`) affiche « Coque à préciser » à la place de `-null %` pour les bonus de temps quand `hullToSpecify` est vrai ;
   - l'option héritée du sélecteur de type du formulaire est conservée (on ne retire aucun réglage).
8. Front, assistant de démarrage (`ProjectSetupStations.vue`) : si `workStations.structuresToSpecify` n'est pas vide, un encart ambre sous le titre dit « Ces structures sont laissées de côté : coque à préciser » avec le nom de chacune et le lien existant vers les réglages industrie. Il s'affiche aussi quand les types de travail n'ont aucune ligne.
9. Pas de devinette automatique, pas de migration : les lignes en base ne changent pas.

## Exemples chiffrés

| Cas | hullToSpecify | manufacturingTimeBonus | reactionTimeBonus |
|---|---|---|---|
| `engineering_complex`, nullsec, aucun rig | true | null | null |
| `refinery`, nullsec, aucun rig | true | null | null |
| `azbel`, nullsec, aucun rig | false | valeur SDE du rôle (non nulle) | valeur SDE du rôle |
| `tatara`, nullsec, aucun rig | false | 0 pour la fabrication, valeur SDE pour les réactions | idem |
| `engineering_complex` + rig de fabrication reconnu | true | null | null (le bonus de matériaux du rig reste affiché) |

## Cas limites

- Config supprimée (`isDeleted`) : ni dans `configuredBy`, ni dans `structuresToSpecify`.
- Structure héritée dont la coque est précisée plus tard : disparaît de `structuresToSpecify`, entre dans `configuredBy` avec ses bonus SDE.
- Un utilisateur sans structure héritée : `structuresToSpecify` est `[]`.
- `FactoryStations` (spécialisations) : un bonus de temps `null` ne compte pas comme positif ; le bonus de matériaux des rigs compte comme avant.

## Hors périmètre

- Retrait de l'option héritée du formulaire, migration des données, choix automatique de coque.
- Exposer l'avertissement ailleurs que sur les écrans de configuration et l'assistant de démarrage.
- Mercure : la mise à jour d'une structure est un PATCH synchrone existant, aucun traitement asynchrone ajouté.

## Décisions

- D1 : option recommandée par le brief (aucune devinette) ; la coque se choisit depuis l'avertissement par un PATCH du `structureType` seul.
- D2 : la recommandation passe de « échec » à « structure écartée avec raison affichée » (l'issue demande de dire pourquoi elle est écartée). Écart au comportement testé auparavant, à relire.
- D3 : seuls les bonus de temps deviennent nullables (le rôle de structure n'intervient que dans le temps dans `StructureBonusSummary`).
