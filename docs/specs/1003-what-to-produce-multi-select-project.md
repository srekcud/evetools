# #1003 — Quoi produire : sélection multiple et un projet avec ME/TE des blueprints possédés

Circuit M, mode nuit (spec validée en autonomie). Front, plus un champ d'API.

## Contexte

Le scanner de profit par lot (« Quoi produire », `BatchScanTab.vue`) est une impasse : on lit les marges, puis on doit ressaisir chaque produit dans le projet. La modale de création de projet accepte déjà plusieurs lignes (`navigationIntent` `target: 'projects'` avec `prefillItems`, utilisé par `BlueprintLibraryTab.vue`). Le backend accepte jusqu'à 100 items par projet, rien à changer côté création.

Écart avec l'issue : `teUsed` existe dans `BatchProfitScannerService` (T2 = `InventionService::BASE_INVENTION_TE`, réaction = 0, T1 = 20) mais `BatchScanResultResource` ne l'expose pas. Il faut l'exposer (même règle T2/réaction que la modale, sans la dupliquer côté front).

## Règles

1. Chaque ligne de résultat du scanner porte une case à cocher (colonne étroite, sans élargir les colonnes : cf. #1002).
2. L'en-tête de la colonne coche ou décoche toutes les lignes visibles : visibles = lignes qui passent les filtres client (catégorie, marge min, volume min), toutes pages confondues (même convention que `BlueprintLibraryTab`).
3. La sélection est un ensemble de `typeId`. Elle survit au tri et à la pagination. Elle est vidée quand un nouveau scan remplace `scannerStore.scanResults`. Un changement de filtre client ne vide rien, mais un produit filtré n'est ni compté ni envoyé (N = sélectionnés ∩ visibles).
4. Bouton « Démarrer un projet (N) » dans l'en-tête des résultats, désactivé quand N = 0.
5. Au clic : si `useBlueprintsStore().library` est nul, `fetchLibrary()` d'abord. Si la bibliothèque reste indisponible (`store.error`), pas de navigation et l'erreur s'affiche près du bouton : jamais de repli silencieux sur l'hypothèse.
6. Puis `industryStore.navigationIntent = { target: 'projects', prefillItems }`, un item par produit coché et rien d'autre, dans l'ordre d'affichage courant du scanner.
7. Choix du blueprint possédé pour un produit : parmi les entrées de la bibliothèque dont `productTypeId` = `typeId` du produit (scope personnel et corporation confondus). Ordre de préférence : `bpo`, puis `bpr`, puis `bpc`. À rang égal : ME le plus haut, puis TE le plus haut. Pour un `bpc`, à ME/TE égaux, le plus de runs (`runs` nul compte pour 0).
8. ME/TE de l'item : ceux du blueprint choisi, source `owned`. Sans blueprint possédé : `meUsed` et `teUsed` du scanner, source `assumed`.
9. Runs de l'item : `runs` du blueprint choisi quand c'est un `bpc` avec `runs` non nul ; sinon 1 (défaut actuel de création par `prefillItems`).
10. Réactions et T2 : aucune règle propre côté front. Sans blueprint possédé, `teUsed` du scanner porte la règle (T2 = TE d'invention, réaction = 0). Avec un blueprint possédé, ses valeurs gagnent.
11. `ProjectPrefillItem` gagne un champ optionnel `blueprintSource?: 'owned' | 'assumed'`. La modale de création affiche, à côté du nom, un badge `owned` (« Blueprint possédé ») ou `assumed` (« Hypothèse du scanner ») quand le champ est présent, avec une info-bulle qui dit d'où viennent ME/TE. Sans champ (cas `BlueprintLibraryTab`, cas `prefill` simple), aucun badge : comportement inchangé. Le badge reste affiché quand le joueur modifie ME/TE.
12. Le nom du produit dans l'item : `typeNameLabel(item.typeName, item.typeId)` (jamais un nom nul).
13. Mise en page HUD EVE : noms longs tronqués avec infobulle, la case n'ajoute pas de colonne large.

## Exemples chiffrés (ce seront les tests rouges)

Scanner : A (T1, `meUsed` 10, `teUsed` 20), B (T2, `meUsed` 2, `teUsed` 4), C (réaction, `meUsed` 0, `teUsed` 0).

| Bibliothèque | Produit | Item préremplie |
|---|---|---|
| BPO ME 10 / TE 20 pour A | A | ME 10, TE 20, runs 1, `owned` |
| rien pour B | B | ME 2, TE 4, runs 1, `assumed` |
| rien pour C | C | ME 0, TE 0, runs 1, `assumed` |
| BPO ME 5 / TE 10 et BPC ME 10 / TE 20 (30 runs) pour A | A | BPO : ME 5, TE 10, runs 1, `owned` |
| BPC ME 8 / TE 16 (20 runs) et BPC ME 10 / TE 20 (5 runs) pour B | B | ME 10, TE 20, runs 5, `owned` |
| BPC ME 10 / TE 20, `runs` nul pour A | A | ME 10, TE 20, runs 1, `owned` |
| BPO ME 10 / TE 20 pour A, deux fois (personnel, corporation) | A | une seule ligne, `owned` |

Sélection : cocher 3 lignes puis le bouton → 3 items, dans l'ordre d'affichage. Aucune ligne cochée → bouton désactivé. Tri par marge après cochage → sélection intacte. Nouveau scan → sélection vide, bouton désactivé. Case d'en-tête → toutes les lignes filtrées cochées, puis décochées au second clic.

## Cas limites

- Produit sans `typeName` : libellé par `typeNameLabel`.
- Bibliothèque vide : tous les items en `assumed`.
- Plus de 100 produits cochés : le backend refuse à la création (hors périmètre, aucune limite côté sélection).
- Le scanner relancé pendant que la modale est ouverte : hors périmètre.
- Bibliothèque en erreur : règle 5.

## API

`BatchScanResultResource::$teUsed` (int, défaut 0, même rôle que `meUsed`), renseigné par `BatchScanProvider::mapToResource` depuis `$data['teUsed']`. Aucune migration, aucune donnée de plus d'un utilisateur : `teUsed` est une hypothèse du scanner, pas une donnée ESI.

## Accès et fraîcheur

Aucun nouvel endpoint. La bibliothèque est celle de l'utilisateur connecté (`/industry/blueprints`, déjà scopée). Fraîcheur : celle du store au moment du clic (rafraîchi si absent).

## Tests existants modifiés

Aucun attendu. `BatchScanProviderTest` reçoit une assertion sur `teUsed` (ajout). Les tests de `batchScanTab*.spec.ts` ne changent pas : la colonne ajoutée ne casse pas les sélecteurs existants (à vérifier).

## Hors périmètre

- Prix réel des apports, marge de référence : autres issues du groupe.
- Choix du blueprint par personnage ou par emplacement.
- Mercure : aucune action async nouvelle.
- Retrait de réglage, champ ou écran existant : aucun.

## Glossaire

Pas de terme nouveau : « blueprint possédé » et « hypothèse du scanner » sont des libellés d'interface, pas des symboles métier.

## Décisions de la nuit

- `teUsed` exposé par l'API (l'issue disait front seul).
- Visibles = filtres client, toutes pages.
- Bibliothèque en erreur : arrêt avec message, pas de repli.
- `bpr` placé entre `bpo` et `bpc` (original sans limite de runs).
