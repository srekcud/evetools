# Refonte Patrimoine › Inventaire (/assets) — 2026-11-13

Source : session avec Sylvain du 2026-11-13. Issues créées le 2026-11-13 : #874 à #898 (groupes `group:assets-perf`, `group:assets-page`, `group:assets-polish`, plus #877 dans `group:assets-sync` et #894 dans `group:industry-features`). Les numéros sont en face de chaque point ; 9, 11 et 12 sont regroupés dans #883, 21 et 23 dans #895.

Maquette interactive : https://claude.ai/artifact/MMLpjVgCYvmatj7BY7U97e (privée), copie locale régénérée par `dc2vue.py` dans le scratchpad de la session (temporaire).

## Mesures prod (corpo de Sylvain, vue Corporation)

- 14 512 lignes d'asset (1 707 racines, 12 805 dans des conteneurs), 85 lieux, 88 systèmes, 2 770 types, 449 conteneurs (max 575 éléments), profondeur 1.
- Réponse JSON-LD 8,1 Mo (environ 0,7 Mo compressé), 4 947 plans, 445 vaisseaux assemblés, 4 302 modules montés.
- Divisions présentes : CorpSAG1 = ACCES MEMBRE (7 911 lignes), CorpSAG3 = INDUSTRIE (6 601) ; seules divisions partagées par le panneau de visibilité.
- Temps mesurés dans Chrome sur la prod : serveur 8,1 à 9,5 s avant le premier octet, téléchargement 0,3 à 1 s, JSON.parse 30 ms, rendu Vue 2,4 s (1 707 lignes, 1 708 images), total 11,3 s.
- Rejeu local avec les 14 512 lignes : requête hangars 90 ms, contenus 400 ms, classification SDE 140 ms, mapping DTO 40 ms, sérialisation JSON-LD API Platform 2 400 ms (JSON simple 1 270 ms, json_encode 40 ms). Le facteur 3 prod/local n'est pas expliqué (CPU probable, non mesuré).

## Décisions de Sylvain

- Pas de valeur ISK dans l'inventaire (« peu d'intérêt ») : retirée de la maquette et des propositions.
- Libellé des divisions : préfixe « Hangar - » (ex. « Hangar - INDUSTRIE »).
- Export CSV : non retenu dans la maquette (garder comme idée basse priorité, à reconfirmer avant issue).
- Vue par type : retirée de la vue Corporation (point 7).
- Containers de projet industrie épinglés d'office (point 20).
- Stocks surveillés : pas concluants sur le stock corpo, décision en attente (point 16) ; masqués en vue corpo dans la maquette.

## Propositions, dans l'ordre suggéré

### Performance (bugs)
1. **#874** Construire la réponse `/api/me/corporation/assets` (et personnage) sans le serializer API Platform : tableaux construits à la main ou DBAL, JSON simple, même contrat front. Gain local sérialisation 2,4 s → environ 0,05 s. Fichiers : `src/State/Provider/Assets/CorporationAssetsProvider.php`, `src/Service/Assets/VisibleCorporationAssetsFinder.php`, `CachedAssetRepository::findContentsOfContainers`.
2. **#875** Rendu front : lieux repliés par défaut au-delà d'un seuil, `shallowRef` pour `assets`, images `loading="lazy"`, `applyResolvedItemName` ne recopie plus tout le tableau. Fichiers : `frontend/src/views/Assets.vue`, `components/assets/AssetLocationGroup.vue`.
3. **#876** Rechargement sans flash : garder liste et filtres pendant le rechargement, remplacer d'un bloc, garder système/lieu sélectionnés s'ils existent encore, vider tout de suite au changement de personnage, `AbortController` contre les réponses arrivées dans le désordre. Aujourd'hui `isLoading` masque la liste et le watch `[selectedCharacterId, viewMode]` remet les filtres à zéro.

### Bug backend
4. **#877** Noms de lieux cassés : `Asset Safety Wrap — ` (1 480 lignes, Misaba) et `Office — ` sans nom de structure ; 18 lignes en `Structure #…` non résolues et exclues du filtre Lieu.

### Évolution de structure (décision à prendre)
5. **#878** Résumé par lieu (85 lignes) puis contenu chargé à l'ouverture d'un lieu / conteneur : le temps ne dépend plus de la taille de la corpo. Change l'API.

### Fonctionnalités (toutes dans la maquette)
6. **#879** Vue corpo regroupée par division de hangar (« Hangar - X »), filtre par division avec compteurs.
7. **#881** Vue par type : quantité totale, nombre de lieux, détail par lieu. Décision de Sylvain (2026-11-13) : inutile en vue Corporation (chacun a son container, le total corpo ne sert pas) ; retirée de la vue corpo dans la maquette, gardée en vue Personnages.
8. **#882** Recherche qui couvre le nom des conteneurs et leur contenu, ouvre les conteneurs qui correspondent (cas testé : « 1_Srek »). Insensible à la casse et aux accents (« materiaux » trouve « Smyth Matériaux ») ; le vrai front ne gère aujourd'hui que la casse (`toLowerCase` dans `Assets.vue`).
9. **#883** Tri des lieux (éléments, nom), barre proportionnelle au nombre d'éléments.
10. **#885** Sélection + actions : copier au format multibuy, envoyer à l'Appraisal.
11. **#883** Badge sur les lieux à nom cassé ou structure non résolue.
12. **#883** Fraîcheur : afficher `cachedAt` (« synchronisé il y a X min »).
13. **#886** Vaisseaux assemblés regroupés par type + nom + fit (ex. Vexor « Yolo Keepstar » ×18 à C-J6MT), bouton « Copier le fit » (EFT), badge sur les « !! BAD FIT !! ».
14. **#887** Plans : croiser avec `OwnedBlueprint` par `itemId` pour afficher BPC/BPO, runs, ME/TE (quantité -2 = copie).
15. **#888** Écarts depuis la dernière sync (+/- par lieu et conteneur) : demande une table d'historique, décision de structure.
16. **#889** Lien avec les stocks surveillés de l'Industrie (`IndustryStockpileTarget`) : marquer un type à surveiller, voir l'écart avec la cible. Retour de Sylvain (2026-11-13) : idée bonne mais pas concluante telle quelle, le stock corpo mélange tout (hangars de membres, conteneurs perso `1_<pseudo>`, Asset Safety, plusieurs bureaux). À recadrer (cible par conteneur ou par lieu) ou abandonner : décision en attente.
17. **#890** Volume m³ par lieu et sélection, bouton « Créer un contrat de courrier ». Attention : `InvType::$volume` est le volume assemblé, sans volume reconditionné dans le SDE importé ; les Station Container / Warehouse Container (intransportables) gonflent les totaux (Asset Safety affiché à 1,1 G m³ dans la maquette). Il faut le volume reconditionné et exclure les conteneurs de station.
18. **#891** Bloc Asset Safety en tête de page (lieu, nombre d'éléments, vaisseaux), rappel que la livraison se demande en jeu.
19. **#892** Actions en jeu : « Définir comme destination » sur un lieu (vérifier le scope ESI d'écriture d'itinéraire, `OpenInGameButton` ne gère que market/info/contract), « Infos » sur un conteneur.
20. **#893** Conteneurs épinglés / « mes conteneurs » (convention de nommage `1_<pseudo>` observée). Demande de Sylvain (2026-11-13) : les containers déclarés comme container de projet industrie sont épinglés d'office (non désépinglables tant que le projet est actif), en tête de liste. Source : `IndustryProject::getContainerName()`, résolu par nom sur les assets corpo visibles comme `ProjectContainerService::containerScopeOf()` (plusieurs containers racines peuvent porter le même nom). À préciser dans l'issue : projets dont l'utilisateur est propriétaire seulement, ou aussi participant.

### Industrie (demande liée, hors page Inventaire)
26. **#894** Un projet industrie peut surveiller plusieurs containers (demande de Sylvain, 2026-11-13). Aujourd'hui `IndustryProject::$containerName` est une seule chaîne : `ProjectContainerService::walkNamedContainers()` prend déjà tous les containers racines qui portent ce nom exact (comparaison `===`, sensible à la casse), mais pas des containers de noms différents. À faire : liste de noms de containers par projet (réglages du projet), stock agrégé sur tous (stock proposé, BPC T2, lignes de BOM #866, compagnon #506), état « introuvable » par container. Les containers du projet apparaissent groupés par projet dans « Mes conteneurs épinglés » (point 20). Décision de structure : changement de schéma (`containerName` → collection), migration des projets existants.

### Finitions
21. **#895** Textes en dur non traduits : `types d'items`, `items` (`AssetLocationGroup.vue:135`), `(Main)`, `'Failed to load assets'`.
22. **#896** Filtres dans l'URL, dernier personnage retenu.
23. **#895** `settings-section="pve"` dans `PatrimoineModuleTabs.vue` (copier-coller probable).
24. **#897** `Assets.vue` utilise `fetch` direct au lieu de `apiRequest`.
25. **#898** Export CSV de la vue filtrée (non retenu dans la maquette, à reconfirmer).

## Décisions prises le 2026-11-13 (en interactif)

- #878 : trois niveaux (résumé des lieux, contenu d'un lieu à l'ouverture, contenu d'un conteneur à l'ouverture), recherche côté serveur. Passée en `circuit:l`.
- #888 : table de diffs, seule la dernière sync gardée par propriétaire. Passée en `circuit:l`.
- #889 : abandonnée, issue fermée (not planned).
- #890 : volume reconditionné dans une table à nous, mise à jour une fois par semaine par une tâche planifiée (pas d'appel ESI au calcul), conteneurs de station exclus. Passée en `circuit:l`.
- #893 : épinglage d'office pour les projets dont l'utilisateur est propriétaire ou participant.
- #894 : table enfant (projet, nom de container) avec unicité par projet, migration des noms existants ; correspondance avec les assets insensible à la casse. Passée en `circuit:l`.
